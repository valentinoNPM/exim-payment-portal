<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Division;
use App\Models\Tax;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use UnitEnum;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected static ?string $title = 'PO Baru';

    protected static ?string $navigationLabel = 'PO Baru';

    protected static string|UnitEnum|null $navigationGroup = 'Purchase Order';

    protected static ?int $navigationSort = 2;

    protected static bool $shouldRegisterNavigation = true;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['division_id'] = Division::query()
            ->whereRaw('UPPER(code) = ?', ['GA'])
            ->value('id');
        $data['created_by'] = auth()->id();
        $data['pic_user_id'] = $data['pic_user_id'] ?? auth()->id();
        // Nama PIC disalin dari pengguna terpilih — cetakan PDF memakai kolom ini.
        $data['pic_name'] = User::query()->whereKey($data['pic_user_id'])->value('name')
            ?? auth()->user()?->name;

        abort_unless($data['division_id'], 422, 'Division GA belum tersedia.');

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->syncDocumentTax();
    }

    private function syncDocumentTax(): void
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

        foreach ($this->getRecord()->items as $item) {
            $item->selectedTaxes()->sync($taxIds);
            $item->recalculateTaxes();
        }
    }
}
