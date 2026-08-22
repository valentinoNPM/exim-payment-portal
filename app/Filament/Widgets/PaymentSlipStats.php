<?php

namespace App\Filament\Widgets;

use App\Models\PaymentSlip;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class PaymentSlipStats extends BaseWidget
{
    protected static ?int $sort = -4;

    protected int|string|array $columnSpan = 1;

    protected function getStats(): array
    {
        $user = auth()->user();
        $stats = [];

        if (! $user) {
            return [];
        }

        if ($user->hasRole('maker')) {
            $draftCount = PaymentSlip::where('created_by', $user->id)
                ->where('status', 'draft')
                ->count();
            $submittedCount = PaymentSlip::where('created_by', $user->id)
                ->where('status', 'submitted')
                ->count();

            $stats[] = Stat::make('Draft Slips', $draftCount)
                ->description('Slips that need review/editing')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('warning');

            $stats[] = Stat::make('Submitted Slips', $submittedCount)
                ->description('Slips currently submitted to Accounting')
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('info');
        }

        if ($user->hasRole('checker')) {
            $submittedQueue = PaymentSlip::where('status', 'submitted')->count();
            $approvedReady = PaymentSlip::where('status', 'approved')->count();

            $stats[] = Stat::make('Verification Queue', $submittedQueue)
                ->description('Slips waiting for Accounting verification')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('warning');

            $stats[] = Stat::make('Ready to Export', $approvedReady)
                ->description('Approved slips ready for ERP export')
                ->descriptionIcon('heroicon-m-arrow-down-tray')
                ->color('success');
        }

        if ($user->hasRole('approver')) {
            $pendingApproval = PaymentSlip::where('status', 'pending_approval')->count();

            // Total amount approved this month
            $startOfMonth = Carbon::now()->startOfMonth();
            $totalApprovedAmount = PaymentSlip::where('status', 'approved')
                ->where('approved_at', '>=', $startOfMonth)
                ->sum('grand_total_amount');

            $stats[] = Stat::make('Pending Approval', $pendingApproval)
                ->description('Slips awaiting GM decision')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning');

            $stats[] = Stat::make('Approved This Month', 'Rp '.number_format($totalApprovedAmount, 0, ',', '.'))
                ->description('Accumulated approved value for '.Carbon::now()->format('F Y'))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success');
        }

        return $stats;
    }
}
