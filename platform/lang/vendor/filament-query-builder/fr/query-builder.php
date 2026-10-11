<?php

// What Filament's French catalog lacks; Laravel adds it to Filament's own (FR-I18-01).
return [
    'form' => [
        'or_groups' => [
            'group' => [
                'label' => 'Groupe',
            ],
        ],
    ],
    'max_rules_reached_tooltip' => 'Vous avez atteint le maximum de :count règles.',
    'operators' => [
        'date' => [
            'unit_labels' => [
                'second' => 'Secondes',
                'minute' => 'Minutes',
                'hour' => 'Heures',
                'day' => 'Jours',
                'week' => 'Semaines',
                'month' => 'Mois',
                'quarter' => 'Trimestres',
                'year' => 'Années',
            ],
            'presets' => [
                'past_decade' => 'Dix dernières années',
                'past_5_years' => 'Cinq dernières années',
                'past_2_years' => 'Deux dernières années',
                'past_year' => 'Dernière année',
                'past_6_months' => 'Six derniers mois',
                'past_quarter' => 'Dernier trimestre',
                'past_month' => 'Dernier mois',
                'past_2_weeks' => 'Deux dernières semaines',
                'past_week' => 'Dernière semaine',
                'past_hour' => 'Dernière heure',
                'past_minute' => 'Dernière minute',
                'this_decade' => 'Cette décennie',
                'this_year' => 'Cette année',
                'this_quarter' => 'Ce trimestre',
                'this_month' => 'Ce mois-ci',
                'today' => 'Aujourd’hui',
                'this_hour' => 'Cette heure',
                'this_minute' => 'Cette minute',
                'next_minute' => 'Minute suivante',
                'next_hour' => 'Heure suivante',
                'next_week' => 'Semaine suivante',
                'next_2_weeks' => 'Deux semaines suivantes',
                'next_month' => 'Mois suivant',
                'next_quarter' => 'Trimestre suivant',
                'next_6_months' => 'Six mois suivants',
                'next_year' => 'Année suivante',
                'next_2_years' => 'Deux années suivantes',
                'next_5_years' => 'Cinq années suivantes',
                'next_decade' => 'Dix années suivantes',
                'custom' => 'Personnalisée',
            ],
            'form' => [
                'mode' => [
                    'label' => 'Type de date',
                    'options' => [
                        'absolute' => 'Date précise',
                        'relative' => 'Période glissante',
                    ],
                ],
                'preset' => [
                    'label' => 'Période',
                ],
                'relative_value' => [
                    'label' => 'Combien',
                ],
                'relative_unit' => [
                    'label' => 'Unité de temps',
                ],
                'tense' => [
                    'label' => 'Sens',
                    'options' => [
                        'past' => 'Passé',
                        'future' => 'Futur',
                    ],
                ],
            ],
        ],
    ],
];
