<?php

namespace App\Services\Admin;

use App\Models\Contract;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes customers completely: agreements, invoices, payments, emails,
 * history and stored files, plus (optionally) their Stripe subscription and
 * Stripe customer. Also resets all client data for a clean go-live.
 */
final class CustomerEraser
{
    public function __construct(private readonly StripeClient $stripe, private readonly AuditLogger $audit) {}

    /** @return array{stripe: string} what happened in Stripe */
    public function erase(Customer $customer, bool $alsoStripe = true): array
    {
        $stripeNote = $alsoStripe ? $this->removeFromStripe($customer) : 'Stripe left unchanged';
        $label = "{$customer->company_name} ({$customer->email})";
        $contractFolders = DB::table('contracts')->where('customer_id', $customer->id)->pluck('uuid')->map(fn ($u) => "contracts/{$u}");

        DB::transaction(function () use ($customer) {
            $contractIds = DB::table('contracts')->where('customer_id', $customer->id)->pluck('id');
            $invoiceIds = DB::table('invoices')->where('customer_id', $customer->id)->pluck('id');

            // Give back coupon uses from this customer's agreements.
            DB::table('contracts')->whereIn('id', $contractIds)->whereNotNull('coupon_id')->whereNotNull('signed_at')->pluck('coupon_id')
                ->each(fn ($id) => Coupon::whereKey($id)->where('times_used', '>', 0)->decrement('times_used'));

            DB::table('payments')->where('customer_id', $customer->id)->delete();
            DB::table('activity_logs')->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('subject_type', (new Customer)->getMorphClass())->where('subject_id', $customer->id))
                ->orWhere(fn ($w) => $w->where('subject_type', (new Invoice)->getMorphClass())->whereIn('subject_id', $invoiceIds))
                ->orWhere(fn ($w) => $w->where('subject_type', (new Contract)->getMorphClass())->whereIn('subject_id', $contractIds)))->delete();
            DB::table('invoices')->whereIn('id', $invoiceIds)->delete();
            DB::table('renewal_notices')->whereIn('contract_id', $contractIds)->delete();
            DB::table('pauses')->where('customer_id', $customer->id)->delete();
            DB::table('credits')->where('customer_id', $customer->id)->delete();
            DB::table('approvals')->where('customer_id', $customer->id)->delete();
            DB::table('contracts')->whereIn('id', $contractIds)->update(['previous_contract_id' => null]);
            DB::table('contracts')->whereIn('id', $contractIds)->delete();
            DB::table('email_logs')->where('customer_id', $customer->id)->delete();
            DB::table('agent_count_logs')->where('customer_id', $customer->id)->delete();
            DB::table('webhook_deliveries')->where('customer_id', $customer->id)->delete();
            DB::table('quotes')->where('customer_id', $customer->id)->update(['customer_id' => null]);
            $customer->forceDelete();
        });

        // Stored files: signed agreements, signatures, invoice PDFs.
        foreach ([...$contractFolders, "invoices/{$customer->uuid}"] as $dir) {
            Storage::disk('local')->deleteDirectory($dir);
        }
        $this->audit->log('customer.deleted', "Deleted customer {$label} and all their records. {$stripeNote}.");

        return ['stripe' => $stripeNote];
    }

    /** Test data reset: removes every customer and everything linked to them. Admins, settings, templates and coupons stay. */
    public function resetAll(): int
    {
        $count = 0;
        Customer::withTrashed()->orderBy('id')->each(function (Customer $c) use (&$count) {
            $this->erase($c, alsoStripe: false);
            $count++;
        });
        DB::table('stripe_events')->delete();
        DB::table('email_logs')->delete();
        DB::table('webhook_deliveries')->delete();
        DB::table('quotes')->delete();
        DB::table('activity_logs')->delete();
        DB::table('coupons')->update(['times_used' => 0]);
        Storage::disk('local')->deleteDirectory('contracts');
        Storage::disk('local')->deleteDirectory('invoices');
        StripeClient::newKeyPrefix(app(SettingsService::class)); // record numbers restart, so Stripe request keys must too
        foreach (['health:stripe_last_error', 'health:reconcile_last'] as $key) {
            Cache::forget($key);
        }

        return $count;
    }

    private function removeFromStripe(Customer $customer): string
    {
        if (! $customer->stripe_customer_id || ! $this->stripe->isConfigured()) {
            return 'No Stripe records';
        }
        try {
            if ($customer->stripe_subscription_id) {
                $this->stripe->delete('subscriptions/'.$customer->stripe_subscription_id);
            }
            $this->stripe->delete('customers/'.$customer->stripe_customer_id);

            return 'Stripe subscription cancelled and Stripe customer deleted';
        } catch (Throwable $e) {
            report($e);

            return 'Stripe clean-up failed ('.$e->getMessage().'); remove them in the Stripe dashboard';
        }
    }
}
