<?php

namespace App\Http\Controllers;

use App\Models\Payment;
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
    public function __construct(private ProcessPaymentController $processor) {}

    public function __invoke(Request $request): JsonResponse
    {
        $orderId = (string) ($request->input('data.id') ?? $request->query('data_id') ?? '');

        if (! $this->validSignature($request, $orderId)) {
            Log::warning('Webhook Mercado Pago: assinatura inválida ou segredo não configurado');

            return response()->json(['message' => 'invalid signature'], 401);
        }

        // ids de ordem chegam em caixa diferente entre o corpo e a assinatura: compara sem diferenciar
        $payment = $orderId === '' ? null : Payment::withoutGlobalScopes()
            ->whereRaw('LOWER(ide) = ?', [strtolower($orderId)])
            ->orWhereRaw('LOWER(card_order_id) = ?', [strtolower($orderId)])
            ->first();

        if ($payment) {
            $this->processor->processOne($payment);
        }

        // sempre 200 para ids desconhecidos: o MP reenviaria indefinidamente
        return response()->json(['status' => 'ok']);
    }

    /** Manifesto do MP: `id:{data.id};request-id:{x-request-id};ts:{ts};` assinado com HMAC-SHA256. */
    private function validSignature(Request $request, string $orderId): bool
    {
        $secret = config('gateways.mercadopago.webhook_secret');
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
