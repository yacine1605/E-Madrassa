<?php
return [
    'label' => 'Élèves et inscriptions', 'requires' => ['catalog'],
    'api_actions' => ['enroll', 'importStudents'],
    'permissions' => ['students.read', 'students.write', 'enrollments.read', 'enrollments.write'],
    'resources' => ['students', 'guardians', 'student_guardians', 'enrollments'],
    'pages' => [
        'students' => ['resources' => ['students', 'guardians', 'student_guardians'], 'all' => ['students.read']],
        'enrollments' => ['resources' => ['enrollments', 'invoices'], 'all' => ['enrollments.read']],
    ],
];
