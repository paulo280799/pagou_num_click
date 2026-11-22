<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Definir senha</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700&family=Instrument+Sans:wght@400;500;600&display=swap">
    <style>
        :root{--bg:#F7F7F4;--surface:#fff;--ink:#141413;--muted:#6B6A64;--line:#E8E6DF;--accent:#00B389;--warn:#C1440E}
        @media (prefers-color-scheme:dark){:root{--bg:#101110;--surface:#181A18;--ink:#F0F0EA;--muted:#A3A39A;--line:#2A2D2A;--accent:#19D3A5;--warn:#F08A5D}}
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:grid;place-items:center;background:var(--bg);color:var(--ink);font:16px/1.5 'Instrument Sans','Helvetica Neue',Arial,sans-serif}
        main{width:100%;max-width:400px;margin:20px;background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:28px}
        h1{font:700 1.5rem 'Bricolage Grotesque',sans-serif;letter-spacing:-.02em;margin:0 0 6px}
        p{color:var(--muted);margin:0 0 20px}
        label{display:block;font-weight:600;font-size:.9rem;margin:14px 0 6px}
        input{width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font:inherit}
        input:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
        button{width:100%;margin-top:22px;padding:13px;border:0;border-radius:10px;background:var(--accent);color:#06251C;font:600 1rem inherit;cursor:pointer}
        .err{color:var(--warn);font-size:.85rem;margin-top:6px}
    </style>
</head>
<body>
<main>
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
</main>
</body>
</html>
