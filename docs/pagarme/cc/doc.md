# Pagar.me — Pagamento via Cartão de Crédito

Resumo de como criar um pagamento com cartão de crédito na API v5 da Pagar.me.

> **Status no projeto:** **implementado** (Planos 2 e 3): rota `POST /checkout/{id}/pay-card`, tokenização no browser, `auth_and_capture`, parcelamento sem juros. Como funciona no código: [integration.md](integration.md). Ver também `DOCUMENTACAO.md` §4 e `.changes/checkout-v2/`.

Fonte: https://docs.pagar.me/v5/reference/cartão-de-crédito-1

## Visão geral

Cartão é um meio de pagamento do nó `payments[]` de um **pedido** (`POST /v5/orders`, mesmo endpoint e mesma autenticação Basic Auth do PIX). Diferente do PIX, a resposta é quase imediata: `charges[0].last_transaction.status` já indica se foi autorizado/capturado ou recusado.

```
Nosso sistema ──POST /v5/orders (payment_method=credit_card)──► Pagar.me ──► adquirente
              ◄── charges[0] { status, last_transaction.status, acquirer_message }
```

## Objeto `credit_card`

| Campo | Tipo | Descrição |
| :-- | :-- | :-- |
| `installments` | integer | Nº de parcelas. Padrão `1`. |
| `statement_descriptor` | string | Texto na fatura. Máx. 13 caracteres (PSP) ou 22 (gateway). |
| `operation_type` | string | `auth_and_capture` (padrão), `auth_only` ou `pre_auth`. |
| `card` / `card_id` / `card_token` / `network_token` | object / string | **Um** é obrigatório: dados brutos do cartão, ID de cartão salvo, token, ou network token. |
| `recurrence_cycle` | string | `first` ou `subsequent` (cobranças recorrentes). |
| `authentication` | object | Autenticação 3DS (quando aplicável). |

Campos comuns do pagamento: `payment_method` (`"credit_card"`), `amount` (centavos), `metadata`.

### Objeto `card`

`number`, `holder_name`, `exp_month`, `exp_year`, `cvv`, `billing_address` (`line_1`, `zip_code`, `city`, `state`, `country`).

> **Segurança:** não trafegar nem logar número/CVV do cartão no backend. Usar `card_token` (tokenizar no front, ver abaixo) ou `card_id`. Tokenização evita escopo PCI no servidor.

## Tokenização do cartão (abordagem adotada)

O navegador do pagador envia os dados do cartão **direto para a Pagar.me**, recebe um token, e só o token passa pelo nosso backend.

`POST https://api.pagar.me/core/v5/tokens?appId={PUBLIC_KEY}`

- Autenticação **somente** com a `public_key` (`pk_...`) no query param `appId`. Nunca enviar a secret key (`sk_...`) nesta chamada: ela roda no front.

```json
{
  "type": "card",
  "card": {
    "number": "4000000000000010",
    "holder_name": "Tony Stark",
    "exp_month": 1,
    "exp_year": 30,
    "cvv": "651",
    "label": "Sua bandeira"
  }
}
```

Resposta:

```json
{
  "id": "token_zVw7rqvHEPH8XlbE",
  "type": "card",
  "created_at": "2018-06-18T18:48:58Z",
  "expires_at": "2018-06-18T18:49:58Z",
  "card": {
    "last_four_digits": "5580",
    "holder_name": "Tony Stark",
    "exp_month": 1,
    "exp_year": 30,
    "brand": "Visa",
    "label": "Sua bandeira"
  }
}
```

Uso no pedido (`POST /v5/orders`, com a secret key como sempre):

```json
"payments": [
    {
        "payment_method": "credit_card",
        "credit_card": {
            "installments": 1,
            "statement_descriptor": "MINHALOJA",
            "card_token": "token_zVw7rqvHEPH8XlbE",
            "card": {
                "billing_address": {
                    "line_1": "10880, Malibu Point, Malibu Central",
                    "zip_code": "90265",
                    "city": "Malibu",
                    "state": "CA",
                    "country": "US"
                }
            }
        }
    }
]
```

Pontos de atenção:

