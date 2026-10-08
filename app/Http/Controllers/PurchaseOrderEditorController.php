<?php

namespace App\Http\Controllers;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Http\Requests\SavePurchaseOrderRequest;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrders\PurchaseOrderNumberGenerator;
use App\Services\PurchaseOrders\SavePurchaseOrder;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PurchaseOrderEditorController extends Controller
{
    public function store(SavePurchaseOrderRequest $request, SavePurchaseOrder $savePurchaseOrder): RedirectResponse
    {
        $purchaseOrder = $savePurchaseOrder->execute(
            $request->validated(),
            $request->user(),
        );

        Notification::make()
            ->title('Purchase Order berhasil dibuat')
            ->success()
            ->send();

        return redirect(PurchaseOrderResource::getUrl('view', ['record' => $purchaseOrder]));
    }

    public function update(
        SavePurchaseOrderRequest $request,
        PurchaseOrder $purchaseOrder,
        SavePurchaseOrder $savePurchaseOrder,
    ): RedirectResponse {
        $purchaseOrder = $savePurchaseOrder->execute(
            $request->validated(),
            $request->user(),
            $purchaseOrder,
        );

        Notification::make()
            ->title('Purchase Order berhasil diperbarui')
            ->success()
            ->send();

        return redirect(PurchaseOrderResource::getUrl('view', ['record' => $purchaseOrder]));
    }

    public function items(Request $request): JsonResponse
    {
        abort_unless(PurchaseOrderResource::canAccess(), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));

        $items = Item::query()
            ->where('is_active', true)
            ->with('unit:id,code,name')
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('source_code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(15);

        return response()->json([
            'data' => $items->getCollection()->map(fn (Item $item): array => [
                'id' => $item->getKey(),
                'code' => $item->source_code ?: $item->code,
                'name' => $item->name,
                'specification' => $item->specification,
                'unit_id' => $item->unit_id,
                'unit_name' => $item->unit?->name,
            ])->values(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function numberPreview(Request $request, PurchaseOrderNumberGenerator $generator): JsonResponse
    {
        abort_unless(PurchaseOrderResource::canCreate(), 403);

        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        return response()->json($generator->preview(Carbon::parse($validated['date'])));
    }
}
