# ERP Journal Export - Milestones

This plan is ordered so that accounting correctness is proven before the download UI is finalized.

## Milestone 0 - Baseline and fixtures

### Work

- Confirm installed versions from `composer.lock`; do not upgrade dependencies.
- Add representative Payment Slip test fixtures with multiple invoices and multiple items.
- Preserve the canonical ERP workbook as the comparison fixture/template.
- Document the status interpretation: only `approved` is ready for export.

### Exit criteria

- Tests can create an approved Payment Slip with supplier, invoice items, COA, PPN, and PPh.
- The canonical workbook can be opened by the installed spreadsheet library.
- The approved migration preserves legacy COA data and adds the Invoice VAT field.

## Milestone 1 - COA per Invoice Item

### Work

- Add COA selection to each Invoice Item in Accounting's verification UI.
- Populate Invoice Item COA snapshots when COA changes.
- Copy legacy Invoice COA into empty Invoice Items, then remove Invoice-level COA.
- Add a reusable Account resolver that reads Invoice Item COA only.

### Tests

- Checker can select an item COA during the allowed status.
- Maker cannot see or alter the item COA.
- Item snapshot persists the selected code/name.
- Legacy invoice-only COA is migrated to its items.
- Missing item COA produces a named validation error.

### Exit criteria

- Different items in one invoice can resolve to different expense accounts.
- Existing records retain their COA at Invoice Item level after migration.

## Milestone 2 - Journal builder and validator

### Work

- Add `config/erp-export.php` for fixed accounts, dimensions, currency, posting profile, tax code, and Cost Centers.
- Build immutable/typed journal rows from one Payment Slip.
- Produce one expense row per Invoice Item.
- Produce conditional PPN and PPh rows from stored values.
- Produce one supplier row per invoice.
- Accept optional VAT Invoice Numbers keyed by invoice ID.
- Add per-invoice and whole-file balance validation.

### Tests

- Export transaction uses `I05_902020`; import uses `I05_190000`.
- Supplier code maps to Account and FD_5.
- PPN maps to debit account `11990501` and code `i2`.
- PPh maps to credit account `21020401`.
- Supplier credit equals stored net payable.
- Tax is not recalculated or rounded by the builder.
- Duplicate COAs remain separate item rows.
- Blank VAT Invoice Number is accepted.
- Supplied VAT Invoice Number retains leading zeroes.
- Missing Account and imbalance block output.

### Exit criteria

- The journal builder is independent of Filament and workbook-writing code.
- Every tested journal balances without corrective mutations.

## Milestone 3 - Workbook writer

### Work

- Copy/open the canonical workbook template.
- Clear only the template's example transaction rows, preserving sheets and headers.
- Populate `LedgerJournalTrans` from row 3 through column BG.
- Preserve cell types, date formats, sheet names/order, and supporting sheets.
- Store generated files privately.
- Use a sanitized `{slip_number}-ERP.xlsx` filename.

### Tests

- Workbook contains exactly the expected sheet names and order.
- Header rows match the canonical template cell by cell.
- Output has 59 columns in A:BG order.
- Dates are typed dates; amounts are numeric; identifiers are text.
- Blank optional columns remain present.
- Workbook reopens successfully after export.
- Representative rows equal expected data and totals.

### Exit criteria

- A generated file can be opened in Excel and visually matches the template.
- No formula, reference, or corrupt-workbook warning appears.

## Milestone 4 - ERP Export page and preview

### Work

- Add the auto-discovered Filament v5 `ERP Export` custom page.
- Restrict access to Checker.
- Add Ready and Export History tabs.
- Add search, useful columns, and default sorting.
- Add the `Prepare file` row action.
- Add optional persisted VAT Invoice Number fields per invoice.
- Add compact journal preview and balance summary.
- Add clear blocking error states and loading/double-submit protection.

### Tests

- Unauthorized roles cannot access the page or invoke export.
- Ready list includes approved, unexported slips only.
- Pending approval, draft, submitted, and exported slips do not appear in Ready.
- Row action operates on exactly one slip.
- Preview matches the journal builder output.
- Optional blank inputs do not create warnings.
- Structural errors prevent the export action.

### Exit criteria

- Accounting can understand what will be exported without opening the XLSX first.
- The workflow requires no bulk selection and no database-backed draft form.

## Milestone 5 - Export transaction, audit, and history

### Work

- Coordinate workbook generation, private file storage, export records, audit, and status transition.
- Create exactly one batch and one item for the selected Payment Slip.
- Move successful slips to `exported`.
- Support authorized re-download from Export History.
- Handle concurrent duplicate attempts using the existing unique constraint.
- Clean up incomplete files when a database operation fails.

### Tests

- Successful export creates the existing records and audit event.
- Successful export changes only the intended Payment Slip status.
- Failed generation leaves status/history unchanged.
- Concurrent/duplicate first export is rejected cleanly.
- Re-download does not create a second export record.

### Exit criteria

- Export state is consistent even after a simulated failure.
- History and re-download work without a new table or column.

## Milestone 6 - End-to-end verification

### Work

- Compare generated output against the validated sample workbook.
- Verify both Import and Export Cost Centers.
- Test representative multi-invoice Payment Slips.
- Review UI at desktop and tablet widths in light and dark mode.
- Run formatter and complete test suite.

### Required commands

```text
vendor/bin/pint --dirty
php artisan test
```

### Exit criteria

- All acceptance criteria in `docs/erp-export/README.md` pass.
- No database migration or dependency version change is present.
- Generated XLSX passes structural and visual review.
- No unresolved blocker remains for the defined version-1 scope.

## Suggested delivery slices

For easier review, commits may follow these slices:

1. Item COA compatibility and tests.
2. Journal builder/validator and tests.
3. Workbook writer and template verification.
4. ERP Export page, optional form, and preview.
5. Export persistence, audit, history, and final QA.

Do not mix unrelated cleanup or package upgrades into these changes.
