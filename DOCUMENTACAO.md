# Pagou num Click — Documentação técnica

Gateway/intermediador de pagamentos **PIX e cartão de crédito** multi-conta (multi-tenant). Um sistema externo (o "cliente" da conta) chama uma API REST para criar uma cobrança; o serviço cria o pedido no provedor de pagamentos, devolve um **link de checkout** com QR Code, acompanha o status por polling e **notifica por webhook** o sistema do cliente quando o pagamento muda de estado. Há também um painel administrativo (Filament) para o dono da conta acompanhar transações.

- **Stack:** PHP ^8.1, Laravel 10, Filament 3.3 (painel), Livewire 3 (checkout), Sanctum, Telescope, Guzzle/HTTP client, Vite + Tailwind 3.
- **Banco:** MySQL (via Sail) — todos os ids são UUID.
- **Provedores de pagamento:** **Pagar.me** (primário; API REST v5 de orders/charges com Basic Auth, URL e credenciais no `.env`) e **Mercado Pago** (redundância, Orders API `/v1/orders`; só entra quando o Pagar.me falha e há `MP_ACCESS_TOKEN`). Ver §4 (failover), §5.1 (polling por gateway) e §7.
- **Idioma/fuso:** interface em português; `timezone = America/Sao_Paulo`, `locale = en`.

---

## 1. Visão geral do fluxo

