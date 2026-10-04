# Pagou num Click — Documentação técnica

Gateway/intermediador de pagamentos **PIX** multi-conta (multi-tenant). Um sistema externo (o "cliente" da conta) chama uma API REST para criar uma cobrança; o serviço cria o pedido no provedor de pagamentos, devolve um **link de checkout** com QR Code, acompanha o status por polling e **notifica por webhook** o sistema do cliente quando o pagamento muda de estado. Há também um painel administrativo (Filament) para o dono da conta acompanhar transações.

- **Stack:** PHP ^8.1, Laravel 10, Filament 3.3 (painel), Livewire 3 (checkout), Sanctum, Telescope, Guzzle/HTTP client, Vite + Tailwind 3.
- **Banco:** MySQL (via Sail) — todos os ids são UUID.
- **Provedor de pagamento:** API REST v5 de orders/charges com Basic Auth (formato do Pagar.me/Mundipagg). URL e credenciais vêm do `.env`.
- **Idioma/fuso:** interface em português; `timezone = America/Sao_Paulo`, `locale = en`.

---

## 1. Visão geral do fluxo

```
Sistema do cliente                Pagou num Click                    Provedor (v5 orders)
      │  POST /api/create-payment      │                                     │
      │  Authorization: Bearer <token> │                                     │
      ├───────────────────────────────►│ valida (CheckoutRequest)            │
      │                                │ POST /v5/orders (PIX) ─────────────►│
      │                                │◄──────── id, qr_code, qr_code_url ──┤
      │                                │ grava Payment (PENDING)             │
      │◄── 201 {payment_link} ─────────┤                                     │
      │                                                                      │
Pagador abre /checkout/{id}  (Livewire: QR Code + timer + copia-e-cola)      │
      │                                                                      │
      │        scheduler (a cada 10s)                                        │
      │        app:process-payment ── GET /v5/orders/{ide} ─────────────────►│
      │          PENDING → PAID/FAILED/CANCELED (ou CANCELED se expirou)     │
      │        app:process-webhook                                           │
      │◄── POST notification_url {payment_id: refExternal, status} ──────────┤
      │    header X-Webhook-Secret                                           │
```

---

## 2. Estrutura de pastas relevante

| Caminho | Papel |
|---|---|
| [routes/api.php](routes/api.php) | Única rota de API: `POST /api/create-payment` (middleware `api.token`) |
| [routes/web.php](routes/web.php) | `GET /checkout/{id}` → componente Livewire `PaymentPage` (`throttle:10,1`) |
| [app/Http/Controllers/CheckoutController.php](app/Http/Controllers/CheckoutController.php) | Cria o pedido no provedor e o `Payment` local |
| [app/Http/Controllers/ProcessPaymentController.php](app/Http/Controllers/ProcessPaymentController.php) | Sincroniza status dos pagamentos pendentes |
| [app/Http/Controllers/WebhookController.php](app/Http/Controllers/WebhookController.php) | Envia notificações ao cliente com retry |
| [app/Console/Commands/](app/Console/Commands) | `app:process-payment` e `app:process-webhook` (finos: só chamam os controllers) |
| [app/Console/Kernel.php](app/Console/Kernel.php) | Agenda os dois comandos a cada 10 s e `telescope:prune` diário |
| [app/Services/ApiService.php](app/Services/ApiService.php) | Cliente HTTP do provedor (`create_order`, `get_order`, `close_order`) |
| [app/Http/Middleware/ApiTokenMiddleware.php](app/Http/Middleware/ApiTokenMiddleware.php) | Autenticação da API por token da conta |
| [app/Http/Requests/CheckoutRequest.php](app/Http/Requests/CheckoutRequest.php) | Validação do payload de criação |
| [app/Models/](app/Models) | `Account`, `User`, `Config`, `Payment`, `Scopes/UserScope` |
| [app/Enums/](app/Enums) | `StatusPaymentEnum`, `PaymentMethodEnum`, `TypeTransactionEnum` |
| [app/Livewire/](app/Livewire) | Componentes da página de checkout |
| [app/Filament/](app/Filament) | Painel admin: resources, widgets, página de registro (desativada) |
| [config/integration.php](config/integration.php) | URL/usuário/senha do provedor (env) |
| [database/migrations/2025_*](database/migrations) | Tabelas do domínio |
| `public/` e `public_html/` | Docroots (ver item 15 do §8) |
| `docker/`, `docker-compose.yml` | Laravel Sail (PHP 8.0–8.4, MySQL/MariaDB/PGSQL) |

