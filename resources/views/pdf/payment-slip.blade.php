<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payment Slip - {{ $slip->slip_number }}</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #333;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 100%;
            padding: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        th, td {
            border: 1px solid #5b2d8e;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }

        /* Header */
        .header-table td {
            border: none;
            padding: 5px 8px;
        }
        .logo-box {
            width: 100%;
            vertical-align: top;
        }
        .company-name {
            font-size: 28px;
            font-weight: bold;
            color: #333;
        }
        .company-address {
            font-size: 10px;
            color: #555;
            line-height: 1.3;
        }
        .document-title {
            font-size: 20px;
            font-weight: bold;
            color: #5b2d8e;
            text-align: center;
            margin: 10px 0;
            letter-spacing: 0.5px;
        }

        /* Approval Grid */
        .approval-grid {
            margin-top: 10px;
        }
        .approval-grid th {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-size: 8px;
            font-weight: bold;
            text-align: center;
            padding: 3px 4px;
        }
        .approval-grid td {
            text-align: center;
            height: 22px;
            vertical-align: middle;
            font-size: 8px;
        }
        .approval-grid .label-cell {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-weight: bold;
            width: 15%;
        }

        /* Data Section */
        .data-table td {
            border: none;
            padding: 2px 6px;
            font-size: 10px;
        }
        .data-label {
            font-weight: bold;
            width: 18%;
            color: #333;
        }
        .data-separator {
            width: 2%;
            text-align: center;
        }
        .data-value {
            width: 30%;
        }

        /* Detail Table */
        .detail-table {
            margin-top: 8px;
        }
        .detail-table th {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-weight: bold;
            font-size: 9px;
            text-align: center;
            padding: 5px 4px;
        }
        .detail-table td {
            padding: 4px 6px;
            font-size: 9px;
        }
        .detail-table .amount-cell {
            text-align: right;
            white-space: nowrap;
        }
        .summary-label {
            text-align: right;
            font-weight: bold;
            color: #5b2d8e;
            padding-right: 10px;
        }
        .summary-value {
            text-align: right;
            font-weight: bold;
        }

        /* Payment Box */
        .payment-table {
            margin-top: 10px;
        }
        .payment-table th {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-weight: bold;
            font-size: 8px;
            text-align: center;
            padding: 4px;
        }
        .payment-table td {
            height: 30px;
            text-align: center;
            vertical-align: middle;
            font-size: 8px;
        }
        .payment-label {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
        }

        /* Footer Box */
        .footer-table {
            margin-top: 8px;
        }
        .footer-table th {
            background-color: #f3eef9;
            color: #5b2d8e;
            font-weight: bold;
            font-size: 9px;
            text-align: center;
            padding: 5px;
        }
        .footer-table td {
            height: 25px;
            text-align: center;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header: Company Info -->
        <table class="header-table">
            <tr>
                <td class="logo-box">
                    @if(file_exists(public_path('images/logo.svg')))
                        <img src="{{ public_path('images/logo.svg') }}" style="height: 14px; width: auto; margin-bottom: 3px;" /><br/>
                    @endif
                    <span class="company-name">PT. HANSOLL INDO JAVA</span><br/>
                    <span class="company-address">
                        Dukuh Ngemplak, RT.006/RW.002, Dusun III, Randusari<br/>
                        Randusari, Kec. Teras, Kabupaten Boyolali, Jawa Tengah 57372, Jawa Tengah - Indonesia<br/>
                        Telp.: (0276) 3280401
                    </span>
                </td>
            </tr>
        </table>

        <hr style="border: none; border-top: 1.5px solid #5b2d8e; margin: 5px 0 10px 0;" />

        <!-- Title: Payment Slip -->
        <div class="document-title">
            PAYMENT SLIP
        </div>

        <!-- Data Section -->
        @php
            $invoiceNumbers = $slip->invoices->pluck('invoice_number')->filter()->implode(', ');
            $department = $slip->creator?->division?->name ?? strtoupper($slip->transaction_type);
        @endphp
        <table style="width: 100%; border: none; margin-bottom: 8px;">
            <tr>
                <td style="width: 60%; vertical-align: top; border: none; padding: 0;">
                    <table class="data-table" style="width: 100%;">
                        <tr>
                            <td class="data-label" style="width: 32%;">Payment Slip Number</td>
                            <td class="data-separator" style="width: 4%;">:</td>
                            <td class="data-value" style="width: 64%;">{{ $slip->slip_number }}</td>
                        </tr>
                        <tr>
                            <td class="data-label">Date</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">{{ $slip->created_at->format('Y-m-d') }}</td>
                        </tr>
                        <tr>
                            <td class="data-label">Department</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">{{ $department }}</td>
                        </tr>
                        <tr>
                            <td class="data-label">Name</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">{{ $slip->creator?->name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="data-label">Supplier</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">{{ $slip->supplier?->name ?? '-' }}</td>
                        </tr>
                        <tr>
                            <td class="data-label">Account Name</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">
                                @php
                                    $coaNames = $slip->invoices->map(fn($inv) => $inv->coa_name_snapshot)->filter()->unique()->implode(', ');
                                @endphp
                                {{ $coaNames ?: '-' }}
                            </td>
                        </tr>
                        <tr>
                            <td class="data-label">Transaction</td>
                            <td class="data-separator">:</td>
                            <td class="data-value">CHARGE {{ strtoupper($slip->transaction_type) }}</td>
                        </tr>
                    </table>
                </td>
                <td style="width: 40%; vertical-align: top; border: none; padding: 0;">
                    <table class="data-table" style="width: 100%;">
                        <tr>
                            <td class="data-label" style="width: 25%;">No. Invoice</td>
                            <td class="data-separator" style="width: 5%;">:</td>
                            <td class="data-value" style="width: 70%;">{{ $invoiceNumbers }}</td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>

        <!-- Detail Table -->
        <table class="detail-table">
            <thead>
                <tr>
                    <th style="width: 45%;">Detail</th>
                    <th style="width: 14%;">Amount</th>
                    <th style="width: 14%;">PPN</th>
                    <th style="width: 14%;">PPH</th>
                    <th style="width: 13%;">Amount Dibayar</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $totalSubtotal = 0;
                    $totalPpn = 0;
                    $totalPph = 0;
                    $totalGrand = 0;
                @endphp
                @foreach($slip->invoices as $idx => $invoice)
                @php
                    $invSubtotal = (float) $invoice->subtotal_amount;
                    $invPpn = (float) $invoice->tax_addition_amount;
                    $invPph = (float) $invoice->tax_deduction_amount;
                    $invGrand = (float) $invoice->grand_total_amount;
                    $totalSubtotal += $invSubtotal;
                    $totalPpn += $invPpn;
                    $totalPph += $invPph;
                    $totalGrand += $invGrand;

                    // Build description: REF [invoice_number] - [item names]
                    $itemNames = $invoice->items->pluck('item_name')->implode(', ');
                    $description = "REF {$invoice->invoice_number}";
                    if ($itemNames) {
                        $description .= " - {$itemNames}";
                    }
                @endphp
                <tr>
                    <td>{{ $idx + 1 }}. {{ $description }}</td>
                    <td class="amount-cell">Rp {{ number_format($invSubtotal, 0, ',', '.') }}</td>
                    <td class="amount-cell">{{ $invPpn > 0 ? 'Rp ' . number_format($invPpn, 0, ',', '.') : '-' }}</td>
                    <td class="amount-cell">{{ $invPph > 0 ? 'Rp ' . number_format($invPph, 0, ',', '.') : '-' }}</td>
                    <td class="amount-cell">Rp {{ number_format($invGrand, 0, ',', '.') }}</td>
                </tr>
                @endforeach

                <!-- Empty spacer rows if few invoices -->
                @for($i = count($slip->invoices); $i < 3; $i++)
                <tr>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                    <td>&nbsp;</td>
                </tr>
                @endfor
            </tbody>

            <!-- Summary Rows -->
            <tfoot>
                <tr>
                    <td class="summary-label" colspan="4">SUB TOTAL</td>
                    <td class="summary-value">Rp {{ number_format($totalSubtotal, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="summary-label" colspan="4">
                        PPN
                        @php
                            $ppnLabel = $slip->invoices->first()?->ppnTax?->name;
                        @endphp
                        {{ $ppnLabel ? "({$ppnLabel})" : '(0%)' }}
                    </td>
                    <td class="summary-value">Rp {{ number_format($totalPpn, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="summary-label" colspan="4">
                        PPH
                        @php
                            $pphLabel = $slip->invoices->first()?->pphTax?->name;
                        @endphp
                        {{ $pphLabel ? "({$pphLabel})" : '(0%)' }}
                    </td>
                    <td class="summary-value">Rp {{ number_format($totalPph, 0, ',', '.') }}</td>
                </tr>
                <tr>
                    <td class="summary-label" colspan="4" style="font-size: 11px;">GRAND TOTAL</td>
                    <td class="summary-value" style="font-size: 11px;">Rp {{ number_format($totalGrand, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        </table>

        <!-- Approval Grid -->
        <table class="approval-grid">
            <tr>
                <td rowspan="2" class="label-cell">APPROVAL</td>
                <th>INCHARGE</th>
                <th>PASSED</th>
                <th>PASSED</th>
                <th>PASSED</th>
                <th>DIRECTOR</th>
            </tr>
            <tr>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
            </tr>
        </table>

        <!-- Payment Signature Box -->
        <table class="payment-table">
            <tr>
                <td rowspan="2" class="payment-label" style="width: 15%;">PAYMENT</td>
                <th style="width: 21%;">INCHARGE</th>
                <th style="width: 21%;">ACC MANAGER</th>
                <th style="width: 22%;">GENERAL MANAGER</th>
                <th style="width: 21%;">PASSED</th>
            </tr>
            <tr>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
            </tr>
        </table>

        <!-- Footer: Tanggal Bayar & Received/Confirmed -->
        <table class="footer-table">
            <tr>
                <th style="width: 50%;">Tanggal Bayar</th>
                <th style="width: 50%;">Received / Confirmed</th>
            </tr>
            <tr>
                <td>&nbsp;</td>
                <td>&nbsp;</td>
            </tr>
        </table>
    </div>
</body>
</html>
