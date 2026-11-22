<?php

return [

    'url_integration' => env('URL_INTEGRATION'),
    'user_integration' => env('USER_INTEGRATION'),
    'password_integration' => env('PASSWORD_INTEGRATION'),

    'public_key' => env('STONE_PUBLIC_KEY_INTEGRATION'),
    'card_statement_descriptor' => env('CARD_STATEMENT_DESCRIPTOR', 'PAGOUNUMCLICK'), // <= 13 chars (PSP)
    'card_http_timeout' => (int) env('CARD_HTTP_TIMEOUT', 15),

    // após rotacionar o token da API, o anterior segue valendo por este prazo (a menos que a conta revogue na hora)
    'api_token_rotation_grace_minutes' => (int) env('API_TOKEN_ROTATION_GRACE_MINUTES', 60),
];
