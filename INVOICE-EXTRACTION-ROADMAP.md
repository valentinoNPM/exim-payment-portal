# Invoice extraction: implementation plan and roadmap

Updated: 12 September 2026.

## Architecture decision and scope

Decision confirmed on 12 September: focus on PHP/Laravel with the existing Gemini
integration. Skip invoice2data evaluation and do not introduce Python extraction
libraries or a Python service in this roadmap. This is a stack/scope decision,
not a benchmark claim that our implementation is more accurate than those libraries.
M4 evaluates our current pipeline across suppliers, not competing Python engines.
Reusing suitable PHP libraries remains possible; this does not require building
a PDF engine from scratch or removing Gemini.

The primary path is general Gemini extraction plus shared PHP validation, not
mandatory templates per supplier and not a replacement of Gemini with a generic
deterministic parser. New suppliers use the same path without registering a template.
Supplier/layout groups are evaluation dimensions, not required routing rules.

Pipeline: PDF -> PHP text extraction with page markers -> Gemini structured result
-> normalization -> evidence/arithmetic validation -> accounting form and review.
When text is unusable, send the PDF to Gemini instead; text-only evidence checks
cannot verify scanned content. Missing evidence must remain visible for review.

Keep printed document amounts separate from application payment-tax calculations.
Do not infer a missing amount as zero or multiply a line total by source quantity.
Existing Expeditors reconciliation remains a compatibility exception, not the
blueprint for one parser per supplier. Test it alongside the general path.

Specialized layout rules/parsers are optional future work only after measured,
recurring errors justify their maintenance cost. They must use the same output
contract and validators, reject uncertain layout matches, and retain the general
path for unsupported documents. No automatic rule learning or fine-tuning is planned.

## Milestone status

| Milestone | Scope | Status |
| --- | --- | --- |
| M1 | Private production baseline across 8 suppliers | Captured; labels provisional |
| M2 | Remove MarkItDown; PHP text extraction with Gemini PDF fallback | Implemented locally; not deployed |
| M3 | Shared result contract, source evidence and advisory validation | Implemented locally; not real-document validated or deployed |
| M4 | Cross-supplier evaluation and source-reviewed expected results | Next; not started |
| M5 | General extraction improvements from M4 findings, including scans | Planned; scope depends on M4 |
| M6 | Persistent review/audit workflow and release acceptance | Planned; workflow decisions require confirmation |
| M7 | Controlled production rollout and ongoing evaluation | Planned; deployment requires authorization |

Local automated verification after M3: 47 tests passed, 248 assertions. This does
not establish real-document extraction accuracy. No existing invoices were rewritten.

## M1: production baseline (11 September 2026)

Read-only snapshot: 15 payment slips, 49 invoices, 38 distinct source PDF checksums,
8 suppliers. Status distribution: 11 submitted, 2 exported, 2 draft.
All associated source files existed at capture time. Line item sums agree with
stored invoice subtotals within IDR 1 for every invoice. This is an internal
consistency check, not proof of agreement with every printed document.

The user accepts existing records as provisional expected results regardless of
status. Preserve status and timestamps; do not silently promote them to verified
labels. Draft data can subsequently change. No fine-tuning or Gemini call is
needed to build this baseline.

| Supplier | Slips | Invoices | Unique PDFs | Text available |
| --- | ---: | ---: | ---: | --- |
| Expeditors Indonesia | 6 | 21 | 20 | Yes |
| Schenker Logistics Indonesia | 3 | 11 | 11 | Yes |
| Hellmann Worldwide Logistics Indonesia | 1 | 1 | 1 | Yes |
| Seven Seas Logistics | 1 | 1 | 1 | Yes |
| FNS Transbuana | 1 | 4 | 1 | No |
| CHRobinson Global Forwarding Indonesia | 1 | 2 | 2 | Yes |
| Bahana Samudera Transportindo | 1 | 1 | 1 | Yes |
| Jamkrida Jakarta | 1 | 8 | 1 | No |

Private snapshot: `storage/app/private/parser-baseline/2026-09-11.json`.
Private PDF copies: `storage/app/private/parser-baseline/pdfs/`.
These paths are ignored by Git: never publish production fixtures in the public
repository. The read-only capture script is `scripts/invoice-baseline-read.php`;
execute from the application root. It reads only related records and PDFs, and
outputs JSON to stdout. It does not contact an AI service or write production data.

Group by supplier first, then manually classify layout versions within each group.
A supplier is not automatically a single template. Group all invoices and repeats
of the same PDF checksum together for any development/holdout split. If the same
PDF has conflicting user labels, quarantine the conflict for review. Suppliers
with only one PDF have no independent holdout yet.

## M2: PHP text extraction only

Remove MarkItDown invocation, Process dependency and obsolete example/config keys.
Use Smalot PdfParser directly. Usable text goes to Gemini; empty, insufficient,
unrecognized or failed text extraction falls back to Gemini PDF input. Existing
HTTP retries, partial-success reporting, line-total mapping and Expeditors number
reconciliation remain. Old MARKITDOWN environment variables become inert; do not
uninstall shared Python environments as part of this change.

Local unit tests cover text routing, empty text and parser exceptions, invalid AI
results, PDF fallback, retry and number/amount regressions. Production rollout is
a separate step after local review. No old invoices are rewritten.

