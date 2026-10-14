# Integração PIX com a Pagar.me — como funciona no projeto

Descreve o que o código **faz hoje** (revisado em 2026-10-10). Para o contrato da API da Pagar.me, ver [doc.md](doc.md).

## Visão geral

```
Sistema do cliente                Pagou num Click                      Pagar.me
       │                                 │                                │
       │ POST /api/create-payment        │                                │
       │ Bearer <api_token> ───────────► │                                │
       │                                 │ POST /v5/orders (pix) ───────► │
       │                                 │ ◄── order + qr_code/qr_code_url│
       │                                 │ grava Payment (PENDING)        │
       │ ◄── 201 { payment_link }        │                                │
       │                                 │                                │
Pagador abre payment_link ─► /checkout/{payment.id} (Livewire, QR + timer)
       │                                 │                                │
       │               scheduler (10 s)  │ GET /v5/orders/{ide} ────────► │
       │                                 │ ◄── status                     │
       │                                 │ atualiza Payment               │
       │ ◄── POST notification_url       │                                │
       │     { payment_id, status }      │                                │
```

Não há webhook **da** Pagar.me para nós: a confirmação de pagamento é feita por **polling**. O webhook existente é **de saída** (nós → sistema do cliente).

## Peças e responsabilidades

| Peça | Arquivo | Papel |
| :-- | :-- | :-- |
| Rota | [routes/api.php](../../../routes/api.php) | `POST /api/create-payment` sob middleware `api.token` |
| Auth | [ApiTokenMiddleware](../../../app/Http/Middleware/ApiTokenMiddleware.php) | Bearer → `Config` → primeiro `User` da conta → `loginUsingId` |
| Validação | [CheckoutRequest](../../../app/Http/Requests/CheckoutRequest.php) | Corpo da requisição do cliente |
| Criação | [CheckoutController::store](../../../app/Http/Controllers/CheckoutController.php) | Monta pedido, chama Pagar.me, grava `Payment` |
| Cliente HTTP | [ApiService](../../../app/Services/ApiService.php) | `create_order`, `get_order`, `create_card_order`, `get_orders_by_code`, `close_order` (Basic Auth; `close_order` não é usado) |
| Config | [config/integration.php](../../../config/integration.php) | `URL_INTEGRATION`, `USER_INTEGRATION`, `PASSWORD_INTEGRATION` |
| Polling | [ProcessPaymentController](../../../app/Http/Controllers/ProcessPaymentController.php) | Via comando `app:process-payment` a cada 10 s |
| Webhook saída | [WebhookController](../../../app/Http/Controllers/WebhookController.php) | Via comando `app:process-webhook` a cada 10 s |
| Checkout | [PaymentPage](../../../app/Livewire/PaymentPage.php), [PaymentTimer](../../../app/Livewire/PaymentTimer.php) | Tela pública do pagador |
| Modelo | [Payment](../../../app/Models/Payment.php) | Tabela `payments`, com `UserScope` |

## 1. Criação da cobrança

### Entrada (cliente → nós)

`POST /api/create-payment` com `Authorization: Bearer <api_token>`.

```json
{
  "refExternal": "pedido-123",
  "items": [{ "amount": "29.90", "description": "Produto X", "quantity": 1 }],
  "customer": {
    "name": "Fulano",
    "email": "f@x.com",
    "type": "individual",
    "document": "123.456.789-00",
    "phones": {
      "home_phone": { "country_code": "55", "area_code": "11", "number": "999999999" }
    }
  }
}
```

Regras de validação relevantes:

- `refExternal` obrigatório e **único por conta** (a regra `unique` filtra por `account_id` do usuário logado pelo middleware).
- `items.*.amount`: string/número em **reais**, `^\d+(\.\d{1,2})?$`.
- `customer.type`: `individual` ou `corporation`.
- `customer.phones.home_phone` completo (`country_code`, `area_code`, `number`) é obrigatório, a Pagar.me exige telefone para PIX.

### Transformação (`CheckoutController::store`)

| Campo enviado à Pagar.me | Origem / regra |
| :-- | :-- |
| `items[0].amount` | `items[0].amount` em reais → **centavos** (`reaisParaCentavos`, `round(valor*100)`) |
| `items[0].description` | `items[0].description` |
| `items[0].quantity` | **sempre `1`** (`quantity` é validado mas não repassado) |
| `customer.document` | só dígitos (`removerMascaraCpf`) |
| `customer.*` | repassado como recebido |
| `payments[0].payment_method` | `"pix"` fixo |
| `payments[0].amount` | mesmo valor em centavos |
| `payments[0].pix.expires_at` | agora **+3h + `Config.duration`** segundos, formato `Y-m-d H:i:s` |

