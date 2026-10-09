<?php
return [
    'label' => 'Pédagogie', 'requires' => ['students', 'hr'],
    'api_actions' => ['attendance', 'grade'],
    'permissions' => ['pedagogy.read', 'pedagogy.write'],
    'resources' => ['sessions', 'attendance', 'grades', 'assessments', 'lesson_versions'],
    'pages' => [
        'schedule' => ['resources' => ['sessions'], 'all' => ['pedagogy.read']],
        'attendance' => ['resources' => ['attendance'], 'all' => ['pedagogy.read']],
        'grades' => ['resources' => ['grades'], 'all' => ['pedagogy.read']],
        'assessments' => ['resources' => ['assessments'], 'all' => ['pedagogy.read']],
        'lessons' => ['resources' => ['lesson_versions'], 'all' => ['pedagogy.read']],
    ],
];
