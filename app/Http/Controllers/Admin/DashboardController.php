<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardStats;
use App\Services\Admin\SystemHealth;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardStats $stats, SystemHealth $health): View
    {
        $range = in_array($request->query('range'), ['day', 'month', 'year', 'custom'], true) ? $request->query('range') : 'month';
        [$start, $end, $label] = $stats->range($range, $request->query('from'), $request->query('to'));

        return view('admin.dashboard', [
            'kpis' => $stats->kpis(),
            'range' => $range,
            'rangeLabel' => $label,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'revenueCents' => $stats->revenueCents($start, $end),
            'chart' => $stats->monthlyRevenue(),
            'attention' => $stats->attention(),
            'health' => $request->user('admin')->isSuperAdmin() ? $health->checks() : null,
        ]);
    }
}
