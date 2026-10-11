<?php

// What Filament's French catalog lacks; Laravel adds it to Filament's own (FR-I18-01).
return [
    'callout' => [
        'statuses' => [
            'danger' => 'Erreur :',
            'info' => 'Remarque :',
            'success' => 'Réussite :',
            'warning' => 'Attention :',
        ],
    ],
    'section' => [
        'actions' => [
            'collapse' => [
                'label' => 'Replier la section',
            ],
            'expand' => [
                'label' => 'Déplier la section',
            ],
        ],
    ],
    'wizard' => [
        'header' => [
            'step' => [
                'statuses' => [
                    'completed' => 'Terminée',
                    'upcoming' => 'Non terminée',
                ],
            ],
        ],
    ],
];
