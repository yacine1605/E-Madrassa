<?php

return [
    // FR-PLN-01: the calendar.
    'calendar' => 'Calendrier',
    'calendar_help' => 'Ouvert le :days (Paramètres). Une fermeture ou une ouverture datée prime sur la semaine, celle d’un site sur celle de tout l’établissement. Aucune séance n’est planifiée un jour fermé.',
    'kinds' => ['holiday' => 'Jour férié', 'vacation' => 'Vacances', 'closure' => 'Fermeture', 'opening' => 'Ouverture exceptionnelle'],
    'kind' => 'Type',
    'scope' => 'Portée',
    'whole_school' => 'Tout l’établissement',
    'from' => 'Du',
    'until' => 'Au',
    'states' => ['planned' => 'Prévue', 'cancelled' => 'Annulée'],
    'add_exception' => 'Ajouter une fermeture ou une ouverture',
    'reason_help' => 'Par exemple : 1er Novembre, vacances d’hiver, rattrapage du lundi férié.',
    'scope_help' => 'Un site garde ses propres fermetures et ouvertures ; celles de tout l’établissement valent pour chaque site.',
    'cancel_exception' => 'Annuler',
    'cancel_exception_help' => 'Ces jours retrouvent la règle de la semaine. Le journal garde la trace.',
    'no_exception' => 'Aucune fermeture ni ouverture à venir',
    'no_exception_help' => 'L’établissement suit la règle de la semaine.',
    'include_past' => 'Avec les passées',
    'include_cancelled' => 'Avec les annulées',
    'open_days' => 'Jours ouverts',

    // FR-PLN-01: the grid of time slots.
    'time_slots' => 'Créneaux horaires',
    'time_slots_help' => 'La grille sur laquelle les séances sont planifiées. Un créneau garde son code et ses heures ; archivé, il n’est plus proposé, et les séances déjà planifiées gardent leurs heures.',
    'code_help' => 'De 1 à 20 lettres, chiffres ou tirets, par exemple M1 ; définitif.',
    'start' => 'Début',
    'end' => 'Fin',
    'duration' => 'Durée',
    'minutes' => ':n min',
    'time_help' => 'Heures et minutes, par exemple 08:00 ; 24:00 pour minuit en fin de journée.',
    'add_slot' => 'Ajouter un créneau',
    'archive_slot_help' => 'Il n’est plus proposé pour les nouvelles séances ; celles déjà planifiées gardent leurs heures.',
    'no_slot' => 'Aucun créneau',
    'no_slot_help' => 'Ajoutez les créneaux de la journée, par exemple 08:00–09:30.',
];
