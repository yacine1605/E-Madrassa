<?php

// Each feature owns a manifest. SCHOOL_MODULES selects deployment features;
// dependencies are included automatically. User permissions remain separate.
$modules = [];
foreach (glob(__DIR__.'/modules/*.php') as $path) {
    $modules[basename($path, '.php')] = require $path;
}
$profiles = [
    'full' => '*',
    'school' => '*',
    'language_center' => 'pedagogy,finance,payroll,communications,teacher_portal,audit',
    'training_center' => 'pedagogy,finance,payroll,lifecycle,progression,communications,teacher_portal,audit',
    'administration' => 'students,lifecycle,finance,audit',
];
$profile = env('SCHOOL_PROFILE', 'full');
if (!isset($profiles[$profile])) throw new InvalidArgumentException('Unknown school profile: '.$profile);
$selection = env('SCHOOL_MODULES');
return [
    'profile' => $profile, 'profiles' => $profiles,
    'enabled' => $selection === null || trim($selection) === '' ? $profiles[$profile] : $selection, 'definitions' => $modules,
];
