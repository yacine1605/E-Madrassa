<?php
return [
    'label' => 'Finances', 'requires' => ['students'],
    'api_actions' => ['summary', 'payment', 'refund', 'disbursement'],
    'permissions' => ['finance.read', 'finance.write'],
    'resources' => ['invoices', 'invoice_balances', 'payments', 'refunds', 'payment_reversals', 'expenses', 'disbursements'],
    'pages' => [
        'payments' => ['resources' => ['payments', 'refunds', 'payment_reversals', 'invoice_balances'], 'all' => ['finance.read']],
        'expenses' => ['resources' => ['expenses', 'disbursements'], 'all' => ['finance.read']],
        'finance' => ['resources' => ['invoice_balances', 'invoices', 'payments', 'refunds', 'expenses', 'disbursements'], 'all' => ['finance.read']],
        'analytics' => ['resources' => ['invoice_balances'], 'all' => ['finance.read']],
    ],
];
