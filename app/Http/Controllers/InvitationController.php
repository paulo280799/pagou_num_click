<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Aceite do convite: o dono da conta define a senha uma única vez (rotas assinadas, ver routes/web.php). */
class InvitationController extends Controller
{
    private function ensurePending(User $user): void
    {
        abort_if($user->password_set_at !== null, 410, 'Este convite já foi utilizado.');
    }

    public function show(Request $request, User $user): View
    {
        $this->ensurePending($user);

        return view('invitation', ['user' => $user, 'action' => $request->fullUrl()]);
    }

    public function accept(Request $request, User $user): RedirectResponse
    {
        $this->ensurePending($user);

        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);

        $user->forceFill([
            'password' => $data['password'],
            'password_set_at' => now(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/admin');
    }
}
