<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\BusinessClock;
use Illuminate\View\View;

/**
 * Dashboard shell. KPIs read live data now; the revenue chart, date ranges
 * and "needs attention" list are completed in Sprint 5.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $monthStart = BusinessClock::now()->startOfMonth()->utc();

        return view('admin.dashboard', [
            'kpis' => [
                'customers' => Customer::whereNotIn('status', [CustomerStatus::Draft])->count(),
                'revenue_cents' => (int) Payment::where('status', 'succeeded')->where('settled_at', '>=', $monthStart)->sum('amount_cents'),
                'failed' => Invoice::where('status', InvoiceStatus::Failed)->count(),
                'suspended' => Customer::where('status', CustomerStatus::Suspended)->count(),
            ],
        ]);
    }
}
