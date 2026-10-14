# Pagou num Click — Documentação técnica

Gateway/intermediador de pagamentos **PIX e cartão de crédito** multi-conta (multi-tenant). Um sistema externo (o "cliente" da conta) chama uma API REST para criar uma cobrança; o serviço cria o pedido no provedor de pagamentos, devolve um **link de checkout** com QR Code, acompanha o status por polling e **notifica por webhook** o sistema do cliente quando o pagamento muda de estado. Há também um painel administrativo (Filament) para o dono da conta acompanhar transações.

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
Pagador abre /checkout/{id}  (Livewire: abas Pix | Cartão)                  │
      │   Cartão: browser ──► Pagar.me /tokens ──► card_token                │
      │           POST /checkout/{id}/pay-card ── POST /v5/orders (cartão) ─►│
      │           ◄── paid | declined | processing (síncrono)                │
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
**configs** — `id`, `duration` (segundos de validade do PIX; coluna string, com cast inteiro no model), `notification_url`, `redirect_url`, `api_token`, `account_id`.
**payments** — `id` (uuid, usado no link de checkout), `ide` (id do pedido no provedor), `refExternal` (referência do cliente), `qrCode` (URL da imagem), `copyPaste` (PIX copia-e-cola), `amount` (double, em reais), `status`, `payment_method`, `duration`, `expirationDate`, `paymentDate`, `redirect_url`, `notification_url`, `notification_attempts`, `last_notification_attempt`, `is_notified`, `account_id`.
**payments — colunas de cartão** (todas nullable; nunca guardam PAN, CVV, validade, `card_token` nem endereço): `card_order_id` (pedido de cartão `or_...`; `ide` continua sendo o pedido PIX), `card_attempt_key` + `card_attempt_started_at` (tentativa em curso; a chave vira o header `Idempotency-Key`), `card_brand`, `card_last_four`, `installments`, `acquirer_tid`/`acquirer_nsu`/`acquirer_auth_code`, e `customer` (dados do cliente do `POST /api/create-payment`, `encrypted:array`, em `$hidden`; depende de `APP_KEY` estável).

`Payment` copia `duration`, `redirect_url` e `notification_url` da `Config` no momento da criação (snapshot).

### Enums
- `StatusPaymentEnum`: `PENDING`, `PAID`, `FAILED`, `CANCELED` (com label pt-BR, cor e ícone para o Filament).
- `PaymentMethodEnum`: `CREDIT_CARD`, `PIX`. O `Payment` nasce sempre `PIX`; vira `CREDIT_CARD` quando o cartão é aprovado.
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
4b. Se o provedor responder 200 com `charges[0].status = failed` ou sem QR code → `422 {success:false, message, errors}` e **nenhum `Payment` é criado**.
5. Cria `Payment` (status vindo do provedor, normalmente `PENDING`), guardando `ide`, `qrCode`, `copyPaste`, `amount` e `customer` (criptografado).
6. Responde `201 {"payment_link": "<APP_URL>/checkout/<payment.id>"}`.

### `POST /checkout/{id}/pay-card` (pública, usada pela página de checkout)
Rota `web` (CSRF obrigatório, `X-CSRF-TOKEN`), com limiter dedicado `pay-card` (5 tentativas / 10 min por IP + `Payment.id`; o throttle global do grupo `web` continua desligado, ver §9).

Corpo (`PayCardRequest`): `card_token` (`token_...`), `installments` (1..`CARD_MAX_INSTALLMENTS`, sem juros), `billing_address` (`line_1`, `zip_code`, `city`, `state`, `country` de 2 letras). **Não existe campo de valor**: o `amount` vem sempre do `Payment` no banco.

