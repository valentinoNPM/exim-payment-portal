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
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }
        th, td {
            border: 1px solid #00a8e8;
            padding: 5px 8px;
            text-align: left;
            vertical-align: top;
        }
        .header-table td {
            padding: 10px;
        }
        .logo-box {
            width: 50%;
            font-size: 16px;
            font-weight: bold;
            color: #00a8e8;
            line-height: 1.2;
        }
        .logo-box .sub {
            font-size: 10px;
            font-style: italic;
            font-weight: normal;
            color: #777;
        }
        .logo-box .company {
            font-size: 8px;
            font-weight: bold;
            color: #000;
            margin-top: 3px;
        }
        .title-box {
            width: 50%;
            font-size: 13px;
            font-weight: bold;
            color: #00a8e8;
            text-align: center;
            vertical-align: middle;
        }
        .label-cell {
            font-weight: bold;
            color: #00a8e8;
            background-color: #f2faff;
            width: 15%;
            text-transform: uppercase;
        }
        .value-cell {
            width: 18%;
        }
        .customer-title {
            font-weight: bold;
            color: #00a8e8;
            background-color: #f2faff;
            width: 25%;
            text-transform: uppercase;
        }
        .description-title {
            font-weight: bold;
            color: #00a8e8;
            background-color: #f2faff;
            width: 25%;
            text-transform: uppercase;
            vertical-align: middle;
            text-align: center;
        }
        .ac-box {
            width: 30%;
            border-left: 1px dotted #00a8e8;
        }
        .ac-title {
            font-weight: bold;
            color: #00a8e8;
            margin-bottom: 5px;
        }
        .dotted-line {
            border-bottom: 1px dotted #00a8e8;
            margin-bottom: 5px;
            height: 10px;
        }
        .amount-label {
            font-weight: bold;
            color: #00a8e8;
            background-color: #f2faff;
            width: 15%;
            text-transform: uppercase;
        }
        .amount-val {
            font-weight: bold;
            color: #00a8e8;
            width: 35%;
        }
        .meta-label {
            font-weight: bold;
            color: #00a8e8;
            background-color: #f2faff;
            width: 18%;
            text-transform: uppercase;
        }
        .meta-val {
            width: 15%;
        }
        .approval-table th {
            background-color: #f2faff;
            color: #00a8e8;
            font-weight: bold;
            text-align: center;
            font-size: 8px;
            width: 20%;
        }
        .approval-table td {
            height: 25px;
            text-align: center;
            vertical-align: middle;
        }
        .approval-label-column {
            background-color: #f2faff;
            font-weight: bold;
            color: #00a8e8;
            text-align: center;
            vertical-align: middle;
            width: 20%;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Logo & Title Header -->
        <table class="header-table">
            <tr>
                <td class="logo-box">
                    <img src="{{ public_path('images/logo.svg') }}" style="height: 30px; width: auto; margin-bottom: 5px;" /><br/>
                    <span class="company">PT. HANSOLL INDO JAVA</span>
                </td>
                <td class="title-box">
                    APPLICATION FOR PAYMENT/INCOME
                </td>
            </tr>
        </table>

        <!-- Slip Number Row -->
        <table>
            <tr>
                <td class="label-cell" style="width: 15%;">SLIP NO.</td>
                <td style="width: 85%; font-weight: bold;">{{ $slip->slip_number }}</td>
            </tr>
        </table>

        <!-- Division, Name, Date Row -->
        <table>
            <tr>
                <td class="label-cell">DIVISION</td>
                <td class="value-cell" style="width: 25%;">{{ strtoupper($slip->transaction_type) }}</td>
                <td class="label-cell">NAME</td>
                <td class="value-cell" style="width: 25%;">{{ $slip->creator?->name ?? 'Sugiyanto' }}</td>
                <td class="label-cell">DATE</td>
                <td class="value-cell" style="width: 15%;">{{ $slip->created_at->format('Y-m-d') }}</td>
            </tr>
        </table>

        <!-- Main Invoice details table -->
        <table style="margin-bottom: 8px;">
            <tr>
                <!-- Left Side: Customer & Description -->
                <td style="width: 85%; padding: 0; border: 1px solid #00a8e8; border-right: none;">
                    <table style="margin: 0; width: 100%; border: none;">
                        <tr>
                            <td class="customer-title" style="border-top: none; border-left: none;">CUSTOMER</td>
                            <td style="border-top: none; border-right: none; font-weight: bold;">
                                {{ $slip->supplier?->name }}
                            </td>
                        </tr>
                        <tr>
                            <td class="description-title" style="height: 140px; border-left: none; border-bottom: none;">
                                DESCRIPTION<br/>OF. GOODS
                            </td>
                            <td style="border-right: none; border-bottom: none; line-height: 1.4;">
                                <strong>CHARGE {{ strtoupper($slip->transaction_type) }}</strong><br/>
                                @if(in_array($slip->status, ['verified', 'approved']))
                                    <table style="width: 100%; border: none; margin-top: 5px;">
                                        <tr style="border-bottom: 1px dashed #00a8e8;">
                                            <th style="border: none; padding: 2px 0;">No</th>
                                            <th style="border: none; padding: 2px 0;">REF</th>
                                            <th style="border: none; padding: 2px 0; text-align: right;">Amount</th>
                                            <th style="border: none; padding: 2px 0; text-align: right;">PPH 23</th>
                                            <th style="border: none; padding: 2px 0; text-align: right;">Amount Dibayar</th>
                                        </tr>
                                        @foreach($slip->invoices as $idx => $invoice)
                                        @php
                                            $taxValue = 0;
                                            if ($invoice->tax_addition_amount > 0) {
                                                $taxValue = $invoice->tax_addition_amount;
                                            } elseif ($invoice->tax_deduction_amount > 0) {
                                                $taxValue = $invoice->tax_deduction_amount;
                                            }
                                        @endphp
                                        <tr>
                                            <td style="border: none; padding: 2px 0;">{{ $idx + 1 }}.</td>
                                            <td style="border: none; padding: 2px 0;">{{ $invoice->invoice_number }}</td>
                                            <td style="border: none; padding: 2px 0; text-align: right;">{{ number_format($invoice->subtotal_amount, 0, ',', '.') }}</td>
                                            <td style="border: none; padding: 2px 0; text-align: right;">{{ $taxValue > 0 ? number_format($taxValue, 0, ',', '.') : '-' }}</td>
                                            <td style="border: none; padding: 2px 0; text-align: right;">{{ number_format($invoice->grand_total_amount, 0, ',', '.') }}</td>
                                        </tr>
                                        @endforeach
                                    </table>
                                @else
                                    @foreach($slip->invoices as $idx => $invoice)
                                        {{ $idx + 1 }}. REF {{ $invoice->invoice_number }} = Rp. {{ number_format($invoice->subtotal_amount, 0, ',', '.') }}<br/>
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    </table>
                </td>
                <!-- Right Side: A/C No Section -->
                <td class="ac-box" style="width: 15%; height: 180px;">
                    <div class="ac-title">A/C NO.</div>
                    <div class="dotted-line"></div>
                    <div class="dotted-line"></div>
                    <div class="dotted-line"></div>
                    <div class="dotted-line"></div>
                    <div class="dotted-line"></div>
                    <div class="dotted-line"></div>
                </td>
            </tr>
        </table>

        <!-- Amounts Row -->
        <table>
            <tr>
                <td class="amount-label" style="width: 15%;">AMOUNT</td>
                <td class="amount-val" style="width: 25%;">Rp. : {{ number_format($slip->subtotal_amount, 0, ',', '.') }}</td>
                <td class="amount-label" style="width: 25%; font-size: 9px;">AMOUNT DIBAYAR</td>
                <td class="amount-val" style="width: 35%;">Rp. : {{ number_format($slip->grand_total_amount, 0, ',', '.') }}</td>
            </tr>
        </table>

        <!-- Due Date, Pay Type, Bank, etc -->
        <table>
            <tr>
                <td class="meta-label">DUE DATE</td>
                <td class="meta-val"></td>
                <td class="meta-label">PAY TYPE</td>
                <td class="meta-val"></td>
                <td class="meta-label">BANK</td>
                <td class="meta-val"></td>
            </tr>
            <tr>
                <td class="meta-label">PAY DATE</td>
                <td class="meta-val"></td>
                <td class="meta-label">CHECK NO.</td>
                <td class="meta-val"></td>
                <td class="meta-label">SIGN DATE</td>
                <td class="meta-val"></td>
            </tr>
        </table>

        <!-- Approvals Signature Box -->
        <table class="approval-table" style="margin-top: 15px;">
            <tr>
                <td rowspan="2" class="approval-label-column" style="width: 20%;">NAME</td>
                <th style="width: 20%;">PROPOSAL</th>
                <th style="width: 20%;">I. CHARGE</th>
                <th style="width: 20%;">MANAGER</th>
                <th style="width: 20%;">DIRECTOR</th>
            </tr>
            <tr>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td rowspan="2" class="approval-label-column">SIGN</td>
                <th style="background-color: #f2faff; color: #00a8e8; font-weight: bold; font-size: 8px;">ACCOUNTING</th>
                <td></td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
            </tr>
        </table>
    </div>
</body>
</html>
