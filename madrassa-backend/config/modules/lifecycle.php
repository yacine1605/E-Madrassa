<?php
return [
    'label' => 'Admissions et historique', 'requires' => ['students'], 'routes' => ['lifecycle.php'],
    'permissions' => [], 'resources' => ['admissions'],
    'pages' => [
        'admissions' => ['resources' => [], 'all' => ['students.read', 'enrollments.read', 'catalog.read']],
    ],
];
