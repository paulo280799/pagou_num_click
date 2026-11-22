@extends('invitation.layout')
@section('title', 'Definir senha')
@section('content')
    <h1>Defina sua senha</h1>
    <p>{{ $user->name }}, escolha uma senha para acessar o painel.</p>
    <form method="POST" action="{{ $action }}">
        @csrf
        <label for="password">Senha (mínimo 8 caracteres)</label>
        <input id="password" name="password" type="password" required autocomplete="new-password" autofocus>
        @error('password')<div class="err">{{ $message }}</div>@enderror
        <label for="password_confirmation">Repita a senha</label>
        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        <button type="submit">Entrar</button>
    </form>
@endsection
