<?php

/*
| Laravel's built-in CORS middleware is disabled (no paths). ChatBox sets CORS headers in
| App\Http\Middleware\SecurityHeaders so the public widget API can be embedded from any site
| while the staff API only answers configured dashboard origins (audit M4).
*/
return [
    'paths' => [],
    'allowed_methods' => ['*'],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
