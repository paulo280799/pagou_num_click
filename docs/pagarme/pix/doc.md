# Pagar.me — Pagamento via PIX

Resumo de como criar e acompanhar um pagamento PIX na API v5 da Pagar.me, e como o projeto usa isso hoje.

Fonte: https://docs.pagar.me/v5/reference/pix-2

## Visão geral

PIX é um meio de pagamento do nó `payments[]` de um **pedido** (`POST /v5/orders`). A Pagar.me devolve uma **cobrança** (`charges[0]`) com a transação PIX (`last_transaction`) contendo o QR Code e o código copia-e-cola. O pagador paga no app do banco; a Pagar.me atualiza o status da cobrança para `paid`.

```
Nosso sistema ──POST /v5/orders (payment_method=pix)──► Pagar.me
              ◄── charges[0].last_transaction { qr_code, qr_code_url, status=waiting_payment }
Pagador paga no app do banco
Nosso sistema ──GET /v5/orders/{id} (polling)──► Pagar.me  (status → paid)
```

## Autenticação

Basic Auth. Usuário = chave secreta (`sk_...`), senha vazia (no projeto: `USER_INTEGRATION` / `PASSWORD_INTEGRATION`). Base URL em `URL_INTEGRATION`.

## Objeto `pix`

| Campo | Tipo | Obrigatório | Descrição |
| :-- | :-- | :-- | :-- |
| `expires_in` | integer | sim, se não enviar `expires_at` | Validade do PIX em **segundos**. |
| `expires_at` | datetime | sim, se não enviar `expires_in` | Data de expiração em **UTC** (`YYYY-MM-DDThh:mm:ss`). Máximo de 10 anos. |
| `additional_information` | array `{name, value}` | não | Informações extras exibidas ao cliente no pagamento. |

Campos comuns do pagamento: `payment_method` (`"pix"`), `amount` (centavos, inteiro), `metadata` (chave/valor livre).

## Requisição — criar pedido PIX

`POST {URL_INTEGRATION}/v5/orders`

```json
{
    "items": [
        { "amount": 2990, "description": "Chaveiro do Tesseract", "quantity": 1 }
    ],
    "customer": {
        "name": "Tony Stark",
        "email": "avengerstark@ligadajustica.com.br",
        "type": "individual",
        "document": "01234567890",
        "phones": {
            "home_phone": { "country_code": "55", "number": "22180513", "area_code": "21" }
        }
    },
    "payments": [
        {
            "payment_method": "pix",
            "pix": {
                "expires_in": "52134613",
                "additional_information": [
                    { "name": "Quantidade", "value": "2" }
                ]
            }
        }
    ]
}
```

Requisitos: `customer` precisa de `name`, `email`, `document` e `phones` para o PIX ser processado. Valores sempre em **centavos**.

## Resposta (cobrança)

Campos relevantes de `charges[0]`:

```json
{
    "id": "ch_Qym35AmhvcZ5goJV",
    "code": "J3X6U97A6O",
    "status": "paid",
    "currency": "BRL",
    "payment_method": "pix",
    "paid_at": "2021-12-01T17:55:27Z",
    "last_transaction": {
        "id": "tran_24zrDeoJf7FXLEp9",
        "transaction_type": "pix",
        "amount": 350,
        "status": "paid",
        "success": true,
        "qr_code": "00020101021226840014br.gov.bcb.pix2562pix-h.stone.com.br/pix/v2/...",
        "qr_code_url": "https://api.pagar.me/core/v5/transactions/tran_xxx/qrcode?payment_method=pix",
        "expires_at": "2023-10-22T04:43:47Z",
        "payer": {
            "name": "Tony Stark",
            "document_type": "CPF",
            "bank_account": { "bank_name": "Pagarme", "ispb": "423452" }
        }
    }
}
```

- `qr_code` → string **copia-e-cola** do PIX.
- `qr_code_url` → URL da **imagem** do QR Code.
- `payer` só aparece depois de pago.

## Status da transação

| Status | Significado |
| :-- | :-- |
| `waiting_payment` | Aguardando pagamento do cliente |
| `paid` | Pago |
| `pending_refund` | Estorno em andamento |
| `refunded` | Estornado |
| `with_error` | Erro no processamento |
| `failed` | Falhou |

Status do **pedido** (`order.status`): `pending`, `paid`, `failed`, `canceled`.

## Como o projeto usa

| Etapa | Onde | Detalhe |
| :-- | :-- | :-- |
| Criar pedido | `CheckoutController::store` → `ApiService::create_order` | Envia `payment_method: pix` com `pix.expires_at` (UTC; código soma +3h ao horário local) e `amount` em centavos. |
| Guardar resultado | `Payment` | `ide` = `id` do pedido; `qrCode` = `qr_code_url`; `copyPaste` = `qr_code`. |
| Acompanhar | `ProcessPaymentController` (`app:process-payment`, a cada 10 s) | `GET /v5/orders/{ide}` para cada `Payment` PENDING; mapeia `status` para `StatusPaymentEnum`. |
| Notificar cliente | `WebhookController` (`app:process-webhook`) | POST em `notification_url` quando status ≠ PENDING. |

Mapeamento de status do provedor → interno: `pending` → `PENDING`, `paid` → `PAID`, `failed` → `FAILED`, `canceled` → `CANCELED`.

## Armadilhas conhecidas

- Com dados de cliente inválidos, a Pagar.me pode responder **HTTP 200** com `charges[0].status = failed` (`gateway_response: action_forbidden`) e **sem** `qr_code_url`. Checar o status da cobrança, não só `$response->failed()`.
- `expires_at` é UTC; o servidor roda em `America/Sao_Paulo`.
- Valores em centavos (inteiro). Documento sem máscara.
