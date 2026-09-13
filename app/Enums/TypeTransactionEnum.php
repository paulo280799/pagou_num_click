<?php

namespace App\Enums;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Contracts\HasColor;

enum TypeTransactionEnum:string implements HasLabel, HasColor{
    case INFLOW = 'INFLOW';
    case OUTFLOW = 'OUTFLOW';

    public function getLabel(): ?string
    {

        return match ($this) {
            self::INFLOW => 'entrada',
            self::OUTFLOW => 'saída',
        };
    }

    public function getColor(): string | array | null
    {
        return match ($this) {
            self::INFLOW => 'success',
            self::OUTFLOW => 'danger',
        };
    }
}
