@extends('invitation.layout')
@section('title', $title)
@section('content')
    <div class="icon" aria-hidden="true">{{ $icon ?? '🔒' }}</div>
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
    @isset($loginUrl)
        <a class="btn" href="{{ $loginUrl }}">Ir para o login</a>
    @endisset
@endsection