`CardPaymentController::store`, em 3 fases (nenhum lock fica preso durante a chamada externa):
1. **Reservar** (transação com `lockForUpdate`): exige `PENDING`, não expirado e `customer` preenchido; grava `card_attempt_key` (reaproveita a anterior se a tentativa deu timeout) e `card_attempt_started_at`.
2. **Cobrar**: `POST /v5/orders` com `credit_card`, `auth_and_capture`, `Idempotency-Key = card_attempt_key` e timeout (`CARD_HTTP_TIMEOUT`). O `billing_address` é obrigatório no pedido (a Pagar.me não o tokeniza).
3. **Aplicar** (transação com lock): o resultado vem de `charges[0].last_transaction.status` via `ChargeResult`:

| Resultado | Efeito no `Payment` | Resposta |
|---|---|---|
| `captured` | `PAID`, `payment_method = CREDIT_CARD`, dados do cartão e `acquirer_*`, chave limpa | `200 {status:"paid"}` |
| `not_authorized` / `failed` / `with_error` | continua `PENDING`, chave limpa (nova tentativa usa chave nova); o PIX segue pagável | `422 {status:"declined"}` |
| em análise / desconhecido | continua `PENDING`, `card_order_id` gravado, chave mantida (bloqueia reenvio até o polling resolver) | `202 {status:"processing"}` |
| timeout / 5xx | continua `PENDING`, chave mantida; o reenvio depois da janela usa a mesma `Idempotency-Key` e a Pagar.me devolve o mesmo pedido | `503 {status:"processing"}` |

Outros códigos: `409` (não pagável: já pago/falho/cancelado, expirado, sem `customer`, em análise, outra requisição em voo), `404` (id inexistente), `422` (validação), `429` (rate limit). A resposta nunca traz `acquirer_*` nem `gateway_response`. O webhook de saída é o mesmo do PIX (`{payment_id, status}`).

O PIX original **não é encerrado**: a Pagar.me não cancela PIX pendente (ver `docs/pagarme/pix/integration.md`); ele só expira. O polling o vigia (§5.1).

### Autenticação — `ApiTokenMiddleware` (alias `api.token`)
Lê `Authorization`, remove `Bearer `, busca a `Config` com esse `api_token` (sem scope), pega **o primeiro `User` da conta** e faz `auth()->loginUsingId(...)`. Conta sem nenhum `User` → `401`. Isso é o que faz `UserScope`, `Payment::creating` e a regra `unique` do `CheckoutRequest` funcionarem em requisições de API.

### Webhook de saída (para o cliente)
`POST <notification_url>` com header `X-Webhook-Secret: <WEBHOOK_SECRET>` e corpo:
```json
{ "payment_id": "<refExternal>", "status": "PAID" }   // ou FAILED / CANCELED
```
Considera sucesso qualquer 2xx. Detalhes em §5.3.

---

## 5. Processos em segundo plano

O scheduler ([Kernel.php](app/Console/Kernel.php)) roda a cada 10 s (exige `php artisan schedule:work`, ou cron por minuto — Laravel 10 executa as tarefas sub-minuto dentro do minuto).

Os dois comandos usam `withoutOverlapping(1)` (lock expira em 1 min).

### 5.1 `app:process-payment` → `ProcessPaymentController::index`
Para cada pagamento `PENDING` (todas as contas), com `ConnectionException` capturada **por pagamento** (provedor fora do ar não trava os demais):
1. **Cartão primeiro**, se há `card_order_id` ou `card_attempt_key`: com `card_order_id` consulta `GET /v5/orders/{card_order_id}`; sem ele (timeout) e depois da janela de `CARD_HTTP_TIMEOUT + 5 s`, procura `GET /v5/orders?code={Payment.id}` filtrando `metadata.payment_id`. `ChargeResult` decide: `PAID` → `markCardPaid`; `DECLINED` → libera a tentativa; em análise → mantém e loga warning; pedido não encontrado → limpa a chave. Cartão em análise **não é cancelado por expiração**.
2. Se `expirationDate < now()` → `CANCELED`.
3. Senão consulta `GET /v5/orders/{ide}` (PIX); mapeia `status` para o enum:
   - `PAID` → grava `status` e `paymentDate = now()`;
   - `FAILED`/`CANCELED` → grava `status`;
   - qualquer outro é ignorado.
