<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum GatewayEnum: string implements HasColor, HasLabel
{
    case PAGARME = 'PAGARME';
    case MERCADOPAGO = 'MERCADOPAGO';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::PAGARME => 'Pagar.me',
            self::MERCADOPAGO => 'Mercado Pago',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PAGARME => 'success',
            self::MERCADOPAGO => 'info',
        };
    }
}
