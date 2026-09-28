<?php
return [
    'driver'=>env('SESSION_DRIVER','file'), 'lifetime'=>120, 'expire_on_close'=>false,
    'encrypt'=>true, 'files'=>storage_path('framework/sessions'), 'connection'=>env('SESSION_CONNECTION','default'),
    'store'=>env('SESSION_STORE'), 'lottery'=>[2,100], 'cookie'=>env('SESSION_COOKIE','madrassa_session'),
    'path'=>'/', 'domain'=>env('SESSION_DOMAIN'), 'secure'=>env('SESSION_SECURE_COOKIE',true),
    'http_only'=>true, 'same_site'=>'lax', 'partitioned'=>false,
];