4. **Vigilância do PIX após cartão aprovado:** para `PAID` com `payment_method = CREDIT_CARD` até `expirationDate + 5 min`, consulta `ide`; se o PIX aparecer pago, `Log::error` com `Payment.id` e `ide` para estorno manual (não há estorno automático).

### 5.2 Checkout público — Livewire
- `PaymentPage` (`/checkout/{id}`): carrega o `Payment` sem scope; `wire:poll.5s="atualizar"` recarrega. Renderiza conforme o status:
  - `PENDING` → `PaymentTimer` + `PaymentStatusPending` (abas **Pix | Cartão**; PIX com QR Code, valor e copia-e-cola);
  - `PAID` → `PaymentStatusPaid` (texto e dados por método: PIX ou cartão com marca, final e parcelas; botão "Voltar à loja" se houver `redirect_url`);
  - `FAILED`/`CANCELED` → `PaymentStatusCanceled` (textos distintos).
  O `wire:poll.5s` só existe enquanto `PENDING`.
- `PaymentTimer`: countdown em JS no browser; ao zerar chama `checkExpiration()`, que relê a expiração **do banco** (sem propriedade pública nem método `cancelar()`).
- **Aba Cartão** (`PaymentStatusPending` + `partials/card-form.blade.php` + `resources/js/card-form.js`): só aparece com `STONE_PUBLIC_KEY_INTEGRATION` configurada, `Payment` `PENDING` e `customer` preenchido (pagamentos antigos mostram só PIX). Formulário em **Alpine puro dentro de `wire:ignore`**, sem `wire:model`: dados do cartão nunca passam pelo Livewire nem pelo Laravel. Fluxo: valida no browser (Luhn, validade, CVV, endereço) → `POST https://api.pagar.me/core/v5/tokens?appId={public_key}` → zera número e CVV → `POST /checkout/{id}/pay-card` com `X-CSRF-TOKEN`. CEP autocompleta rua/cidade/UF via ViaCEP (só o CEP sai do browser; falha é silenciosa). Qualquer tentativa sem resultado definitivo (`card_attempt_key` preenchida) mostra "Pagamento em análise" e bloqueia o formulário até o polling resolver; o componente dá `wire:poll.5s` para o aviso sumir sozinho.
- O layout [layouts/app.blade.php](resources/views/components/layouts/app.blade.php) usa Tailwind via Vite (`app.css` + `app.js`) e a meta `csrf-token`.

### 5.3 `app:process-webhook` → `WebhookController::index`
Seleciona `is_notified = false` e `status != PENDING`. Para cada um (`attemptNotification`):
- sem `notification_url` → log de warning e segue;
- `notification_attempts >= 5` → log de erro, desiste;
- respeita backoff `[60s, 5min, 10min, 30min, 1h]` a partir de `last_notification_attempt`;
- chamada com `timeout(10)`; `ConnectionException` é capturada por pagamento (conta a tentativa e segue o backoff), então uma URL inalcançável não bloqueia os demais;
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
| `STONE_PUBLIC_KEY_INTEGRATION` | Public key (`pk_...`) exposta ao browser para tokenizar o cartão. Precisa ser do **mesmo ambiente** (teste/produção) de `USER_INTEGRATION`. Sem ela a aba de cartão não aparece |
| `CARD_MAX_INSTALLMENTS` | Máximo de parcelas sem juros (padrão 12) |
| `CARD_STATEMENT_DESCRIPTOR` | Texto na fatura, até 13 caracteres (padrão `PAGOUNUMCLICK`) |
| `CARD_HTTP_TIMEOUT` | Timeout (s) das chamadas ao provedor no fluxo de cartão e no polling (padrão 15) |
| `APP_URL` | Base do `payment_link` retornado e do link copiado no painel |

Como subir (Sail): `composer install`, criar `.env`, `php artisan key:generate`, `php artisan migrate`, `npm install && npm run build`, `php artisan schedule:work`. Não há seeder: o primeiro `Account` e `User` (com `account_id`) precisam ser criados manualmente (ex.: `tinker`) já que o registro está desativado. A `Config` da conta é criada pelo painel.

