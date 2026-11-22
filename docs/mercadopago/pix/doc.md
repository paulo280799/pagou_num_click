# Mercado Pago — Pagamento via PIX

> **⚠ Atualização 2026-11-21:** o gateway agora usa a **Orders API** (`POST /v1/orders`, `GET /v1/orders/{id}`, `POST /v1/orders/{id}/cancel`). O texto abaixo descreve a Payments API (`/v1/payments`) e está **desatualizado**; ver `app/Services/Gateways/MercadoPagoGateway.php` e CHANGELOG (18).

Resumo de como criar e acompanhar um pagamento PIX na **Payments API** (`/v1/payments`, Checkout Transparente) do Mercado Pago. Gateway de **redundância**: só é usado quando a Pagar.me não consegue gerar o pagamento (ver [.changes/mercado-pago-fallback/plano.md](../../../.changes/mercado-pago-fallback/plano.md)).

> **Status no projeto:** **implementado em código** (`MercadoPagoGateway`, `GatewayManager`), **ainda sem validação em sandbox/produção** (spikes S1–S13 pendentes, ver [.changes/mercado-pago-fallback/tasks.md](../../../.changes/mercado-pago-fallback/tasks.md)). Este documento é o contrato da API do provedor, coletado da documentação oficial. Equivalente Pagar.me: [../../pagarme/pix/doc.md](../../pagarme/pix/doc.md).

Fontes (documentação oficial, coletada em 2026-10-16):

- https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/integrate-pix
- https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-payment/post
- https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/get-payment/get
- https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/search-payments/get
- https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/response-handling/query-results
- https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/additional-content/your-integrations/notifications/webhooks
- https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/payment-management/cancellations-and-refunds/refund-pix

