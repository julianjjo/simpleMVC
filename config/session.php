<?php

declare(strict_types=1);

return [
    'name' => env('SESSION_NAME', 'simplemvc_session'),
    'lifetime' => env_int('SESSION_LIFETIME', 7200),
];
