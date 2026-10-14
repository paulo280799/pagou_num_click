# `POST /api/create-payment`

Cria uma cobrança e devolve o link do checkout hospedado. É o único endpoint que o sistema do lojista chama; o resto do fluxo (pagamento, polling, webhook) acontece sozinho.

- Spec OpenAPI: [openapi.yaml](openapi.yaml)
- Swagger UI interativo: `GET /api/docs` (usa a spec acima, sempre em sincronia)

## Fluxo

```
Seu sistema ── POST /api/create-payment ──▶ Pagou num Click ──(PIX)──▶ provedor (cria pedido PIX)
            ◀── 201 { payment_link } ──────┘
Pagador ───── abre payment_link ──▶ checkout (Pix e/ou Cartão)
Pagou num Click ── webhook POST notification_url { payment_id, status } ──▶ Seu sistema
```

1. Você cria o pagamento e recebe `payment_link`.
2. Redireciona o pagador para o link.
3. Recebe o webhook quando o status deixa de ser `PENDING`. Guarde o `payment.id`/`refExternal` do seu lado: o webhook devolve o seu `refExternal` como `payment_id`.

## Autenticação

```
Authorization: Bearer <api_token>
Content-Type: application/json
```

`api_token` é o da configuração da conta (painel `/admin` → Configuração). Token ausente, desconhecido ou de conta sem usuário → `401 {"error":"Unauthorized"}`. Cada conta só enxerga os próprios pagamentos.

## Corpo da requisição

| Campo | Tipo | Obrig. | Regras |
|---|---|---|---|
| `refExternal` | string | sim | até 255 caracteres; **único por conta** (repetir → 422) |
| `payment_methods` | array de string | não | `pix`, `credit_card`; sem repetição; mín. 1. Omitido = `["pix","credit_card"]` |
| `items` | array | sim | 1 a 50 itens |
| `items[].amount` | string/número | sim | preço **unitário** em reais, ponto decimal, até 2 casas, mínimo `0.01` (`"10.50"`; `"10,50"` é rejeitado) |
| `items[].description` | string | sim | até 255 caracteres |
| `items[].quantity` | inteiro | sim | 1 a 9999 |
| `customer.name` | string | sim | até 64 caracteres |
| `customer.email` | string | sim | e-mail válido, até 64 caracteres |
| `customer.type` | string | sim | `individual` (CPF) ou `corporation` (CNPJ) |
| `customer.document` | string | sim | CPF (11 dígitos) se `individual`, CNPJ (14) se `corporation`; máscara aceita e removida. Só o **tamanho** é validado (não os dígitos verificadores) |
| `customer.phones.home_phone.country_code` | string | sim | 1–3 dígitos, sem `+` (`"55"`) |
| `customer.phones.home_phone.area_code` | string | sim | 2–3 dígitos (`"11"`) |
| `customer.phones.home_phone.number` | string | sim | 8–9 dígitos, sem DDD (`"999999999"`) |

O valor cobrado é a soma de `amount × quantity` de todos os itens.

### Meios de pagamento (`payment_methods`)

| Valor | O que o pagador vê | O que acontece no provedor |
|---|---|---|
| omitido / `["pix","credit_card"]` | abas **Pix** e **Cartão** | cria o pedido PIX na criação; o pedido de cartão é criado quando o pagador paga |
| `["pix"]` | só Pix (QR code + copia e cola) | idem; a rota de cartão do checkout responde 409 |
| `["credit_card"]` | só cartão | **nenhum pedido PIX** é criado; o pedido de cartão nasce quando o pagador paga |

Regras:

- Pedir `credit_card` explicitamente sem o cartão estar habilitado no ambiente (`STONE_PUBLIC_KEY_INTEGRATION` ausente) → `422` imediato, sem criar nada. Com `payment_methods` omitido, a falta de cartão apenas faz o checkout aparecer só com Pix.
- O meio escolhido fica gravado no pagamento (`payments.payment_methods`) e não muda depois. Pagamentos anteriores a esta funcionalidade (`null`) aceitam PIX e cartão.
- Pix e cartão não são exclusivos em um mesmo checkout: o pagador paga por **um**; o primeiro que for aprovado fecha o pagamento. Em checkout com os dois, o PIX não é cancelável no provedor, ele só expira (ver `docs/pagarme/`).

### Validade

