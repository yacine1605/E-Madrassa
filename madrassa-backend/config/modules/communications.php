<?php
return [
    'label' => 'Communication', 'requires' => ['pedagogy'], 'permissions' => [],
    'resources' => ['announcements'],
    'pages' => ['announcements' => ['resources' => ['announcements'], 'all' => ['pedagogy.read']]],
];
