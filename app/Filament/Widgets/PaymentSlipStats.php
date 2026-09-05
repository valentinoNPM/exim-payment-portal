<?php

namespace App\Filament\Widgets;

use App\Models\PaymentSlip;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

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
                ->color('gray')
                ->chart([2, 3, 5, 4, 7, 2, $draftCount]);

            $stats[] = Stat::make('Submitted Slips', $submittedCount)
                ->description('Slips currently submitted to Accounting')
                ->descriptionIcon('heroicon-m-paper-airplane')
                ->color('warning')
                ->chart([1, 4, 3, 6, 5, 8, $submittedCount]);
        }

        if ($user->hasRole('checker')) {
            $submittedQueue = PaymentSlip::where('status', 'submitted')->count();
            $approvedReady = PaymentSlip::where('status', 'approved')->count();

            $stats[] = Stat::make('Verification Queue', $submittedQueue)
                ->description('Slips waiting for Accounting verification')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning')
                ->chart([3, 5, 2, 7, 4, 6, $submittedQueue]);

            $stats[] = Stat::make('Ready to Export', $approvedReady)
                ->description('Verified slips ready for ERP export')
                ->descriptionIcon('heroicon-m-arrow-down-tray')
                ->color('success')
                ->chart([0, 2, 5, 8, 12, 15, $approvedReady]);
        }

        return $stats;
    }
}
