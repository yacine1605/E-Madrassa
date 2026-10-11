<?php

// What Filament's French catalog lacks; Laravel adds it to Filament's own (FR-I18-01).
return [
    'builder' => [
        'block_picker' => [
            'no_search_results_message' => 'Aucun bloc ne correspond à votre recherche.',
            'search_prompt' => 'Rechercher des blocs',
        ],
    ],
    'checkbox_list' => [
        'required_description' => 'Choisissez au moins une option.',
    ],
    'color_picker' => [
        'panel_label' => 'Sélecteur de couleur',
    ],
    'date_time_picker' => [
        'month_select' => [
            'label' => 'Mois',
        ],
        'year_input' => [
            'label' => 'Année',
        ],
        'hour_input' => [
            'label' => 'Heure',
        ],
        'minute_input' => [
            'label' => 'Minute',
        ],
        'second_input' => [
            'label' => 'Seconde',
        ],
    ],
    'file_upload' => [
        'actions' => [
            'download' => [
                'label' => 'Télécharger',
            ],
            'open' => [
                'label' => 'Ouvrir dans un nouvel onglet',
            ],
        ],
        'editor' => [
            'label' => 'Éditeur d’image',
        ],
    ],
    'key_value' => [
        'columns' => [
            'actions' => [
                'label' => 'Actions',
            ],
            'reorder' => [
                'label' => 'Réordonner',
            ],
        ],
    ],
    'repeater' => [
        'columns' => [
            'actions' => [
                'label' => 'Actions',
            ],
            'reorder' => [
                'label' => 'Réordonner',
            ],
        ],
    ],
    'rich_editor' => [
        'actions' => [
            'close_panel' => [
                'label' => 'Fermer le panneau',
            ],
        ],
        'custom_blocks' => [
            'actions' => [
                'delete' => [
                    'label' => 'Supprimer le bloc',
                ],
                'edit' => [
                    'label' => 'Modifier le bloc',
                ],
            ],
            'no_search_results_message' => 'Aucun bloc ne correspond à votre recherche.',
            'search_label' => 'Rechercher des blocs',
            'search_prompt' => 'Rechercher des blocs',
        ],
        'toolbar' => [
            'label' => 'Barre d’outils de l’éditeur',
        ],
        'uploading_file_message' => 'Envoi du fichier…',
    ],
    'select' => [
        'actions' => [
            'clear' => [
                'label' => 'Effacer la sélection',
            ],
            'remove_option' => [
                'label' => 'Retirer :label',
            ],
        ],
        'search_label' => 'Rechercher',
    ],
    'tags_input' => [
        'tag_added' => 'Ajouté : :tag',
        'tag_removed' => 'Retiré : :tag',
    ],
    'toggle_buttons' => [
        'required_description' => 'Choisissez au moins une option.',
    ],
];