```
Sistema do cliente                Pagou num Click                    Provedor (v5 orders)
      │  POST /api/create-payment      │                                     │
      │  Authorization: Bearer <token> │                                     │
      ├───────────────────────────────►│ valida (CheckoutRequest)            │
      │                                │ GatewayManager → Pagar.me           │
      │                                │ POST /v5/orders (PIX) ─────────────►│
      │                                │◄──────── id, qr_code, qr_code_url ──┤
      │                                │ (Pagar.me fora → Mercado Pago:      │
      │                                │  POST /v1/orders, Orders API) │
      │                                │ grava Payment (PENDING)             │
      │◄── 201 {payment_link, gateway} ┤                                     │
      │                                                                      │
Pagador abre /checkout/{id}  (Livewire: abas Pix | Cartão)                  │
      │   Cartão: GET card-config → browser ──► /tokens (Pagar.me ou MP)     │
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
| [app/Services/Gateways/](app/Services/Gateways) | `PaymentGateway` (interface), `PagarmeGateway`, `MercadoPagoGateway`, `GatewayManager` (escolha + failover + breaker), `CircuitBreaker`, `PagarmeStatusMap`/`MercadoPagoStatusMap` |
| [app/Support/Gateways/](app/Support/Gateways) | DTOs (`PixRequest`, `CardRequest`, `PixCharge`, `NormalizedStatus`, `CardCheckoutConfig`) e exceções (`GatewayUnavailableException` = infra, `GatewayRequestException` = 4xx) |
| [app/Http/Middleware/ApiTokenMiddleware.php](app/Http/Middleware/ApiTokenMiddleware.php) | Autenticação da API por token da conta |
| [app/Http/Requests/CheckoutRequest.php](app/Http/Requests/CheckoutRequest.php) | Validação do payload de criação |
| [app/Models/](app/Models) | `Account`, `User`, `Config`, `Payment`, `Scopes/UserScope` |
| [app/Enums/](app/Enums) | `StatusPaymentEnum`, `PaymentMethodEnum`, `GatewayEnum`, `TypeTransactionEnum` |
| [app/Livewire/](app/Livewire) | Componentes da página de checkout |
| [app/Filament/](app/Filament) | Painel admin: resources, widgets, página de registro (desativada) |
| [config/integration.php](config/integration.php) | URL/usuário/senha/chave pública do Pagar.me (env, nomes inalterados) |
| [config/gateways.php](config/gateways.php) | Mercado Pago (`MP_*`), circuit breaker, timeout de criação e janela de graça |
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

**gateway_credentials** — credenciais dos provedores **por conta**: `id (uuid)`, `account_id`, `gateway` (`PAGARME`/`MERCADOPAGO`), `credentials` (`encrypted:array` com `APP_KEY`: Pagar.me `user`/`password`/`public_key`; Mercado Pago `access_token`/`public_key`/`webhook_secret`; oculto na serialização), `enabled`, `last_validated_at`, `last_validation_error`; único por (`account_id`, `gateway`); `UserScope`. Resolvidas por `GatewayCredentialResolver` (linha ativa → senão `.env` só para a conta de `GATEWAYS_ENV_ACCOUNT_ID` → senão o gateway não existe para a conta).

**accounts** — `id (uuid)`, `name`, `legal_name`, `document` (CPF/CNPJ, só dígitos), `contact_email`, `phone` (só dígitos); os quatro últimos são opcionais.
**users** — tabela padrão do Laravel + `account_id` (uuid, FK cascade).
**configs** — `id`, `duration` (segundos de validade do PIX; coluna string, com cast inteiro no model), `notification_url`, `redirect_url`, `card_installments_enabled` (bool, padrão `true`), `pix_fee_percent` e `card_fee_percent_1x`/`_2_6x`/`_7_12x` (tarifas do Pagar.me, ver §6), `mp_pix_fee_percent` e `mp_card_fee_percent_1x`/`_2_6x`/`_7_12x` (tarifas do Mercado Pago, nullable = não configurada), `card_max_installments` (1–12, padrão 12; ambos editáveis no painel, seção "Cartão de crédito"), `api_token`, `account_id`.
**payments** — `id` (uuid, usado no link de checkout), `ide` (id do pedido PIX no provedor; `NULL` em checkout só-cartão), `refExternal` (referência do cliente), `payment_methods` (JSON `["pix","credit_card"]`, meios habilitados no checkout; `NULL` = legado, aceita ambos), `pix_gateway` (`PAGARME`/`MERCADOPAGO`: quem criou o PIX; `NULL` em só-cartão), `qrCode` (`text`: URL da imagem no Pagar.me, `data:image/png;base64,...` no Mercado Pago; `NULL` em só-cartão), `copyPaste` (`text`; `NULL` em só-cartão), `amount` (double, em reais), `status`, `payment_method`, `duration`, `expirationDate`, `paymentDate`, `redirect_url`, `notification_url`, `notification_attempts`, `last_notification_attempt`, `is_notified`, `account_id`.
**payments — colunas de gateway/auditoria** (nullable): `pix_gateway`, `card_gateway` (gateway da tentativa de cartão, gravado **pelo servidor** na fase 1 do `pay-card`), `provider_status` e `provider_status_detail` (status cru do provedor; o polling só loga quando eles mudam). A migration preenche `PAGARME` onde já havia `ide`/`card_order_id`/`card_attempt_key`; linhas sem valor são tratadas como Pagar.me no roteamento.
**payments — colunas de cartão** (todas nullable; nunca guardam PAN, CVV, validade, `card_token` nem endereço): `card_order_id` (pedido de cartão `or_...` no Pagar.me, id numérico do pagamento no MP; `ide` continua sendo o pedido PIX), `card_attempt_key` + `card_attempt_started_at` (tentativa em curso; a chave vira o header `Idempotency-Key`), `card_brand`, `card_last_four`, `installments`, `fee_percent`/`fee_amount` (tarifa aplicada na confirmação do pagamento; `NULL` = legado ou tarifa não configurada), `max_installments` (snapshot de parcelas máximas da `Config` na criação; 1 = à vista; `NULL` = legado, teto 12), `acquirer_tid`/`acquirer_nsu`/`acquirer_auth_code`, e `customer` (dados do cliente do `POST /api/create-payment`, `encrypted:array`, em `$hidden`; depende de `APP_KEY` estável).

`Payment` copia `duration`, `redirect_url` e `notification_url` da `Config` no momento da criação (snapshot).

### Enums
- `StatusPaymentEnum`: `PENDING`, `PAID`, `FAILED`, `CANCELED` (com label pt-BR, cor e ícone para o Filament).
- `PaymentMethodEnum`: `CREDIT_CARD`, `PIX`. O `Payment` nasce sempre `PIX`; vira `CREDIT_CARD` quando o cartão é aprovado.
- `GatewayEnum`: `PAGARME`, `MERCADOPAGO`. Dimensão independente do meio. O accessor `Payment::gateway` (painel, relatório, tarifa) devolve o gateway que **liquidou** a venda (cartão se pago no cartão, senão PIX); **nunca** é usado para rotear chamada — `pixGatewayOwner()` (dono do `ide`) e `cardGatewayOwner()` (dono do `card_order_id`/`card_attempt_key`) fazem isso.
- `TypeTransactionEnum`: `INFLOW`/`OUTFLOW` — **não utilizado** no código.

### Multi-tenancy
`Payment` e `Config` usam o global scope [UserScope](app/Models/Scopes/UserScope.php), que filtra por `account_id` do tenant atual, resolvido por [TenantContext](app/Support/TenantContext.php) (`scoped` no container): conta definida explicitamente (API, pelo token) ou, na falta dela, a do usuário logado (painel). Consequências:
- **Sem tenant o scope falha fechado** (`1 = 0`, nada é devolvido; antes dava 500). Comandos do scheduler e o checkout público continuam usando `withoutGlobalScopes()` — e o código faz isso.
- `creating` de `Payment`/`Config` preenche `account_id` com o tenant atual (só se ainda não vier preenchido); sem tenant lança `LogicException`.
- Plano de evolução (credenciais por tenant, token com hash, convite): [docs/multi-tenant-plano.md](docs/multi-tenant-plano.md).
- `Account` **não** tem scope.

---

## 4. API pública

### `POST /api/create-payment`
> Documentação completa do endpoint em [docs/api/create-payment.md](docs/api/create-payment.md); spec OpenAPI em [docs/api/openapi.yaml](docs/api/openapi.yaml), com Swagger UI em `GET /api/docs`.

Headers: `Authorization: Bearer <api_token da Config>`.

Corpo (validado por `CheckoutRequest`; erro de validação é sempre JSON 422):
```json
{
  "refExternal": "pedido-123",              // único por conta, até 255
  "payment_methods": ["pix", "credit_card"],// opcional; pix | credit_card | ambos (padrão: ambos)
  "items": [{ "amount": "10.50", "description": "Camiseta", "quantity": 1 }],
  "customer": {
    "name": "Fulano", "email": "f@x.com",
    "type": "individual",                   // individual (CPF, 11 díg.) | corporation (CNPJ, 14 díg.)
    "document": "123.456.789-09",           // máscara é removida
    "phones": { "home_phone": { "country_code": "55", "area_code": "11", "number": "999999999" } }
  }
}
```
- `amount`: preço unitário em reais, `^\d{1,9}(\.\d{1,2})?$`, mínimo `0.01`. O total é a soma de `amount × quantity` de todos os `items` (até 50).
- `payment_methods` é gravado em `payments.payment_methods` (JSON; `null` em pagamentos legados = PIX + cartão). `["pix"]`: checkout só com Pix, `pay-card` responde 409. `["credit_card"]`: **nenhum pedido PIX** é criado (`ide`/`qrCode`/`copyPaste` ficam `NULL`), o checkout só mostra o formulário de cartão e o polling apenas cancela por expiração. `credit_card` pedido explicitamente sem chave pública em **nenhum** gateway (`STONE_PUBLIC_KEY_INTEGRATION` ou `MP_PUBLIC_KEY` com Mercado Pago ativo) → 422.

Processamento (`CheckoutController::store`):
1. Resolve `payment_methods` (padrão ambos). Cartão explícito sem chave pública → `422 {success:false, errors.payment_methods}`.
2. Lê a `Config` da conta (`duration`) e calcula `expirationDate` (agora + duration, horário local).
3. **Só cartão**: cria o `Payment` (`PENDING`, `payment_method = CREDIT_CARD`, `customer` criptografado) sem chamar o provedor e responde 201.
4. **Com Pix**: gera o UUID do `Payment` **antes** da chamada (vira `code` + `metadata.payment_id` no Pagar.me e `external_reference` + `X-Idempotency-Key` no MP) e chama `GatewayManager::createPix`. O Pagar.me é o primeiro (timeout curto, `GATEWAY_CREATE_TIMEOUT`, padrão 5 s; `expires_at` em UTC); se estiver com o circuit breaker aberto, o Mercado Pago vai primeiro.
5. **Failover:** `ConnectionException`/timeout, HTTP 5xx, 429 ou HTTP 200 com `charges[0].status = failed`/sem QR → tenta o Mercado Pago (se `MP_ACCESS_TOKEN` configurado). 4xx de validação (400/422) **não** aciona failover: devolve `{success:false, message, errors, gateway}` com o status do provedor. Sem Mercado Pago, a falha do Pagar.me sobe com o status e o corpo de sempre (200+`failed` → 422).
5b. Os dois gateways fora → `503 {success:false, message:"Erro ao processar pagamento", errors, gateway}` e **nenhum `Payment` é criado**.
6. Cria `Payment` (status vindo do provedor, normalmente `PENDING`), guardando `ide`, `pix_gateway`, `qrCode`, `copyPaste`, `amount` e `customer` (criptografado). No MP, `expirationDate` = `date_of_expiration` enviado (`max(duration, 1800 s)`, o MP exige 30 min): o timer, o `409` do `pay-card` e o polling seguem a validade real do QR.
7. Responde `201 {"payment_link": "<APP_URL>/checkout/<payment.id>", "gateway": "pagarme"|"mercadopago"}` (`gateway` ausente em checkout só-cartão, que não chama o provedor).

### `POST /checkout/{id}/pay-card` (pública, usada pela página de checkout)
Rota `web` (CSRF obrigatório, `X-CSRF-TOKEN`), com limiter dedicado `pay-card` (5 tentativas / 10 min por IP + `Payment.id`; o throttle global do grupo `web` continua desligado, ver §9).

Corpo (`PayCardRequest`), condicional ao `gateway` (`pagarme`, padrão se ausente, ou `mercadopago`) com que o **browser** tokenizou:
- `pagarme`: `card_token` (`token_...`), `installments` (1..limite do `Payment`, sem juros; snapshot `payments.max_installments`), `billing_address` (`line_1`, `zip_code`, `city`, `state`, `country` de 2 letras).
- `mercadopago`: `card_token` (alfanumérico 16–64), `payment_method_id` (`visa`, `master`...), `issuer_id` (opcional), `installments` = 1 (só à vista até o spike S4), **sem** endereço de cobrança.

**Não existe campo de valor**: o `amount` vem sempre do `Payment` no banco. O token só vale no gateway que o gerou: antes de reservar a tentativa o backend confere `GatewayManager::isCardGatewayStillCurrent(gateway)`; divergiu → `503 {status:"fallback", gateway, publicKey, tokenUrl, maxInstallments}` **sem cobrar nada** e o browser re-tokeniza.

`CardPaymentController::store`, em 3 fases (nenhum lock fica preso durante a chamada externa):
1. **Reservar** (transação com `lockForUpdate`): exige `PENDING`, não expirado e `customer` preenchido; grava `card_attempt_key` (reaproveita a anterior se a tentativa deu timeout), `card_attempt_started_at` e `card_gateway`. No máximo **uma** tentativa ativa por `Payment`, somando os gateways: tentativa pendente de outro gateway → `409` até o polling resolvê-la.
2. **Cobrar** no gateway do payload: Pagar.me `POST /v5/orders` com `credit_card`, `auth_and_capture`, `Idempotency-Key = card_attempt_key` e timeout (`CARD_HTTP_TIMEOUT`), `billing_address` obrigatório; Mercado Pago `POST /v1/payments` com `X-Idempotency-Key = card_attempt_key`, `external_reference = Payment.id`, 1x. O resultado vem de `ChargeResult` (Pagar.me: `charges[0].last_transaction.status`; MP: `status`/`status_detail`). Se `transaction_details.total_paid_amount` do MP divergir do `Payment.amount`, loga `error`.
3. **Aplicar** (transação com lock): o resultado vem de `charges[0].last_transaction.status` via `ChargeResult`:

| Resultado | Efeito no `Payment` | Resposta |
|---|---|---|
| `captured` | `PAID`, `payment_method = CREDIT_CARD`, dados do cartão e `acquirer_*`, chave limpa | `200 {status:"paid"}` |
| `not_authorized` / `failed` / `with_error` | continua `PENDING`, chave limpa (nova tentativa usa chave nova); o PIX segue pagável | `422 {status:"declined"}` |
| em análise / desconhecido | continua `PENDING`, `card_order_id` gravado, chave mantida (bloqueia reenvio até o polling resolver) | `202 {status:"processing"}` |
| timeout / 5xx | continua `PENDING`, chave mantida; o reenvio depois da janela usa a mesma `Idempotency-Key` e a Pagar.me devolve o mesmo pedido | `503 {status:"processing"}` |
| 4xx do MP (token inválido/expirado) | MP não criou o pagamento: tratado como recusa, chave limpa | `422 {status:"declined"}` |
| Pagar.me fora **e** reconciliação (`GET /v5/orders?code=`) prova que não criou cobrança, com MP configurado | chave limpa (tentativa do Pagar.me encerrada), falha alimenta o breaker | `503 {status:"fallback", gateway:"mercadopago", publicKey}`; o browser re-tokeniza no MP e reenvia (uma vez) |
| Pagar.me fora e reconciliação achou a cobrança | aplica o resultado dela | conforme o resultado |
| Pagar.me fora e reconciliação inconclusiva | igual ao timeout (em dúvida, "processando"; duplicar cobrança é pior que falhar) | `503 {status:"processing"}` |

Recusa do emissor **nunca** aciona failover. Outros códigos: `409` (não pagável: já pago/falho/cancelado, expirado, sem `customer`, em análise, outra requisição em voo), `404` (id inexistente), `422` (validação), `429` (rate limit). A resposta nunca traz `acquirer_*` nem `gateway_response`. O webhook de saída é o mesmo do PIX (`{payment_id, status}`).

O PIX original **não é encerrado**: a Pagar.me não cancela PIX pendente (ver `docs/pagarme/pix/integration.md`); ele só expira. O polling o vigia (§5.1). (`MercadoPagoGateway::cancelPix` existe, mas não é usado em fluxo até o spike S5 confirmar o cancelamento.)

### `GET /checkout/{id}/card-config` (pública, usada pelo `card-form.js` no submit)
Rota `web` com limiter `card-config` (30/min por IP + `Payment.id`). Devolve `{gateway, publicKey, tokenUrl, maxInstallments}` do gateway **vigente agora** (`GatewayManager::cardCheckoutConfig()`: Pagar.me, ou MP com o breaker aberto/sem chave do Pagar.me). Só responde para `Payment` `PENDING`, não expirado, com `customer` e cartão habilitado; senão `409`. Só a chave pública sai (nunca `MP_ACCESS_TOKEN`). Existe porque o formulário vive em `wire:ignore` e o `cardConfig` do render congela: sem buscar no submit, uma página aberta durante a queda continuaria tokenizando no gateway errado.

### Webhook de entrada do Mercado Pago — `POST /api/webhooks/mercadopago/{account?}`
Cada conta cadastra no painel do próprio MP a URL com o seu id (mostrada na página *Gateways*) e o segredo no sistema. A assinatura `x-signature` é validada com o segredo **da conta da URL** e só pagamentos dela são reavaliados. A URL antiga, sem `{account}`, vale para a conta de `GATEWAYS_ENV_ACCOUNT_ID` (`MP_WEBHOOK_SECRET`); sem conta configurada recusa tudo (401).

### Autenticação — `ApiTokenMiddleware` (alias `api.token`)
Lê `Authorization`, remove `Bearer `, calcula o SHA-256 e busca a `Config` por `api_token_hash` (ou pelo token anterior, enquanto a janela de rotação não expirou; `Config::findByToken`, sem scope) e define a conta no `TenantContext` — **nenhum `User` é logado** (antes pegava o primeiro `User` da conta). Token ausente/desconhecido → `401`. O `TenantContext` é o que faz `UserScope`, `Payment::creating` e a regra `unique` do `CheckoutRequest` funcionarem em requisições de API.

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
Para cada pagamento `PENDING` (todas as contas), com `GatewayUnavailableException` capturada **por pagamento** (provedor fora do ar não trava os demais). **Roteia sempre pelo dono do id externo** via `GatewayManager::pixStatus()/cardStatus()/findCardAttempt()`: `pix_gateway` para o `ide`, `card_gateway` para `card_order_id`/`card_attempt_key` (nulo = Pagar.me), nunca pelo accessor `gateway`. Cada gateway traduz o status cru na borda (`PagarmeStatusMap`/`MercadoPagoStatusMap` → `NormalizedStatus`/`ChargeOutcome`); o controller nunca lê status de provedor.
1. **Cartão primeiro**, se há `card_order_id` ou `card_attempt_key`: com `card_order_id` consulta o pedido/pagamento; sem ele (timeout) e depois da janela de `CARD_HTTP_TIMEOUT + 5 s`, reconcilia por referência (Pagar.me: `GET /v5/orders?code=` filtrando `metadata.payment_id`; MP: `GET /v1/payments/search?external_reference=`, só pagamentos `credit_card` — o PIX do mesmo `Payment` tem a mesma referência). `ChargeResult` decide: `PAID` → `markCardPaid`; `DECLINED` → libera a tentativa (cartão **nunca** leva o `Payment` a `FAILED`); em análise → mantém; não encontrado → limpa a chave; busca inconclusiva → mantém. Cartão em análise **não é cancelado por expiração**.
2. Se `expirationDate < now()`: **antes de cancelar consulta o gateway** (PIX pago no último segundo, ou durante uma queda, não pode virar `CANCELED`). `PAID` → `PAID`; gateway indisponível → adia por uma janela de graça de `GATEWAY_EXPIRY_GRACE_MINUTES` (15) depois do vencimento, e só então cancela; senão `CANCELED`. Sem `ide` (só-cartão) cancela direto.
3. Senão consulta o PIX no gateway dono do `ide`:
   - `PAID` → grava `status`, `paymentDate = now()` e a tarifa **do gateway que processou** (§6);
   - `FAILED`/`CANCELED` → grava `status`;
   - `PENDING`, estorno/chargeback e status desconhecidos não mudam o `Payment`.
   O status cru vai para `provider_status`/`provider_status_detail` e **só é logado quando muda** (o polling roda a cada 10 s): desconhecido → `warning`; `refunded`/`pending_refund` (Pagar.me) e `refunded`/`charged_back`/`in_mediation` (MP) → `error` (estorno/chargeback não são tratados; só log). `pending`/`waiting_payment` não logam.
4. **Vigilância do PIX após cartão aprovado:** para `PAID` com `payment_method = CREDIT_CARD` até `expirationDate + 5 min`, consulta o `ide` no gateway do PIX; se aparecer pago, `Log::error` com `Payment.id` e `ide` para estorno manual (não há estorno automático).

**Circuit breaker** (`CircuitBreaker`, sobre `Cache::` — `file` em produção, `array` nos testes; nunca Redis): `GATEWAY_CIRCUIT_FAILURES` (3) falhas do Pagar.me em `GATEWAY_CIRCUIT_WINDOW` (60 s) abrem o circuito por `GATEWAY_CIRCUIT_COOLDOWN` (120 s); aberto, novos PIX e o `card-config` vão direto ao MP. Passado o cooldown a próxima chamada é a sonda: sucesso fecha, falha reabre na hora. Com `file` o estado é do servidor (hospedagem única). Cada failover loga `warning`; os dois fora, `error`.

### 5.2 Checkout público — Livewire
- `PaymentPage` (`/checkout/{id}`): carrega o `Payment` sem scope; `wire:poll.5s="atualizar"` recarrega. Renderiza conforme o status:
  - `PENDING` → `PaymentTimer` + `PaymentStatusPending` (abas **Pix | Cartão**; PIX com QR Code, valor e copia-e-cola);
  - `PAID` → `PaymentStatusPaid` (texto e dados por método: PIX ou cartão com marca, final e parcelas; botão "Voltar à loja" se houver `redirect_url`);
  - `FAILED`/`CANCELED` → `PaymentStatusCanceled` (textos distintos).
  O `wire:poll.5s` só existe enquanto `PENDING`.
- `PaymentTimer`: countdown em JS no browser; ao zerar chama `checkExpiration()`, que relê a expiração **do banco** (sem propriedade pública nem método `cancelar()`).
- **Aba Cartão** (`PaymentStatusPending` + `partials/card-form.blade.php` + `resources/js/card-form.js`): só aparece se **algum gateway** tiver chave pública (`STONE_PUBLIC_KEY_INTEGRATION` ou `MP_PUBLIC_KEY` com MP ativo), `Payment` `PENDING` e `customer` preenchido (pagamentos antigos mostram só PIX). Formulário em **Alpine puro dentro de `wire:ignore`**, sem `wire:model`: dados do cartão nunca passam pelo Livewire nem pelo Laravel. Fluxo: `GET /checkout/{id}/card-config` (gateway vigente no clique) → valida no browser (Luhn, validade, CVV e, conforme o gateway, endereço **ou** CPF do titular) → tokeniza (Pagar.me: `POST https://api.pagar.me/core/v5/tokens?appId={public_key}`; Mercado Pago: SDK `https://sdk.mercadopago.com/js/v2`, carregado sob demanda, `getPaymentMethods` + `createCardToken`) → zera número e CVV do formulário (o `card-form.js` os mantém só numa variável local do submit, para o fallback) → `POST /checkout/{id}/pay-card` com `gateway` e `X-CSRF-TOKEN`. Se o backend responder `{status:"fallback"}`, re-tokeniza no outro gateway e reenvia **uma vez**. No MP o formulário esconde endereço/parcelas e pede o CPF do titular. CEP autocompleta rua/cidade/UF via ViaCEP (só o CEP sai do browser; falha é silenciosa). Qualquer tentativa sem resultado definitivo (`card_attempt_key` preenchida) mostra "Pagamento em análise" e bloqueia o formulário até o polling resolver; o componente dá `wire:poll.5s` para o aviso sumir sozinho.
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

