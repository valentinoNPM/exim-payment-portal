<?php

namespace App\Actions;

use App\Models\ErpExportBatch;
use App\Models\PaymentSlip;
use App\Models\User;
use App\Services\Erp\ErpJournalBuilder;
use App\Services\Erp\ErpWorkbookWriter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExportPaymentSlipToErp
{
    public function __construct(private ErpJournalBuilder $builder, private ErpWorkbookWriter $writer) {}

    public function execute(PaymentSlip $slip, User $user, array $vatNumbers = []): ErpExportBatch
    {
        abort_unless($user->hasRole('checker'), 403);
        $path = 'erp-exports/'.Str::uuid().'/'.ErpWorkbookWriter::filename($slip->slip_number);
        $disk = Storage::disk('local');
        try {
            return DB::transaction(function () use ($slip, $user, $vatNumbers, $path, $disk): ErpExportBatch {
                $locked = PaymentSlip::query()->lockForUpdate()->findOrFail($slip->id);
                $rows = $this->builder->build($locked, $vatNumbers);
                foreach ($locked->invoices as $invoice) {
                    if (array_key_exists($invoice->id, $vatNumbers)) {
                        $invoice->update([
                            'vat_invoice_number' => filled($vatNumbers[$invoice->id])
                                ? trim((string) $vatNumbers[$invoice->id])
                                : null,
                        ]);
                    }
                }
                if (! $locked->approved_at) {
                    throw ValidationException::withMessages(['erp' => 'Verification date is missing. The payment slip must be verified before export.']);
                }
                if (! $disk->makeDirectory(dirname($path))) {
                    throw new \RuntimeException('Cannot create private export directory.');
                }
                $this->writer->write($rows, $disk->path($path));
                if (! $disk->exists($path) || $disk->size($path) === 0) {
                    throw new \RuntimeException('ERP file was not saved.');
                }
                $batch = ErpExportBatch::create([
                    'batch_number' => 'ERP-'.Str::uuid(), 'format_code' => 'LedgerJournalTrans',
                    'approval_date_from' => $locked->approved_at->toDateString(), 'approval_date_to' => $locked->approved_at->toDateString(),
                    'file_path' => $path, 'created_by' => $user->id, 'exported_at' => now(),
                ]);
                $batch->exportItems()->create(['payment_slip_id' => $locked->id]);
                $locked->update(['status' => 'exported']);
                $locked->audits()->create(['user_id' => $user->id, 'event' => 'erp_exported', 'old_values' => ['status' => 'approved'], 'new_values' => ['status' => 'exported', 'erp_export_batch_id' => $batch->id]]);

                return $batch;
            });
        } catch (Throwable $e) {
            $disk->delete($path);
            if ($e instanceof ValidationException) {
                throw $e;
            }
            if ($e instanceof UniqueConstraintViolationException && $slip->erpExportItem()->exists()) {
                throw ValidationException::withMessages(['erp' => 'This payment slip has already been exported.']);
            }
            report($e);
            throw ValidationException::withMessages(['erp' => 'The ERP file could not be generated. No export was recorded. Please try again.']);
        }
    }
}
