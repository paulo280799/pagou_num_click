<?php

namespace Tests\Feature\Models;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_and_payment_method_are_cast_to_enums(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $this->actingAs($user);

        $payment = Payment::factory()->create([
            'account_id' => $account->id,
            'status' => StatusPaymentEnum::PAID,
            'payment_method' => PaymentMethodEnum::PIX,
        ]);

        $this->assertSame(StatusPaymentEnum::PAID, $payment->status);
        $this->assertSame(PaymentMethodEnum::PIX, $payment->payment_method);
    }

    public function test_account_id_is_auto_filled_from_authenticated_user_when_not_set(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $this->actingAs($user);

        $payment = Payment::factory()->make();
        unset($payment->account_id);
        $payment->save();

        $this->assertSame($account->id, $payment->account_id);
    }

    public function test_account_id_is_kept_when_already_set(): void
    {
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $this->actingAs($user);

        $payment = Payment::factory()->create(['account_id' => $otherAccount->id]);

        $this->assertSame($otherAccount->id, $payment->account_id);
    }

    public function test_user_scope_filters_payments_by_authenticated_users_account(): void
    {
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        Payment::factory()->create(['account_id' => $account->id]);
        Payment::factory()->create(['account_id' => $otherAccount->id]);

        $this->actingAs($user);

        $this->assertSame(1, Payment::count());
        $this->assertSame(2, Payment::withoutGlobalScopes()->count());
    }

    public function test_account_relation(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $this->actingAs($user);

        $payment = Payment::factory()->create(['account_id' => $account->id]);

        $this->assertTrue($payment->account->is($account));
    }
}
