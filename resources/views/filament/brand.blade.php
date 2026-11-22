{{-- Marca da landing (quadrado verde com miolo vazado) + nome da conta --}}
<span style="display:inline-flex;align-items:center;gap:8px;font:700 1.15rem 'Bricolage Grotesque','Helvetica Neue',Arial,sans-serif;letter-spacing:-.02em">
    <span style="width:22px;height:22px;border-radius:6px;background:#00B389;display:grid;place-items:center">
        <span style="width:8px;height:8px;border-radius:2px;background:#F7F7F4"></span>
    </span>
    {{ auth()->user()?->account?->name ?? config('app.name') }}
</span>
