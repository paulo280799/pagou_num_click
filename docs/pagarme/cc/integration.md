# Integração Cartão de Crédito com a Pagar.me — como funciona no projeto

Descreve o que o código **faz hoje** (revisado em 2026-10-14, Planos 2 e 3). Para o contrato da API da Pagar.me, ver [doc.md](doc.md). Para o PIX, ver [../pix/integration.md](../pix/integration.md): o cartão é uma **segunda tentativa sobre o mesmo `Payment`**, então boa parte do fluxo (criação, webhook de saída) é compartilhada.

## Visão geral

```
Sistema do cliente           Pagou num Click                         Pagar.me
       │ POST /api/create-payment (igual ao PIX; cria o pedido PIX)       │
       │ ◄── 201 { payment_link }       │                                 │
       │                                │                                 │
Pagador abre /checkout/{payment.id} ─► aba "Cartão"                       │
       │ (browser)                      │                                 │
       │ POST /core/v5/tokens?appId=pk_... (número, nome, validade, CVV) ►│
       │ ◄── token_... (1 min, uso único)                                 │
       │                                │                                 │
       │ POST /checkout/{id}/pay-card   │                                 │
       │  { card_token, installments,   │ lock + Idempotency-Key          │
       │    billing_address } ────────► │ POST /v5/orders (credit_card) ─►│
       │                                │ ◄── charges[0].last_transaction │
       │ ◄── {status} paid|declined|processing                            │
       │                                │ grava Payment (PAID)            │
       │               scheduler (10 s) │ resolve tentativas em análise   │
       │ ◄── POST notification_url      │                                 │
       │     { payment_id, status }     │                                 │
```

Decisões que moldam o desenho:

- **O sistema cliente não tem o cartão do pagador.** Por isso `POST /api/create-payment` continua igual (sempre cria o pedido PIX) e o cartão entra pela página de checkout.
- **Os dados do cartão nunca chegam ao nosso backend.** O browser tokeniza direto na Pagar.me com a **public key**; só o `card_token` vem para nós. Como o JS é nosso (campos não hospedados pela Pagar.me), o escopo PCI é **SAQ A-EP**, decisão aceita por simplicidade.
- **`ide` continua sendo o pedido PIX**; o pedido de cartão fica em `card_order_id`. Uma recusa de cartão deixa o `Payment` `PENDING` com o PIX ainda pagável.
- **A confirmação é síncrona** (resposta de `POST /v5/orders`); o polling só resolve casos em análise e timeouts.

## Peças e responsabilidades

| Peça | Arquivo | Papel |
| :-- | :-- | :-- |
| Rota | [routes/web.php](../../../routes/web.php) | `POST /checkout/{id}/pay-card` (`checkout.pay-card`), `throttle:pay-card` |
| Limiter | [RouteServiceProvider](../../../app/Providers/RouteServiceProvider.php) | `pay-card`: 5 tentativas / 10 min por IP + `Payment.id` |
| Validação | [PayCardRequest](../../../app/Http/Requests/PayCardRequest.php) | `card_token`, `installments`, `billing_address` (sem campo de valor) |
| Cobrança | [CardPaymentController::store](../../../app/Http/Controllers/CardPaymentController.php) | Reserva a tentativa, chama a Pagar.me, aplica o resultado |
| Mapper | [ChargeResult](../../../app/Support/ChargeResult.php), [ChargeOutcome](../../../app/Support/ChargeOutcome.php) | `last_transaction.status` → `PAID`/`DECLINED`/`PENDING_REVIEW`/`UNKNOWN` |
| Cliente HTTP | [ApiService](../../../app/Services/ApiService.php) | `create_card_order` (com `Idempotency-Key` e timeout), `get_order`, `get_orders_by_code` |
| Config | [config/integration.php](../../../config/integration.php) | `public_key`, `card_statement_descriptor`, `card_http_timeout` |
| Polling | [ProcessPaymentController](../../../app/Http/Controllers/ProcessPaymentController.php) | Resolve tentativas em análise/timeout e vigia o PIX aberto |
| Checkout | [PaymentStatusPending](../../../app/Livewire/PaymentStatusPending.php), [card-form.blade.php](../../../resources/views/livewire/partials/card-form.blade.php), [card-form.js](../../../resources/js/card-form.js) | Abas Pix \| Cartão e formulário |
| Modelo | [Payment](../../../app/Models/Payment.php) | Colunas de cartão, `markCardPaid()`, `releaseCardAttempt()` |

