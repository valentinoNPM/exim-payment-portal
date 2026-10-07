<?php

namespace App\Services\PurchaseOrders;

use Illuminate\Support\Facades\DB;

class ItemCodeGenerator
{
    public function reserve(): string
    {
        $prefix = strtoupper((string) config('purchase-orders.item_code_prefix', 'ITEM'));

        return DB::transaction(function () use ($prefix): string {
            DB::table('item_sequences')->insertOrIgnore([
                'prefix' => $prefix,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('item_sequences')
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->value('last_number');

            $next = ((int) $sequence) + 1;

            DB::table('item_sequences')
                ->where('prefix', $prefix)
                ->update([
                    'last_number' => $next,
                    'updated_at' => now(),
                ]);

            return sprintf('%s-%06d', $prefix, $next);
        });
    }
}