---

## 3. Modelo de dados

```
accounts 1───N users
accounts 1───N configs      (na prática 1:1, ver Filament)
accounts 1───N payments
```

**accounts** — `id (uuid)`, `name`.
**users** — tabela padrão do Laravel + `account_id` (uuid, FK cascade).
**configs** — `id`, `duration` (segundos de validade do PIX; coluna string), `notification_url`, `redirect_url`, `api_token`, `account_id`.
**payments** — `id` (uuid, usado no link de checkout), `ide` (id do pedido no provedor), `refExternal` (referência do cliente), `qrCode` (URL da imagem), `copyPaste` (PIX copia-e-cola), `amount` (double, em reais), `status`, `payment_method`, `duration`, `expirationDate`, `paymentDate`, `redirect_url`, `notification_url`, `notification_attempts`, `last_notification_attempt`, `is_notified`, `account_id`.

`Payment` copia `duration`, `redirect_url` e `notification_url` da `Config` no momento da criação (snapshot).

### Enums
- `StatusPaymentEnum`: `PENDING`, `PAID`, `FAILED`, `CANCELED` (com label pt-BR, cor e ícone para o Filament).
- `PaymentMethodEnum`: `CREDIT_CARD`, `PIX` (apenas PIX é usado hoje).
- `TypeTransactionEnum`: `INFLOW`/`OUTFLOW` — **não utilizado** no código.

### Multi-tenancy
`Payment` e `Config` usam o global scope [UserScope](app/Models/Scopes/UserScope.php): `where account_id = auth()->user()->account->id`. Consequências:
- Em contexto sem usuário logado (comandos do scheduler, Livewire público) é preciso usar `withoutGlobalScopes()` — e o código faz isso.
- `creating` de `Payment`/`Config` preenche `account_id` a partir do usuário autenticado (em `Payment`, só se ainda não vier preenchido).
- `Account` **não** tem scope.

---

## 4. API pública

### `POST /api/create-payment`
Headers: `Authorization: Bearer <api_token da Config>`.

Corpo (validado por `CheckoutRequest`):
```json
{
  "refExternal": "pedido-123",              // único por conta
  "items": [{ "amount": "10.50", "description": "Camiseta", "quantity": 1 }],
  "customer": {
    "name": "Fulano", "email": "f@x.com",
    "type": "individual",                   // individual | corporation
    "document": "123.456.789-00",           // máscara é removida
    "phones": { "home_phone": { "country_code": "55", "area_code": "11", "number": "999999999" } }
  }
}
```
- `amount` deve casar `^\d+(\.\d{1,2})?$` (reais, ponto decimal) e é convertido para centavos.
- Só `items[0]` é enviado ao provedor; `quantity` é validado mas o envio é sempre `1`.

Processamento (`CheckoutController::store`):
1. Lê a `Config` da conta (`duration`).
2. Calcula `expirationDate` (agora + duration, horário local) e `expires_at` do PIX enviado ao provedor (agora **+3h** + duration — compensa o fuso UTC-3 do Brasil).
3. Chama `ApiService::create_order` com pagamento `pix`.
4. Se o provedor falhar → devolve `{success:false, message, errors}` com o mesmo status HTTP.
5. Cria `Payment` (status vindo do provedor, normalmente `PENDING`), guardando `ide`, `qrCode`, `copyPaste`, `amount`.
6. Responde `201 {"payment_link": "<APP_URL>/checkout/<payment.id>"}`.

### Autenticação — `ApiTokenMiddleware` (alias `api.token`)
Lê `Authorization`, remove `Bearer `, busca a `Config` com esse `api_token` (sem scope), pega **o primeiro `User` da conta** e faz `auth()->loginUsingId(...)`. Isso é o que faz `UserScope`, `Payment::creating` e a regra `unique` do `CheckoutRequest` funcionarem em requisições de API.

