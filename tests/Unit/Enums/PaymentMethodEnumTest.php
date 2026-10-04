<?php

namespace Tests\Unit\Enums;

use App\Enums\PaymentMethodEnum;
use PHPUnit\Framework\TestCase;

class PaymentMethodEnumTest extends TestCase
{
    public function test_labels(): void
    {
        $this->assertSame('cartão de crédito', PaymentMethodEnum::CREDIT_CARD->getLabel());
        $this->assertSame('pix', PaymentMethodEnum::PIX->getLabel());
    }

    public function test_try_from_returns_null_for_unknown_value(): void
    {
        $this->assertNull(PaymentMethodEnum::tryFrom('BOLETO'));
    }
}
