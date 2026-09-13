<?php

namespace App\Filament\Pages\Auth;


use Filament\Forms\Components\TextInput;
use Filament\Pages\Auth\Register as BaseRegister;
use Filament\Forms\Form;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class Register extends BaseRegister
{

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Wizard::make([
                    Step::make('Seus dados')->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),
                    Step::make('Empresa')->schema([
                        TextInput::make('company_name')
                            ->label('Nome da empresa')
                            ->required(),
                    ]),
                ]),
            ]);
    }

    public function handleRegistration(array $data): User
    {
        $account = Account::create([
            'name' => $data['company_name'],
        ]);

        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'account_id' => $account->id,
        ]);
    }
}