### Webhook de saída (para o cliente)
`POST <notification_url>` com header `X-Webhook-Secret: <WEBHOOK_SECRET>` e corpo:
```json
{ "payment_id": "<refExternal>", "status": "PAID" }   // ou FAILED / CANCELED
```
Considera sucesso qualquer 2xx. Detalhes em §5.3.

---

## 5. Processos em segundo plano

O scheduler ([Kernel.php](app/Console/Kernel.php)) roda a cada 10 s (exige `php artisan schedule:work`, ou cron por minuto — Laravel 10 executa as tarefas sub-minuto dentro do minuto).

### 5.1 `app:process-payment` → `ProcessPaymentController::index`
Para cada pagamento `PENDING` (todas as contas):
1. Se `expirationDate < now()` → `CANCELED`.
2. Senão consulta `GET /v5/orders/{ide}`; mapeia `status` para o enum:
   - `PAID` → grava `status` e `paymentDate = now()`;
   - `FAILED`/`CANCELED` → grava `status`;
   - qualquer outro é ignorado.

### 5.2 Checkout público — Livewire
- `PaymentPage` (`/checkout/{id}`): carrega o `Payment` sem scope; `wire:poll.5s="atualizar"` recarrega. Renderiza conforme o status:
  - `PENDING` → `PaymentTimer` + `PaymentStatusPending` (QR Code, valor, copia-e-cola e botão copiar);
  - `PAID` → `PaymentStatusPaid`;
  - `FAILED`/`CANCELED` → `PaymentStatusCanceled`.
- `PaymentTimer`: `wire:poll.1s="checkExpiration"`; ao chegar em zero chama `cancelar()` que marca `CANCELED`.
- O layout [layouts/app.blade.php](resources/views/components/layouts/app.blade.php) traz o CSS inline. `redirect_url` é armazenado mas **não é usado** nas views.

### 5.3 `app:process-webhook` → `WebhookController::index`
Seleciona `is_notified = false` e `status != PENDING`. Para cada um (`attemptNotification`):
- sem `notification_url` → log de warning e segue;
- `notification_attempts >= 5` → log de erro, desiste;
- respeita backoff `[60s, 5min, 10min, 30min, 1h]` a partir de `last_notification_attempt`;
- 2xx → `is_notified = true`; caso contrário incrementa tentativas e registra `last_notification_attempt`.

---

## 6. Painel administrativo (Filament, `/admin`)

Configurado em [AdminPanelProvider](app/Providers/Filament/AdminPanelProvider.php): login habilitado, **registro desativado** (a classe `Register`, que cria `Account` + `User`, está comentada), cor primária âmbar, atalho para `/telescope` no menu do usuário.

- **PaymentResource** (Transações): lista somente-leitura com polling de 5 s, id copiável (copia o link de checkout), valor, status; visualização com taxa de processamento **0,75 %** e total líquido. Header com widgets:
  - `PagamentosStats`: total, pendentes, pagos, cancelados/falhos;
  - `PagamentosResumoStats`: receita líquida (–0,75 %) do dia/semana/mês/ano.
- **AccountResource**: edição do nome da conta (padrão "singleton": `/create` redireciona para `edit` se a conta já existe).
- **ConfigResource**: edição de `duration`, `notification_url`, `redirect_url`; exibe o `api_token` em campo desabilitado. Mesmo padrão singleton (cria a `Config` se não existir; o token é gerado no evento `creating`).

---

## 7. Configuração / variáveis de ambiente

Não há `.env.example` no repositório. Variáveis específicas do projeto, além das padrão do Laravel (`APP_*`, `DB_*`, …):

| Variável | Uso |
|---|---|
| `URL_INTEGRATION` | Base URL do provedor (`{url}/v5/orders`) |
| `USER_INTEGRATION` / `PASSWORD_INTEGRATION` | Basic Auth do provedor |
| `WEBHOOK_SECRET` | Enviado em `X-Webhook-Secret` nos webhooks de saída |
| `APP_URL` | Base do `payment_link` retornado e do link copiado no painel |

