<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CardPaymentController;
use App\Livewire\PaymentPage;

Route::get('/checkout/{id}', PaymentPage::class)
    ->name('checkout.show');

Route::get('/checkout/{id}/card-config', [CardPaymentController::class, 'config'])
    ->middleware('throttle:card-config')
    ->name('checkout.card-config');

Route::post('/checkout/{id}/pay-card', [CardPaymentController::class, 'store'])
    ->middleware('throttle:pay-card')
    ->name('checkout.pay-card');

Route::get('/', fn () => view('landing', [
    'contactUrl' => config('services.landing.contact_url') ?: '#topo',
]))->name('landing');

// Convite do dono da conta (link assinado e de uso único): define a senha e entra no painel
Route::middleware(['signed', 'throttle:10,1'])->group(function () {
    Route::get('/convite/{user}', [\App\Http\Controllers\InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/convite/{user}', [\App\Http\Controllers\InvitationController::class, 'accept'])->name('invitation.accept');
});
