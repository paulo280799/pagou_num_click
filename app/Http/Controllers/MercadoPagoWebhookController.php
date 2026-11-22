<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Gateways\GatewayCredentialResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Notificação de entrada do Mercado Pago (tópico de ordens). Não confia no corpo: só usa o id da ordem para
 * reavaliar o pagamento consultando a API (a verdade continua sendo o `GET /v1/orders/{id}`).
 * O polling a cada 10 s segue valendo como rede de segurança.
 */
class MercadoPagoWebhookController extends Controller
{
    public function __construct(private ProcessPaymentController $processor, private GatewayCredentialResolver $credentials) {}

    /**
     * Cada conta registra no painel do próprio Mercado Pago a URL `/api/webhooks/mercadopago/{account}` e cadastra o
     * segredo no sistema: a assinatura é validada com o segredo DA CONTA da URL e só os pagamentos dela são reavaliados.
     * Sem `{account}` (URL antiga) vale a conta que ainda usa o .env (`gateways.env_credentials_account`) ou, sem ela, a conta dona do pedido.
     */
    public function __invoke(Request $request, ?string $account = null): JsonResponse
    {
        $orderId = (string) ($request->input('data.id') ?? $request->query('data_id') ?? '');
        // URL antiga (sem conta): a conta do .env; sem ela, a dona do pedido (o segredo continua sendo o dessa conta)
        $account ??= $this->legacyAccount() ?? $this->accountOfOrder($orderId);

        if (! $this->validSignature($request, $orderId, $this->secretFor($account))) {
            Log::warning('Webhook Mercado Pago: assinatura inválida ou segredo não configurado');

            return response()->json(['message' => 'invalid signature'], 401);
        }

        // ids de ordem chegam em caixa diferente entre o corpo e a assinatura: compara sem diferenciar
        $payment = $orderId === '' ? null : Payment::withoutGlobalScopes()
            ->when($account !== null && $account !== '*', fn ($q) => $q->where('account_id', $account))
            ->where(fn ($q) => $q
                ->whereRaw('LOWER(ide) = ?', [strtolower($orderId)])
                ->orWhereRaw('LOWER(card_order_id) = ?', [strtolower($orderId)]))
            ->first();

        if ($payment) {
            $this->processor->processOne($payment);
        }

        // sempre 200 para ids desconhecidos: o MP reenviaria indefinidamente
        return response()->json(['status' => 'ok']);
    }

    /** Manifesto do MP: `id:{data.id};request-id:{x-request-id};ts:{ts};` assinado com HMAC-SHA256. */
    private function legacyAccount(): ?string
    {
        return filled(config('gateways.env_credentials_account')) ? (string) config('gateways.env_credentials_account') : null;
    }

    private function accountOfOrder(string $orderId): ?string
    {
        if ($orderId === '') {
            return null;
        }

        return Payment::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereRaw('LOWER(ide) = ?', [strtolower($orderId)])->orWhereRaw('LOWER(card_order_id) = ?', [strtolower($orderId)]))
            ->value('account_id');
    }

    private function secretFor(?string $account): ?string
    {
        if ($account === null) {
            return null; // URL antiga sem conta configurada para o .env: ninguém pode validar
        }

        return $account === '*'
            ? config('gateways.mercadopago.webhook_secret')
            : $this->credentials->mercadoPago($account)?->webhookSecret;
    }

    private function validSignature(Request $request, string $orderId, ?string $secret): bool
    {
        $header = (string) $request->header('x-signature');

        if (! filled($secret) || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
            $parts[$key] = $value;
        }

        if (empty($parts['ts']) || empty($parts['v1'])) {
            return false;
        }

        $manifest = 'id:'.strtolower($orderId).';request-id:'.$request->header('x-request-id').';ts:'.$parts['ts'].';';

        return hash_equals(hash_hmac('sha256', $manifest, (string) $secret), (string) $parts['v1']);
    }
}
