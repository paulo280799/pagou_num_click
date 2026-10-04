<?php

namespace Tests\Feature\Livewire;

use App\Livewire\PaymentStatusCanceled;
use App\Livewire\PaymentStatusPaid;
use App\Livewire\PaymentStatusPending;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentStatusComponentsTest extends TestCase
{
    public function test_payment_status_canceled_renders(): void
    {
        Livewire::test(PaymentStatusCanceled::class)->assertOk();
    }

    public function test_payment_status_paid_renders(): void
    {
        Livewire::test(PaymentStatusPaid::class)->assertOk();
    }

    public function test_payment_status_pending_renders_with_mounted_data(): void
    {
        Livewire::test(PaymentStatusPending::class, [
            'qrCode' => 'https://qr.test/img.png',
            'copyPaste' => '00020126...',
            'amount' => 10.5,
        ])
            ->assertOk()
            ->assertSet('qrCode', 'https://qr.test/img.png')
            ->assertSet('copyPaste', '00020126...')
            ->assertSet('amount', 10.5);
    }
}