- **Dashboard** ([app/Filament/Pages/Dashboard.php](app/Filament/Pages/Dashboard.php)): página inicial do painel (`/admin`, primeiro item do menu). Filtro de período no topo (`DashboardPeriodEnum`: Hoje, Últimos 7/30 dias, Este mês, Este ano; padrão Hoje, persistido na sessão) lido pelos widgets via `InteractsWithPageFilters`. Todas as métricas usam `created_at` (coorte de cobranças criadas no período) e o escopo da conta: `DashboardStats` (cobranças criadas = soma de todos os status, autorizadas = soma dos pagos com a receita líquida na descrição — `Payment::netRevenue()`: valor – `fee_amount` de cada pagamento; sem snapshot, Pix legado usa 0,75 % e cartão não tem tarifa —, número de cobranças, ticket médio = pago ÷ nº de pagos), `DashboardChart` (linha; TPV = volume pago, ou nº de cobranças, via dropdown do gráfico; agrupa por hora no "Hoje", por dia nos demais e por mês no ano; usa `DATE_FORMAT`, ou seja, MySQL) e `DashboardStatusStats` (valor, quantidade e % por status: Pago, Pendente, Cancelado, Falha). O botão "Baixar relatório" (ao lado do filtro) chama `Dashboard::downloadReport()` → `PaymentsReport`, que faz streaming de um CSV dos pagamentos do período (sem dados do cliente); a última coluna é o gateway.
- **PaymentResource** (Pagamentos): lista somente-leitura com polling de 5 s. Colunas: referência (busca por referência/ID; ID curto na descrição), meio (Pix / "Cartão · 3x" / "Pix ou cartão"), gateway (badge do accessor `gateway`), valor, status (badge) e criado em. Abas por status com contagem (Todos / Aguardando / Pagos / Cancelados — cancelados inclui falhos), filtros (meio, gateway — `Payment::settledOn()`, mesma regra do accessor —, período, faixa de valor) e ação "Copiar link" (checkout) nos pendentes. A visualização (modal "Pagamento {ref}") tem cabeçalho (status, valor, referência, id) e seções Pagamento (meio, bandeira/final e parcelamento do cartão pago, criado/expira/pago em), Valores (venda, taxa, tarifa de processamento, total líquido), Cliente (nome, e-mail e documento **mascarado**; o `customer` continua criptografado, por isso não é pesquisável) e Dados técnicos (recolhida: gateway do Pix e do cartão, status e detalhe crus no provedor, IDs do provedor, TID, NSU, autorização). Os itens da venda não são guardados no `Payment`, então não aparecem.
- **Painel da plataforma** (`/platform`, [PlatformPanelProvider](app/Providers/Filament/PlatformPanelProvider.php)): só `users.is_super_admin` entra (`User::canAccessPanel`). A flag **não é mass-assignable**: nasce só por `php artisan platform:make-admin {email}` (promove ou cria o usuário na conta "Plataforma"; ou o equivalente via tinker com `forceFill`). Lista todas as contas (situação Ativa / Convite pendente / Suspensa, cobranças, volume pago; números por subconsulta porque `Payment` tem escopo de tenant). **Nova conta**: nome da empresa + nome/e-mail do responsável → cria `Account` e o `User` (senha aleatória inutilizável, `password_set_at` nulo) e envia `AccountInvitation` por e-mail; se o envio falhar, mostra o link para repasse manual. Convite = URL assinada que expira em 7 dias (`/convite/{user}`), de **uso único** (vale enquanto `password_set_at` é nulo; depois 410), define a senha, loga e leva a `/admin`; "Reenviar convite" disponível enquanto pendente. **Suspender/Reativar** (`accounts.suspended_at`; não dá para suspender a própria conta): a API responde `403 {"error":"Account suspended"}` em `create-payment`; checkouts já criados seguem pagáveis e o scheduler segue rodando; o painel da conta mostra um aviso. O e-mail depende de `MAIL_*` configurado.
- **Página Gateways** (`/admin/gateways`, [GatewayCredentialsPage](app/Filament/Pages/GatewayCredentialsPage.php)): cada conta cadastra as credenciais do Pagar.me e do Mercado Pago. Segredos nunca voltam para a tela (só `••••` + 4 últimos; em branco = manter); salvar valida com uma chamada de leitura (`CredentialTester`) e guarda o resultado; mostra ambiente (teste/produção), a URL do webhook do MP **da conta** e o aviso "sem redundância" quando só há um gateway. Desativar um gateway com cobranças em andamento é bloqueado. Sem nenhum gateway, `create-payment` responde `422`; só Mercado Pago = MP é o primário; sem MP e Pagar.me fora = 503 (sem Payment). O breaker é por conta+gateway (`gateways:circuit:{account}:{gateway}:*`).
- **AccountResource** ("Minha conta"): dados cadastrais, gestão de usuários da conta (`UsersRelationManager`: criar/editar/remover; não remove a si mesmo nem o último usuário), widget de atividade do mês (cobranças, pagas/volume, conversão) e ação "Alterar minha senha" no cabeçalho. O `api_token` é regenerado em **Configurações → Integração** (botão "Regenerar token": o valor novo aparece uma única vez; o anterior segue válido por `API_TOKEN_ROTATION_GRACE_MINUTES` (60), ou é revogado na hora marcando a opção). Padrão "singleton": `/create` redireciona para `edit` se a conta já existe. `getEloquentQuery()` filtra pela conta do usuário logado.
- **ConfigResource**: edição de `duration`, `notification_url`, `redirect_url`, parcelamento do cartão (toggle + parcelas máximas 1–12) e tarifas por meio (`pix_fee_percent`, padrão 0,75; cartão por faixa de parcelas `card_fee_percent_1x` / `_2_6x` / `_7_12x`, em branco = "não configurada"). Tarifas **por gateway**: a seção "Tarifas — Mercado Pago" (`mp_*`, só aparece com MP ativo) vale para vendas processadas pelo MP; `Payment::feeAttributesFor($method, $installments, ?GatewayEnum $gateway)` escolhe a tabela pelo gateway gravado (`pix_gateway`/`card_gateway`; sem registro, Pagar.me) e grava o snapshot na confirmação. Tarifa do MP sem configurar grava `NULL` (spike S12 pendente); exibe o `api_token` em campo desabilitado. Mesmo padrão singleton (cria a `Config` se não existir; o token é gerado no evento `creating`).

