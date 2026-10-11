<?php

// What Filament's French catalog lacks; Laravel adds it to Filament's own (FR-I18-01).
return [
    'login_form' => [
        'code' => [
            'actions' => [
                'resend' => [
                    'notifications' => [
                        'throttled' => [
                            'title' => 'Trop de demandes d’envoi. Patientez avant de demander un autre code.',
                        ],
                    ],
                ],
            ],
        ],
    ],
];
