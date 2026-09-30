<?php
use Illuminate\Support\Facades\Route;
use App\Livewire\PaymentPage;

Route::get('/checkout/{id}', PaymentPage::class)
    ->name('checkout.show');
