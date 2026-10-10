<?php

return [
    // Dedicated installation (ND-06): the slug of the only school this deployment serves.
    // Empty for the shared SaaS, where a user opens any school they are an active member of.
    'dedicated_school' => env('PLATFORM_DEDICATED_SCHOOL') ?: null,

    // Database limits applied to every school request.
    'lock_timeout' => env('PLATFORM_LOCK_TIMEOUT', '3s'),
    'statement_timeout' => env('PLATFORM_STATEMENT_TIMEOUT', '10s'),
];