Pontos importantes:

- **Só `items[0]` é enviado.** Itens adicionais são ignorados silenciosamente.
- **Validade do PIX:** `duration` vem da `Config` da conta (segundos). O `expires_at` vai em UTC; como o app roda em `America/Sao_Paulo` (UTC-3), o código soma 3h fixas para converter. Funciona porque o Brasil não tem horário de verão hoje; se mudar, quebra.
- **Não usa `expires_in`**, só `expires_at`.
- `Config` vem de `$this->config->first()`, que sofre o `UserScope`, então é a config da conta do token.

### Saída da Pagar.me → `Payment`

Campos lidos da resposta:

| Resposta Pagar.me | Coluna `payments` |
| :-- | :-- |
| `id` (pedido, `or_...`) | `ide` |
| `charges[0].last_transaction.qr_code_url` | `qrCode` (imagem) |
| `charges[0].last_transaction.qr_code` | `copyPaste` (copia-e-cola) |
| `amount` (centavos) | `amount` (reais, `/100`) |
| `charges[0].payment_method` | `payment_method` (fallback `PIX`) |
| `status` do **pedido** | `status` (fallback `PENDING`) |

Também grava `refExternal`, e faz **snapshot** de `duration`, `redirect_url`, `notification_url` da `Config` (edições posteriores na `Config` não afetam pagamentos em andamento). `expirationDate` = agora + `duration` em horário local (sem o +3h). `account_id` é preenchido por `Payment::creating` a partir do usuário logado.

### Resposta (nós → cliente)

- Sucesso: `201 { "payment_link": "<APP_URL>/checkout/<payment.id>" }`. O `id` do link é o UUID interno, não o `ide` da Pagar.me.
- Falha HTTP da Pagar.me (`$response->failed()`): mesmo status HTTP com `{ success: false, message: "Erro ao processar pagamento", errors: <corpo da Pagar.me> }`.
- Falha de validação: 422 padrão do Laravel. Token ausente/inválido: 401.

### Cobrança "failed" com HTTP 200 (tratada)

Com dados de cliente inválidos (ex.: CPF com dígitos verificadores inválidos → `action_forbidden | | Erro no gateway`) a Pagar.me responde **200** com `charges[0].status = "failed"` e sem `qr_code_url`. `CheckoutController::store` detecta isso (charge `failed` ou QR ausente), loga o erro e responde `422 {success:false, message, errors}` **sem criar o `Payment`**. Antes disso virava 500.

### Dados do cliente persistidos

O `store` grava o `customer` enviado (com `document` sem máscara) em `payments.customer` (`encrypted:array`, oculto na serialização). É necessário porque o pedido de cartão é criado depois, na página de checkout, e a Pagar.me exige `customer.document` nele.

## 2. Checkout do pagador

`GET /checkout/{payment.id}` (rota pública, sem auth) → Livewire `PaymentPage`.

