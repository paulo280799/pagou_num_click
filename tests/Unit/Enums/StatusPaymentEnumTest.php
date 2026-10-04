<?php

namespace Tests\Unit\Enums;

use App\Enums\StatusPaymentEnum;
use PHPUnit\Framework\TestCase;

class StatusPaymentEnumTest extends TestCase
{
    /** @dataProvider cases */
    public function test_label_color_and_icon_are_defined(StatusPaymentEnum $status, string $label, string $color, string $icon): void
    {
        $this->assertSame($label, $status->getLabel());
        $this->assertSame($color, $status->getColor());
        $this->assertSame($icon, $status->getIcon());
    }

    public static function cases(): array
    {
        return [
            'pending' => [StatusPaymentEnum::PENDING, 'Aguardando pagamento', 'warning', 'heroicon-o-clock'],
            'paid' => [StatusPaymentEnum::PAID, 'Pago', 'success', 'heroicon-o-check-circle'],
            'failed' => [StatusPaymentEnum::FAILED, 'Falha', 'danger', 'heroicon-o-x-circle'],
            'canceled' => [StatusPaymentEnum::CANCELED, 'Cancelado', 'danger', 'heroicon-o-x-circle'],
        ];
    }

    public function test_try_from_returns_null_for_unknown_value(): void
    {
        $this->assertNull(StatusPaymentEnum::tryFrom('UNKNOWN'));
    }

    public function test_try_from_returns_case_for_known_value(): void
    {
        $this->assertSame(StatusPaymentEnum::PAID, StatusPaymentEnum::tryFrom('PAID'));
    }
}
