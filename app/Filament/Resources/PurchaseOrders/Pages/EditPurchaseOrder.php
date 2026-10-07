<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Tax;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected static ?string $title = 'Ubah PO';

    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->visible(fn (): bool => PurchaseOrderResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $picUserId = $data['pic_user_id'] ?? $this->getRecord()->pic_user_id ?? auth()->id();
        $data['pic_user_id'] = $picUserId;

        // Nama PIC disalin dari pengguna terpilih HANYA kalau PIC sistemnya memang diganti.
        // Dokumen hasil impor ECOUNT tidak punya PIC sistem dan nama PIC aslinya harus tetap utuh.
        $picLama = $this->getRecord()->pic_user_id;
        if ($picLama !== null && (int) $picLama !== (int) $picUserId) {
            $data['pic_name'] = User::query()->whereKey($picUserId)->value('name')
                ?? $this->getRecord()->pic_name;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $additionTaxId = Tax::query()
            ->whereKey($this->data['addition_tax_id'] ?? null)
            ->where('calculation_type', 'addition')
            ->where('is_active', true)
            ->value('id');
        $deductionTaxId = Tax::query()
            ->whereKey($this->data['deduction_tax_id'] ?? null)
            ->where('calculation_type', 'deduction')
            ->where('is_active', true)
            ->value('id');
        $taxIds = array_values(array_filter([$additionTaxId, $deductionTaxId]));

        $taxChanged = false;

        foreach ($this->getRecord()->items as $item) {
            $changes = $item->selectedTaxes()->sync($taxIds);
            $taxChanged = $taxChanged
                || $changes['attached'] !== []
                || $changes['detached'] !== []
                || $changes['updated'] !== [];
        }

        if ($taxChanged || $this->getRecord()->wasChanged(['discount_amount', 'shipping_amount'])) {
            $this->getRecord()->recalculateTotals();
        }
    }
}
