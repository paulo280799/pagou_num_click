<?php

return [

    'url_integration' => env('URL_INTEGRATION'),
    'user_integration' => env('USER_INTEGRATION'),
    'password_integration' => env('PASSWORD_INTEGRATION'),

    'public_key' => env('STONE_PUBLIC_KEY_INTEGRATION'),
    'card_max_installments' => (int) env('CARD_MAX_INSTALLMENTS', 12),
    'card_statement_descriptor' => env('CARD_STATEMENT_DESCRIPTOR', 'PAGOUNUMCLICK'), // <= 13 chars (PSP)
    'card_http_timeout' => (int) env('CARD_HTTP_TIMEOUT', 15),
];
