<?php

return [

    // Limite da API por CONTA (token), por minuto: um cliente barulhento não consome a cota dos outros
    'api_rate_limit_per_minute' => (int) env('API_ACCOUNT_RATE_LIMIT', 60),

    // Teto por IP (proteção contra inundação, antes de saber a conta). Alto de propósito: o limite real é por conta
    'api_ip_rate_limit_per_minute' => (int) env('API_IP_RATE_LIMIT', 300),
];
