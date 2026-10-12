<?php

// Only what differs from Livewire's defaults.
return [
    // Its CSP build of Alpine, for the strict policy's trial only (config/platform.php, csp_strict).
    'csp_safe' => (bool) env('PLATFORM_CSP_STRICT', false),
];