---

## 7. Configuração / variáveis de ambiente

Não há `.env.example` no repositório. Variáveis específicas do projeto, além das padrão do Laravel (`APP_*`, `DB_*`, …). Nenhuma variável do Pagar.me foi renomeada (a produção é Hostinger sem SSH): `config/integration.php` segue lendo `URL_INTEGRATION`, `USER_INTEGRATION`, `PASSWORD_INTEGRATION`, `STONE_PUBLIC_KEY_INTEGRATION` e `CARD_HTTP_TIMEOUT`; `config/gateways.php` só acrescenta o que é novo.

| Variável | Uso |
|---|---|
| `GATEWAYS_ENV_ACCOUNT_ID` | **Multi-tenant**: UUID da conta que ainda usa as credenciais do `.env` (abaixo) por não ter cadastrado nada em *Gateways* no painel. Vazio = ninguém usa o `.env`; as demais contas só cobram com o que cadastrarem. `*` vale para todas as contas e é **só para dev/teste** |
| `URL_INTEGRATION` | Base URL do Pagar.me (`{url}/v5/orders`); global. As credenciais abaixo valem só para a conta de `GATEWAYS_ENV_ACCOUNT_ID` |
| `USER_INTEGRATION` / `PASSWORD_INTEGRATION` | Basic Auth do Pagar.me |
| `WEBHOOK_SECRET` | Enviado em `X-Webhook-Secret` nos webhooks de saída |
| `STONE_PUBLIC_KEY_INTEGRATION` | Public key (`pk_...`) exposta ao browser para tokenizar o cartão. Precisa ser do **mesmo ambiente** (teste/produção) de `USER_INTEGRATION`. Sem ela a aba de cartão não aparece |
| `CARD_STATEMENT_DESCRIPTOR` | Texto na fatura, até 13 caracteres (padrão `PAGOUNUMCLICK`) |
| `CARD_HTTP_TIMEOUT` | Timeout (s) das chamadas ao provedor no fluxo de cartão e no polling (padrão 15) |
| `MP_ACCESS_TOKEN` | Access Token do Mercado Pago (backend; **nunca** vai ao browser). **Sem ele o failover fica desligado** e o sistema se comporta só com o Pagar.me. Vale só para a conta de `GATEWAYS_ENV_ACCOUNT_ID`; as outras cadastram o delas no painel |
| `MP_PUBLIC_KEY` | Public key do Mercado Pago, exposta ao browser para tokenizar o cartão no fallback. Precisa ser do mesmo ambiente do `MP_ACCESS_TOKEN` (teste e produção misturados dão erro `2034`). Sem ela o MP faz só PIX |
| `MP_ENABLED` | `false` desliga o failover mesmo com credenciais (padrão `true`) |
| `MP_URL` / `MP_HTTP_TIMEOUT` | Base URL (padrão `https://api.mercadopago.com`) e timeout (s) das consultas/cobrança de cartão no MP (padrão 15) |
| `GATEWAY_CREATE_TIMEOUT` | Timeout (s) da criação de PIX, curto de propósito para o failover não esperar 30 s (padrão 5). Não reaproveita `CARD_HTTP_TIMEOUT` |
| `GATEWAY_CIRCUIT_FAILURES` / `_WINDOW` / `_COOLDOWN` | Circuit breaker do Pagar.me: falhas (3), janela em s (60) e tempo aberto em s (120) |
| `API_ACCOUNT_RATE_LIMIT` / `API_IP_RATE_LIMIT` | Cota da API por conta (60/min) e teto por IP contra inundação (300/min) |
| `API_TOKEN_ROTATION_GRACE_MINUTES` | Quanto tempo o token anterior da API segue válido após "Regenerar token" (padrão 60) |
| `GATEWAY_EXPIRY_GRACE_MINUTES` | Janela de graça para cancelar por expiração quando o gateway não responde (padrão 15) |
| `APP_URL` | Base do `payment_link` retornado e do link copiado no painel |