- O **billing address não é tokenizado**: precisa ir no pedido, junto do `card_token`.
- Token tem vida curta e expira (veja `expires_at`; a doc cita 1 hora, mas o exemplo oficial mostra 1 minuto, então tratar como expiração rápida). Criar o pedido logo após tokenizar; se expirar, tokenizar de novo.
- Depois da primeira cobrança, o `card.id` (`card_...`) retornado permite recompra via `card_id` sem novo token.
- A `public_key` fica exposta no front por design. Guardar em `.env` (ex.: `PUBLIC_KEY_INTEGRATION`) e injetar na view.

Fonte: https://docs.pagar.me/reference/criar-token-cartão-1

### 3DS (`authentication`)

| Campo | Descrição |
| :-- | :-- |
| `type` | `threed_secure` (único valor suportado) |
| `threed_secure.mpi` | `pagarme` ou `third_party` |
| MPI `pagarme` | exige `transaction_id` |
| MPI `third_party` | exige `eci`, `cavv`, `ds_transaction_id`, `version` |

Versões 3DS suportadas: 2.1.0 e 2.2.0. Disponível para Visa/Mastercard.

## Tipos de operação

| `operation_type` | Comportamento |
| :-- | :-- |
| `auth_and_capture` | Autoriza e captura na hora (padrão). |
| `auth_only` | Só autoriza; captura depois (`status: authorized_pending_capture`). |
| `pre_auth` | Pré-autorização; exige aprovação prévia do adquirente. |

## Requisição — criar pedido com cartão

`POST {URL_INTEGRATION}/v5/orders`

```json
{
    "items": [
        { "amount": 2990, "description": "Chaveiro do Tesseract", "quantity": 1 }
    ],
    "customer": {
        "name": "Tony Stark",
        "email": "avengerstark@ligadajustica.com.br"
    },
    "payments": [
        {
            "payment_method": "credit_card",
            "credit_card": {
                "recurrence_cycle": "first",
                "installments": 1,
                "statement_descriptor": "AVENGERS",
                "card": {
                    "number": "4000000000000010",
                    "holder_name": "Tony Stark",
                    "exp_month": 1,
                    "exp_year": 30,
                    "cvv": "3531",
                    "billing_address": {
                        "line_1": "10880, Malibu Point, Malibu Central",
                        "zip_code": "90265",
                        "city": "Malibu",
                        "state": "CA",
                        "country": "US"
                    }
                }
            }
        }
    ]
}
```

## Resposta (resumida)

```json
{
    "id": "or_DNobwn2CpGuvw0zp",
    "amount": 2990,
    "currency": "BRL",
    "closed": true,
    "status": "pending",
    "charges": [
        {
            "id": "ch_p4lnAGyU0GT1E9MZ",
            "amount": 2990,
            "status": "pending",
            "payment_method": "credit_card",
            "funding_source": "prepaid",
            "last_transaction": {
                "id": "tran_ywqNVaxiorcpde8W",
                "transaction_type": "credit_card",
                "amount": 2990,
                "status": "authorized_pending_capture",
                "success": true,
                "installments": 1,
                "statement_descriptor": "AVENGERS",
                "acquirer_name": "simulator",
                "acquirer_tid": "806863466",
                "acquirer_nsu": "66184",
                "acquirer_auth_code": "890",
                "acquirer_message": "Transação autorizada com sucesso",
                "acquirer_return_code": "00",
                "operation_type": "auth_only",
                "card": {
                    "id": "card_D5p74jkH15SBYvYq",
                    "first_six_digits": "400000",
                    "last_four_digits": "0010",
                    "brand": "Visa",
                    "holder_name": "Tony Stark",
                    "exp_month": 12,
                    "exp_year": 2025,
                    "status": "active",
                    "type": "credit"
                },
                "gateway_response": { "code": "200", "errors": [] },
                "antifraud_response": {}
            }
        }
    ]
}
```

O `card.id` retornado (`card_...`) pode ser reutilizado em compras futuras via `card_id`. O cartão nunca volta completo: só `first_six_digits` e `last_four_digits`.

## Status da transação (`charge.last_transaction.status`)

| Status | Significado |
| :-- | :-- |
| `authorized_pending_capture` | Autorizada, aguardando captura |
| `captured` | Capturada (venda concluída) |
| `partial_capture` | Captura parcial |
| `waiting_capture` | Aguardando captura |
| `not_authorized` | Recusada pelo emissor |
| `voided` | Cancelada antes da captura |
| `refunded` / `partial_refunded` | Estornada total / parcialmente |
| `partial_void` | Cancelamento parcial |
| `waiting_cancellation` | Cancelamento em andamento |
| `error_on_voiding` / `error_on_refunding` | Erro ao cancelar / estornar |
| `with_error` / `failed` | Erro de processamento / falha |

