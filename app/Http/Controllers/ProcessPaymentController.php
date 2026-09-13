<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\ApiService;
use App\Enums\StatusPaymentEnum;

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
    }
}