Como subir (Sail): `composer install`, criar `.env`, `php artisan key:generate`, `php artisan migrate`, `npm install && npm run build`, `php artisan schedule:work`. Não há seeder: o primeiro `Account` e `User` (com `account_id`) precisam ser criados manualmente (ex.: `tinker`) já que o registro está desativado. A `Config` da conta é criada pelo painel.

---

## 8. Pontos de atenção encontrados na leitura

Observações para revisão, das mais importantes às menores. Itens 1, 5, 6, 7, 9, 10, 12 e 16 já foram corrigidos (ver §9 para o 7); os demais seguem em aberto.

**Segurança / integridade**
1. ~~**Cancelamento forjável no checkout**~~ — **resolvido**: [PaymentTimer](app/Livewire/PaymentTimer.php) não expõe mais `cancelar()` nem `expirationDate` públicos; a expiração é relida do banco em `checkExpiration()`.
2. ~~**`AccountResource` sem escopo**~~ — **resolvido**: `AccountResource::getEloquentQuery()` restringe à conta do usuário logado (UUID de outra conta → 404).
3. ~~**Painel/Telescope**~~ — **resolvido**: o gate do Telescope (`viewTelescope`) agora é só de super-admin (`is_super_admin`) e o item "telescope" do menu só aparece para eles (antes qualquer login via dados de **todas** as contas). `canAccessPanel()` do `/admin` segue aberto a qualquer usuário (cada um vê só a própria conta); o do `/platform` exige super-admin. Entradas do Telescope levam a tag `account:{uuid}`.
4. ~~**`api_token` em texto puro**~~ — **resolvido**: o banco guarda só o SHA-256 (`api_token_hash`), o prefixo (`api_token_prefix`) e, na rotação, o hash anterior com prazo (`previous_api_token_*`); o valor puro aparece uma única vez ao gerar (`pnc_` + 40 caracteres). Tokens antigos foram migrados pelo hash e continuam válidos.
5. ~~**Bug no `Config::boot`** — usa `$model->api_token` (variável inexistente; deveria ser `$item`), então o `empty()` é sempre verdadeiro e sobrescreve qualquer token fornecido.~~ — **corrigido** (`$item->api_token`); o guard agora funciona e só gera token novo quando não há um já definido no model.
6. ~~`ApiTokenMiddleware` quebra (erro 500) se a conta do token não tiver nenhum `User`~~ — **corrigido** (responde 401); a API ainda atua sempre como "primeiro usuário da conta".
7. ~~Rate limit `throttle` em `/checkout` e no grupo `web` bloqueando clientes legítimos com HTTP 429~~ — **corrigido**, ver §9.

