<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CardPaymentController;
use App\Livewire\PaymentPage;

Route::get('/checkout/{id}', PaymentPage::class)
    ->name('checkout.show');

Route::post('/checkout/{id}/pay-card', [CardPaymentController::class, 'store'])
    ->middleware('throttle:pay-card')
    ->name('checkout.pay-card');

Route::get('/', fn () => view('landing', [
    'contactUrl' => config('services.landing.contact_url') ?: '#topo',
]))->name('landing');
