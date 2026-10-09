<?php
return [
    'label' => 'Catalogue et organisation', 'requires' => ['core'],
    'permissions' => ['catalog.read', 'catalog.write'],
    'resources' => ['campuses', 'periods', 'programs', 'levels', 'subjects', 'curriculum', 'offerings', 'cohorts', 'rooms'],
    'pages' => [
        'formations' => ['resources' => ['programs', 'levels', 'periods', 'offerings'], 'all' => ['catalog.read']],
        'groups' => ['resources' => ['cohorts', 'teacher_assignments'], 'all' => ['catalog.read']],
        'subjects' => ['resources' => ['subjects', 'curriculum'], 'all' => ['catalog.read']],
        'rooms' => ['resources' => ['campuses', 'rooms'], 'all' => ['catalog.read']],
    ],
];
