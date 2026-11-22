<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use App\Notifications\AccountInvitation;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // link de convite adulterado ou vencido: tela própria em vez da página de erro padrão (403)
        $this->renderable(function (InvalidSignatureException $e, Request $request) {
            if ($request->routeIs('invitation.*')) {
                return response()->view('invitation.unavailable', [
                    'icon' => '⏳',
                    'title' => 'Link inválido ou expirado',
                    'message' => 'Este link de convite não é válido ou passou do prazo de '.AccountInvitation::VALID_DAYS.' dias. Peça um novo convite a quem administra a plataforma.',
                ], 403);
            }
        });
    }
}
