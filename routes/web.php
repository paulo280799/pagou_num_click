<?php
use Illuminate\Support\Facades\Route;
use App\Livewire\PaymentPage;

Route::get('/checkout/{id}', PaymentPage::class)
    ->name('checkout.show');

Route::get('/', fn () => view('landing', [
    'contactUrl' => config('services.landing.contact_url') ?: '#topo',
]))->name('landing');
