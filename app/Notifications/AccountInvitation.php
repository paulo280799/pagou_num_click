<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** Convite para o dono de uma conta nova definir a senha. O link é assinado, expira e só vale enquanto a senha não foi definida. */
class AccountInvitation extends Notification
{
    public const VALID_DAYS = 7;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public static function urlFor(User $user): string
    {
        return URL::temporarySignedRoute('invitation.show', now()->addDays(self::VALID_DAYS), ['user' => $user->id]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Seu acesso ao '.config('app.name'))
            ->greeting('Olá, '.$notifiable->name.'!')
            ->line('Criamos a conta da sua empresa no '.config('app.name').'. Defina a sua senha para entrar no painel, cadastrar as credenciais dos seus gateways e pegar o token da API.')
            ->action('Definir minha senha', self::urlFor($notifiable))
            ->line('O link vale por '.self::VALID_DAYS.' dias e só pode ser usado uma vez. Se você não esperava este e-mail, ignore-o.');
    }
}