## 1. Pagamento com cartão

### Pré-requisitos do `Payment`

O cartão só funciona sobre um `Payment` que já existe (criado pelo fluxo PIX) e que:

- está `PENDING` e não expirou;
- tem `customer` preenchido (gravado pelo `store` do PIX; pagamentos antigos não têm e só podem pagar por PIX);
- não tem tentativa em análise.

### Tokenização (browser → Pagar.me)

`POST https://api.pagar.me/core/v5/tokens?appId={STONE_PUBLIC_KEY_INTEGRATION}`, sem cookies:

```json
{ "type": "card", "card": { "number": "4000000000000010", "holder_name": "TONY STARK",
                            "exp_month": 12, "exp_year": 30, "cvv": "123" } }
```

Resposta: `{ "id": "token_..." }`. O token **expira em 1 minuto e só vale uma vez**; por isso o front tokeniza e envia em seguida, sem passo intermediário. A tokenização funciona direto do browser (CORS ok) e aceita `exp_year` de 2 dígitos (verificado em 2026-10-12).

### Entrada (browser → nós)

`POST /checkout/{payment.id}/pay-card` (rota `web`, **CSRF obrigatório** via `X-CSRF-TOKEN`, sem Bearer):

```json
{
  "card_token": "token_abc123",
  "installments": 1,
  "billing_address": { "line_1": "Praça da Sé, 100", "zip_code": "01001000",
                       "city": "São Paulo", "state": "SP", "country": "BR" }
}
```

Regras de validação ([PayCardRequest](../../../app/Http/Requests/PayCardRequest.php)):

- `card_token`: obrigatório, `^token_[A-Za-z0-9]+$`.
- `installments`: inteiro, `1..payments.max_installments`. O limite é configurado por conta no painel (`Config.card_installments_enabled` / `card_max_installments`, 1–12, padrão 12; desligado = 1) e copiado para o `Payment` na criação. Só **parcelamento sem juros**: o valor total não muda, a Pagar.me apenas divide.
- `billing_address.*`: `line_1` (≤256), `zip_code` (≤16), `city` (≤64), `state` (≤64) e `country` (2 letras) obrigatórios. **A Pagar.me não tokeniza o endereço**: sem `billing_address` no pedido a charge falha (`validation_error | billing | "value" is required`).
- **Não existe campo de valor.** Qualquer `amount` no corpo é ignorado; o valor vem do `Payment` no banco.

### Processamento (`CardPaymentController::store`)

Três fases; nenhum lock fica preso durante a chamada externa.

**Fase 1 — reservar a tentativa** (transação com `lockForUpdate`). Responde `409` se:

- `status != PENDING`, ou `expirationDate` já passou, ou `customer` é nulo;
- há cartão em análise (`card_order_id` e `card_attempt_key` preenchidos);
- há outra requisição em voo (chave gravada e `card_attempt_started_at` mais recente que `CARD_HTTP_TIMEOUT + 5 s`).

Senão grava `card_attempt_key` (UUID novo, **ou a anterior se a tentativa deu timeout**, para reenvio idempotente) e `card_attempt_started_at = now()`.

**Fase 2 — cobrar** (`ApiService::create_card_order`, sem lock). Header `Idempotency-Key: <card_attempt_key>` e timeout `CARD_HTTP_TIMEOUT` (15 s). Corpo:

