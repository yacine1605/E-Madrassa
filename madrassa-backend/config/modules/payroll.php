<?php
return [
    'label' => 'Rémunérations', 'requires' => ['hr', 'finance'], 'routes' => ['staff-pay.php'],
    'api_actions' => ['payroll'],
    'permissions' => ['payroll.read', 'payroll.write'], 'resources' => ['payroll_runs'],
    'pages' => [
        'payroll' => ['resources' => ['payroll_runs'], 'all' => ['payroll.read']],
        'teacher-payments' => ['resources' => [], 'all' => ['hr.read', 'payroll.read', 'finance.read']],
    ],
];
