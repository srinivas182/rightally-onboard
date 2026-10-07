<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ReportStats;
use App\Support\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, ReportStats $stats): View
    {
        [$from, $to, $group] = $this->filters($request);

        return view('admin.reports.index', [
            'funnel' => $stats->funnel($from, $to, $group),
            'mrr' => $stats->mrr(),
            'revenue' => $stats->revenueByMonth(),
            'forecast' => $stats->forecast(),
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'group' => $group, 'groups' => ReportStats::GROUPS,
        ]);
    }

    public function export(Request $request, ReportStats $stats, string $report): StreamedResponse
    {
        [$from, $to, $group] = $this->filters($request);

        return response()->streamDownload(function () use ($stats, $report, $from, $to, $group) {
            $out = fopen('php://output', 'w');
            if ($report === 'funnel') {
                fputcsv($out, [ReportStats::GROUPS[$group], 'Started', 'Details done', 'Signed', 'Paid deposit', 'Live', 'Started to live %']);
                foreach ($stats->funnel($from, $to, $group) as $r) {
                    fputcsv($out, [$r['group'], $r['started'], $r['details'], $r['signed'], $r['paid'], $r['live'], $r['started'] ? round($r['live'] / $r['started'] * 100, 1) : 0]);
                }
            } else {
                fputcsv($out, ['Month', 'Deposits', 'Go-live balances', 'Monthly fees', 'Yearly fees', 'Early termination', 'Total (net of refunds and tax)']);
                foreach ($stats->revenueByMonth() as $r) {
                    fputcsv($out, [$r['month'], ...array_map(fn ($c) => number_format($c / 100, 2, '.', ''), [$r['deposit'], $r['balance'], $r['monthly'], $r['annual'], $r['early_termination'], $r['total']])]);
                }
            }
            fclose($out);
        }, "rightally-{$report}-".BusinessClock::today()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    private function filters(Request $request): array
    {
        $tz = BusinessClock::timezone();
        try {
            $from = Carbon::parse((string) $request->query('from', BusinessClock::today()->subDays(89)->toDateString()), $tz)->startOfDay();
            $to = Carbon::parse((string) $request->query('to', BusinessClock::today()->toDateString()), $tz)->endOfDay();
        } catch (\Throwable) {
            $from = BusinessClock::today()->subDays(89);
            $to = BusinessClock::now()->endOfDay();
        }
        $group = array_key_exists((string) $request->query('group'), ReportStats::GROUPS) ? (string) $request->query('group') : 'source';

        return [$from, $to, $group];
    }
}
