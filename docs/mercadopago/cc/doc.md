# Mercado Pago — Pagamento via Cartão de Crédito

> **⚠ Atualização 2026-11-21:** o gateway agora usa a **Orders API** (`POST /v1/orders`, `GET /v1/orders/{id}`, `POST /v1/orders/{id}/cancel`). O texto abaixo descreve a Payments API (`/v1/payments`) e está **desatualizado**; ver `app/Services/Gateways/MercadoPagoGateway.php` e CHANGELOG (18).

Resumo de como criar um pagamento com cartão de crédito na **Payments API** (`/v1/payments`, Checkout Transparente) do Mercado Pago. Gateway de **redundância**: só é usado quando a Pagar.me não consegue processar (ver [.changes/mercado-pago-fallback/plano.md](../../../.changes/mercado-pago-fallback/plano.md)).

> **Status no projeto:** **implementado em código** (`MercadoPagoGateway`, `GatewayManager`), **ainda sem validação em sandbox/produção** (spikes S1–S13 pendentes, ver [.changes/mercado-pago-fallback/tasks.md](../../../.changes/mercado-pago-fallback/tasks.md)). Este documento é o contrato da API do provedor, coletado da documentação oficial. Equivalente Pagar.me: [../../pagarme/cc/doc.md](../../pagarme/cc/doc.md). PIX: [../pix/doc.md](../pix/doc.md).

Fontes (documentação oficial, coletada em 2026-10-16):

- https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/integration-configuration/card/integrate-via-core-methods
- https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/create-payment/post
- https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-api-payments/get-payment/get
- https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/response-handling/query-results
- https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/how-tos/improve-payment-approval/reasons-for-rejection
- https://www.mercadopago.com.br/developers/pt/docs/checkout-api-payments/additional-content/your-integrations/test/cards

## Visão geral

Cartão é um pagamento criado em `POST /v1/payments` (mesmo endpoint e mesma autenticação do PIX). Como na Pagar.me, os dados do cartão **nunca passam pelo nosso servidor**: o browser os envia ao Mercado Pago via **MercadoPago.js v2** (campos PCI em iframes), recebe um `token` e o nosso backend usa o token. Diferente da Pagar.me (que tem `charges[0].last_transaction`), o resultado vem direto em `status` / `status_detail` do pagamento.

```
Browser ── MercadoPago.js (public key) ──► MP   tokeniza (mp.fields.createCardToken) → token (7 dias, 1 uso)
Browser ── POST /checkout/{id}/pay-card { token, installments, payment_method_id, issuer_id, ... } ──► Nosso backend
Nosso backend ── POST /v1/payments (Access Token + X-Idempotency-Key) ──► MP ──► adquirente
              ◄── 201 { id, status, status_detail, installments, card { last_four_digits, ... } }
```

## Pré-requisitos

- **Public Key** (frontend) e **Access Token** (backend) da mesma aplicação **e do mesmo ambiente** (teste ou produção). Nova env sugerida: `MP_PUBLIC_KEY`, `MP_ACCESS_TOKEN` (análogas a `STONE_PUBLIC_KEY_INTEGRATION` / `USER_INTEGRATION`).
- O **token é preso ao gateway**: um token gerado pelo MercadoPago.js só vale no Mercado Pago, e um token da Pagar.me só vale na Pagar.me. Por isso o fallback de cartão exige **re-tokenizar** no browser (ver plano).

## Frontend — MercadoPago.js v2

SDK: `<script src="https://sdk.mercadopago.com/js/v2"></script>` (ou npm `@mercadopago/sdk-js`, com `await loadMercadoPago()`). Inicialização: `const mp = new MercadoPago("PUBLIC_KEY")`.

### Campos do cartão (PCI)

```javascript
const cardNumberElement = mp.fields.create('cardNumber', { placeholder: 'Número do cartão' })
  .mount('form-checkout__cardNumber');
// idem para 'expirationDate' e 'securityCode', montados em divs próprias
```

As divs viram **iframes**; número, validade e CVV nunca tocam o nosso JavaScript. Isso muda o `resources/js/card-form.js` atual, que lê esses campos de inputs próprios (na Pagar.me a tokenização é um `fetch` a `api.pagar.me/core/v5/tokens` com os valores crus).