---

## 8. Pontos de atenção encontrados na leitura

Observações para revisão, das mais importantes às menores. Itens 5, 6, 7, 9, 10, 12 e 16 já foram corrigidos (ver §9 para o 7); os demais seguem em aberto.

**Segurança / integridade**
1. **Cancelamento forjável no checkout** — [PaymentTimer](app/Livewire/PaymentTimer.php): `cancelar()` é método público e `expirationDate` é propriedade pública (adulterável no cliente Livewire). Qualquer pagador pode cancelar a própria cobrança. Pior: o pagamento cancelado localmente deixa de ser consultado pelo `ProcessPaymentController`, então um PIX pago depois **não é reconhecido**.
2. **`AccountResource` sem escopo** — `Account` não tem `UserScope`; um usuário autenticado pode abrir `/admin/accounts/{uuid}/edit` de outra conta se souber o id.
3. **Painel/Telescope** — `User::canAccessPanel()` retorna sempre `true`; o gate do Telescope libera qualquer usuário autenticado; `laravel/telescope` está em `require` (produção). Telescope guarda payloads (incluindo dados de clientes e o token) em produção só se cair nos filtros, mas fica exposto a qualquer login.
4. **`api_token` em texto puro**, comparado direto no banco; o token aparece em claro no painel.
5. ~~**Bug no `Config::boot`** — usa `$model->api_token` (variável inexistente; deveria ser `$item`), então o `empty()` é sempre verdadeiro e sobrescreve qualquer token fornecido.~~ — **corrigido** (`$item->api_token`); o guard agora funciona e só gera token novo quando não há um já definido no model.
6. ~~`ApiTokenMiddleware` quebra (erro 500) se a conta do token não tiver nenhum `User`~~ — **corrigido** (responde 401); a API ainda atua sempre como "primeiro usuário da conta".
7. ~~Rate limit `throttle` em `/checkout` e no grupo `web` bloqueando clientes legítimos com HTTP 429~~ — **corrigido**, ver §9.

**Lógica**
8. Só o primeiro item é enviado ao provedor; `quantity` ignorada; valores multi-item não são somados.
9. ~~`CheckoutController` faz `$paymentEnum->value`/`$paymentStatusEnum->value` sem tratar `null` (status/método desconhecido do provedor → erro 500 após o pedido já criado no provedor).~~ — **corrigido**: em caso de `status`/`payment_method` não mapeado, cai para `PENDING`/`PIX` e loga warning com o `id` do pedido no provedor para investigação manual.
10. ~~`duration` é `string` em `configs` mas usado como inteiro em `addSeconds`.~~ — **corrigido**: cast `integer` em `Config` (a coluna continua string).
11. ~~Sem `withoutOverlapping()` nos comandos de 10 s~~ — **corrigido** (`withoutOverlapping(1)`). `ProcessPaymentController` agora usa timeout e captura `ConnectionException` por pagamento.
12. ~~`ConfigResource::getRecord()` contém um `dd()` esquecido.~~ — **removido**.
13. Taxa 0,75 % fixa e repetida em 3 lugares (infolist e widgets); não há campo de taxa.
14. `ApiService::close_order` (comprovadamente não cancela PIX pendente), `TypeTransactionEnum` e o layout `filament/pages/auth/register.blade.php` não são usados.

**Repositório**
15. Versionados por engano: `error_log` (log do PHP do hosting), `vite.config.js.tar.gz`, `public_html/` duplicando `public/` (docroot de hospedagem compartilhada), e 19 arquivos `*:Zone.Identifier` em `docker/` (metadados de download do Windows; nome inválido no Windows — ver §10).
16. ~~Testes são apenas os exemplos padrão do Laravel.~~ — **corrigido**: há cobertura do fluxo PIX, cartão, polling, webhook, middleware e checkout (suíte de 149 testes).

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
