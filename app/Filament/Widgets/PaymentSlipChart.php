<?php

namespace App\Filament\Widgets;

use App\Models\PaymentSlip;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentSlipChart extends ChartWidget
{
    protected ?string $heading = 'Monthly Payment Trend (Import vs Export)';

    protected ?string $maxHeight = '300px';

    protected function getData(): array
    {
        $months = [];
        $importData = [];
        $exportData = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $monthKey = $date->format('Y-m');
            $months[$monthKey] = $date->format('F Y');
            $importData[$monthKey] = 0;
            $exportData[$monthKey] = 0;
        }

        // Query approved payment slips grouped by month and transaction type
        $slips = PaymentSlip::where('status', 'approved')
            ->where('approved_at', '>=', Carbon::now()->subMonths(5)->startOfMonth())
            ->select(
                DB::raw("DATE_FORMAT(approved_at, '%Y-%m') as month"),
                'transaction_type',
                DB::raw('SUM(grand_total_amount) as total')
            )
            ->groupBy('month', 'transaction_type')
            ->get();

        foreach ($slips as $slip) {
            $month = $slip->month;
            if (isset($importData[$month]) && $slip->transaction_type === 'import') {
                $importData[$month] = (float) $slip->total;
            }
            if (isset($exportData[$month]) && $slip->transaction_type === 'export') {
                $exportData[$month] = (float) $slip->total;
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Import (Rp)',
                    'data' => array_values($importData),
                    'borderColor' => '#3b82f6', // blue
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => 'start',
                ],
                [
                    'label' => 'Export (Rp)',
                    'data' => array_values($exportData),
                    'borderColor' => '#10b981', // green
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => 'start',
                ],
            ],
            'labels' => array_values($months),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
