<?php

namespace App\Http\Controllers;

use App\Models\Buyer;
use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentSlipMasterOptionController extends Controller
{
    public function __invoke(Request $request, string $kind): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('maker'), 403);

        $model = match ($kind) {
            'supplier' => Supplier::class,
            'customer' => Customer::class,
            'buyer' => auth()->user()?->isEximDivision() ? Buyer::class : null,
            'unit' => Unit::class,
            default => null,
        };
        abort_unless($model !== null, 404);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique((new $model)->getTable(), 'code')],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
        ]);

        $record = $model::create($validated);

        return response()->json(['id' => $record->getKey(), 'label' => $record->name], 201);
    }
}
