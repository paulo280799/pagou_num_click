<?php

/*
 * Gateways de pagamento. O Pagar.me é o primário e continua lendo as chaves de config/integration.php
 * (URL_INTEGRATION, USER_INTEGRATION, PASSWORD_INTEGRATION, STONE_PUBLIC_KEY_INTEGRATION, CARD_HTTP_TIMEOUT):
 * a produção é Hostinger sem SSH, então nenhuma variável do .env é renomeada. Aqui entra só o que é novo.
 * Sem MP_ACCESS_TOKEN o failover fica desligado e o sistema se comporta como antes.
 */
return [

    // timeout (s) da criação de pagamento: curto de propósito, para o failover não esperar os 30 s padrão
    // conta que ainda usa as credenciais do .env (URL/USER/PASSWORD_INTEGRATION, MP_*) por não ter cadastrado nada no painel:
    // o id (UUID) da conta "dona" da instalação atual. `*` = todas as contas (SÓ dev/teste). Vazio = ninguém usa o .env.
    'env_credentials_account' => env('GATEWAYS_ENV_ACCOUNT_ID'),

    'create_timeout' => (int) env('GATEWAY_CREATE_TIMEOUT', 5),

    'mercadopago' => [
        'enabled' => (bool) env('MP_ENABLED', true),
        'access_token' => env('MP_ACCESS_TOKEN'),
        'public_key' => env('MP_PUBLIC_KEY'),
        // categoria dos itens enviada ao MP (items.category_id): art, electronics, fashion, services, virtual_goods, others...
        'item_category' => env('MP_ITEM_CATEGORY', 'others'),
        // chave secreta do webhook (painel do MP → Webhooks); sem ela o endpoint recusa tudo
        'webhook_secret' => env('MP_WEBHOOK_SECRET'),
        'url' => env('MP_URL', 'https://api.mercadopago.com'),
        // consulta/cobrança de cartão (a criação de PIX usa create_timeout)
        'http_timeout' => (int) env('MP_HTTP_TIMEOUT', 15),
        // o MP não aceita PIX com validade menor que 30 min
        'min_pix_expiration' => 1800,
    ],

    // circuit breaker do Pagar.me (Cache do Laravel: `file` em produção, `array` nos testes)
    'circuit' => [
        'failures' => (int) env('GATEWAY_CIRCUIT_FAILURES', 3),
        'window' => (int) env('GATEWAY_CIRCUIT_WINDOW', 60),
        'cooldown' => (int) env('GATEWAY_CIRCUIT_COOLDOWN', 120),
    ],

    // PIX vencido que não deu para consultar (gateway fora): adia o cancelamento por esta janela (min)
    'expiry_grace_minutes' => (int) env('GATEWAY_EXPIRY_GRACE_MINUTES', 15),
];
