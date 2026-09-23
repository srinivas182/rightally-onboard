<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Services\Billing\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Requests waiting for a second admin. */
class ApprovalController extends Controller
{
    public function index(): View
    {
        return view('admin.approvals.index', [
            'pending' => Approval::with('customer', 'requester')->where('status', 'pending')->oldest()->get(),
            'decided' => Approval::with('customer', 'requester', 'decider')->where('status', '!=', 'pending')->latest('decided_at')->limit(30)->get(),
        ]);
    }

    public function approve(Request $request, Approval $approval, ApprovalService $approvals): RedirectResponse
    {
        $a = $approvals->approve($approval, $request->user('admin'), $request->input('note'));

        return back()->with($a->status === 'failed' ? 'warning' : 'success', $a->status === 'failed'
            ? "Approved, but it couldn’t be completed: {$a->result}"
            : "Approved: {$a->result}.");
    }

    public function reject(Request $request, Approval $approval, ApprovalService $approvals): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:250']], ['note.required' => 'Say why, so the requester knows.']);
        $approvals->reject($approval, $request->user('admin'), $data['note']);

        return back()->with('success', 'Request rejected.');
    }
}
