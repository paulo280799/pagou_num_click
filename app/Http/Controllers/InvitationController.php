<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Aceite do convite: o dono da conta define a senha uma única vez (rotas assinadas, ver routes/web.php). */
class InvitationController extends Controller
{
    /** Convite já usado: tela própria (410), não a página de erro do framework. */
    private function alreadyUsed(): Response
    {
        return response()->view('invitation.unavailable', [
            'icon' => '✅',
            'title' => 'Este convite já foi utilizado',
            'message' => 'A senha desta conta já foi definida. Se você é o responsável, entre no painel com o seu e-mail e a sua senha. Se esqueceu a senha, fale com o suporte.',
            'loginUrl' => url('/admin/login'),
        ], 410);
    }

    public function show(Request $request, User $user): View|Response
    {
        if (! $user->invitation_pending) {
            return $this->alreadyUsed();
        }

        return view('invitation.form', ['user' => $user, 'action' => $request->fullUrl()]);
    }

    public function accept(Request $request, User $user): RedirectResponse|Response
    {
        if (! $user->invitation_pending) {
            return $this->alreadyUsed();
        }

        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);

        $user->forceFill([
            'password' => $data['password'],
            'invitation_pending' => false,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/admin');
    }
}