- Carrega o `Payment` com `withoutGlobalScopes()` (não há usuário logado).
- Renderiza por status: **PENDING** (timer + abas **Pix | Cartão**; a aba de cartão só aparece com public key e `customer`, ver `docs/pagarme/cc/doc.md` e `DOCUMENTACAO.md` §4), **PAID**, **FAILED**/**CANCELED**.
- Em PENDING a tela faz `wire:poll.5s` só **lendo o banco**; quem muda o status é o scheduler, não a tela.
- `PaymentTimer`: contagem regressiva no browser (Alpine). Ao zerar chama `checkExpiration()`, que revalida `expirationDate` no banco e, se vencido e ainda PENDING, marca `CANCELED`.
- Em PAID, mostra botão "Voltar à loja" se `redirect_url` existir.

## 3. Confirmação de pagamento (polling)

Comando `app:process-payment`, agendado a cada 10 s em [Kernel.php](../../../app/Console/Kernel.php) (exige `schedule:work` ou cron por minuto). Para **todos** os `Payment` `PENDING` de **todas** as contas (`withoutGlobalScopes()`):

1. Se `expirationDate < now()` → `CANCELED` (não consulta a Pagar.me).
2. Senão `GET /v5/orders/{ide}` e lê `status` do **pedido**.
3. Converte para o enum interno (`strtoupper`, `StatusPaymentEnum::tryFrom`):

| `order.status` (Pagar.me) | Ação |
| :-- | :-- |
| `paid` | `status = PAID`, `paymentDate = now()` |
| `failed` | `status = FAILED` |
| `canceled` | `status = CANCELED` |
| `pending` | nada (continua PENDING) |
| ausente / desconhecido | ignora, tenta de novo no próximo ciclo |

Observações:

- É uma chamada HTTP **sequencial por pagamento** a cada 10 s. Escala linearmente com o nº de PIX pendentes. Não há `withoutOverlapping`, então ciclos lentos podem sobrepor.
- Falha de rede em `get_order` não é tratada explicitamente: `$response['status']` vira `null` e o pagamento é pulado.
- O `close_order` do `ApiService` **não é usado**: ao cancelar localmente por expiração o pedido não é fechado na Pagar.me. Na prática o `expires_at` enviado coincide com a expiração local, então o PIX expira nos dois lados quase juntos.
- **Risco:** pagamento cancelado localmente (expiração) deixa de ser consultado. Se o pagador pagar no limite/atraso, o PIX pode ser aprovado na Pagar.me sem o sistema reconhecer.

## 4. Notificação ao sistema do cliente (webhook de saída)

Comando `app:process-webhook`, a cada 10 s. Seleciona `Payment` com `is_notified = false` e `status != PENDING`.

Para cada um:

1. Sem `notification_url` → loga warning e pula (nunca marca como notificado).
2. `notification_attempts >= 5` → loga erro e desiste (permanece `is_notified = false`).
3. Respeita backoff pela tentativa atual: `[60s, 5min, 10min, 30min, 1h]` contado a partir de `last_notification_attempt`. A 1ª tentativa é imediata.
4. `POST <notification_url>`:

```
Content-Type: application/json
X-Webhook-Secret: <WEBHOOK_SECRET>

{ "payment_id": "<refExternal>", "status": "PAID" }   // PAID | FAILED | CANCELED
```

5. Qualquer 2xx → `is_notified = true`. Caso contrário incrementa `notification_attempts` e grava `last_notification_attempt`.

O cliente deve: validar o header `X-Webhook-Secret`, tratar a notificação de forma **idempotente** (pode chegar mais de uma vez) e responder 2xx rápido. `payment_id` é o `refExternal` que ele enviou, não um id nosso.

## 5. Ciclo de vida do `Payment`

```
            create-payment
                  │
              ┌───▼───┐  paid (polling)         ┌──────┐
              │PENDING├────────────────────────►│ PAID │
              └─┬───┬─┘                         └──────┘
   failed       │   │ expirationDate < now()    (webhook enviado em todos
  (polling) ┌───▼┐ ┌▼─────────┐                  os estados finais)
            │FAIL│ │ CANCELED │
            └────┘ └──────────┘
```

Estados finais não são reavaliados.

## 6. Configuração necessária

| Variável | Uso |
| :-- | :-- |
| `URL_INTEGRATION` | Base URL da Pagar.me (o código acrescenta `/v5/...`) |
| `USER_INTEGRATION` | Usuário do Basic Auth (secret key) |
| `PASSWORD_INTEGRATION` | Senha do Basic Auth (vazia na Pagar.me) |
| `WEBHOOK_SECRET` | Enviado em `X-Webhook-Secret` ao cliente |
| `APP_URL` | Base do `payment_link` |

Por conta (`Config`): `duration` (segundos de validade do PIX), `notification_url`, `redirect_url`, `api_token`.

## 7. Limitações conhecidas (resumo)

1. ~~Cobrança `failed` com HTTP 200 não tratada~~ — tratada (422 sem criar `Payment`).
2. Só `items[0]` e `quantity = 1` são enviados.
3. Conversão de fuso por soma fixa de 3h.
4. Polling sequencial (agora com `withoutOverlapping` e tolerância a falha de rede por pagamento); sem webhook da Pagar.me.
5. PIX pago após cancelamento local não é reconhecido.
6. `close_order` existe mas não é chamado: no sandbox não cancela PIX pendente (`PATCH /orders/{id}/closed` → 404 "order is closed"; `DELETE /charges/{id}` → 412). O PIX só expira; após cartão aprovado o polling o vigia e loga erro se for pago.
7. Webhook de saída que esgota as 5 tentativas fica sem notificar (sem alerta nem reprocessamento manual).
8. Ver também DOCUMENTACAO.md §8 para os itens de segurança (`api_token` em texto puro, `AccountResource` sem escopo, etc.).
