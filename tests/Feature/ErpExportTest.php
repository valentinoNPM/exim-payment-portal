<?php

namespace Tests\Feature;

use App\Actions\ExportPaymentSlipToErp;
use App\Actions\VerifyPaymentSlip;
use App\Filament\Pages\ErpExports;
use App\Filament\Resources\PaymentSlips\Pages\EditPaymentSlip;
use App\Filament\Resources\PaymentSlips\PaymentSlipResource;
use App\Models\ChartOfAccount;
use App\Models\ErpExportBatch;
use App\Models\PaymentSlipAudit;
use App\Models\User;
use App\Services\Erp\AccountResolver;
use App\Services\Erp\ErpJournalBuilder;
use App\Services\Erp\ErpWorkbookWriter;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fixtures\ErpPaymentSlip;
use Tests\TestCase;

class ErpExportTest extends TestCase
{
    use RefreshDatabase;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['maker', 'checker', 'approver'] as $role) {
            Role::create(['name' => $role]);
        }
        $this->checker = User::factory()->create()->assignRole('checker');
        $this->actingAs($this->checker);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
    }

    public function test_builder_preserves_stored_taxes_separate_items_and_identifiers(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $rows = app(ErpJournalBuilder::class)->build($slip, [$slip->invoices()->first()->id => '000099']);
        $this->assertCount(10, $rows);
        $this->assertSame('I05_902020', $rows[0]->costCenter);
        $this->assertSame($rows[0]->account, $rows[1]->account);
        $this->assertSame(3301, $rows[2]->debit);
        $this->assertSame('11990501', $rows[2]->account);
        $this->assertSame(601, $rows[3]->credit);
        $this->assertSame('21020401', $rows[3]->account);
        $this->assertSame(32700, $rows[4]->credit);
        $this->assertSame('000123', $rows[4]->account);
        $this->assertSame('000099', $rows[4]->vatInvoiceNumber);
        $this->assertNull($rows[9]->vatInvoiceNumber);
        $slip->transaction_type = 'import';
        $this->assertSame('I05_190000', app(ErpJournalBuilder::class)->build($slip)[0]->costCenter);
    }

    public function test_account_resolution_order(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $invoice = $slip->invoices()->first();
        $item = $invoice->items()->first();
        $resolver = app(AccountResolver::class);
        $this->assertSame('43011011', $resolver->resolve($item, $invoice));
        $item->coa_code_snapshot = null;
        $item->setRelation('chartOfAccount', new ChartOfAccount(['code' => '222']));
        $this->assertSame('222', $resolver->resolve($item, $invoice));
        $item->coa_code_snapshot = '111';
        $this->assertSame('111', $resolver->resolve($item, $invoice));
        $item->coa_code_snapshot = null;
        $item->setRelation('chartOfAccount', null);
        $this->expectException(ValidationException::class);
        $resolver->resolve($item, $invoice);
    }

    public function test_item_coa_snapshot_does_not_recalculate_stored_taxes(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'submitted']);
        $item = $slip->invoices()->first()->items()->first();
        $coa = ChartOfAccount::create(['code' => '222', 'name' => 'Other expense']);
        $item->update(['coa_id' => $coa->id]);
        $this->assertSame('222', $item->fresh()->coa_code_snapshot);
        $this->assertSame('Other expense', $item->fresh()->coa_name_snapshot);
        $this->assertSame('33.01', $item->invoice->fresh()->tax_addition_amount);
        $this->assertSame('6.01', $item->invoice->fresh()->tax_deduction_amount);
        app(VerifyPaymentSlip::class)->execute($slip, $this->checker);
        $verified = $slip->fresh();
        $this->assertSame('approved', $verified->status);
        $this->assertSame($this->checker->id, $verified->approved_by);
        $this->assertNotNull($verified->verified_at);
        $this->assertNotNull($verified->approved_at);
        $this->expectException(AuthorizationException::class);
        $item->fresh()->update(['coa_id' => null]);
    }

    public function test_maker_cannot_change_item_coa(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'submitted']);
        $this->actingAs(User::factory()->create()->assignRole('maker'));
        $otherCoa = ChartOfAccount::create(['code' => '999', 'name' => 'Forbidden account']);
        $this->expectException(AuthorizationException::class);
        $slip->invoices()->first()->items()->first()->update(['coa_id' => $otherCoa->id]);
    }

    public function test_stale_item_model_cannot_change_coa_after_verification(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'submitted']);
        $item = $slip->invoices()->first()->items()->first();
        $item->load('invoice.paymentSlip');
        $slip->update(['status' => 'approved']);
        $otherCoa = ChartOfAccount::create(['code' => '998', 'name' => 'Late account']);
        $this->expectException(AuthorizationException::class);
        $item->update(['coa_id' => $otherCoa->id]);
    }

    public function test_export_and_download_are_atomic_and_preserve_workbook_contract(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $batch = app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker, [$slip->invoices()->first()->id => '000099']);
        $this->assertSame('exported', $slip->fresh()->status);
        $this->assertDatabaseCount('erp_export_batches', 1);
        $this->assertDatabaseCount('erp_export_items', 1);
        $this->assertDatabaseHas('payment_slip_audits', ['event' => 'erp_exported']);
        $this->assertSame('2026-09-04', $batch->approval_date_from->toDateString());
        $this->assertSame($batch->approval_date_from->toDateString(), $batch->approval_date_to->toDateString());
        $book = IOFactory::load(Storage::disk('local')->path($batch->file_path));
        $template = IOFactory::load(config('erp-export.template'));
        $this->assertSame(['LedgerJournalTrans', 'Costcenter', 'Sub'], $book->getSheetNames());
        $sheet = $book->getSheet(0);
        $this->assertSame('BG', $sheet->getHighestColumn());
        $this->assertSame($template->getSheet(0)->rangeToArray('A1:BG2', ''), $sheet->rangeToArray('A1:BG2', ''));
        foreach ([1, 2] as $index) {
            $this->assertSame($template->getSheet($index)->toArray(), $book->getSheet($index)->toArray());
        }
        $this->assertSame('000123', $sheet->getCell('D7')->getValue());
        $this->assertSame('000123', $sheet->getCell('I7')->getValue());
        $this->assertSame('000045', $sheet->getCell('AL7')->getValue());
        $this->assertSame('000099', $sheet->getCell('AV7')->getValue());
        $this->assertSame('AP_OTP', $sheet->getCell('T7')->getValue());
        $this->assertSame('i2', $sheet->getCell('U5')->getValue());
        foreach (['D7', 'I7', 'AL7', 'AV7'] as $cell) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType());
        }
        foreach (['B3', 'AN7'] as $cell) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType());
            $this->assertSame('2026-08-31', Date::excelToDateTimeObject($sheet->getCell($cell)->getValue())->format('Y-m-d'));
        }
        $debit = $credit = 0;
        for ($row = 3; $row <= 12; $row++) {
            $this->assertSame(1, $sheet->getCell('A'.$row)->getValue());
            $debit += $sheet->getCell('P'.$row)->getValue() ?? 0;
            $credit += $sheet->getCell('Q'.$row)->getValue() ?? 0;
            $this->assertNull($sheet->getCell('BG'.$row)->getValue());
        }
        $this->assertEqualsWithDelta(666.01, $debit, 0.001);
        $this->assertEqualsWithDelta($debit, $credit, 0.001);
        $this->assertNull($sheet->getCell('O13')->getValue());
        $book->disconnectWorksheets();
        $template->disconnectWorksheets();
        $this->get(route('erp-exports.download', $batch))->assertOk()->assertDownload();
        $this->assertDatabaseCount('erp_export_batches', 1);
        $this->expectException(ValidationException::class);
        app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
    }

    public function test_database_failure_removes_file_and_rolls_back_every_record(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        PaymentSlipAudit::creating(fn () => throw new \RuntimeException('Simulated audit failure'));
        try {
            app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
            $this->fail('Expected export failure');
        } catch (ValidationException) {
            $this->assertSame('approved', $slip->fresh()->status);
            $this->assertDatabaseCount('erp_export_batches', 0);
            $this->assertDatabaseCount('erp_export_items', 0);
            $this->assertDatabaseCount('payment_slip_audits', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        } finally {
            PaymentSlipAudit::flushEventListeners();
        }
    }

    public function test_failed_writer_cleans_up_partial_file(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $this->mock(ErpWorkbookWriter::class)->shouldReceive('write')->once()->andReturnUsing(function ($rows, $path): void {
            file_put_contents($path, 'partial');
            throw new \RuntimeException('Disk failure');
        });
        try {
            app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
            $this->fail('Expected export failure');
        } catch (ValidationException) {
            $this->assertSame('approved', $slip->fresh()->status);
            $this->assertDatabaseCount('erp_export_batches', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_invalid_balance_and_pending_approval_block_export(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        foreach (['pending_approval', 'draft', 'submitted', 'exported'] as $status) {
            $slip->update(['status' => $status, 'verified_at' => now()]);
            try {
                app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
                $this->fail('Expected blocked status');
            } catch (ValidationException) {
                $this->assertDatabaseCount('erp_export_batches', 0);
            }
        }
        $slip->update(['status' => 'approved']);
        $slip->invoices()->first()->updateQuietly(['grand_total_amount' => '1.00']);
        $this->expectException(ValidationException::class);
        app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
    }

    public function test_page_queue_preview_and_history(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $page = Livewire::test(ErpExports::class)->assertSuccessful()->assertCanSeeTableRecords([$slip])
            ->mountAction(TestAction::make('prepare')->table($slip))
            ->assertActionMounted(TestAction::make('prepare')->table($slip));
        $html = $page->getMountedActionModalHtml();
        $this->assertStringContainsString('Journal preview', $html);
        $this->assertStringContainsString('000045', $html);
        $this->assertStringContainsString('666,01', $html);
        app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
        Livewire::test(ErpExports::class)->assertCanNotSeeTableRecords([$slip])->set('activeTab', 'history')->assertCanSeeTableRecords([$slip]);
    }

    public function test_roles_cannot_access_page_export_or_download(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $batch = app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
        foreach (['maker', 'approver'] as $role) {
            $user = User::factory()->create()->assignRole($role);
            $this->actingAs($user)->get('/admin/erp-exports')->assertForbidden();
            $this->get(route('erp-exports.download', $batch))->assertForbidden();
            try {
                app(ExportPaymentSlipToErp::class)->execute($slip, $user);
                $this->fail('Expected forbidden export');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_modal_exports_with_blank_vat_and_persists_it_on_invoice(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $invoice = $slip->invoices()->first();
        Livewire::test(ErpExports::class)
            ->callAction(TestAction::make('prepare')->table($slip), ['vat_numbers' => [$invoice->id => null]])
            ->assertHasNoActionErrors()->assertSet('activeTab', 'history')->assertFileDownloaded('PS-FIXTURE-001-ERP.xlsx');
        $this->assertSame('exported', $slip->fresh()->status);
        $this->assertNull($invoice->fresh()->vat_invoice_number);
        $this->assertDatabaseCount('erp_export_items', 1);
        $this->assertStringNotContainsString('vat_numbers', PaymentSlipAudit::first()->toJson());
    }

    public function test_preview_updates_vat_and_matches_download(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $invoice = $slip->invoices()->first();
        $page = Livewire::test(ErpExports::class)->mountAction(TestAction::make('prepare')->table($slip))
            ->setActionData(['vat_numbers' => [$invoice->id => '000777']]);
        $html = $page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->toHtml();
        $this->assertStringContainsString('000777', $html);
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('000777', $invoice->fresh()->vat_invoice_number);
        $batch = ErpExportBatch::firstOrFail();
        $book = IOFactory::load(Storage::disk('local')->path($batch->file_path));
        $this->assertSame('000777', $book->getSheet(0)->getCell('AV7')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_structural_failures_block_modal_and_no_tax_invoice_is_supported(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->invoices()->update(['tax_addition_amount' => 0, 'tax_deduction_amount' => 0, 'grand_total_amount' => 300]);
        $rows = app(ErpJournalBuilder::class)->build($slip);
        $this->assertCount(6, $rows);
        $slip->invoices()->first()->items()->update(['coa_id' => null, 'coa_code_snapshot' => null, 'coa_name_snapshot' => null]);
        Livewire::test(ErpExports::class)->callAction(TestAction::make('prepare')->table($slip))
            ->assertNotified('Export blocked');
        $this->assertDatabaseCount('erp_export_batches', 0);
        $this->assertSame('approved', $slip->fresh()->status);
    }

    public function test_invalid_amounts_empty_items_and_supplier_are_rejected(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $builder = app(ErpJournalBuilder::class);
        $slip->load('supplier', 'invoices.items');
        $item = $slip->invoices->first()->items->first();
        foreach (['-1.00', 'NaN', '1.001', '10000000000000000'] as $amount) {
            $item->setRawAttributes(array_merge($item->getAttributes(), ['subtotal_amount' => $amount]), true);
            try {
                $builder->build($slip);
                $this->fail('Expected invalid amount');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Invalid money value', $e->getMessage());
            }
        }
        $slip->refresh()->load('supplier', 'invoices.items');
        $slip->invoices->first()->setRelation('items', collect());
        try {
            $builder->build($slip);
            $this->fail('Expected no items');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no expense items', $e->getMessage());
        }
        $slip->supplier->code = '';
        $this->expectException(ValidationException::class);
        $builder->build($slip);
    }

    public function test_ready_excludes_every_other_status_and_cannot_edit_approved_slip(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        foreach (['draft', 'submitted', 'pending_approval', 'exported'] as $status) {
            $slip->update(['status' => $status]);
            Livewire::test(ErpExports::class)->assertCanNotSeeTableRecords([$slip]);
        }
        $slip->update(['status' => 'approved']);
        $this->get(PaymentSlipResource::getUrl('edit', ['record' => $slip]))->assertForbidden();
    }

    public function test_checker_can_save_item_coa_through_verification_form_without_changing_tax(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'submitted']);
        $coa = ChartOfAccount::create(['code' => '222', 'name' => 'Second account']);
        $page = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $data = $page->get('data');
        $invoiceKey = array_key_first($data['invoices']);
        $itemKey = array_key_first($data['invoices'][$invoiceKey]['items']);
        $page->set("data.invoices.{$invoiceKey}.items.{$itemKey}.coa_id", $coa->id)->call('save')->assertHasNoFormErrors();
        $item = $slip->invoices()->first()->items()->first();
        $this->assertSame('222', $item->coa_code_snapshot);
        $this->assertSame('33.01', $item->invoice->tax_addition_amount);
        $this->assertSame('6.01', $item->invoice->tax_deduction_amount);
        $this->assertSame('327.00', $item->invoice->grand_total_amount);
        $this->actingAs(User::factory()->create()->assignRole('maker'));
        $slip->update(['status' => 'draft']);
        $makerPage = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $this->assertStringNotContainsString('Second account', $makerPage->html());
    }

    public function test_checker_with_maker_role_can_save_item_coa(): void
    {
        $this->checker->assignRole('maker');
        $slip = ErpPaymentSlip::create($this->checker);
        $slip->update(['status' => 'submitted']);
        $coa = ChartOfAccount::create(['code' => '333', 'name' => 'Multi-role account']);

        $page = Livewire::test(EditPaymentSlip::class, ['record' => $slip->id]);
        $data = $page->get('data');
        $invoiceKey = array_key_first($data['invoices']);
        $itemKey = array_key_first($data['invoices'][$invoiceKey]['items']);

        $page->set("data.invoices.{$invoiceKey}.items.{$itemKey}.coa_id", $coa->id)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('333', $slip->invoices()->first()->items()->first()->coa_code_snapshot);
    }

    public function test_missing_template_and_missing_download_are_safe(): void
    {
        $slip = ErpPaymentSlip::create($this->checker);
        config(['erp-export.template' => storage_path('app/nonexistent-erp-template.xlsx')]);
        try {
            app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
            $this->fail('Expected invalid template');
        } catch (ValidationException) {
            $this->assertDatabaseCount('erp_export_batches', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
        config(['erp-export.template' => base_path('docs/vmc 5 -expeditor-.xlsx')]);
        $batch = app(ExportPaymentSlipToErp::class)->execute($slip, $this->checker);
        Storage::disk('local')->delete($batch->file_path);
        $this->get(route('erp-exports.download', $batch))->assertNotFound();
        $this->assertDatabaseCount('erp_export_batches', 1);
    }
}
