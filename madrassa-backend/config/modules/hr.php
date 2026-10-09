<?php
return [
    'label' => 'Personnel', 'requires' => ['catalog'],
    'permissions' => ['hr.read', 'hr.write'],
    'resources' => ['staff', 'teacher_qualifications', 'teacher_assignments', 'contracts', 'work_logs'],
    'pages' => [
        'teachers' => ['resources' => ['staff', 'teacher_qualifications', 'teacher_assignments', 'contracts', 'work_logs', 'payroll_runs'], 'all' => ['hr.read']],
        'staff' => ['resources' => ['staff', 'contracts'], 'all' => ['hr.read']],
        'hours' => ['resources' => ['work_logs', 'contracts'], 'all' => ['hr.read']],
    ],
];
