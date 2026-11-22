<?php

namespace App\Support\Gateways;

use App\Enums\GatewayEnum;
use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use Illuminate\Support\Carbon;

/** Saída da criação de PIX. `expiresAt` só vem preenchido quando o gateway impõe outra validade (MP: mínimo 30 min). */
final class PixCharge
{
    public function __construct(
        public readonly GatewayEnum $gateway,
        public readonly string $externalId,
        public readonly string $qrCodeImage,
        public readonly string $copyPaste,
        public readonly int $amountCents,
        public readonly StatusPaymentEnum $status = StatusPaymentEnum::PENDING,
        public readonly PaymentMethodEnum $paymentMethod = PaymentMethodEnum::PIX,
        public readonly ?Carbon $expiresAt = null,
        public readonly ?string $rawStatus = null,
        public readonly ?string $rawDetail = null,
    ) {}
}