Em caso de recusa, ver `acquirer_message` e `acquirer_return_code`.

## Comportamento verificado no sandbox (2026-10-11)

Testado com chaves `sk_test_`/`pk_test_` do projeto:

- Aprovado (`auth_and_capture`): HTTP 200, `order.status=paid`, `charge.status=paid`, `last_transaction.status=captured`, `acquirer_return_code=0000`.
- **Falhas voltam como HTTP 200** com `order.status=failed`, `last_transaction.status=failed`, `success=false` e `gateway_response.errors[].message`. Exemplos reais: `"The customer Document is required."`, `"The item Code is required."`, `"invalid_parameter | amount | valor não permitido"` (amount = 1).
- Campos obrigatórios que o doc público não destaca: `customer.document` e `items[].code`.
- Token: expira em **1 minuto** e é de **uso único** (segundo uso → 404).
- `Idempotency-Key` (header no `POST /v5/orders`): mesma chave devolve o mesmo pedido, mesmo com `card_token` novo.
- `code` do pedido é aceito e buscável (`GET /v5/orders?code=`), mas não é único.
- Pedido PIX pendente **não pode ser cancelado** pela API: `DELETE /v5/charges/{id}` → 412 e `PATCH /v5/orders/{id}/closed` → 404 "This order is closed.". Só expira.
- O simulador aprovou todos os números de cartão e CVVs testados; ainda não há gatilho conhecido de recusa.

## Notas

- Cartão **Elo** em *payment link*: desde 17/10/2025 o campo `channel` deve ser `payment_link`.
- `metadata` aceita chave/valor livres para guardar contexto (ex.: `refExternal`).
- Cartão usa a mesma estrutura de pedido/cobrança do PIX, então `ApiService::create_order` / `get_order` já servem; muda o corpo e o mapeamento de status.

## Simulador de cartões (documentação oficial vs. observado)

Pela doc oficial ([crédito](https://docs.pagar.me/docs/simulador-de-cart%C3%A3o-de-cr%C3%A9dito)), com validade futura e sem regras por valor, CVV, nome ou parcelas:

| Cartão | Cenário esperado |
|---|---|
| `4000000000000010` | Sucesso (`Pago` / `Capturado`) |
| `4000000000000028` | Falha: sempre não autorizada |
| `4000000000000036` | Processando e depois sucesso |
| `4000000000000044` | Processando e depois falha |
| `4000000000000051` | Processando e depois cancelado/estornado |
| `4000000000000069` | Pago e depois chargeback |
| `4000000000000077` | Sucesso; erro no cancelamento e estorno final |
| `4000000000000093` | Sucesso; erro no cancelamento e volta a `Pago` |
| outros | Não autorizada |

**Observado na conta de teste do projeto (2026-10-12):** todos os cartões acima foram **aprovados**, inclusive o `…0028` (e `4111111111111111`, `5555555555554444`); o `…0036` teve timeout na 1ª tentativa e o reenvio com a mesma `Idempotency-Key` devolveu o mesmo pedido, já pago (um único pedido, sem cobrança dupla). O simulador desta conta não recusa cartões. Gatilho confiável de recusa: **valor de R$ 0,01** (`invalid_parameter | amount | valor não permitido`, HTTP 200 com charge `failed`). A recusa real de emissor (`not_authorized`) e o AVS só podem ser validados com credencial de produção.

`billing_address` é **obrigatório** no pedido: sem ele a charge falha com `validation_error | billing | "value" is required`.

## O que não está coberto

- 3DS, cartão salvo (`card_id`), recorrência, captura posterior (`auth_only`), parcelamento com juros.
- Estorno/cancelamento de cartão e chargeback (o sistema não trata `voided`/`refunded`/`chargedback`).
- Tarifa por meio (Pix e cartão por faixa de parcelas) é configurada por conta no painel e gravada em `payments.fee_percent`/`fee_amount` na confirmação (resolvido).
- PIX pendente de um pagamento pago no cartão não é cancelável: o polling o vigia até expirar e loga erro se for pago.
