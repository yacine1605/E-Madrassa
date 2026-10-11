<?php

// What Filament's French catalog lacks; Laravel adds it to Filament's own (FR-I18-01).
return [
    'skip_to_content' => [
        'label' => 'Aller au contenu',
    ],
    'actions' => [
        'open_database_notifications' => [
            'label_with_unread_count' => '{1} Notifications, :count notification non lue|[2,*] Notifications, :count notifications non lues',
        ],
        'theme_switcher' => [
            'label' => 'Thème',
        ],
    ],
    'navigation' => [
        'label' => 'Navigation latérale',
    ],
    'topbar' => [
        'label' => 'Barre supérieure',
    ],
    'tenant_menu' => [
        'search_field' => [
            'label' => 'Rechercher un établissement',
            'placeholder' => 'Rechercher',
        ],
    ],
];