| Campo enviado à Pagar.me | Origem / regra |
| :-- | :-- |
| `code` | `Payment.id` (aceito e buscável, mas **não é único**) |
| `items[0].amount` | `round(Payment.amount * 100)` (centavos) |
| `items[0].description` | `"Pedido {refExternal}"` (a descrição original não é persistida) |
| `items[0].quantity` / `items[0].code` | `1` / `Payment.id` (**`items[].code` é obrigatório no cartão**) |
| `customer` | `Payment.customer` (precisa de `document`) |
| `payments[0].payment_method` | `"credit_card"` |
| `payments[0].amount` | mesmo valor em centavos |
| `credit_card.operation_type` | `"auth_and_capture"` (captura na hora) |
| `credit_card.installments` | `installments` validado |
| `credit_card.statement_descriptor` | `CARD_STATEMENT_DESCRIPTOR` (≤13 caracteres) |
| `credit_card.card_token` | `card_token` |
| `credit_card.card.billing_address` | `billing_address` |
| `metadata` | `{ payment_id, refExternal }` |

**Fase 3 — aplicar o resultado** (transação com `lockForUpdate`, relendo o `Payment`).

### Mapeamento do resultado ([ChargeResult](../../../app/Support/ChargeResult.php))

A fonte da verdade é `charges[0].last_transaction.status`, **nunca** só `order.status` nem o HTTP status (falhas voltam como **HTTP 200**).

| `last_transaction.status` | `ChargeOutcome` |
| :-- | :-- |
| `captured` | `PAID` |
| `not_authorized`, `failed`, `with_error` | `DECLINED` |
| `authorized_pending_capture`, `waiting_capture`, `partial_capture` | `PENDING_REVIEW` |
| `voided`, `refunded`, `partial_*`, `waiting_cancellation`, `error_on_*` | `UNKNOWN` (fora do fluxo de criação) |
| ausente / charge ausente | `UNKNOWN` |
| ausente **e** `charges[0].status = failed` | `DECLINED` |

O acesso é sempre defensivo (`data_get`). Não há fallback "PIX/PENDING" para valor desconhecido.

### Efeito por resultado

| Resultado | `Payment` | Resposta ao front |
| :-- | :-- | :-- |
| `PAID` | `status = PAID`, `paymentDate = now()`, `payment_method = CREDIT_CARD`, `card_order_id`, `card_brand`, `card_last_four`, `installments`, `acquirer_tid/nsu/auth_code`; chave de tentativa limpa | `200 {status:"paid", message}` |
| `DECLINED` | continua `PENDING`; chave limpa (a próxima tentativa usa chave nova); PIX intacto | `422 {status:"declined", message:"Cartão recusado…"}` |
| `PENDING_REVIEW` / `UNKNOWN` | continua `PENDING`; grava `card_order_id`; **mantém** a chave (bloqueia reenvio até o polling resolver) | `202 {status:"processing"}` |
| timeout (`ConnectionException`) ou 5xx | continua `PENDING`; chave mantida, sem `card_order_id` | `503 {status:"processing"}` |

Outros códigos: `409` (não pagável), `404` (id inexistente), `422` (validação), `429` (rate limit). A resposta **nunca** traz `acquirer_*`, `gateway_response` nem a mensagem do adquirente; só `{status, message}` genérico.

### Idempotência (cobrança duplicada)

Cenário: o `POST /v5/orders` sofre timeout depois de a Pagar.me já ter processado. Sem resposta, não se sabe se houve cobrança.

- A chave fica gravada antes da chamada. Em timeout ela **não** é liberada.
- Reenvio depois da janela de `CARD_HTTP_TIMEOUT + 5 s` reutiliza a **mesma `Idempotency-Key`**: a Pagar.me devolve o **mesmo pedido** (verificado: mesmo com `card_token` novo), sem cobrar de novo.
- Em recusa a chave é limpa; uma nova tentativa gera chave nova (a mesma chave devolveria o mesmo pedido falho).
- O polling reconcilia timeouts antigos (seção 3).

