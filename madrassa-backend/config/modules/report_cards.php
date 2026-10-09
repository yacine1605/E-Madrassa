<?php
return [
    'label' => 'Bulletins scolaires', 'requires' => ['pedagogy'], 'permissions' => [], 'routes' => ['documents.php'],
    'resources' => ['report_card_terms', 'report_card_observations', 'report_card_archives'],
    'pages' => ['report-cards' => ['resources' => [], 'all' => ['pedagogy.read']]],
];
