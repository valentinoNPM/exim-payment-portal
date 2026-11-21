<?php

namespace App\Http\Controllers;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\Item;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseOrderMasterOptionController extends Controller
{
    public function __invoke(Request $request, string $kind): JsonResponse
    {
        abort_unless(PurchaseOrderResource::canCreate(), 403);
        $model = match ($kind) {
            'item' => Item::class,
            'supplier' => Supplier::class,
            'unit' => Unit::class,
            default => null,
        };
        abort_unless($model, 404);

        $rules = ['name' => ['required', 'string', 'max:255']];
        if ($kind !== 'item') {
            $rules['code'] = ['required', 'string', 'max:255', Rule::unique((new $model)->getTable(), 'code')];
        }
        if ($kind === 'supplier') {
            $rules += [
                'address' => ['nullable', 'string', 'max:5000'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:255'],
            ];
        }
        if ($kind === 'item') {
            $rules += [
                'specification' => ['nullable', 'string', 'max:500'],
                'unit_id' => ['nullable', Rule::exists('units', 'id')->where('is_active', true)],
            ];
        }
        $validated = $request->validate($rules, [
            'required' => ':attribute wajib diisi.',
            'unique' => ':attribute sudah digunakan.',
            'email' => 'Format email tidak valid.',
            'exists' => ':attribute tidak tersedia atau sudah nonaktif.',
            'max' => ':attribute maksimal :max karakter.',
            'string' => ':attribute harus berupa teks.',
        ], ['code' => 'Kode', 'name' => 'Nama', 'unit_id' => 'Satuan', 'specification' => 'Spesifikasi', 'address' => 'Alamat', 'phone' => 'Telepon', 'email' => 'Email']);
        $record = $model::create([...$validated, 'is_active' => true]);

        return response()->json([
            'id' => $record->getKey(),
            'name' => $record->name,
            'label' => $record->name,
            'code' => $record->code,
            'specification' => $kind === 'item' ? $record->specification : null,
            'unit_id' => $kind === 'item' ? $record->unit_id : null,
            'unit_name' => $kind === 'item' ? $record->unit?->name : null,
        ], 201);
    }
}
