<?php

namespace App\Services\Gateways;

use App\Models\Payment;
use App\Support\TenantContext;

/**
 * Entrada única para os gateways, agora por conta: cada conta cobra com as próprias credenciais
 * (ver GatewayCredentialResolver). Quem tem um Payment (scheduler, checkout público, Livewire) usa `forPayment`;
 * a API, que tem o tenant definido pelo token, usa `forTenant`.
 */
class GatewayManager
{
    public function __construct(
        private GatewayCredentialResolver $credentials,
        private CircuitBreaker $breaker,
        private TenantContext $tenant,
    ) {}

    public function forAccount(string $accountId): TenantGateways
    {
        return new TenantGateways(
            $accountId,
            new PagarmeGateway($this->credentials->pagarme($accountId)),
            new MercadoPagoGateway($this->credentials->mercadoPago($accountId)),
            $this->breaker,
        );
    }

    public function forPayment(Payment $payment): TenantGateways
    {
        return $this->forAccount($payment->account_id);
    }

    /** Conta da requisição atual (API autenticada por token ou painel). */
    public function forTenant(): TenantGateways
    {
        return $this->forAccount($this->tenant->requireAccountId());
    }
}