Como subir (Sail): `composer install`, criar `.env`, `php artisan key:generate`, `php artisan migrate`, `npm install && npm run build`, `php artisan schedule:work`. Não há seeder: o primeiro `Account` e `User` (com `account_id`) precisam ser criados manualmente (ex.: `tinker`) já que o registro está desativado. A `Config` da conta é criada pelo painel.

---

## 8. Pontos de atenção encontrados na leitura

Observações para revisão, das mais importantes às menores. Itens 5, 7 e 9 já foram corrigidos (ver §9 para o 7; os demais abaixo); os demais seguem em aberto.

**Segurança / integridade**
1. **Cancelamento forjável no checkout** — [PaymentTimer](app/Livewire/PaymentTimer.php): `cancelar()` é método público e `expirationDate` é propriedade pública (adulterável no cliente Livewire). Qualquer pagador pode cancelar a própria cobrança. Pior: o pagamento cancelado localmente deixa de ser consultado pelo `ProcessPaymentController`, então um PIX pago depois **não é reconhecido**.
2. **`AccountResource` sem escopo** — `Account` não tem `UserScope`; um usuário autenticado pode abrir `/admin/accounts/{uuid}/edit` de outra conta se souber o id.
3. **Painel/Telescope** — `User::canAccessPanel()` retorna sempre `true`; o gate do Telescope libera qualquer usuário autenticado; `laravel/telescope` está em `require` (produção). Telescope guarda payloads (incluindo dados de clientes e o token) em produção só se cair nos filtros, mas fica exposto a qualquer login.
4. **`api_token` em texto puro**, comparado direto no banco; o token aparece em claro no painel.
5. ~~**Bug no `Config::boot`** — usa `$model->api_token` (variável inexistente; deveria ser `$item`), então o `empty()` é sempre verdadeiro e sobrescreve qualquer token fornecido.~~ — **corrigido** (`$item->api_token`); o guard agora funciona e só gera token novo quando não há um já definido no model.
6. `ApiTokenMiddleware` quebra (erro 500) se a conta do token não tiver nenhum `User`; e a API sempre atua como "primeiro usuário da conta".
7. ~~Rate limit `throttle` em `/checkout` e no grupo `web` bloqueando clientes legítimos com HTTP 429~~ — **corrigido**, ver §9.

**Lógica**
8. Só o primeiro item é enviado ao provedor; `quantity` ignorada; valores multi-item não são somados.
9. ~~`CheckoutController` faz `$paymentEnum->value`/`$paymentStatusEnum->value` sem tratar `null` (status/método desconhecido do provedor → erro 500 após o pedido já criado no provedor).~~ — **corrigido**: em caso de `status`/`payment_method` não mapeado, cai para `PENDING`/`PIX` e loga warning com o `id` do pedido no provedor para investigação manual.
10. `duration` é `string` em `configs` mas usado como inteiro em `addSeconds`.
11. Sem `withoutOverlapping()` nos comandos de 10 s: se o provedor for lento, execuções se sobrepõem e podem gerar webhooks duplicados. `ProcessPaymentController` faz uma chamada HTTP por pagamento pendente (sem timeout/erro tratado).
12. `ConfigResource::getRecord()` contém um `dd()` esquecido (não é chamado hoje).
13. Taxa 0,75 % fixa e repetida em 3 lugares (infolist e widgets); não há campo de taxa.
14. `ApiService::close_order`, `TypeTransactionEnum` e o layout `filament/pages/auth/register.blade.php` não são usados.

**Repositório**
15. Versionados por engano: `error_log` (log do PHP do hosting), `vite.config.js.tar.gz`, `public_html/` duplicando `public/` (docroot de hospedagem compartilhada), e 19 arquivos `*:Zone.Identifier` em `docker/` (metadados de download do Windows; nome inválido no Windows — ver §10).
16. Testes são apenas os exemplos padrão do Laravel; não há cobertura do fluxo de pagamento.

---