## M3: shared extraction contract and advisory validation

The normalized array contract now carries schema_version=3, line_total,
source_quantity, unit_price, currency, printed_subtotal, printed_tax,
printed_amount_due, field evidence (page/quote), warnings and review_required.
The legacy original_price field is retained as a compatibility alias for line_total.
PdfParser text now includes explicit page markers. Evidence is checked against
the specified page and the extracted field value; quote verification is not proof
that the correct semantic label or invoice was selected. Scanned evidence remains
unverified and requires manual review. IDR reconciliation tolerance is 1 rupiah;
other/unknown currencies always require review and are not automatically converted.

The form displays a read-only extraction review summary and warning notifications.
This summary is transient and not persisted in the invoice database (no migration).
Users can still save after reviewing; this is an advisory validation stage, not
a submission gate. Persisting audit evidence and explicit review acknowledgement
is a future workflow change. Existing invoices are unaffected.

No real-document Gemini evaluation has been run for this new prompt. Next evaluate
all supplier groups against the private baseline before production rollout.

Implementation uses a versioned normalized array, not dedicated DTO classes.
Relevant files: `app/Services/GeminiInvoiceExtractor.php`,
`app/Services/InvoiceExtractionValidator.php`,
`app/Services/InvoiceExtractionDataMapper.php`, and
`app/Filament/Resources/PaymentSlips/Schemas/PaymentSlipForm.php`.

## M4: evaluate all supplier groups before choosing further parsers

Implementation plan:

1. Build a read-only local evaluation command against the private M1 snapshot.
   Freeze input checksums, model identifier, prompt/schema version and run ID.
   Do not save extraction results into operational payment-slip records.
2. Review expected values against source PDFs, especially disagreements and
   multi-invoice files. Keep provisional and source-verified labels distinct.
   Printed tax/amount due may need new labels; stored accounting totals are not
   automatically equivalent to printed document totals.
3. Run an explicitly authorized Gemini evaluation across all 38 PDFs / 8 suppliers,
   including the 2 textless PDFs. These calls send production documents/text to
   an external service and incur usage; do not run them implicitly while building
   the offline evaluator. Keep fixtures, responses and reports private.
4. Match expected/extracted invoices without relying solely on invoice number,
   since that number is itself under test. Report missing/extra invoices and
   ambiguous matches separately, especially for multi-invoice PDFs.
5. Report number/date/currency exact matches, line/subtotal/printed-total errors,
   evidence quality, warnings that miss actual errors, extraction failures,
   latency, retries and PDF-fallback rate. Separate per-field denominators and
   unverified labels. Report each supplier/layout, plus overall results.

Exit criteria: reproducible private report covering every baseline PDF, a
source-reviewed discrepancy list, and an evidence-based M5 backlog. A small or
single-document supplier group is not proof of generalization. Do not call a
development fixture a holdout; reserve new independent documents where necessary.

## M5: improve the general path based on measured failures

Implementation plan:

1. Prioritize observed errors by severity and frequency, not supplier preference.
2. Improve shared prompt, field normalization, label/context checks and validation.
   Preserve invoice-to-page association for PDFs containing multiple invoices.
3. Address textless FNS/Jamkrida documents through the existing PDF-input path;
   evaluate OCR only if evidence quality, accuracy or cost warrants added tooling.
4. Turn each confirmed failure into a private regression case and rerun all groups.
5. Consider a specialized layout rule only if a recurring failure remains and
   comparison demonstrates benefit without regressions. Document matching rules,
   fallback behavior, coverage and maintenance cost before implementation.

Exit criteria: measured improvement on the M4 failures, no unexplained regression
on the reviewed baseline, and explicit unresolved cases routed to human review.
Any optional vendor/layout parser is a separate decision, not an M5 prerequisite.

## M6: review workflow, auditability and release acceptance

Proposed implementation, subject to confirmation of workflow and retention policy:

1. Persist extraction provenance, versions, field evidence, warnings and user
   corrections with appropriate authorization and private storage/retention.
2. Define who acknowledges review and which conditions block submission versus
   merely warn. M3 currently allows saving; do not silently change that behavior.
3. Agree acceptance thresholds after M4 establishes baseline performance. Invoice
   number and amount errors must be assessed separately from overall accuracy;
   unknown/foreign currency must not silently be treated as IDR.
4. Verify existing accounting calculations, permissions, partial upload failures,
   retries and multi-invoice mapping. Prepare migration and rollback procedures.

Exit criteria: approved review policy and acceptance criteria, passing regression
and workflow tests, and reviewed deployment steps. Creating migrations is local
work; applying them to production data requires authorization.

## M7: controlled rollout and continuous evaluation

Deploy only after release acceptance and explicit authorization. Start with a
limited, manually reviewed production cohort; compare against the previous flow
and monitor extraction failures, corrections, amount/number errors, latency and
usage. Define rollback triggers before deployment; do not re-extract or overwrite
historical invoices automatically.

Add corrected examples as versioned, provisional labels and verify them against
source documents before promoting them to evaluation truth. Expand supplier/layout
coverage over time without requiring a template for each new supplier. Reevaluate
model/prompt/rule changes on the same baseline plus independent new examples.
