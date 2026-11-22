<?php

namespace App\Services\Gateways;

use App\Enums\GatewayEnum;
use App\Support\ChargeResult;
use App\Support\Gateways\CardCheckoutConfig;
use App\Support\Gateways\CardRequest;
use App\Support\Gateways\GatewayRequestException;
use App\Support\Gateways\GatewayUnavailableException;
use App\Support\Gateways\NormalizedStatus;
use App\Support\Gateways\PixCharge;
use App\Support\Gateways\PixRequest;

interface PaymentGateway
{
    public function name(): GatewayEnum;

    /** A conta tem credencial deste provedor? Sem ela o gateway não entra em nenhuma decisão. */
    public function isConfigured(): bool;

    /** @throws GatewayUnavailableException infra (conexão, timeout, 5xx, 429)  @throws GatewayRequestException 4xx */
    public function createPix(PixRequest $request): PixCharge;

    /** @throws GatewayUnavailableException conexão/5xx: o resultado da cobrança é desconhecido */
    public function chargeCard(CardRequest $request, string $idempotencyKey): ChargeResult;

    /** @throws GatewayUnavailableException conexão, timeout, 5xx, 429 */
    public function getPixStatus(string $externalId): NormalizedStatus;

    /** null = não foi possível obter o pedido (resposta sem sucesso). */
    public function getCardStatus(string $externalId): ?ChargeResult;

    /**
     * Busca a tentativa de cartão pela referência (reconciliação após timeout).
     * null = o gateway afirma que não existe.
     *
     * @throws GatewayUnavailableException a busca não foi conclusiva
     */
    public function findCardAttempt(string $paymentId): ?ChargeResult;

    /** false = o gateway não suporta cancelar PIX pendente. */
    public function cancelPix(string $externalId): bool;

    public function cardCheckoutConfig(): ?CardCheckoutConfig;
}