## 2. Checkout do pagador

`GET /checkout/{payment.id}` → `PaymentPage` → `PaymentStatusPending`.

A **aba Cartão** só renderiza se `STONE_PUBLIC_KEY_INTEGRATION` está configurada, o `Payment` está `PENDING` e `customer != null`. Só booleanos e a `pk_...` vão ao browser (nunca `customer` nem a chave secreta).

**Formulário** (`partials/card-form.blade.php` + `resources/js/card-form.js`): Alpine puro dentro de `wire:ignore`, **sem `wire:model`**. Dados do cartão nunca passam pelo Livewire (que serializa estado e re-renderiza a cada 5 s) nem pelo Laravel.

Campos: número (máscara em grupos de 4, Luhn, 13–19 dígitos), nome, validade `MM/AA` (não vencida), CVV (3–4), parcelas (1..limite do `Payment`, "Nx de R$ X sem juros"; sem seletor quando o limite é 1), CEP, rua, número, cidade e UF.

- **CEP:** ao completar 8 dígitos consulta `https://viacep.com.br/ws/{cep}/json/` (timeout 5 s, sem cookies) e preenche rua/cidade/UF. Só o CEP sai para o ViaCEP. CEP inexistente ou falha → aviso e preenchimento manual. O envio **não** depende do ViaCEP (basta 8 dígitos).
- `line_1` é enviado como `"{rua}, {número}"`; `country` fixo `BR`.

**Submit:**

1. Trava duplo clique (estado `busy`) e valida; erros por campo, nada vai à rede se houver erro.
2. Tokeniza na Pagar.me e **zera número e CVV** imediatamente (estado e DOM).
3. `POST /checkout/{id}/pay-card` com `X-CSRF-TOKEN`.
4. Trata a resposta:

| HTTP / `status` | Tela |
| :-- | :-- |
| `200 paid` | "Pagamento aprovado" e `refreshStatus()` → página vira `PAID` |
| `422 declined` | "Cartão recusado" + atalho "Pagar com Pix"; formulário reabre (número/CVV em branco, demais campos mantidos) |
| `422` validação | primeiro erro retornado |
| `202` / `503` | "Pagamento em análise. Não pague novamente…"; formulário bloqueado |
| `409` | "Este pagamento não pode mais ser pago no cartão" e relê o status |
| `429` | "Muitas tentativas…"; bloqueia por ~1 min |
| rede / outros | "Falha de conexão. Tente novamente." |

**"Em análise" persistente:** qualquer tentativa sem resultado definitivo (`card_attempt_key` preenchida, com ou sem `card_order_id`) faz o servidor renderizar o aviso "Pagamento em análise" no lugar do formulário, inclusive após recarregar. O componente tem `wire:poll.5s` quando o cartão está habilitado, para o aviso sumir sozinho quando o polling libera a tentativa.

**Tela de pago:** `PaymentStatusPaid` mostra "Seu pagamento no cartão foi aprovado" e "Cartão Visa final 0010 · 3x" (marca, final e parcelas quando > 1).

## 3. Confirmação e resolução (polling)

O cartão é síncrono, mas o `app:process-payment` (a cada 10 s, `withoutOverlapping`) ainda resolve casos pendentes. Para cada `Payment` `PENDING`, com `ConnectionException` capturada **por pagamento**:

1. **Tentativa de cartão** (se há `card_order_id` ou `card_attempt_key`):
   - com `card_order_id`: `GET /v5/orders/{card_order_id}` e aplica [ChargeResult](../../../app/Support/ChargeResult.php): `PAID` → `markCardPaid`; `DECLINED` → `releaseCardAttempt` (libera nova tentativa); em análise → mantém e loga warning;
   - sem `card_order_id` (timeout) e **depois da janela** de `CARD_HTTP_TIMEOUT + 5 s`: `GET /v5/orders?code={Payment.id}` e escolhe o pedido cujo `metadata.payment_id` confere (o `code` não é único). Achou → aplica o resultado; não achou → limpa a chave.
