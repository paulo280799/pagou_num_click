<?php

namespace App\Services\Gateways;

use App\Enums\GatewayEnum;
use App\Models\Payment;
use App\Support\ChargeResult;
use App\Support\Gateways\CardCheckoutConfig;
use App\Support\Gateways\GatewayRequestException;
use App\Support\Gateways\GatewayUnavailableException;
use App\Support\Gateways\NormalizedStatus;
use App\Support\Gateways\PixCharge;
use App\Support\Gateways\PixRequest;
use Illuminate\Support\Facades\Log;

/**
 * Porta única para os gateways: ninguém escolhe provedor por conta própria. O Pagar.me é o primário;
 * o Mercado Pago entra quando o Pagar.me falha por infra ou com o circuit breaker aberto.
 */
class GatewayManager
{
    public function __construct(
        private PagarmeGateway $pagarme,
        private MercadoPagoGateway $mercadoPago,
        private CircuitBreaker $breaker,
    ) {}

    public function gateway(GatewayEnum $gateway): PaymentGateway
    {
        return match ($gateway) {
            GatewayEnum::PAGARME => $this->pagarme,
            GatewayEnum::MERCADOPAGO => $this->mercadoPago,
        };
    }

    public function fallbackEnabled(): bool
    {
        return $this->mercadoPago->isEnabled();
    }

    /** Ordem de tentativa na criação de PIX: com o Pagar.me aberto no breaker, o MP vai primeiro. */
    private function pixOrder(): array
    {
        if (! $this->fallbackEnabled()) {
            return [$this->pagarme];
        }

        return $this->breaker->isOpen(GatewayEnum::PAGARME)
            ? [$this->mercadoPago, $this->pagarme]
            : [$this->pagarme, $this->mercadoPago];
    }

    /** Decisão vigente para criar PIX (já considerando o breaker). */
    public function pixGateway(): PaymentGateway
    {
        return $this->pixOrder()[0];
    }

    /**
     * Cria o PIX no primeiro gateway que responder.
     * 4xx (GatewayRequestException) não aciona failover. Se só há um gateway, a falha dele sobe como veio;
     * se todos falham, sobe um GatewayUnavailableException 503 (e nenhum Payment deve ser criado).
     *
     * @throws GatewayRequestException
     * @throws GatewayUnavailableException
     */
    public function createPix(PixRequest $request): PixCharge
    {
        $order = $this->pixOrder();
        $first = null;
        $last = null;

        foreach ($order as $gateway) {
            try {
                $charge = $gateway->createPix($request);
                $this->recordSuccess($gateway);

                if ($first !== null) {
                    Log::warning("Failover PIX: {$first->gateway?->value} indisponível ({$first->getMessage()}); payment={$request->paymentId} criado em {$gateway->name()->value}");
                }

                return $charge;
            } catch (GatewayRequestException $e) {
                // o gateway respondeu: está de pé, o problema é o pedido
                $this->recordSuccess($gateway);

                throw $e;
            } catch (GatewayUnavailableException $e) {
                $this->recordFailure($gateway);
                $first ??= $e;
                $last = $e;
            }
        }

        if (count($order) === 1) {
            throw $first;
        }

        Log::error("PIX: todos os gateways indisponíveis; payment={$request->paymentId} primeiro={$first->getMessage()} ultimo={$last->getMessage()}");

        throw new GatewayUnavailableException('Todos os gateways indisponíveis', $last->gateway, 503, $first->body, $last);
    }

    private function recordSuccess(PaymentGateway $gateway): void
    {
        if ($gateway->name() === GatewayEnum::PAGARME) {
            $this->breaker->recordSuccess(GatewayEnum::PAGARME);
        }
    }

    private function recordFailure(PaymentGateway $gateway): void
    {
        if ($gateway->name() === GatewayEnum::PAGARME) {
            $this->breaker->recordFailure(GatewayEnum::PAGARME);
        }
    }

    /** Falha do Pagar.me fora da criação de PIX (ex.: cartão em voo): alimenta o breaker. */
    public function reportFailure(GatewayEnum $gateway): void
    {
        if ($gateway === GatewayEnum::PAGARME) {
            $this->breaker->recordFailure(GatewayEnum::PAGARME);
        }
    }

    /** Config de tokenização do gateway vigente; null = cartão indisponível (nenhum gateway com chave pública). */
    public function cardCheckoutConfig(): ?CardCheckoutConfig
    {
        $pagarme = $this->pagarme->cardCheckoutConfig();
        $mercadoPago = $this->fallbackEnabled() ? $this->mercadoPago->cardCheckoutConfig() : null;

        if ($pagarme && $mercadoPago) {
            return $this->breaker->isOpen(GatewayEnum::PAGARME) ? $mercadoPago : $pagarme;
        }

        return $pagarme ?? $mercadoPago;
    }

    /** O cartão fica habilitado se qualquer gateway tiver chave pública. */
    public function cardEnabled(): bool
    {
        return $this->cardCheckoutConfig() !== null;
    }

    /** Config de tokenização de um gateway específico (resposta de fallback do pay-card). */
    public function cardCheckoutConfigFor(GatewayEnum $gateway): ?CardCheckoutConfig
    {
        return match ($gateway) {
            GatewayEnum::PAGARME => $this->pagarme->cardCheckoutConfig(),
            GatewayEnum::MERCADOPAGO => $this->fallbackEnabled() ? $this->mercadoPago->cardCheckoutConfig() : null,
        };
    }

    /**
     * O gateway com que o browser tokenizou ainda é o vigente? O Pagar.me só deixa de valer com o breaker aberto
     * (e um MP para onde ir); o MP vale com o Pagar.me degradado, inclusive logo depois de falhar em voo
     * (breaker ainda fechado), senão o re-envio do fallback seria barrado.
     */
    public function isCardGatewayStillCurrent(GatewayEnum $gateway): bool
    {
        $mercadoPagoAvailable = $this->cardCheckoutConfigFor(GatewayEnum::MERCADOPAGO) !== null;

        return match ($gateway) {
            GatewayEnum::PAGARME => ! ($mercadoPagoAvailable && $this->breaker->isOpen(GatewayEnum::PAGARME)),
            GatewayEnum::MERCADOPAGO => $mercadoPagoAvailable
                && ($this->breaker->isDegraded(GatewayEnum::PAGARME) || $this->pagarme->cardCheckoutConfig() === null),
        };
    }

    /** Status do PIX: roteia pelo dono do `ide`, nunca pelo accessor `gateway`. */
    public function pixStatus(Payment $payment): NormalizedStatus
    {
        return $this->gateway($payment->pixGatewayOwner())->getPixStatus($payment->ide);
    }

    /** Status da tentativa de cartão: roteia pelo dono do `card_order_id`. */
    public function cardStatus(Payment $payment): ?ChargeResult
    {
        return $this->gateway($payment->cardGatewayOwner())->getCardStatus($payment->card_order_id);
    }

    public function findCardAttempt(Payment $payment): ?ChargeResult
    {
        return $this->gateway($payment->cardGatewayOwner())->findCardAttempt($payment->id);
    }
}
