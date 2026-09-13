<?php

namespace App\Enums;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

enum PaymentMethodEnum:string implements HasLabel{
    case CREDIT_CARD = 'CREDIT_CARD';
    case PIX = 'PIX';

    public function getLabel(): ?string
    {

        return match ($this) {
            self::CREDIT_CARD => 'cartão de crédito',
            self::PIX => 'pix',
        };
    }
}
