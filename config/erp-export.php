<?php

return [
    'company_dimension' => 'I0500000',
    'currency' => 'IDR',
    'posting_profile' => 'AP_OTP',
    'sales_tax_code' => 'i2',
    'accounts' => ['ppn' => '11990501', 'pph_23' => '21020401'],
    'cost_centers' => [
        'import' => 'I05_190000',
        'export' => 'I05_902020',
        'general' => env('ERP_GENERAL_COST_CENTER', 'I05_190000'),
    ],
    'cost_center_names' => [
        'import' => 'M_PTHJ Common [PTHJ공통]',
        'export' => 'Export/Import [수출입팀]',
        'general' => 'M_PTHJ Common [PTHJ공통]',
    ],
    'template' => base_path('docs/vmc 5 -expeditor-.xlsx'),
];