2. **Cartão em análise não é cancelado por expiração:** enquanto `card_order_id` e `card_attempt_key` existem, o `Payment` não vira `CANCELED` por `expirationDate`.
3. Depois segue o fluxo PIX normal (expiração e `GET /v5/orders/{ide}`).

**Vigilância do PIX após cartão aprovado:** o PIX original **não pode ser cancelado** na Pagar.me (`PATCH /orders/{id}/closed` → 404 "order is closed"; `DELETE /charges/{id}` → 412), só expira. Por isso, para `Payment` `PAID` com `payment_method = CREDIT_CARD` até `expirationDate + 5 min`, o polling consulta `ide`; se o PIX aparecer `paid`, grava `Log::error` com `Payment.id`, `ide` e `card_order_id` para **estorno manual**. Não há estorno automático.

Corrida PIX × cartão: o primeiro confirmado vence; o segundo gera o log de erro acima.

## 4. Notificação ao sistema do cliente (webhook de saída)

Idêntica à do PIX (ver [../pix/integration.md](../pix/integration.md) §4): `PAID` por cartão gera `POST <notification_url>` com `{ "payment_id": "<refExternal>", "status": "PAID" }` e `X-Webhook-Secret`. O contrato **não** diferencia o método de pagamento. Backoff `[60s, 5min, 10min, 30min, 1h]`, 5 tentativas, `timeout(10)` e `ConnectionException` capturada por pagamento (URL inalcançável não bloqueia as demais).

## 5. Ciclo de vida do `Payment` com cartão

```
              create-payment (PIX)
                     │
                 ┌───▼───┐   pay-card: captured      ┌──────────────────────┐
                 │PENDING├─────────────────────────► │ PAID (CREDIT_CARD)   │
                 └─┬─┬─┬─┘                           └──────────────────────┘
   not_authorized  │ │ │ timeout / em análise → chave mantida, polling resolve
   (chave limpa,   │ │ └──────────────► PAID | libera nova tentativa | limpa a chave
   continua        │ │
   PENDING)  ◄─────┘ └─ expirationDate < now() (sem tentativa em análise) ─► CANCELED
   PIX pago (polling) ─► PAID (PIX)
```

Estados da tentativa (colunas em `payments`):

| `card_attempt_key` | `card_order_id` | Significado |
| :-- | :-- | :-- |
| nulo | nulo | nenhuma tentativa (ou recusada e liberada) |
| preenchido | nulo | chamada enviada sem resposta (timeout) ou em voo; reenvio reaproveita a chave após a janela |
| preenchido | preenchido | cartão em análise; bloqueia reenvio até o polling resolver |
| nulo | preenchido | aprovado (`PAID`) |

Colunas de cartão (todas nullable; **nunca** PAN, CVV, validade, `card_token` nem endereço): `card_order_id`, `card_attempt_key`, `card_attempt_started_at`, `card_brand`, `card_last_four`, `installments`, `acquirer_tid`, `acquirer_nsu`, `acquirer_auth_code`, `customer` (`encrypted:array`, `$hidden`; exige `APP_KEY` estável).

## 6. Configuração necessária

