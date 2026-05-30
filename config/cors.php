<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5133',
        'http://127.0.0.1:5133',
        'http://localhost:8000',
        'https://react-frontend-production-b8cd.up.railway.app',
        'https://studmo.com',       // ✅ nouveau
        'https://www.studmo.com',   // ✅ nouveau
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];