## 9. Investigação: HTTP 429 (rate limit) em clientes legítimos

**Sintoma:** pagadores com a página de checkout aberta recebiam `429 Too Many Requests`; o polling parava e a página "congelava" mesmo após o pagamento.

**Causas encontradas**
1. `'throttle:60,1'` no grupo `web` de [Kernel.php](app/Http/Kernel.php) valia para **todas** as rotas web, inclusive `POST /livewire/update`. O checkout faz polling de 1 s (`payment-timer`) + 5 s (`payment-page`) ≈ 60–72 requisições/min por aba, acima do limite de 60.
2. A rota `/checkout/{id}` tinha ainda `throttle:10,1`. O Laravel calcula a chave do throttle numérico só por domínio + IP (não inclui a rota), então os dois limites compartilhavam **o mesmo contador**: os polls do Livewire consumiam o limite de 10 do GET, e após ~10 s recarregar/abrir o link dava 429. Duas pessoas no mesmo IP (Wi-Fi, CGNAT) também se bloqueavam.
3. `TrustProxies` estava com `$proxies = null`. Com a hospedagem atrás da **Cloudflare**, `$request->ip()` devolvia o IP da borda da Cloudflare, e todos os clientes dividiam um único contador. Isso ainda afeta o limiter `api` (60/min por IP, aplicado antes do `ApiTokenMiddleware`, portanto sempre por IP).

**Correções aplicadas**
- [app/Http/Kernel.php](app/Http/Kernel.php): removido `'throttle:60,1'` do grupo `web`. O grupo `api` e o alias `throttle` foram mantidos.
- [routes/web.php](routes/web.php): removido `->middleware('throttle:10,1')` da rota `checkout.show`.
- [app/Http/Middleware/TrustProxies.php](app/Http/Middleware/TrustProxies.php): `$proxies` agora lista as faixas oficiais da Cloudflare (IPv4 e IPv6). Assim `X-Forwarded-For` só é aceito quando vem da Cloudflare e `$request->ip()` é o IP real do visitante. Não foi usado `'*'` porque permitiria forjar o IP via `X-Forwarded-For`. Lista oficial: <https://www.cloudflare.com/ips> (revisar 1–2 vezes por ano).

**Aplicar em produção**
1. Subir os 3 arquivos e rodar `php artisan optimize:clear` na raiz do projeto (na Hostinger: `~/domains/<dominio>`); sem SSH, apagar `routes-v7.php` e `config.php` em `bootstrap/cache/`.
2. Validar com um `/checkout/{id}` aberto por 1–2 min e recarregando; ou 35 requisições seguidas com `curl` (todas devem ser 200).
3. Para conferir o IP visto pelo Laravel, criar temporariamente `Route::get('/_ip', fn (Request $r) => ['ip' => $r->ip(), 'xff' => $r->header('X-Forwarded-For'), 'remote' => $_SERVER['REMOTE_ADDR'] ?? null]);`. Se `remote` **não** for um IP da Cloudflare (proxy interno da hospedagem), a lista de `$proxies` precisa incluir esse hop. Remover a rota depois.

**Ressalvas**
- `/checkout/{id}` e `/livewire/update` ficam sem limite por IP; o risco é baixo (id é UUID). Se for preciso proteção, aplicar na Cloudflare (regras de rate limiting).
- Sugestão futura: remover o `wire:poll.1s` do timer (fazer a contagem em JavaScript) e parar o polling quando o status deixar de ser `PENDING`.
- O limiter `api` continua por IP; para lojistas com alto volume ou IP de saída compartilhado, considerar chave por `api_token`.

---

## 10. Nota sobre o clone


O `git clone` no Windows falhou no checkout por causa dos 19 arquivos `docker/**/*:Zone.Identifier` (dois-pontos é inválido em nomes NTFS). Os arquivos foram extraídos com `git archive` **excluindo** esses arquivos; o `.git` está completo (histórico: 1 commit, `6f323aa Initial commit`). Por isso `git status` mostra esses 19 como "deleted" — é esperado e inofensivo. Foi definido `core.protectNTFS=false` no `.git/config` local para permitir o `git archive`.
