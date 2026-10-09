<?php
return [
    'label' => 'Journal d’audit', 'requires' => ['core'],
    'permissions' => ['audit.read'], 'resources' => ['audit_events'],
    'pages' => ['audit' => ['resources' => ['audit_events'], 'all' => ['audit.read']]],
];
