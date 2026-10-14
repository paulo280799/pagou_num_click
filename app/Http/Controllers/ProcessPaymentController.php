<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Payment;
use App\Services\ApiService;
use App\Support\ChargeOutcome;
use App\Support\ChargeResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

class ProcessPaymentController extends Controller
{
    function __construct(private Payment $payment, private ApiService $apiService) {}

    public function index()
    {
        $payments = $this->payment
            ->withoutGlobalScopes()
            ->where('status', StatusPaymentEnum::PENDING)
            ->get();

        foreach ($payments as $payment) {
            // Cartão primeiro: pode virar PAID (ou liberar nova tentativa) antes de decidir expiração.
            if ($payment->card_order_id || $payment->card_attempt_key) {
                $this->checkCardAttempt($payment);
                $payment->refresh();

                if ($payment->status !== StatusPaymentEnum::PENDING) {
                    continue;
                }

                // Cartão ainda em análise: não cancelar por expiração.
                if ($payment->card_order_id && $payment->card_attempt_key) {
                    continue;
                }
            }

            if ($payment->expirationDate < now()) {
                $payment->update(['status' => StatusPaymentEnum::CANCELED]);
                continue;
            }

            $response = $this->apiService->get_order($payment->ide);

            $paymentStatusFromApi = $response['status'] ?? null;

            if (!$paymentStatusFromApi) {
                // Se não houver status no retorno da API, pula para o próximo
                continue;
            }

            $paymentStatusEnum = StatusPaymentEnum::tryFrom(strtoupper($paymentStatusFromApi));

            if (!$paymentStatusEnum) {
                // Status inválido ou desconhecido — você pode logar isso, se quiser
                continue;
            }

            match ($paymentStatusEnum) {
                StatusPaymentEnum::PAID => $payment->update([
                    'status' => $paymentStatusEnum,
                    'paymentDate' => now()
                ]),

                StatusPaymentEnum::FAILED,
                StatusPaymentEnum::CANCELED => $payment->update([
                    'status' => $paymentStatusEnum
                ]),

                default => null // Caso queira tratar PENDING_REFUND, REFUNDED, etc., adicione aqui
            };
        }

        $this->watchOpenPixOfCardPayments();
    }

    /**
     * Confere a tentativa de cartão de um Payment PENDING.
     */
    private function checkCardAttempt(Payment $payment): void
    {
        try {
            if ($payment->card_order_id) {
                $response = $this->apiService->get_order($payment->card_order_id);
                $data = $response->successful() ? $response->json() : null;
            } else {
                $data = $this->reconcileTimedOutAttempt($payment);
                if ($data === false) {
                    return;
                }
            }
        } catch (ConnectionException $e) {
            Log::warning("Polling cartão: falha de conexão; payment={$payment->id}");

            return;
        }

        if ($data === null) {
            // timeout sem pedido no provedor: libera a chave para nova tentativa
            if (! $payment->card_order_id) {
                $payment->releaseCardAttempt();
            }

            return;
        }

        $result = ChargeResult::fromOrderResponse($data);

        switch ($result->outcome) {
            case ChargeOutcome::PAID:
                $payment->markCardPaid($result);
                break;

            case ChargeOutcome::DECLINED:
                $payment->releaseCardAttempt();
                break;

            default:
                Log::warning("Polling cartão: em análise/desconhecido; payment={$payment->id} order={$result->orderId} tx_status={$result->transactionStatus}");
        }
    }

    /**
     * Tentativa com chave gravada e sem card_order_id, mais antiga que a janela de timeout.
     * Retorna o pedido (array), null se não existe no provedor, false se ainda dentro da janela.
     */
    private function reconcileTimedOutAttempt(Payment $payment): array|null|false
    {
        $window = config('integration.card_http_timeout') + 5;

        if (! $payment->card_attempt_started_at || $payment->card_attempt_started_at->gt(now()->subSeconds($window))) {
            return false;
        }

        $response = $this->apiService->get_orders_by_code($payment->id);

        if (! $response->successful()) {
            return false;
        }

        foreach ($response->json('data') ?? [] as $order) {
            if (data_get($order, 'metadata.payment_id') === $payment->id) {
                return $order;
            }
        }

        return null;
    }

    /**
     * PIX não é cancelável na Pagar.me: após cartão aprovado, vigia o PIX até expirar (+5 min).
     */
    private function watchOpenPixOfCardPayments(): void
    {
        $payments = $this->payment
            ->withoutGlobalScopes()
            ->where('status', StatusPaymentEnum::PAID)
            ->where('payment_method', PaymentMethodEnum::CREDIT_CARD)
            ->where('expirationDate', '>=', now()->subMinutes(5))
            ->get();

        foreach ($payments as $payment) {
            try {
                $response = $this->apiService->get_order($payment->ide);
            } catch (ConnectionException $e) {
                continue;
            }

            if (strtoupper((string) ($response['status'] ?? '')) === 'PAID') {
                Log::error("PIX pago em Payment já pago no cartão (estorno manual); payment={$payment->id} ide={$payment->ide} card_order={$payment->card_order_id}");
            }
        }
    }
}
