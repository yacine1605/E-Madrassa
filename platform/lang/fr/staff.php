<?php

// The staff's screens (FR-STF-01).
return [
    'staff' => 'Personnel',
    'create' => 'Nouveau membre du personnel',
    'created' => 'Fiche créée, numéro :number.',
    'number' => 'Numéro',
    'number_help' => 'Laissez vide : il est attribué (P puis numéro). Un numéro repris d’un autre registre est définitif.',
    'type' => 'Fonction',
    'types' => ['teacher_internal' => 'enseignant interne', 'teacher_external' => 'enseignant externe', 'administrative' => 'administratif',
        'other' => 'autre'],
    'hire_date' => 'Date d’embauche',
    'states' => ['active' => 'en activité', 'on_leave' => 'en congé', 'left' => 'parti', 'archived' => 'archivé'],
    'left_on' => 'Date de départ',
    'left_since' => 'parti le :date',
    'left_on_help' => 'Aujourd’hui si vous la laissez vide ; jamais dans le futur. La fiche, ses séances et ses heures restent consultables.',
    'site_help' => 'Le site qui gère cette fiche : son équipe la lit et la met à jour.',
    'account' => 'Compte de connexion',
    'no_account' => 'sans compte',
    'account_help' => 'Le compte avec lequel ce membre du personnel se connecte. Une fiche existe sans compte ; un compte va à une seule fiche.',
    'link_account' => 'Lier un compte',
    'unlink_account' => 'Retirer le compte',
    'unlink_account_help' => 'La fiche reste ; ce compte n’y est plus lié.',
    'archive_help' => 'La fiche d’un membre parti quitte les listes ; elle reste consultable avec les fiches archivées et ne change plus.',
];