**Lógica**
8. Só o primeiro item é enviado ao provedor; `quantity` ignorada; valores multi-item não são somados.
9. ~~`CheckoutController` faz `$paymentEnum->value`/`$paymentStatusEnum->value` sem tratar `null` (status/método desconhecido do provedor → erro 500 após o pedido já criado no provedor).~~ — **corrigido**: em caso de `status`/`payment_method` não mapeado, cai para `PENDING`/`PIX` e loga warning com o `id` do pedido no provedor para investigação manual.
10. ~~`duration` é `string` em `configs` mas usado como inteiro em `addSeconds`.~~ — **corrigido**: cast `integer` em `Config` (a coluna continua string).
11. ~~Sem `withoutOverlapping()` nos comandos de 10 s~~ — **corrigido** (`withoutOverlapping(1)`). `ProcessPaymentController` agora usa timeout e captura `ConnectionException` por pagamento.
12. ~~`ConfigResource::getRecord()` contém um `dd()` esquecido.~~ — **removido**.
13. ~~Taxa 0,75 % fixa e repetida em 3 lugares~~ — **resolvido**: tarifa por meio configurável por conta no painel, gravada como snapshot (`payments.fee_percent`/`fee_amount`) quando o pagamento é confirmado. Observação: o próprio usuário da conta edita as tarifas; elas são informativas (não alteram a cobrança no provedor).
14. `close_order` (comprovadamente não cancelava PIX pendente; removido junto com o `ApiService` ao virar `PagarmeGateway`), `TypeTransactionEnum` e o layout `filament/pages/auth/register.blade.php` não são usados.

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
- **Limites da API por conta** (`config/tenancy.php`): `API_ACCOUNT_RATE_LIMIT` (padrão 60/min) é contado por conta dentro do `ApiTokenMiddleware` (excedeu → `429 {"error":"Too Many Requests"}` com `Retry-After`); o limiter `api` por IP virou só proteção contra inundação, com teto alto (`API_IP_RATE_LIMIT`, padrão 300/min, era 60). Lojistas com IP de saída compartilhado deixam de dividir cota.
- **Logs por conta**: todo log de requisição de API, scheduler, webhook e `pay-card` leva `account_id` no contexto (`TenantContext::tagLogs`); no Telescope, a tag `account:{uuid}`. O painel `/platform` mostra o último uso da API por conta.

---

## 10. Nota sobre o clone


O `git clone` no Windows falhou no checkout por causa dos 19 arquivos `docker/**/*:Zone.Identifier` (dois-pontos é inválido em nomes NTFS). Os arquivos foram extraídos com `git archive` **excluindo** esses arquivos; o `.git` está completo (histórico: 1 commit, `6f323aa Initial commit`). Por isso `git status` mostra esses 19 como "deleted" — é esperado e inofensivo. Foi definido `core.protectNTFS=false` no `.git/config` local para permitir o `git archive`.
