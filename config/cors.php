<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5133',   // React (Vite)
        'http://127.0.0.1:5133',
        'http://localhost:8000',   // Laravel نفسه
        'https://react-frontend-production-b8cd.up.railway.app', // React (Railway)
        ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];