<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Payment;
use App\Enums\StatusPaymentEnum;
use Carbon\Carbon;

class WebhookController extends Controller
{
    private array $retryIntervals = [60, 300, 600, 1800, 3600]; // 60s, 5min, 10min, 30min, 1h

    function __construct(private Payment $payment){}

    public function index(){

        $payments = $this->payment->withoutGlobalScopes()->where('is_notified', false)->where('status','!=', StatusPaymentEnum::PENDING)->get();

        foreach ($payments as $payment) {
            $this->attemptNotification($payment);
        }

    }

    private function attemptNotification($payment)
    {
        if (!$payment->notification_url) {
            Log::warning("Pagamento {$payment->id} sem URL de notificação.");
            return;
        }

        if ($payment->notification_attempts >= count($this->retryIntervals)) {
            Log::error("Tentativas de notificação esgotadas para pagamento {$payment->id}.");
            return;
        }

        $attempt = $payment->notification_attempts;
        $lastAttempt = $payment->last_notification_attempt ? Carbon::parse($payment->last_notification_attempt) : null;
        $nextAttemptTime = $lastAttempt
            ? $lastAttempt->addSeconds($this->retryIntervals[$attempt])
            : now();

        if ($nextAttemptTime->isFuture()) {
            return;
        }
        
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Webhook-Secret' => config('services.webhook_secret'), // ou env('WEBHOOK_SECRET')
        ])->post($payment->notification_url, [
            'payment_id' => $payment->refExternal,
            'status' => $payment->status
        ]);
        
        
        if ($response->successful()) {
            info("Notificação enviada com sucesso para o pagamento {$payment->id}.");
            $payment->update([
                'is_notified' => true
            ]);
        } else {
            Log::error("Erro ao notificar o pagamento {$payment->id}: {$response->body()}");
            $payment->update([
                'notification_attempts' => $payment->notification_attempts + 1,
                'last_notification_attempt' => now()
            ]);
        }
    }
}
