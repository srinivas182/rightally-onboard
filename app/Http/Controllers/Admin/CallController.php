<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallBooking;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Support\BusinessClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Admin > Calls: discovery calls booked on the GoHighLevel calendar. */
class CallController extends Controller
{
    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'past' ? 'past' : 'upcoming';
        $coupon = strtoupper(trim((string) $request->query('coupon')));
        $status = array_key_exists((string) $request->query('status'), CallBooking::STATUSES) ? (string) $request->query('status') : '';

        $calls = CallBooking::with('customer')
            ->when($tab === 'upcoming', fn ($q) => $q->where('starts_at', '>=', now()->subHour())->orderBy('starts_at'))
            ->when($tab === 'past', fn ($q) => $q->where(fn ($w) => $w->where('starts_at', '<', now()->subHour())->orWhereNull('starts_at'))->orderByDesc('starts_at'))
            ->when($coupon !== '', fn ($q) => $q->where('coupon_code', $coupon))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->paginate(30)->withQueryString();

        $summary = CallBooking::query()
            ->select(DB::raw("COALESCE(coupon_code, '(no coupon)') as coupon"), DB::raw('count(*) as booked'),
                DB::raw("sum(case when status in ('completed','onboarded') then 1 else 0 end) as held"),
                DB::raw("sum(case when status = 'onboarded' then 1 else 0 end) as onboarded"))
            ->groupBy('coupon')->orderByDesc('booked')->limit(12)->get();

        return view('admin.calls.index', compact('calls', 'tab', 'coupon', 'status', 'summary') + [
            'tz' => BusinessClock::timezone(),
            'coupons' => CallBooking::whereNotNull('coupon_code')->distinct()->orderBy('coupon_code')->pluck('coupon_code'),
        ]);
    }

    public function update(Request $request, CallBooking $call, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:'.implode(',', array_keys(CallBooking::STATUSES))], 'admin_notes' => ['nullable', 'string', 'max:1000']]);
        $call->update(array_filter($data, fn ($v) => $v !== null));
        $audit->log('call.updated', "Updated call with {$call->name}", $call, $data);

        return back()->with('success', 'Call updated.');
    }

    /** Emails the prospect an onboarding link, with the coupon they came in with. */
    public function sendOnboardingLink(CallBooking $call, EmailSender $email, AuditLogger $audit): RedirectResponse
    {
        abort_unless($call->email, 400);
        $link = url('/').($call->coupon_code ? '?coupon='.urlencode($call->coupon_code) : '');
        $email->toAddress('call_onboarding_link', $call->email, (string) $call->name, [
            'first_name' => strtok((string) $call->name, ' ') ?: 'there',
            'company_name' => $call->company_name ?: 'your brokerage',
            'onboarding_link' => $link,
        ]);
        $audit->log('call.onboarding_link_sent', "Sent onboarding link to {$call->email}", $call);

        return back()->with('success', "Onboarding link sent to {$call->email}".($call->coupon_code ? " with coupon {$call->coupon_code}" : '').'.');
    }
}
