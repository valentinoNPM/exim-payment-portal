<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('supplier_name_snapshot')->nullable()->after('supplier_id');
            $table->text('supplier_address_snapshot')->nullable()->after('supplier_name_snapshot');
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->string('unit_code_snapshot', 50)->nullable()->after('unit_id');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(<<<'SQL'
                UPDATE purchase_orders po
                INNER JOIN suppliers s ON s.id = po.supplier_id
                SET po.supplier_name_snapshot = s.name,
                    po.supplier_address_snapshot = s.address
            SQL);
            DB::statement(<<<'SQL'
                UPDATE purchase_order_items poi
                LEFT JOIN items i ON i.id = poi.item_id
                LEFT JOIN units u ON u.id = poi.unit_id
                SET poi.item_code_snapshot = COALESCE(NULLIF(i.source_code, ''), NULLIF(poi.item_code_snapshot, ''), i.code),
                    poi.unit_code_snapshot = u.code
            SQL);

            return;
        }

        DB::table('purchase_orders')
            ->select(['id', 'supplier_id'])
            ->orderBy('id')
            ->chunkById(500, function ($orders): void {
                $suppliers = DB::table('suppliers')
                    ->whereIn('id', $orders->pluck('supplier_id')->filter()->unique())
                    ->get(['id', 'name', 'address'])
                    ->keyBy('id');

                foreach ($orders as $order) {
                    $supplier = $suppliers->get($order->supplier_id);
                    DB::table('purchase_orders')->where('id', $order->id)->update([
                        'supplier_name_snapshot' => $supplier?->name,
                        'supplier_address_snapshot' => $supplier?->address,
                    ]);
                }
            });

        DB::table('purchase_order_items')
            ->select(['id', 'item_id', 'unit_id', 'item_code_snapshot'])
            ->orderBy('id')
            ->chunkById(500, function ($lines): void {
                $items = DB::table('items')
                    ->whereIn('id', $lines->pluck('item_id')->filter()->unique())
                    ->get(['id', 'code', 'source_code'])
                    ->keyBy('id');
                $units = DB::table('units')
                    ->whereIn('id', $lines->pluck('unit_id')->filter()->unique())
                    ->get(['id', 'code'])
                    ->keyBy('id');

                foreach ($lines as $line) {
                    $item = $items->get($line->item_id);
                    DB::table('purchase_order_items')->where('id', $line->id)->update([
                        'item_code_snapshot' => $item?->source_code ?: ($line->item_code_snapshot ?: $item?->code),
                        'unit_code_snapshot' => $units->get($line->unit_id)?->code,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            $table->dropColumn('unit_code_snapshot');
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['supplier_name_snapshot', 'supplier_address_snapshot']);
        });
    }
};
