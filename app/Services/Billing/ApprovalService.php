<?php

namespace App\Services\Billing;

use App\Models\Admin;
use App\Models\Approval;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Support\Money;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Two-person rule: early terminations always, and refunds or credits above
 * the threshold, need a second admin to approve. The requester can't approve
 * their own request.
 */
final class ApprovalService
{
    public function __construct(
        private readonly EarlyTerminationService $termination,
        private readonly RefundService $refunds,
        private readonly CreditService $credits,
        private readonly EmailSender $email,
        private readonly AuditLogger $audit,
    ) {}

    public static function threshold(): int
    {
        return (int) config('rightally.approval_threshold_cents', 50000);
    }

    public function needsApproval(string $action, int $cents): bool
    {
        return $action === 'early_termination' || $cents > self::threshold();
    }

    public function request(string $action, Customer $customer, int $cents, array $payload, string $reason, Admin $admin): Approval
    {
        $approval = Approval::create(['action' => $action, 'customer_id' => $customer->id, 'amount_cents' => $cents, 'payload' => $payload,
            'reason' => $reason, 'requested_by' => $admin->id, 'status' => 'pending']);
        $this->audit->log('approval.requested', "{$admin->name} requested {$approval->label()} for {$customer->company_name} (".Money::format($cents).')', $approval, ['reason' => $reason]);
        $this->email->toTeam('team_alert', [
            'alert_title' => "Approval needed: {$approval->label()} for {$customer->company_name}",
            'alert_text' => "{$admin->name} asked for {$approval->label()} of ".Money::format($cents).". Reason: {$reason}. Another admin must approve it.",
            'alert_link' => route('admin.approvals.index'),
        ]);

        return $approval;
    }

    public function approve(Approval $approval, Admin $admin, ?string $note = null): Approval
    {
        $this->guard($approval, $admin);
        $approval->update(['status' => 'approved', 'decided_by' => $admin->id, 'decided_at' => now(), 'decision_note' => $note]);

        try {
            $result = $this->execute($approval, $admin);
            $approval->update(['result' => $result]);
            $this->audit->log('approval.approved', "{$admin->name} approved {$approval->label()} for {$approval->customer->company_name}", $approval, ['result' => $result]);
        } catch (Throwable $e) {
            report($e);
            $approval->update(['status' => 'failed', 'result' => mb_substr($e->getMessage(), 0, 1000)]);
            $this->audit->log('approval.failed', "{$approval->label()} for {$approval->customer->company_name} failed after approval", $approval, ['error' => $e->getMessage()]);
        }

        return $approval->fresh();
    }

    public function reject(Approval $approval, Admin $admin, string $note): Approval
    {
        $this->guard($approval, $admin);
        $approval->update(['status' => 'rejected', 'decided_by' => $admin->id, 'decided_at' => now(), 'decision_note' => $note]);
        $this->audit->log('approval.rejected', "{$admin->name} rejected {$approval->label()} for {$approval->customer->company_name}", $approval, ['note' => $note]);

        return $approval;
    }

    private function guard(Approval $approval, Admin $admin): void
    {
        if ($approval->status !== 'pending') {
            throw ValidationException::withMessages(['approval' => 'This request has already been decided.']);
        }
        if ($approval->requested_by === $admin->id) {
            throw ValidationException::withMessages(['approval' => 'Another admin must approve your own request.']);
        }
    }

    private function execute(Approval $approval, Admin $admin): string
    {
        $customer = $approval->customer;

        return match ($approval->action) {
            'early_termination' => ($i = $this->termination->terminate($customer, $admin, $approval->reason))
                ? "Invoice {$i->number} for ".Money::format($i->amount_cents).' issued' : 'Terminated; nothing further due',
            'refund' => 'Refunded '.Money::format($approval->amount_cents).' on '.$this->refunds->refund(Invoice::findOrFail($approval->payload['invoice_id']), $approval->amount_cents, $approval->reason, $admin)->invoice->number,
            'credit' => 'Credit '.Money::format($this->credits->credit($customer, $approval->amount_cents, $approval->reason, $admin, $approval->id)->amount_cents).' applied',
            default => throw new \RuntimeException('Unknown action'),
        };
    }
}
