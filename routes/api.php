<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CheckoutController;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::middleware(['api.token'])->group(function () {
    Route::post('/create-payment', [CheckoutController::class, 'store']);
});

// Documentação pública da API (Swagger UI + especificação OpenAPI em docs/api/openapi.yaml)
Route::view('/docs', 'api-docs')->name('api.docs');
Route::get('/docs/openapi.yaml', fn () => response(File::get(base_path('docs/api/openapi.yaml')), 200, [
    'Content-Type' => 'application/yaml; charset=UTF-8',
]))->name('api.docs.spec');
