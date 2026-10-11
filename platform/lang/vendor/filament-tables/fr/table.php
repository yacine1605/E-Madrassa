<?php

// What Filament's French catalog lacks; Laravel adds it to Filament's own (FR-I18-01).
return [
    'column_manager' => [
        'actions' => [
            'reorder' => [
                'label' => 'Réordonner la colonne',
            ],
        ],
    ],
    'columns' => [
        'icon' => [
            'boolean' => [
                'true' => 'Oui',
                'false' => 'Non',
            ],
        ],
        'select' => [
            'loading_message' => 'Chargement…',
            'no_options_message' => 'Aucune option disponible.',
            'no_search_results_message' => 'Aucune option ne correspond à votre recherche.',
            'placeholder' => 'Choisissez une option',
            'searching_message' => 'Recherche…',
            'search_prompt' => 'Commencez à taper pour rechercher',
        ],
    ],
    'actions' => [
        'reorder_record' => [
            'label' => 'Réordonner l’élément :key',
        ],
        'toggle_record_content' => [
            'label' => 'Déplier ou replier l’élément :key',
        ],
    ],
    'loading' => 'Chargement…',
    'result_count' => '{0} Aucun résultat|{1} :count résultat|[2,*] :count résultats',
];