O tempo do checkout vem da configuração da conta (`duration`, em segundos). Passado esse prazo o pagamento vira `CANCELED` (se nenhum cartão estiver em análise). Não há campo no payload para sobrescrever.

## Respostas

### `201 Created`

```json
{ "payment_link": "https://seu-dominio.com/checkout/0b5b0b42-5ab2-4e4c-9f0e-3d1a8f6a1c11" }
```

Redirecione o pagador para essa URL.

### `401`

```json
{ "error": "Unauthorized" }
```

### `422` — validação

Sempre JSON, mesmo sem `Accept: application/json`. Formato Laravel: mensagem principal e mapa `campo → mensagens`, com índices em notação de ponto.

```json
{
  "message": "O CPF deve ter 11 dígitos (customer.type = individual).",
  "errors": { "customer.document": ["O CPF deve ter 11 dígitos (customer.type = individual)."] }
}
```

### `422` / `4xx` — provedor ou meio indisponível

```json
{ "success": false, "message": "Erro ao processar pagamento", "errors": [] }
```

- `credit_card` pedido sem estar habilitado → `422`, `errors.payment_methods`.
- Provedor recusou o pedido (HTTP de erro): mesmo status HTTP, `errors` traz o corpo do provedor.
- Provedor respondeu 2xx mas com cobrança `failed` ou sem QR code (PIX) → `422`. Nenhum pagamento é criado nesses casos, então o mesmo `refExternal` pode ser reenviado.

## Exemplos

Pix e cartão (padrão):

```bash
curl -X POST https://seu-dominio.com/api/create-payment \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "refExternal": "pedido-1001",
    "items": [{ "amount": "149.90", "description": "Tênis Corrida", "quantity": 1 }],
    "customer": {
      "name": "Maria da Silva", "email": "maria@example.com",
      "type": "individual", "document": "123.456.789-09",
      "phones": { "home_phone": { "country_code": "55", "area_code": "11", "number": "999999999" } }
    }
  }'
```

Somente cartão, dois itens (total R$ 225,50):

```json
{
  "refExternal": "pedido-1003",
  "payment_methods": ["credit_card"],
  "items": [
    { "amount": "100.00", "description": "Camiseta", "quantity": 2 },
    { "amount": "25.50", "description": "Frete", "quantity": 1 }
  ],
  "customer": { "...": "..." }
}
```

## Webhook

Quando o pagamento sai de `PENDING`, enviamos `POST` para a `notification_url` da conta:

```
X-Webhook-Secret: <WEBHOOK_SECRET>
{ "payment_id": "pedido-1001", "status": "PAID" }      // PAID | FAILED | CANCELED
```

Responda `2xx` para confirmar. Falhas são reenviadas após 1 min, 5 min, 10 min, 30 min e 1 h; depois de 5 tentativas desistimos. Valide o `X-Webhook-Secret` e trate o evento de forma idempotente (pode chegar mais de uma vez).

## Notas de implementação

- Código: [CheckoutController](../../app/Http/Controllers/CheckoutController.php), [CheckoutRequest](../../app/Http/Requests/CheckoutRequest.php), middleware [ApiTokenMiddleware](../../app/Http/Middleware/ApiTokenMiddleware.php).
- O endpoint cria o pedido PIX de forma síncrona; se o provedor demorar, a requisição demora. Não há retry interno.
- O `customer` é guardado criptografado em `payments.customer` (necessário para cobrar o cartão depois). `APP_KEY` precisa ser estável.
- `payments.ide`, `qrCode` e `copyPaste` são `NULL` em checkout só-cartão; o polling pula a consulta PIX para eles.
- Testes: `tests/Feature/Api/CreatePaymentTest.php`, `ApiDocsTest.php`.

### Mudanças de comportamento desta versão

Quem integrava antes pode notar:

- `items[].quantity` agora é respeitado e **todos** os itens são enviados. Antes só `items[0]` era usado e a quantidade era sempre `1` (cobrava menos que o esperado quando havia quantidade > 1 ou mais de um item).
- Validação mais rígida: `amount` ≥ 0.01, CPF/CNPJ coerente com `customer.type`, telefone só com dígitos nos tamanhos acima, limites de tamanho nos textos. Antes, valores assim chegavam ao provedor e voltavam como erro dele.
- Erro de validação passa a ser JSON 422 mesmo sem `Accept: application/json` (antes era redirect 302).
