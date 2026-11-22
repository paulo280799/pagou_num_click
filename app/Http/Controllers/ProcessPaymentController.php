<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Payment;
use App\Services\Gateways\GatewayManager;
use App\Support\TenantContext;
use App\Support\ChargeOutcome;
use App\Support\ChargeResult;
use App\Support\Gateways\GatewayCredentialsRejectedException;
use App\Support\Gateways\GatewayUnavailableException;
use Illuminate\Support\Facades\Cache;
use App\Support\Gateways\NormalizedStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessPaymentController extends Controller
{
    public function __construct(private Payment $payment, private GatewayManager $gateways) {}

    public function index()
    {
        $payments = $this->payment
            ->withoutGlobalScopes()
            ->where('status', StatusPaymentEnum::PENDING)
            ->get();

        foreach ($payments as $payment) {
            TenantContext::tagLogs($payment->account_id);

            try {
                $this->processPayment($payment);
            } catch (GatewayUnavailableException $e) {
                // provedor fora do ar não pode travar os demais pagamentos
                Log::warning("Polling: provedor indisponível ({$e->getMessage()}); payment={$payment->id}");
            }
        }

        $this->watchOpenPixOfCardPayments();
    }

    /** Reavalia um único pagamento (webhook do provedor): mesma regra do polling, sem esperar o scheduler. */
    public function processOne(Payment $payment): void
    {
        TenantContext::tagLogs($payment->account_id);

        if ($payment->status !== StatusPaymentEnum::PENDING) {
            return;
        }

        try {
            $this->processPayment($payment);
        } catch (GatewayUnavailableException $e) {
            Log::warning("Webhook: provedor indisponível ({$e->getMessage()}); payment={$payment->id}");
        }
    }

    private function processPayment(Payment $payment): void
    {
        // Cartão primeiro: pode virar PAID (ou liberar nova tentativa) antes de decidir expiração.
        if ($payment->card_order_id || $payment->card_attempt_key) {
            $this->checkCardAttempt($payment);
            $payment->refresh();

            if ($payment->status !== StatusPaymentEnum::PENDING) {
                return;
            }

            // Cartão ainda em análise: não cancelar por expiração.
            if ($payment->card_order_id && $payment->card_attempt_key) {
                return;
            }
        }

        if ($payment->expirationDate < now()) {
            $this->expire($payment);

            return;
        }

        // Checkout só-cartão não tem pedido PIX: nada a consultar além da tentativa de cartão e da expiração.
        if (! $payment->ide) {
            return;
        }

        $this->applyPixStatus($payment, $this->gateways->forPayment($payment)->pixStatus($payment));
    }

    /**
     * Vencido localmente: antes de cancelar pergunta ao gateway (PIX pago no último segundo, ou durante uma queda,
     * não pode virar CANCELED). Gateway indisponível adia o cancelamento por uma janela de graça.
     */
    private function expire(Payment $payment): void
    {
        if ($payment->ide) {
            try {
                $status = $this->gateways->forPayment($payment)->pixStatus($payment);
                $this->applyPixStatus($payment, $status);

                // status que não deu para interpretar (ex.: 404 de outra conta do provedor): não é "não pago"
                if ($status->rawStatus === null) {
                    $this->warnOncePerHour($payment, 'status do PIX indecifrável no provedor; não cancelo');

                    return;
                }
            } catch (GatewayCredentialsRejectedException $e) {
                // credencial da conta recusada: nunca cancelar sem enxergar (o cliente pode ter pago). Fica PENDING
                // até a conta corrigir a credencial em /admin/gateways; o provedor então diz o que aconteceu.
                $this->warnOncePerHour($payment, "credencial recusada ({$e->getMessage()}); não cancelo");

                return;
            } catch (GatewayUnavailableException $e) {
                $graceEnd = Carbon::parse($payment->expirationDate)->addMinutes((int) config('gateways.expiry_grace_minutes'));

                if (now()->lt($graceEnd)) {
                    return;
                }

                Log::warning("Polling: janela de graça esgotada, cancelando sem confirmar com o provedor; payment={$payment->id}");
            }

            // o gateway disse PAID/FAILED/CANCELED: já aplicado
            if ($payment->refresh()->status !== StatusPaymentEnum::PENDING) {
                return;
            }
        }

        $payment->update(['status' => StatusPaymentEnum::CANCELED]);
    }

    /** O polling roda a cada 10 s: o alerta de pagamento que não dá para resolver sai no máximo 1 vez por hora. */
    private function warnOncePerHour(Payment $payment, string $reason): void
    {
        if (Cache::add("expire-blocked:{$payment->id}", true, 3600)) {
            Log::error("Expiração bloqueada: {$reason}; payment={$payment->id} account={$payment->account_id}");
        }
    }

    private function applyPixStatus(Payment $payment, NormalizedStatus $status): void
    {
        if ($status->rawStatus === null) {
            // Se não houver status no retorno da API, pula para o próximo
            return;
        }

        $this->logWhenStatusChanges($payment, $status);

        match ($status->status) {
            StatusPaymentEnum::PAID => $payment->update($payment->feeAttributesFor(PaymentMethodEnum::PIX) + [
                'status' => StatusPaymentEnum::PAID,
                'paymentDate' => now(),
            ]),

            StatusPaymentEnum::FAILED,
            StatusPaymentEnum::CANCELED => $payment->update([
                'status' => $status->status,
            ]),

            default => null // PENDING, estorno/chargeback (só log) e desconhecidos não mudam o Payment
        };
    }

    /**
     * O polling roda a cada 10 s: o status cru só é logado quando muda desde a última leitura
     * (`provider_status`). `pending`/`waiting_payment` são conhecidos e não logam.
     */
    private function logWhenStatusChanges(Payment $payment, NormalizedStatus $status): void
    {
        if (! $payment->recordProviderStatus($status->rawStatus, $status->rawDetail)) {
            return;
        }

        $gateway = $payment->pixGatewayOwner()->value;

        if ($status->isUnsupported()) {
            Log::error("Polling: status sem tratamento no sistema (estorno/chargeback?); gateway={$gateway} status={$status->rawStatus} detail={$status->rawDetail} payment={$payment->id}");
        } elseif (! $status->isKnown()) {
            Log::warning("Polling: status desconhecido; gateway={$gateway} status={$status->rawStatus} detail={$status->rawDetail} payment={$payment->id}");
        }
    }

    /**
     * Confere a tentativa de cartão de um Payment PENDING.
     */
    private function checkCardAttempt(Payment $payment): void
    {
        try {
            if ($payment->card_order_id) {
                $result = $this->gateways->forPayment($payment)->cardStatus($payment);
            } else {
                $result = $this->reconcileTimedOutAttempt($payment);
                if ($result === false) {
                    return;
                }
            }
        } catch (GatewayUnavailableException $e) {
            Log::warning("Polling cartão: falha de conexão; payment={$payment->id}");

            return;
        }

        if ($result === null) {
            // timeout sem pedido no provedor: libera a chave para nova tentativa
            if (! $payment->card_order_id) {
                $payment->releaseCardAttempt();
            }

            return;
        }

        switch ($result->outcome) {
            case ChargeOutcome::PAID:
                $payment->markCardPaid($result);
                break;

            case ChargeOutcome::DECLINED:
                $payment->releaseCardAttempt();
                break;

            default:
                if ($payment->recordProviderStatus($result->rawStatus, $result->rawDetail)) {
                    Log::warning("Polling cartão: em análise/desconhecido; gateway={$payment->cardGatewayOwner()->value} payment={$payment->id} order={$result->orderId} tx_status={$result->transactionStatus} detail={$result->rawDetail}");
                }
        }
    }

    /**
     * Tentativa com chave gravada e sem card_order_id, mais antiga que a janela de timeout.
     * Retorna o resultado, null se não existe no provedor, false se ainda dentro da janela ou a busca não foi conclusiva.
     */
    private function reconcileTimedOutAttempt(Payment $payment): ChargeResult|null|false
    {
        $window = config('integration.card_http_timeout') + 5;

        if (! $payment->card_attempt_started_at || $payment->card_attempt_started_at->gt(now()->subSeconds($window))) {
            return false;
        }

        try {
            return $this->gateways->forPayment($payment)->findCardAttempt($payment);
        } catch (GatewayUnavailableException) {
            return false;
        }
    }

    /**
     * PIX não é cancelável no Pagar.me: após cartão aprovado, vigia o PIX até expirar (+5 min).
     */
    private function watchOpenPixOfCardPayments(): void
    {
        $payments = $this->payment
            ->withoutGlobalScopes()
            ->where('status', StatusPaymentEnum::PAID)
            ->where('payment_method', PaymentMethodEnum::CREDIT_CARD)
            ->whereNotNull('ide')
            ->where('expirationDate', '>=', now()->subMinutes(5))
            ->get();

        foreach ($payments as $payment) {
            TenantContext::tagLogs($payment->account_id);

            try {
                $status = $this->gateways->forPayment($payment)->pixStatus($payment);
            } catch (GatewayUnavailableException) {
                continue;
            }

            if ($status->status === StatusPaymentEnum::PAID) {
                Log::error("PIX pago em Payment já pago no cartão (estorno manual); payment={$payment->id} ide={$payment->ide} card_order={$payment->card_order_id}");
            }
        }
    }
}