### Sequência de chamadas

| Etapa | Chamada | Resultado |
| :-- | :-- | :-- |
| Tipos de documento | `mp.getIdentificationTypes()` | lista `{ id, name }` (CPF/CNPJ) |
| Bandeira pelo BIN | `mp.getPaymentMethods({ bin })` (a cada `binChange` do `cardNumber`) | `results[0].id` = `payment_method_id` (`visa`, `master`, `elo`...); `settings[0]` traz regras de validação de número e CVV |
| Emissor | `mp.getIssuers({ paymentMethodId, bin })` se `additional_info_needed` contiver `issuer_id`; senão usar o `issuer` retornado em `getPaymentMethods` | `issuer_id` |
| Parcelas | `mp.getInstallments({ amount, bin, paymentTypeId: 'credit_card' })` | `[0].payer_costs[]` (cada item: `installments`, `recommended_message`, ...) |
| Token | `mp.fields.createCardToken({ cardholderName, identificationType, identificationNumber })` | `token.id` |

- O **token vale 7 dias e só pode ser usado uma vez**: gerar um novo a cada tentativa.
- A página oficial documenta `payer_costs[].installments` e `recommended_message`; os campos de valor total/juros por parcela **não foram coletados** (ver [Parcelamento](#parcelamento-e-juros)).

## Corpo da requisição — criar pagamento com cartão

`POST https://api.mercadopago.com/v1/payments`

Headers: `Authorization: Bearer <ACCESS_TOKEN>`, `Content-Type: application/json`, **`X-Idempotency-Key`** (obrigatório; ausente → erro `4292`).

| Campo | Tipo | Obrig. | Descrição |
| :-- | :-- | :-- | :-- |
| `transaction_amount` | número | sim | Valor em **reais** (`100.00`). Sempre ler do `Payment`, nunca do request do browser. |
| `token` | string | sim | Token do cartão (MercadoPago.js). Erros: `2006` (não encontrado), `2062` (inválido). |
| `installments` | inteiro | sim | Nº de parcelas. Erros: `4004` (nulo), `4005` (não numérico), `4033` (inválido). |
| `payment_method_id` | string | sim | Bandeira (`visa`, `master`, `amex`, `elo`...), vinda de `getPaymentMethods`. Ausente → `4001`; não inferido → `2131`. |
| `issuer_id` | inteiro/string | quando exigido | Emissor, vindo de `getIssuers`/`getPaymentMethods`. Erros: `2009`, `3001`. |
| `payer.email` | string | sim | E-mail do comprador. |
| `payer.identification.type` / `.number` | string | recomendado | `CPF`/`CNPJ` e número. A página manda enviar o máximo de dados do comprador para reduzir recusa antifraude. |
| `payer.first_name` / `payer.last_name` | string | não | Nome e sobrenome. |
| `description` | string | não | Descrição. |
| `external_reference` | string | não | Referência do integrador, **pesquisável** (`/v1/payments/search`). Usar o `payment.id` (equivale ao `code` da Pagar.me). |
| `statement_descriptor` | string | não | Texto na fatura (equivale ao `statement_descriptor` da Pagar.me; o limite não foi coletado, validar). |
| `notification_url` | string | não | Webhook por pagamento (`https://`, < 500 caracteres). |
| `capture` | booleano | não | `true` (padrão) = captura imediata, equivalente ao `auth_and_capture` da Pagar.me. `false` = só autoriza (`authorized/pending_capture`); o erro `2077` indica que captura diferida não é suportada em alguns cenários. |
| `binary_mode` | booleano | não | `true`: o pagamento só pode ser `approved` ou `rejected` (sem `in_process`). Útil para resposta imediata no checkout; **perde a análise manual** (mais recusas). Decisão em aberto. |
| `additional_info` | objeto | não | Dados para antifraude (itens, pagador, endereço). Melhora a aprovação. |
| `metadata` | objeto | não | JSON livre (`payment_id`, `refExternal`). |
| `date_of_expiration` | string | não | Validade do pagamento pendente (formato `yyyy-MM-dd'T'HH:mm:ss.SSSz`). |

Exemplo:

```json
{
    "transaction_amount": 100,
    "token": "<card_token>",
    "installments": 1,
    "payment_method_id": "visa",
    "issuer_id": 310,
    "description": "Pedido 1234",
    "external_reference": "0b6a7c1e-0000-0000-0000-000000000000",
    "statement_descriptor": "PAGOUNUMCLICK",
    "payer": {
        "email": "cliente@example.com",
        "first_name": "Tony",
        "last_name": "Stark",
        "identification": { "type": "CPF", "number": "12345678909" }
    },
    "metadata": { "payment_id": "0b6a7c1e-0000-0000-0000-000000000000" }
}
```

Diferenças em relação ao corpo da Pagar.me:

| Pagar.me (v5) | Mercado Pago |
| :-- | :-- |
| `amount` em **centavos** | `transaction_amount` em **reais** |
| `payments[0].credit_card.card_token` | `token` (raiz) |
| `credit_card.card.billing_address` (obrigatório no projeto) | endereço **não é exigido** para cartão (opcional em `payer.address`/`additional_info`); o formulário de CEP/endereço deixa de ser necessário neste gateway |
| `customer` com `phones.home_phone` | `payer` sem telefone obrigatório |
| `Idempotency-Key` opcional | `X-Idempotency-Key` **obrigatório** |
| `code` do pedido | `external_reference` |
| bandeira inferida pelo token | `payment_method_id` (e `issuer_id`) enviados explicitamente |

## Resposta e status

HTTP `201` com o pagamento. **Não presumir sucesso pelo HTTP**: ler `status` e `status_detail`.

Campos úteis: `id`, `status`, `status_detail`, `transaction_amount`, `installments`, `payment_method_id`, `payment_type_id`, `date_approved`, `external_reference`, `transaction_details` (`net_received_amount`, `total_paid_amount`, `installment_amount`), `card` (último(s) dígitos, titular).

**Mapa para `ChargeOutcome`** (hoje derivado de `charges[0].last_transaction.status` da Pagar.me):

| `status` MP | `status_detail` | `ChargeOutcome` |
| :-- | :-- | :-- |
| `approved` | `accredited` | `PAID` |
| `rejected` | `cc_rejected_*`, `rejected_*`, `bank_error` etc. (tabela abaixo) | `DECLINED` |
| `in_process` | `pending_contingency`, `pending_review_manual`, `offline_process`, `deferred_retry` | `PENDING_REVIEW` (aguardar polling; retorno em até 2 dias úteis) |
| `pending` | `pending_challenge` (aguarda challenge) | `PENDING_REVIEW` |
| `authorized` | `pending_capture` | `PENDING_REVIEW` (só ocorre com `capture=false`) |
| `cancelled` | `expired`, `by_collector`, `by_payer` | `DECLINED` (libera nova tentativa) |
| qualquer outro | — | `UNKNOWN` |

Campos do `Payment` preenchidos no `markCardPaid`: `card_brand` ← `payment_method_id`; `card_last_four` ← `card.last_four_digits`; `installments` ← `installments`; `acquirer_tid`/`acquirer_nsu`/`acquirer_auth_code` → **verificar em sandbox** onde o MP expõe NSU/TID/código de autorização (ex.: `transaction_details`, `authorization_code`); não foram coletados.

### Motivos de recusa (`status = rejected`)

| `status_detail` | Significado | Mensagem sugerida / ação |
| :-- | :-- | :-- |
| `cc_rejected_bad_filled_card_number` | Número do cartão incorreto | Revisar o número |
| `cc_rejected_bad_filled_date` | Validade incorreta | Revisar a data |
| `cc_rejected_bad_filled_security_code` | CVV incorreto | Revisar o CVV |
| `cc_rejected_bad_filled_other` | Outro dado incorreto | Revisar os dados |
| `cc_rejected_call_for_authorize` | Emissor exige autorização prévia | Contatar o emissor |
| `cc_rejected_card_disabled` | Cartão desativado/bloqueado | Ativar com o emissor ou outro cartão |
| `cc_rejected_duplicated_payment` | Pagamento idêntico já realizado | Verificar cobrança anterior antes de repetir |
| `cc_rejected_insufficient_amount` | Saldo/limite insuficiente | Outro cartão ou meio |
| `cc_rejected_invalid_installments` | Emissor não aceita o parcelamento | Alterar nº de parcelas |
| `cc_rejected_max_attempts` | Limite de tentativas excedido | Outro cartão; evitar tentativa imediata |
| `cc_rejected_blacklist` | Bloqueado pelo antifraude | Outro meio |
| `cc_rejected_high_risk` | Recusado por risco | Outro meio; evitar repetição imediata |
| `cc_rejected_other_reason` | Emissor não processou, sem detalhe | Contatar o emissor ou outro meio |
| `cc_rejected_time_out` | Sem resposta no tempo esperado | Tentar novamente |
| `cc_rejected_3ds_challenge` / `cc_rejected_3ds_mandatory` | 3DS não superado / obrigatório não realizado | Refazer a autenticação |
| `cc_rejected_card_type_not_allowed` | Crédito não habilitado no cartão múltiplo | Habilitar a função crédito ou outro meio |
| `cc_amount_rate_limit_exceeded` | Limite do meio de pagamento excedido | Tentar mais tarde ou outro meio |
| `rejected_insufficient_data` | Faltam dados obrigatórios | Completar a requisição (erro **nosso**) |
| `rejected_by_bank` | Recusado pelo banco | Contatar o banco ou outro meio |
| `rejected_by_regulations` | Recusado por regulamentação | — |
| `rejected_high_risk` | Risco / suspeita de fraude | Outro meio |
| `rejected_by_biz_rule` | Regra de negócio do recebedor | Revisar regras da conta |
| `rejected_other_reason` | Não classificado | Outro meio |

Recusa é **decisão legítima**: não aciona failover entre gateways (ver plano). `cc_rejected_time_out` e `rejected_insufficient_data` são exceções a avaliar (o primeiro é infra do emissor; o segundo é bug nosso).

Outros status (pós-aprovação): `approved/partially_refunded`, `refunded` (`refunded`, `by_admin`), `charged_back` (`settled`, `reimbursed`, `in_process`), `in_mediation/pending`. Não existem no `StatusPaymentEnum` hoje: logar.

## Parcelamento e juros

**Ponto crítico de negócio.** O projeto hoje oferece parcelamento **sem juros** na Pagar.me (o pagador paga o `amount` do `Payment`, mesmo em 12x; o custo está na tarifa do lojista por faixa de parcelas: 1x, 2–6x, 7–12x).

No Mercado Pago o parcelamento pode ter **juros para o comprador**, conforme a configuração da conta (parcelas sem juros definidas no vendedor/aplicação). Se houver juros, `getInstallments` devolve um valor total maior que `transaction_amount`, e o pagamento aprovado traz `transaction_details.total_paid_amount` maior que o valor do `Payment`.

A documentação coletada **não detalha** os campos de custo por parcela (`payer_costs[].total_amount`, taxa) nem como configurar "sem juros" via API (em Checkout Pro isso é feito por preferência com `installments`/faixa sem juros). **Spike obrigatório** antes de oferecer parcelamento no MP:

1. Ver o JSON real de `getInstallments` em sandbox.
2. Descobrir como a conta absorve os juros (sem juros para o comprador) e a tarifa resultante por faixa.
3. Decidir se o fallback de cartão oferece só **1x** (mais simples, sem risco de cobrar valor diferente do `Payment`) ou parcelado.

Regra a manter: o valor cobrado vem sempre do `Payment`; se `total_paid_amount` divergir de `amount`, tratar como alerta (log de erro), pois o `Payment.amount` e o que o lojista recebe deixariam de bater.

## Consultar e reconciliar

- `GET /v1/payments/{id}`: estado do pagamento (equivale a `get_order` da Pagar.me). Erros: `400` (código `1`), `403` (`4`, `3002`), `404` (`2000`).
- `GET /v1/payments/search?external_reference={payment.id}&sort=date_created&criteria=desc&range=date_created&begin_date=NOW-30DAYS&end_date=NOW`: equivale a `get_orders_by_code`, usado para reconciliar uma tentativa que deu timeout (a chamada pode ter criado o pagamento sem resposta). Parâmetros e erros detalhados em [../pix/doc.md](../pix/doc.md#buscar-por-referência-reconciliação).
- **Idempotência:** repetir o `POST` com a **mesma** `X-Idempotency-Key` devolve o mesmo resultado sem duplicar. Manter a lógica atual de `card_attempt_key` (chave nova por tentativa, reaproveitada após timeout).

## Webhook, cancelamento e reembolso

Iguais ao PIX: ver [../pix/doc.md](../pix/doc.md#webhook-de-entrada-opcional--o-projeto-usa-polling). Reembolso de cartão usa `POST /v1/payments/{id}/refunds` (total ou parcial); cancelar uma autorização pendente usa `PUT /v1/payments/{id}` com `{"status":"cancelled"}`. Não coletados em detalhe para cartão.

## Erros frequentes

| HTTP | Código | Significado |
| :-- | :-- | :-- |
| 400 | `1` | Erro de parâmetros |
| 400 | `2006` / `2062` | Token não encontrado / inválido (token expirou ou já foi usado → gerar novo) |
| 400 | `2034` | Usuários de teste e produção misturados |
| 400 | `4001` / `2131` | `payment_method_id` ausente / não inferido |
| 400 | `4004` / `4005` / `4033` | `installments` nulo / não numérico / inválido |
| 400 | `4292` | `X-Idempotency-Key` ausente |
| 401 | — | Credencial de produção usada indevidamente / sem escopo `payment` |
| 403 | `4`, `3002`, `pa_unauthorized_result_from_policies` | Sem permissão / `Authorization` ausente |
| 403 | — | Em teste, `payer.email` terminado em `@testuser.com` → "Payer email forbidden" |

## Testes e sandbox

Usar credenciais de **teste**. O **nome do titular** define o cenário simulado; o resto do cartão é fixo.

| Bandeira | Número | CVV | Validade |
| :-- | :-- | :-- | :-- |
| Mastercard (crédito) | 5480 8328 0103 3311 | 123 | 11/30 |
| Visa (crédito) | 4235 6477 2802 5682 | 123 | 11/30 |
| American Express (crédito) | 3753 651535 56885 | 1234 | 11/30 |
| Elo (débito) | 5067 7667 8388 8311 | 123 | 11/30 |

| Titular | Resultado simulado |
| :-- | :-- |
| `APRO` | Aprovado |
| `CONT` | Pendente |
| `OTHE` | Recusado, erro geral |
| `CALL` | Recusado, validar para autorizar |
| `FUND` | Recusado, quantia insuficiente |
| `SECU` | Recusado, CVV inválido |
| `EXPI` | Recusado, problema na validade |
| `FORM` | Recusado, erro no formulário |
| `CARD` | Rejeitado, falta `card_number` |
| `INST` | Rejeitado, parcelas inválidas |
| `DUPL` | Rejeitado, pagamento duplicado |
| `LOCK` | Rejeitado, cartão desabilitado |
| `CTNA` | Rejeitado, tipo de cartão não permitido |
| `ATTE` | Rejeitado, tentativas de PIN excedidas |
| `BLAC` | Rejeitado, lista negra |

Documento de teste: **CPF `12345678909`** (usado em `APRO` e `OTHE`; os demais cenários não exigem documento específico).

Esses números são **cartões de teste oficiais do Mercado Pago**, públicos. Mesmo assim, no projeto mantenha o padrão: fixtures de teste só com `Http::fake` e tokens falsos (`token_...`), nunca números de cartão em arquivos.

## Pontos a confirmar nos spikes

1. Payments API vs Orders API (`/v1/orders`): qual adotar (mesma decisão do PIX).
2. Estrutura real de `getInstallments` (`payer_costs`) e como a conta oferece parcelas sem juros; decidir 1x vs parcelado no fallback.
3. Onde o MP expõe TID/NSU/código de autorização para preencher `acquirer_*`.
4. Limite de `statement_descriptor` e se o valor aparece na fatura como configurado.
5. `binary_mode` ligado ou desligado no checkout (resposta imediata vs análise manual).
6. 3DS: se a conta exige/oferece desafio 3DS no Brasil e como o frontend trata `cc_rejected_3ds_*`.
7. Fórmula do manifesto da assinatura do webhook (se for implementar o webhook de entrada).
8. Tarifas por faixa e prazo de liberação (comercial, com o cliente).
