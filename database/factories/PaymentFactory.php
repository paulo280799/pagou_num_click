<?php

namespace Database\Factories;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ide' => 'order_'.Str::random(10),
            'refExternal' => 'ref_'.Str::random(10),
            'qrCode' => fake()->url(),
            'copyPaste' => Str::random(40),
            'amount' => fake()->randomFloat(2, 10, 500),
            'status' => StatusPaymentEnum::PENDING,
            'payment_method' => PaymentMethodEnum::PIX,
            'duration' => 900,
            'expirationDate' => now()->addMinutes(15),
            'redirect_url' => fake()->url(),
            'notification_url' => fake()->url(),
            'notification_attempts' => 0,
            'last_notification_attempt' => null,
            'is_notified' => false,
            'account_id' => Account::factory(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => StatusPaymentEnum::PAID,
            'paymentDate' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expirationDate' => now()->subMinutes(5),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => []);
    }

    public function withCustomer(): static
    {
        return $this->state(fn () => [
            'customer' => [
                'name' => 'Fulano de Tal',
                'email' => 'fulano@example.com',
                'type' => 'individual',
                'document' => '12345678909',
                'phones' => ['home_phone' => ['country_code' => '55', 'area_code' => '11', 'number' => '999999999']],
            ],
        ]);
    }

    public function cardPaid(): static
    {
        return $this->paid()->withCustomer()->state(fn () => [
            'payment_method' => PaymentMethodEnum::CREDIT_CARD,
            'card_order_id' => 'or_'.Str::random(10),
            'card_brand' => 'Visa',
            'card_last_four' => '1111',
            'installments' => 1,
        ]);
    }

    public function cardAttemptPending(): static
    {
        return $this->withCustomer()->state(fn () => [
            'card_order_id' => 'or_'.Str::random(10),
            'card_attempt_key' => (string) Str::uuid(),
            'card_attempt_started_at' => now(),
        ]);
    }

    public function cardTimedOut(): static
    {
        return $this->withCustomer()->state(fn () => [
            'card_attempt_key' => (string) Str::uuid(),
            'card_attempt_started_at' => now()->subMinutes(10),
        ]);
    }
}
