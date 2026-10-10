<?php

return [
    // Dedicated installation (ND-06): the slug of the only school this deployment serves.
    // Empty for the shared SaaS, where a user opens any school they are an active member of.
    'dedicated_school' => env('PLATFORM_DEDICATED_SCHOOL') ?: null,

    // Database limits applied to every school request.
    'lock_timeout' => env('PLATFORM_LOCK_TIMEOUT', '3s'),
    'statement_timeout' => env('PLATFORM_STATEMENT_TIMEOUT', '10s'),

    // FR-IAM-02. Inactivity: the school's `session.idle_minutes` setting (15 to 120), this value
    // until a school is open. A session never outlives the absolute limit.
    'session_idle_minutes' => 30,
    'session_absolute_minutes' => 12 * 60,

    // FR-IAM-02: after this many consecutive failures, an account or an address waits, first
    // `lockout_first_minutes`, doubling at each new failure up to `lockout_max_minutes`.
    'lockout_threshold' => 5,
    // A school's pupils often share one public address: raise this one if they lock each other out.
    'lockout_ip_threshold' => (int) env('PLATFORM_LOCKOUT_IP_THRESHOLD', 5),
    'lockout_first_minutes' => 1,
    'lockout_max_minutes' => 60,

    // Second step of a sign-in with MFA: how long the first step stays valid.
    'mfa_pending_seconds' => 300,
    'mfa_issuer' => env('PLATFORM_MFA_ISSUER', env('APP_NAME', 'Madrassa')),

    // Activation and recovery links point to the front end; the token travels in the fragment,
    // which browsers never send to a server or put in a Referer header.
    'front_url' => rtrim((string) env('PLATFORM_FRONT_URL', env('APP_URL', 'http://localhost')), '/'),
];