| Variável | Uso |
| :-- | :-- |
| `STONE_PUBLIC_KEY_INTEGRATION` | Public key (`pk_...`) exposta ao browser para tokenizar. **Mesmo ambiente** (teste/produção) de `USER_INTEGRATION`. Sem ela a aba de cartão não aparece |
| `CARD_STATEMENT_DESCRIPTOR` | Texto na fatura, até 13 caracteres (padrão `PAGOUNUMCLICK`) |
| `CARD_HTTP_TIMEOUT` | Timeout (s) das chamadas ao provedor no cartão e no polling; também define a janela de reenvio (`+5 s`). Padrão 15 |
| `URL_INTEGRATION` / `USER_INTEGRATION` / `PASSWORD_INTEGRATION` | Os mesmos do PIX (secret key `sk_...`) |
| `APP_KEY` | Precisa ser estável: `payments.customer` é criptografado |

A escolha teste/produção é feita só trocando valores do `.env`, sem lógica no código. Variantes `*_PROD` de chaves no `.env` não são lidas pelo código.

## 7. Segurança e privacidade

- **Nenhum dado de cartão trafega pelo Laravel**: nem número, nem CVV, nem validade. Só o `card_token` (uso único, 1 min) e o endereço de cobrança.
- Campos do cartão fora do Livewire (`wire:ignore`, sem `wire:model`); número e CVV zerados logo após a tokenização; sem `localStorage` nem logs de campos.
- `card_token`, corpo do pedido de cartão e `billing_address` **não são logados nem persistidos**. Logs de cartão trazem só `Payment.id`, ids da Pagar.me, `acquirer_return_code` e o status da transação.
- Telescope oculta `card_token` e `billing_address` (`hideRequestParameters`).
- A view lê só `config('integration.public_key')`; a chave secreta nunca vai ao HTML.
- CSRF obrigatório na rota; rate limit dedicado por IP + `Payment.id` (a rota pública seria um oráculo de teste de cartões). O throttle global do grupo `web` continua desligado (compartilhava contador com o polling do Livewire e gerava 429).
- `customer` (PII) criptografado em repouso e oculto na serialização.
- Respostas ao front só com `{status, message}` genéricos.

## 8. Comportamento verificado no sandbox

Chaves `sk_test_`/`pk_test_` do projeto (2026-10-11/12); detalhes em [doc.md](doc.md):

- Aprovado: `order.status = paid`, `charge.status = paid`, `last_transaction.status = captured`.
- Falhas voltam como **HTTP 200** com `charge.status = failed` e `gateway_response.errors[]`. Gatilho confiável de recusa: **valor de R$ 0,01** (`valor não permitido`).
- `customer.document`, `items[].code` e `billing_address` são **obrigatórios** no cartão.
- Token: 1 min, uso único. `Idempotency-Key` devolve o mesmo pedido.
- O simulador desta conta **aprova todos os cartões** (inclusive o `…0028`, que a doc oficial diz ser recusado). Nenhuma recusa real de emissor (`not_authorized`) foi capturada.

## 9. Limitações conhecidas (resumo)

1. Recusa real de emissor e AVS só podem ser validados com credencial de produção; os testes de recusa usam `Http::fake` e o valor R$ 0,01.
2. PIX pendente não é cancelável: após cartão aprovado ele só expira; pagamento duplicado exige **estorno manual** (o polling apenas loga).
3. Sem 3DS, cartão salvo, recorrência, `auth_only`, parcelamento com juros, estorno/cancelamento e chargeback (o sistema não trata `voided`/`refunded`/`chargedback`).
4. Escopo PCI **SAQ A-EP** (campos nossos, não hospedados); reavaliar se compliance exigir iframe/campos hospedados.
5. ~~Taxa de 0,75% assume PIX~~ — resolvido: tarifa por meio configurável por conta (Pix e cartão por faixa de parcelas), gravada no pagamento ao confirmar.
6. Pagamentos antigos sem `customer` só pagam por PIX.
7. `items[0].description` do pedido de cartão é texto genérico (`Pedido {refExternal}`): a descrição original não é persistida.
8. Dependência externa no browser: ViaCEP (opcional, degrada para digitação manual) e `api.pagar.me` (tokenização).
9. Ver também `DOCUMENTACAO.md` §8 para os itens de segurança herdados.
