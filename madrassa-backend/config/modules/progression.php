<?php
return [
    'label' => 'Passage annuel', 'requires' => ['lifecycle', 'pedagogy'],
    'permissions' => [], 'resources' => ['annual_progressions'],
    'pages' => ['annual-progression' => ['resources' => [], 'all' => ['students.read', 'enrollments.read', 'catalog.read', 'pedagogy.read']]],
];
