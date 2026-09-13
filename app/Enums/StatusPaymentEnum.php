<?php

namespace App\Enums;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;

enum StatusPaymentEnum:string implements HasLabel, HasColor, HasIcon{
    case PENDING = 'PENDING';
    case PAID = 'PAID';
    case FAILED = 'FAILED';
    case CANCELED = 'CANCELED';

    public function getLabel(): ?string
    {

        return match ($this) {
            self::PENDING => 'Aguardando pagamento',
            self::PAID => 'Pago',
            self::FAILED => 'Falha',
            self::CANCELED => 'Cancelado',
        };
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::PAID => 'success',
            self::FAILED => 'danger',
            self::CANCELED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::PENDING => 'heroicon-o-clock',
            self::PAID => 'heroicon-o-check-circle',
            self::FAILED => 'heroicon-o-x-circle',
            self::CANCELED => 'heroicon-o-x-circle',
        };
    }
}
