<?php

namespace App\Support\Gateways;

/**
 * O provedor recusou a credencial da conta (401/403): é configuração quebrada, não indisponibilidade do provedor.
 * Continua sendo um GatewayUnavailableException (não derruba o laço do scheduler), mas o cancelamento por expiração
 * nunca pode se apoiar nela: o cliente pode ter pago e nós não conseguimos ver.
 */
class GatewayCredentialsRejectedException extends GatewayUnavailableException {}
