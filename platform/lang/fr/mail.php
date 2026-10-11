<?php

// Emails: the account's links (FR-IAM-01, FR-IAM-02) and notifications (FR-NOT-01).
return [
    'greeting' => 'Bonjour :name,',
    'activation' => [
        'subject' => 'Activez votre compte',
        'subject_school' => 'Activez votre compte — :school',
        'opened' => 'Un compte vous a été ouvert.',
        'opened_school' => 'L’établissement :school vous a ouvert un compte.',
        'action' => 'Pour l’activer, ouvrez ce lien dans les 72 heures :',
    ],
    'recovery' => [
        'subject' => 'Réinitialisation de votre mot de passe',
        'asked' => 'Une réinitialisation de votre mot de passe a été demandée.',
        'action' => 'Pour choisir un nouveau mot de passe, ouvrez ce lien dans l’heure :',
    ],
    'once' => 'Ce lien ne sert qu’une fois. Si vous n’êtes pas à l’origine de cette demande, ignorez ce message.',
    'notification' => [
        'subject' => ':title — :school',
        'line' => ':title (:school).',
        'details' => 'Connectez-vous pour en voir le détail.',
        'why' => 'Vous recevez ce message selon vos préférences de notification, modifiables dans l’application.',
    ],
];
