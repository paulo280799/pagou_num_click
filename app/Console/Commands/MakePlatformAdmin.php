<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Promove (ou cria) um super-admin da plataforma. `is_super_admin` não é mass-assignable: só entra por aqui. */
class MakePlatformAdmin extends Command
{
    protected $signature = 'platform:make-admin {email} {--name=Administrador} {--password= : senha (se omitida, gera uma e mostra)}';

    protected $description = 'Torna um usuário super-admin da plataforma (acesso ao painel /platform)';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();
        $password = null;

        if (! $user) {
            $account = Account::firstOrCreate(['name' => 'Plataforma']);
            $password = $this->option('password') ?: Str::password(16);
            $user = User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'account_id' => $account->id]);
            $user->forceFill(['email_verified_at' => now()]);
        }

        $user->forceFill(['is_super_admin' => true])->save();

        $this->info("{$email} é super-admin (painel em /platform).");
        if ($password !== null && ! $this->option('password')) {
            $this->warn("Usuário criado com a senha: {$password}");
        }

        return self::SUCCESS;
    }
}