> **Atenção — duas APIs:** o Mercado Pago tem a **Payments API** (`/v1/payments`, documentada aqui) e a mais nova **Orders API** (`/v1/orders`, [referência](https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api/create-order/post)). Este documento cobre só a Payments API. A escolha entre as duas é decisão em aberto (ver [Pontos a confirmar](#pontos-a-confirmar-nos-spikes)).

## Visão geral

PIX é um pagamento criado diretamente em `POST /v1/payments` com `payment_method_id = "pix"`. Diferente da Pagar.me, **não existe o conceito de "pedido" com `charges[]`**: o objeto é o próprio **pagamento** (`id` numérico). A resposta já traz o QR Code e o copia-e-cola em `point_of_interaction.transaction_data`. O pagador paga no app do banco e o `status` muda para `approved`.

```
Nosso sistema ──POST /v1/payments (payment_method_id=pix)──► Mercado Pago
              ◄── 201 { id, status=pending, point_of_interaction.transaction_data { qr_code, qr_code_base64, ticket_url } }
Pagador paga no app do banco
Nosso sistema ──GET /v1/payments/{id} (polling)──► Mercado Pago  (status → approved)
```

## Pré-requisitos

- Aplicação criada em **Suas integrações** (painel de desenvolvedores) com as credenciais: **Access Token** (backend, nunca no frontend) e **Public Key** (frontend; para PIX não é necessária, só para cartão).
- **Chave PIX cadastrada** na conta do Mercado Pago que recebe (sem isso a conta não oferece PIX).
- Credenciais de **teste** e de **produção** são distintas (misturar usuários de teste e produção gera erro `2034`).

## Autenticação

`Authorization: Bearer <ACCESS_TOKEN>`. Base URL: `https://api.mercadopago.com`.

Token sem o escopo `payment` ou credencial de produção usada indevidamente → `401`.

## Headers da criação

| Header | Obrigatório | Descrição |
| :-- | :-- | :-- |
| `Authorization` | sim | `Bearer <ACCESS_TOKEN>` |
| `Content-Type` | sim | `application/json` |
| `X-Idempotency-Key` | **sim** | Valor único por tentativa. Repetir a requisição com a mesma chave não duplica o pagamento. Ausente → erro `4292`. |

Diferença importante da Pagar.me: aqui a chave de idempotência é **obrigatória**. Usar uma chave estável por `Payment` (ex.: `payment.id` + sufixo de tentativa) torna o reenvio seguro após timeout.

## Corpo da requisição — criar pagamento PIX

`POST https://api.mercadopago.com/v1/payments`

| Campo | Tipo | Obrig. | Descrição |
| :-- | :-- | :-- | :-- |
| `transaction_amount` | número | sim | Valor em **reais** (ex.: `29.90`), não em centavos (a Pagar.me usa centavos). |
| `payment_method_id` | string | sim | `"pix"`. |
| `payer.email` | string | sim | E-mail do comprador. |
| `payer.identification.type` | string | recomendado | `CPF` ou `CNPJ`. |
| `payer.identification.number` | string | recomendado | Documento, só dígitos. |
| `payer.first_name` / `payer.last_name` | string | não | Nome e sobrenome (a Pagar.me recebe um `name` único: será preciso separar). |
| `payer.address` | objeto | não | `zip_code`, `street_name`, `street_number`, `neighborhood`, `city`, `federal_unit`. |
| `description` | string | não | Descrição do pagamento. |
| `external_reference` | string | não | Referência do integrador, **pesquisável** via `/v1/payments/search`. Usar o `payment.id` (equivale ao `code` da Pagar.me). |
| `date_of_expiration` | string | não | Validade do PIX. Formato `yyyy-MM-dd'T'HH:mm:ss.SSSz` (com fuso, ex.: `2026-10-16T18:30:00.000-03:00`). Padrão **24 h**; mínimo **30 min**, máximo **30 dias**. |
| `notification_url` | string | não | URL de webhook **por pagamento** (`https://`, < 500 caracteres). Tem prioridade sobre a configurada no painel. |
| `metadata` | objeto | não | JSON livre (ex.: `payment_id`, `refExternal`). |

A página oficial indica tipo/número do documento nos exemplos mas não os marca como obrigatórios; enviar sempre, pois reduz recusas.

Exemplo:

```json
{
    "transaction_amount": 29.9,
    "payment_method_id": "pix",
    "description": "Pedido 1234",
    "external_reference": "0b6a7c1e-0000-0000-0000-000000000000",
    "date_of_expiration": "2026-10-16T18:30:00.000-03:00",
    "payer": {
        "email": "cliente@example.com",
        "first_name": "Tony",
        "last_name": "Stark",
        "identification": { "type": "CPF", "number": "01234567890" }
    },
    "metadata": { "payment_id": "0b6a7c1e-0000-0000-0000-000000000000", "refExternal": "1234" }
}
```

## Resposta — `201 Created`

Campos principais (a resposta completa é maior):

| Campo | Descrição |
| :-- | :-- |
| `id` | ID numérico do pagamento. Equivale ao `ide` da Pagar.me (guardar como string). |
| `status` | `pending` na criação. |
| `status_detail` | `pending_waiting_transfer` na criação. |
| `date_of_expiration` | Expiração efetiva do PIX. |
| `transaction_amount` | Valor cobrado (reais). |
| `external_reference` | Eco do que foi enviado. |
| `point_of_interaction.type` | `"PIX"`. |
| `point_of_interaction.transaction_data.qr_code` | **Copia-e-cola** (equivale a `last_transaction.qr_code` da Pagar.me). |
| `point_of_interaction.transaction_data.qr_code_base64` | **Imagem** do QR Code em base64 (PNG/JPEG). |
| `point_of_interaction.transaction_data.ticket_url` | Página hospedada do MP com QR + copia-e-cola. |
| `point_of_interaction.transaction_data.transaction_id` | ID da transação PIX. |
| `transaction_details` | `net_received_amount`, `total_paid_amount`, `overpaid_amount`, `external_resource_url`, `installment_amount`, `financial_institution`, `transaction_id`. |

### Mapeamento para o que o projeto grava hoje

| Hoje (Pagar.me) | Mercado Pago |
| :-- | :-- |
| `ide` = `id` do pedido | `ide` = `id` do pagamento (string) |
| `copyPaste` = `charges[0].last_transaction.qr_code` | `point_of_interaction.transaction_data.qr_code` |
| `qrCode` = `charges[0].last_transaction.qr_code_url` (**URL** de imagem) | `qr_code_base64` (**base64**, não URL). Renderizar como `data:image/png;base64,...` ou usar `ticket_url`. A view do checkout hoje espera URL. |
| `amount` = `amount / 100` | `transaction_amount` (já em reais) |

## Exibição ao pagador

1. `qr_code_base64` como imagem (`data:image/png;base64,...`) + `qr_code` num campo copiável.
2. Alternativa: link/botão para `ticket_url`.
3. O QR vigente é de uso único.

## Consultar status

`GET https://api.mercadopago.com/v1/payments/{id}` (header `Authorization`). Equivale ao `GET /v5/orders/{id}` da Pagar.me.

- `200`: pagamento. Ler `status` / `status_detail`.
- `400` (código `1`): parâmetros inválidos. `403` (códigos `4` e `3002`): sem permissão. `404` (código `2000`): pagamento não encontrado.

### Buscar por referência (reconciliação)

`GET https://api.mercadopago.com/v1/payments/search?sort=date_created&criteria=desc&external_reference={payment.id}&range=date_created&begin_date=NOW-30DAYS&end_date=NOW`

| Parâmetro | Descrição |
| :-- | :-- |
| `sort` | `date_approved`, `date_created`, `date_last_updated`, `id` ou `money_release_date`. |
| `criteria` | `asc` ou `desc`. |
| `external_reference` | Referência enviada na criação. |
| `range` | Atributo do intervalo: `date_created`, `date_last_updated`, `date_approved` ou `money_release_date`. |
| `begin_date` / `end_date` | Intervalo (ex.: `NOW-30DAYS` / `NOW`). Máx. 365 dias (erro `9062`). Formato de data inválido → `1001`. |

Resposta: `{ "paging": { "total", "limit", "offset" }, "results": [ { id, status, status_detail, transaction_amount, external_reference, ... } ] }`. Cobre os últimos 12 meses. Equivale a `get_orders_by_code` da Pagar.me.

## Status do pagamento → `StatusPaymentEnum`

| `status` MP | `status_detail` típico (PIX) | `StatusPaymentEnum` |
| :-- | :-- | :-- |
| `pending` | `pending_waiting_transfer` | `PENDING` |
| `in_process` | `pending_contingency`, `pending_review_manual`, `offline_process`, `deferred_retry` | `PENDING` |
| `approved` | `accredited` | `PAID` |
| `approved` | `partially_refunded` | `PAID` (reembolso parcial: só registrar) |
| `rejected` | ver motivos em [../cc/doc.md](../cc/doc.md) | `FAILED` |
| `cancelled` | `expired`, `by_collector`, `by_payer` | `CANCELED` |
| `refunded` | `refunded`, `by_admin` | sem equivalente no enum hoje (logar; mesmo tratamento do `REFUNDED` da Pagar.me) |
| `charged_back` | `settled`, `reimbursed`, `in_process` | sem equivalente no enum hoje (logar) |
| `in_mediation` | `pending` | sem equivalente no enum hoje (logar) |

Observação: a tabela oficial cita `cancelled/expired` como "cancelado após 30 dias pendente"; para PIX com `date_of_expiration` curto, o cancelamento por expiração deve ocorrer na data informada. **Confirmar em sandbox** (o projeto também cancela localmente por `expirationDate`).

## Webhook de entrada (opcional — o projeto usa polling)

O Mercado Pago pode notificar o tópico `payment`. No projeto a confirmação é por polling a cada 10 s, igual à Pagar.me; o webhook de entrada é fase opcional do plano.

- **Configuração:** por pagamento (`notification_url` na criação) ou em Suas integrações → Webhooks (URL de teste e de produção separadas).
- **Corpo (`POST`, JSON):**

```json
{
    "id": 12345,
    "live_mode": true,
    "type": "payment",
    "date_created": "2015-03-25T10:04:58.396-04:00",
    "user_id": 44444,
    "api_version": "v1",
    "action": "payment.created",
    "data": { "id": "999999999" }
}
```

- **O corpo não traz o status.** Após receber, consultar `GET /v1/payments/{data.id}`.
- **Resposta esperada:** `200` ou `201`, em até **22 s**. Sem confirmação: nova tentativa a cada **15 min** (continua após a 3ª).
- **Assinatura:** header `x-signature` no formato `ts=...,v1=...`, validado com a **assinatura secreta** da aplicação (Suas integrações → Webhooks, não expira). Os SDKs oficiais validam com `x-signature`, `x-request-id`, `data.id` (query string `data_id`) e a chave secreta. A documentação coletada não traz a fórmula do HMAC-SHA256 sem SDK; **buscar na aba "Sem SDKs" da página de webhooks antes de implementar**.

## Cancelar e reembolsar

- **Reembolso PIX:** `POST /v1/payments/{payment_id}/refunds`, headers `Authorization`, `Content-Type`, `X-Idempotency-Key`; corpo opcional `{"amount": 20}` (parcial). Header opcional `X-Render-In-Process-Refunds: true` devolve `201` com `status: "in_process"` quando há contingência (ex.: comunicação com o Bacen); sem ele, erro de processamento vira `400`. Quando aprovado, o pagamento vai a `refunded`.
- **Cancelar PIX pendente:** a API tem `PUT /v1/payments/{id}` com `{"status":"cancelled"}` ([referência](https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-cancellation/put)); **não coletado em detalhe**. Ao contrário da Pagar.me (PIX não cancelável), vale validar em sandbox se o PIX do MP pode ser cancelado — isso simplificaria o caso "PIX aberto de um `Payment` pago no cartão".

## Erros frequentes (criação)

| HTTP | Código | Significado |
| :-- | :-- | :-- |
| 400 | `1` | Erro de parâmetros. |
| 400 | `8` | Nome de parâmetro incorreto. |
| 400 | `2004` | Falha no gateway. |
| 400 | `2034` | Usuários de teste e produção misturados. |
| 400 | `4292` | `X-Idempotency-Key` ausente. |
| 400 | `4020` / `4021` | `notification_url` inválida (precisa de `https://`, < 500 caracteres). |
| 400 | `23` | `date_of_expiration` em formato inválido. |
| 400 | `4022` | `metadata` com JSON inválido. |
| 401 | — | Credencial de produção usada indevidamente / token sem escopo `payment`. |
| 403 | `4`, `3002`, `pa_unauthorized_result_from_policies` | Sem permissão, ou header `Authorization` ausente. |

A página lista códigos de `1` a `7523`; consultar a [referência](https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-payment/post) para os demais.

### Quando acionar o fallback (do plano)

| Resposta do MP | Significa | Ação |
| :-- | :-- | :-- |
| timeout, `ConnectionException`, 5xx, 429 | MP indisponível | tratar como falha de infra (aqui, o MP é o **segundo** gateway: se também falhar, devolver erro ao cliente) |
| 4xx de validação | dado ruim ou bug nosso | não tentar outro gateway, devolver 422 |

## Testes

- Usar credenciais de **teste** (Access Token de teste). PIX exige chave PIX cadastrada na conta.
- Passo a passo oficial de compra teste com PIX: https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders/integration-test/pix (a página coletada só lista os pré-requisitos, **o método de simular a aprovação do PIX em sandbox ainda precisa ser levantado**).
- Em ambiente de teste, e-mails `<nickname>@testuser.com` no `payer.email` retornam `403 "Payer email forbidden"`; usar e-mail comum (ex.: `test_payer@example.com`).
- Fixtures: guardar o JSON real das respostas de sandbox em `tests/` (nunca dados reais de pagador).

## Pontos a confirmar nos spikes

1. Payments API (`/v1/payments`) vs Orders API (`/v1/orders`): qual adotar. A Orders API é a mais recente; a Payments API é a mais documentada e simples.
2. Como simular aprovação de PIX no sandbox.
3. Se o PIX pendente pode ser cancelado (`PUT /v1/payments/{id}`).
4. Comportamento exato de expiração com `date_of_expiration` curto (`cancelled/expired`).
5. Fórmula do manifesto da assinatura do webhook (se for implementar o webhook de entrada).
6. Tarifa do PIX e prazo de liberação (comercial, com o cliente).
