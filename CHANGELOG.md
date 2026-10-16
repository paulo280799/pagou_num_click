# Changelog

## 2026-10-16 (11)
- Lista de Pagamentos: abas de status alinhadas à esquerda (o Filament as centraliza com `mx-auto`) e mais coladas na tabela, via `<style>` no render hook `STYLES_AFTER` do `AdminPanelProvider` (o painel não tem tema próprio, então classes Tailwind avulsas não existem). Teste em `AdminPanelTest`.

## 2026-10-16 (10)
- Lista de Pagamentos sem os cards de receita líquida (hoje/semana/mês/ano): o Dashboard e seu filtro de período cobrem isso. A **receita líquida** passou para o Dashboard, na descrição de "Cobranças autorizadas" ("N pagas · líquido R$ x"), calculada por `Payment::netRevenue()` (mesma regra de antes). Removidos `PagamentosResumoStats`, `PagamentosStats` (já sem uso) e `WidgetsTest`; o teste do líquido foi para `DashboardTest`. Suíte: 249 passando.

## 2026-10-16 (9)
- **Dashboard → Baixar relatório**: botão ao lado do filtro de período; baixa um CSV (`;`, UTF-8 com BOM, abre direto no Excel pt-BR) com os pagamentos do período selecionado e da conta logada: referência, ID, meio, valor, status, tarifa (% e R$), líquido, criado em e pago em. Tarifa/líquido só nos pagos; dados do cliente ficam de fora (PII); referência que começa com `=`, `+`, `-` ou `@` ganha `'` (injeção de fórmula). Novos: `Support/PaymentsReport`, view `dashboard-download-button`; `PaymentResource::paymentMethodShort` virou público. Suíte: 251 passando.

## 2026-10-16 (8)
- **Dashboard** como tela inicial do painel (`/admin`) e novo item **Dashboard** no topo do menu (o ícone de casa passou a ser de Pagamentos: `banknotes`). Filtro de período (Hoje, 7/30 dias, mês, ano); cards Cobranças criadas / autorizadas / Número de cobranças / Ticket médio; gráfico de linha com dropdown TPV ou nº de cobranças (hora no "Hoje", dia nos demais, mês no ano); cards por status (Pago, Pendente, Cancelado, Falha) com valor, quantidade e %. Novos: `Pages/Dashboard`, `Widgets/Dashboard{Stats,Chart,StatusStats}`, `DashboardPeriodEnum`; testes em `DashboardTest`. Widgets registrados no painel (`discoverWidgets` em `Filament/Widgets`; sem isso o Livewire dava "Unable to find component") e coberto por teste. Gráfico: opções do eixo Y passadas como `RawJs` inteiro (aninhado em array virava `{}` e o gráfico ficava em branco). Suíte: 250 passando.

## 2026-10-16 (7)
- **Pagamentos (lista)**: tudo em português; colunas referência (pesquisável, ID curto embaixo), meio ("Pix", "Cartão · 3x", "Pix ou cartão"), valor (formato pt-BR), status em badge e criado em (`d/m/Y H:i`); abas Todos/Aguardando/Pagos/Cancelados com contagem; filtros por meio, período e faixa de valor; ação "Copiar link" do checkout nos pendentes (o copiar do ID passou a copiar a referência). O widget de contagens (`PagamentosStats`) saiu da lista, já que as abas mostram os números; fica só a receita líquida (a classe e o teste continuam).
- **Pagamentos (detalhes)**: modal "Pagamento {referência}" com cabeçalho (status, valor, referência, id), seções Pagamento, Valores, **Cliente** (nome, e-mail, documento mascarado `***.456.789-**`) e Dados técnicos recolhida (IDs do provedor, TID/NSU/autorização). "Data Pagameto" corrigido para "Pago em" (só aparece quando pago); "Expira em" nos pendentes; botão "Fechar". Testes novos em `PaymentsPageTest`; suíte: 237 passando. Webhook/reenvio ficaram para depois.

## 2026-10-16 (6)
- "Regenerar token da API" saiu do menu Segurança de Minha conta e foi para o cabeçalho da seção **Integração** em Configurações (botão ao lado do token; confirmação, o campo já mostra o token novo). O menu Segurança virou só o botão "Alterar minha senha". Teste movido para `AdminPanelTest`; suíte: 228 passando.

## 2026-10-16 (5)
- Painel: o nome no topo do menu lateral passou a ser o **nome da conta** do usuário logado (`brandName` dinâmico no `AdminPanelProvider`); na tela de login, sem usuário, continua o nome da aplicação.

## 2026-10-16 (4)
- "Minha conta": removida a seção "Resumo" (id, criada em, nº de usuários); a lista de usuários já mostra a contagem.

## 2026-10-16 (3)
- "Minha conta": campo Telefone com máscara dinâmica `(99) 9999-9999` / `(99) 99999-9999` (alterna conforme o nº de dígitos) e formatado ao carregar; continua gravado só com dígitos.

## 2026-10-16 (2)
- "Accounts" virou **Minha conta**: seção "Dados da conta" (nome, razão social, CPF/CNPJ, telefone, e-mail de contato; documento e telefone guardados só com dígitos e validados por tamanho) e "Resumo" (id, criada em, nº de usuários). Migration `add_profile_fields_to_accounts_table` (4 colunas nullable em `accounts`).
- Nova tabela de **Usuários** da conta na própria página (criar, editar com senha opcional, remover; bloqueia remover a si mesmo e o último usuário, já que a API autentica via um usuário da conta). `Account::users()` adicionada.
- Widget de atividade do mês (cobranças criadas, pagas + volume, conversão) e menu **Segurança** no cabeçalho: alterar a própria senha (exige senha atual) e **regenerar o token da API** (confirmação; o token antigo para de funcionar na hora).
- Correção de segurança: `AccountResource::getEloquentQuery()` filtra pela conta do usuário; abrir `/admin/accounts/{uuid}/edit` de outra conta agora dá 404 (item 2 do §8 do DOCUMENTACAO.md resolvido). Dados bancários/Pix para saque ficaram de fora de propósito (o sistema é intermediador, o valor cai direto na conta do dono). Testes novos em `AccountPageTest`; suíte: 225 passando.

## 2026-10-16 (1)
- Tela de Configurações do painel redesenhada: tudo em português (título "Configurações", botões "Salvar alterações"/"Cancelar", labels) e organizada em seções — **Integração** (token de acesso no topo, URL de notificação e de redirecionamento com validação `url`), **Checkout** (validade do link de pagamento em segundos, numérica, mínimo 1), **Cartão de crédito** e **Tarifas de cobrança** (4 campos numa linha). Sem mudança de campos/colunas; testes do painel passando.

## 2026-10-14 (8)
- Detalhes do pagamento no painel agora mostram o **meio de pagamento** (Pix / cartão de crédito; em aberto com os dois meios: "Pix ou cartão (a definir)"), para cartão pago a **bandeira/final** e o **parcelamento** ("À vista" ou "Nx de R$ X sem juros"), a **taxa** aplicada, a tarifa e o total líquido (estimada, com aviso, enquanto não pago).
- Tarifas por meio, configuráveis por conta em Configuração → "Tarifas de cobrança": `pix_fee_percent` (padrão 0,75, o valor antes fixo no código) e cartão por faixa de parcelas (`card_fee_percent_1x`, `_2_6x`, `_7_12x`; em branco = "Não configurada", sem tarifa). Migration `add_fee_settings_to_configs_and_payments`.
- A tarifa é gravada como snapshot (`payments.fee_percent` / `fee_amount`) quando o pagamento é confirmado (polling do Pix e `markCardPaid`), então mudar a configuração não altera o histórico. Pagamentos Pix antigos sem snapshot usam 0,75%. `PagamentosResumoStats` passou a somar o valor líquido por pagamento (antes: soma × 0,9925). Os valores das taxas de cartão **não têm padrão**: precisam ser preenchidos pela conta. Testes novos; suíte: 215 passando.

## 2026-10-14 (7)
- Parcelamento do cartão configurável pelo painel admin (Configuração → "Cartão de crédito"): toggle "Permitir parcelamento" e "Parcelas máximas" (1–12). Desligado = cobrança sempre à vista e o seletor de parcelas some do checkout. Novas colunas `configs.card_installments_enabled` / `card_max_installments` (padrão `true`/12, preserva o comportamento anterior) e `payments.max_installments` (snapshot na criação do pagamento, como `duration`; `NULL` = legado, teto 12). `PayCardRequest` valida `installments` contra o snapshot do `Payment`.
- **Removida** a env `CARD_MAX_INSTALLMENTS` e a chave `integration.card_max_installments`; o teto fixo é `Config::MAX_INSTALLMENTS` (12). Docs (`DOCUMENTACAO.md`, `CLAUDE.md`, `docs/pagarme/cc/integration.md`) atualizadas. Testes novos (Config, snapshot na criação, validação no `pay-card`, checkout, formulário do painel); suíte: 198 passando.

## 2026-10-14 (6)
- Landing page (`/`) atualizada para as novas funcionalidades: PIX **e cartão** no texto, no hero e nos planos; prévia do checkout com abas Pix/Cartão interativas (formulário de cartão de exemplo); novos recursos "PIX, cartão ou ambos", "Cartão de crédito" (tokenização no navegador) e "Documentação interativa"; exemplo de integração com o payload real (`refExternal`, `payment_methods`, `items`, `customer`); botão "Ver documentação da API" para `/api/docs`; FAQ com cartão, escolha de meios e segurança dos dados. Testes novos em `LandingPageTest`.

## 2026-10-14 (5)
- `POST /api/create-payment`: novo campo opcional `payment_methods` (`pix`, `credit_card`; padrão ambos) que define o que o checkout mostra. Só-cartão não cria pedido PIX no provedor (`ide`/`qrCode`/`copyPaste` agora nullable; migration `add_payment_methods_to_payments_table` + coluna JSON `payment_methods`); só-Pix esconde a aba de cartão e `pay-card` responde 409; cartão pedido explicitamente sem `STONE_PUBLIC_KEY_INTEGRATION` → 422. Polling pula a consulta PIX quando `ide` é nulo.
- Validação reforçada em `CheckoutRequest`: limites de tamanho, `amount` ≥ 0.01, CPF (11) / CNPJ (14) conforme `customer.type`, telefone só dígitos, `payment_methods` sem repetição; erros sempre em JSON 422 (antes redirect 302 sem `Accept: application/json`).
- **Mudança de comportamento**: `items[].quantity` e todos os `items` agora são enviados e somados (antes só `items[0]` com quantidade 1).
- Documentação: `docs/api/create-payment.md` e spec OpenAPI `docs/api/openapi.yaml`, servida com Swagger UI em `/api/docs` (`/api/docs/openapi.yaml`). `DOCUMENTACAO.md` §3/§4 e `CLAUDE.md` atualizados. Testes novos (meios de pagamento, validação, checkout só-cartão/só-Pix, polling, docs); suíte: 181 passando.

## 2026-10-14 (4)
- Adicionado `docs/pagarme/cc/integration.md`: como a integração de cartão funciona hoje no código (mesmo padrão do `pix/integration.md`): visão geral, peças, tokenização, rota `pay-card` em 3 fases, mapeamento de resultado, idempotência, checkout, polling, ciclo de vida, config, segurança, comportamento no sandbox e limitações.

## 2026-10-14 (3)
- Documentação atualizada (Plano 4): `DOCUMENTACAO.md` (fluxo com cartão, colunas de cartão, rota `pay-card`, polling de cartão, webhook resiliente, env vars `STONE_PUBLIC_KEY_INTEGRATION`/`CARD_*`, §8 com itens 6, 10, 11, 12 e 16 resolvidos), `CLAUDE.md` (env vars, fluxo de cartão, testes) e `docs/pagarme/cc/doc.md` / `pix/integration.md` (status implementado, simulador oficial vs. observado, guarda de charge `failed`, `customer` persistido, `close_order`).

## 2026-10-14 (2)
- Implementado Plano 4 (tasks 1–5): `ApiTokenMiddleware` responde 401 (não 500) para token de conta sem `User`; `ProcessPaymentController` captura `ConnectionException` por pagamento (extraído `processPayment()`) e `ApiService::get_order` ganha timeout; `app:process-payment` e `app:process-webhook` com `withoutOverlapping(1)`; `Config.duration` com cast inteiro; removido `dd()` de `ConfigResource`. Testes novos (middleware, polling com provedor fora do ar/5xx, scheduler, cast, polling só em `PENDING`, Telescope oculta cartão); suíte: 149 passando. Itens de segurança §8 2–4 e Pint dos arquivos antigos ficam fora (decisão padrão do plano).

## 2026-10-14
- Plano 4 reescrito como SDD em `.changes/checkout-v2/plano4/` (`plano4.md` movido, novos `proposal.md`, `design.md`, `tasks.md`): escopo atualizado para o que sobrou depois dos Planos 1–3 (lacunas de teste, resiliência do polling, `withoutOverlapping`, cast de `duration`, middleware sem `User`, validação final e docs). Itens de segurança §8 2–4 ficam sob decisão do usuário.
- Testes novos em `CreatePaymentTest`: charge `failed` com HTTP 200 → 422 sem criar `Payment`, e `customer` criptografado com documento sem máscara (tarefas do Plano 2 que estavam marcadas como feitas sem teste). Suíte: 138 passando.

## 2026-10-12 (6)
- Checkout de cartão: o aviso "Pagamento em análise" (formulário bloqueado) agora vale para qualquer tentativa sem resultado definitivo (`card_attempt_key` preenchida), inclusive timeout sem `card_order_id`; antes, após timeout e recarga, o formulário reabria e o pagador podia reenviar (seguro pela `Idempotency-Key`, mas confuso). `PaymentStatusPending` passa a fazer `wire:poll.5s` quando o cartão está habilitado, para o aviso sumir quando o polling resolve a tentativa. Testes novos; suíte: 136 passando.
- Simulação de cartões no sandbox (`4000000000000010/28/36/44/51/69/77/93`): todos aprovaram nesta conta (o simulador não recusa o `…28` como diz a doc); o `…36` caiu em timeout na 1ª tentativa e o reenvio com a mesma `Idempotency-Key` devolveu o mesmo pedido (um único pedido na Pagar.me, sem cobrança dupla).

## 2026-10-12 (5)
- `WebhookController`: captura `ConnectionException` por pagamento (conta a tentativa e segue o backoff) e usa `timeout(10)`; antes uma URL inalcançável derrubava o `app:process-webhook` e bloqueava a notificação dos demais pagamentos. Teste novo em `ProcessWebhookTest`; suíte: 134 passando.
- Validação manual do cartão no sandbox: CORS do endpoint de tokens OK no browser, `exp_year` de 2 dígitos aceito, pagamento aprovado (Visa final 0010, 1x) com tela `PAID` e webhook de saída recebido com `status: "PAID"`.

## 2026-10-12 (4)
- Checkout de cartão: autocompletar de endereço por CEP via ViaCEP (browser, timeout 5 s, falha silenciosa). Campo único de endereço virou Rua + Número (`line_1 = "rua, número"`); só o CEP sai para o ViaCEP. `plano3/design.md` e `tasks.md` atualizados.

## 2026-10-12 (3)
- Implementado Plano 3 (`.changes/checkout-v2/plano3/`): frontend de cartão no checkout. Abas Pix | Cartão em `payment-status-pending` (só com `STONE_PUBLIC_KEY_INTEGRATION` e `Payment` com `customer`). Formulário em Alpine puro dentro de `wire:ignore` (`partials/card-form.blade.php`, `resources/js/card-form.js`): máscara/Luhn/validade, tokenização direto na Pagar.me, PAN/CVV zerados após tokenizar, envio de `card_token`/`installments`/`billing_address` para `pay-card` com CSRF e tratamento de 200/422/202/503/409/429/rede. Layout ganha meta CSRF e `@vite` do JS.
- `PaymentStatusPending` calcula `cardEnabled`/`cardInReview` (aviso "em análise" persiste após reload) e expõe `refreshStatus()` (evento `payment-updated` → `PaymentPage::atualizar`). `PaymentStatusPaid` mostra marca/final/parcelas para cartão; textos de `PaymentStatusCanceled` generalizados. Testes em `tests/Feature/Livewire/CardCheckoutTest.php`; suíte: 133 passando. Pendente: validação manual (CORS do endpoint de tokens, `exp_year`) na task 8.

## 2026-10-12 (2)
- SDD do Plano 3 (frontend de cartão) criado em `.changes/checkout-v2/plano3/` seguindo o padrão do `plano1/` e `plano2/`: `proposal.md`, `design.md`, `tasks.md`; `plano3.md` movido para dentro da pasta. Nenhum código alterado.

## 2026-10-12
- Implementado Plano 2 (`.changes/checkout-v2/plano2/`): backend de cartão de crédito. Nova rota pública `POST /checkout/{id}/pay-card` (`CardPaymentController`, `PayCardRequest`, limiter `pay-card` 5/10min por IP+Payment) com 3 fases (reserva sob lock + `Idempotency-Key` → chamada Pagar.me com timeout → aplicação do resultado); valor sempre lido do banco; recusa responde 422 sem 500.
- Migration `add_card_fields_to_payments_table` (`card_order_id`, `card_attempt_key/started_at`, `card_brand`, `card_last_four`, `installments`, `acquirer_*`, `customer` criptografado e oculto). `Payment::markCardPaid/releaseCardAttempt`.
- Mapper `App\Support\ChargeResult`/`ChargeOutcome` (fonte: `charges[0].last_transaction.status`). `ApiService::create_card_order` e `get_orders_by_code`. `CheckoutController::store` passa a gravar `customer` e responde 422 (sem criar `Payment`) quando a charge PIX vem `failed`/sem QR em HTTP 200. `close_order` não usado (PIX pendente não é cancelável).
- `ProcessPaymentController`: confere pedido de cartão em análise, não cancela por expiração nesse caso, reconcilia timeout por `code`/`metadata.payment_id` e loga erro se o PIX for pago após cartão aprovado (estorno manual). Telescope oculta `card_token`/`billing_address`.
- Config novas em `config/integration.php`: `STONE_PUBLIC_KEY_INTEGRATION`, `CARD_MAX_INSTALLMENTS`, `CARD_STATEMENT_DESCRIPTOR`, `CARD_HTTP_TIMEOUT`. Testes novos (mapper, rota de cartão, polling); suíte: 124 passando.

## 2026-10-11
- SDD do Plano 2 (backend de cartão) criado em `.changes/checkout-v2/plano2/` seguindo o padrão do `plano1/`: `proposal.md`, `design.md`, `tasks.md`; `plano2.md` movido para dentro da pasta (links relativos ajustados). Nenhum código alterado.
- Validação no sandbox da Pagar.me (chaves de teste do `.env`) para o Plano 2: aprovado OK, falhas vêm com HTTP 200, `customer.document`/`items[].code` obrigatórios, token 1 min e uso único, `Idempotency-Key` funciona, PIX pendente não é cancelável (`close_order` descartado do plano). `plano2/design.md`, `proposal.md`, `tasks.md`, `plano2.md` e `docs/pagarme/cc/doc.md` atualizados com os achados. Nenhum código de produção alterado.

## 2026-10-10
- Adicionada documentação da integração Pagar.me v5 em `docs/pagarme/pix/doc.md` (criar pedido PIX, objeto `pix`, resposta, status, como o projeto usa, armadilhas) e `docs/pagarme/cc/doc.md` (cartão de crédito: objeto `credit_card`, 3DS, `operation_type`, status; ainda não implementado no projeto, inclui lista do que falta para integrar).
- Adicionado `docs/pagarme/pix/integration.md`: como a integração PIX funciona hoje no código (criação, transformação de campos, checkout, polling, webhook de saída, ciclo de vida do `Payment`, config e limitações conhecidas).
- Reescrito `.changes/checkout-v2/plano2.md` (backend de cartão) conforme `docs/pagarme/cc/doc.md`: cartão vira segunda tentativa sobre o `Payment` existente via rota `pay-card` (contrato de `/api/create-payment` preservado); formato `card_token` + `billing_address` confirmado; mapeamento de status por `last_transaction.status`; encerrar PIX com `close_order`; coluna `card_order_id` e `customer` criptografado; idempotência/timeout; rate limit dedicado; lista de pontos a validar no sandbox e impacto nos Planos 3 e 4.
- `docs/pagarme/cc/doc.md`: adicionada seção de tokenização do cartão (`POST /core/v5/tokens` com `public_key`, uso de `card_token` no pedido, billing address não tokenizado, expiração do token).

## 2026-10-04 (3)
- Implementado Plano 1 (`.changes/checkout-v2/plano1/`): melhorias visuais/UX do checkout PIX.
- **Segurança:** corrigido `PaymentTimer` (`app/Livewire/PaymentTimer.php`) — removida propriedade pública `$expirationDate` e método público `cancelar()` (DOCUMENTACAO.md §8 item 1). Qualquer payer podia cancelar a própria cobrança via Livewire antes da expiração real; pagamento cancelado localmente parava de ser consultado pelo scheduler, então um PIX pago depois nunca era reconhecido. Agora `checkExpiration()` é o único método público e sempre revalida `expirationDate`/`status` lendo do banco (`freshPayment()`), nunca de estado client-side.
- Countdown do timer migrado de `wire:poll.1s` (round-trip Livewire a cada segundo) para `setInterval` em Alpine.js puro no browser; só uma chamada ao servidor (`checkExpiration`) quando o contador chega a zero.
- `payment-page.blade.php`: `wire:poll.5s` agora só fica presente no DOM enquanto o status é PENDING — para de pollar automaticamente após PAID/FAILED/CANCELED.
- Feedback de cópia do código Pix trocado de `alert()` bloqueante por toggle "Copiado!" via Alpine (`x-show`/`x-cloak`).
- Redesenho com Tailwind dos 4 estados do checkout (`payment-status-*.blade.php`) e do layout (`app.blade.php`, removido `<style>` inline, adicionado `@vite` para compilar o CSS), seguindo o layout aprovado pelo cliente (artefato Claude Design `StLLBSEN6LctNXAP4uKpiB`, telas `Pending`/`Success`/`Expired`): header com avatar/nome do lojista + nº do pedido, card de status+timer, card do QR Code, valor, copia-e-cola com botão de feedback, rodapé de confiança (PENDING); círculo de check + recibo com valor/data/forma de pagamento + botão de retorno à loja (PAID); círculo de alerta com mensagem (EXPIRED/FAILED, mesmo componente, texto condicional). Paleta/tipografia do artefato aplicadas via classes arbitrárias Tailwind (`#00B389` accent, `#141413`/`#6B6A64` texto, `#F3F2EE` fundo).
- `payment-page.blade.php`/`PaymentPage.php`: header do lojista (nome da `Account`, inicial, `refExternal` como nº do pedido) movido para o componente pai, compartilhado pelos 4 estados.
- `redirect_url` do `Payment` (salvo mas nunca usado) agora exibido como botão "Voltar à loja" na tela de PAID, quando presente.
- Testes de `PaymentTimerTest`/`PaymentStatusComponentsTest` atualizados para a nova API (sem `cancelar()`/`expirationDate` públicos, novos props `paymentId`/`amount`/`paidAt`) e cobrindo os novos comportamentos (botão de redirect, texto FAILED vs CANCELED). Suíte completa (80 testes) e `npm run build` passam sem regressão.
- Validação manual end-to-end via `POST /api/create-payment` com credenciais reais do provedor: encontrado bug novo em `CheckoutController::store` — com dados de cliente inválidos/fake, o provedor responde HTTP 200 mas com `charges[0].status: failed` (`gateway_response: action_forbidden`), e o controller só checa `$response->failed()` (nível HTTP), não o status do charge; acessa `last_transaction.qr_code_url` sem essa chave existir e quebra com 500 antes de criar o `Payment`. Com dados de cliente reais o fluxo funciona normalmente (QR gerado, checkout renderiza certo). Adicionado só um `Log::error` com a resposta completa do provedor nesse caso, sem alterar o comportamento de erro — fix do guard fica para fora deste PR (Plano 2/4, já que é lógica de `CheckoutController`, fora do escopo do Plano 1).

## 2026-10-04 (4)
- Adicionada página de vendas pública em `GET /` (`routes/web.php`, rota `landing`; view `resources/views/landing.blade.php`), implementada a partir da prévia aprovada pelo cliente. CSS/JS inline na view (sem build Vite), fontes via Google Fonts, tema claro/escuro automático por `prefers-color-scheme`. Seções: hero com checkout de exemplo, como funciona, recursos, integração (exemplo de `POST /api/create-payment`), planos, FAQ e CTA.
- Destino dos botões "Quero contratar"/"Falar com a gente" configurável via `LANDING_CONTACT_URL` (`config/services.php` → `services.landing.contact_url`); sem a variável, cai para a âncora `#topo`. **Pendente:** definir o contato real (WhatsApp/e-mail/formulário) e setar no `.env`.
- Preços dos planos e taxas ficaram como "Sob consulta" (a prévia usava "A DEFINIR", que não deve ir a público). Nomes dos planos (Início/Negócio/Sob medida) são sugestão e podem mudar. O exemplo de payload da API na página é ilustrativo — conferir contra `CheckoutRequest`.
- Adicionado `tests/Feature/LandingPageTest.php` (render, CTA com e sem `LANDING_CONTACT_URL`). Suíte completa: 83 testes passando.
>>>>>>> eec2557 (feat: adiciona pagina de vendas publica em /)
## 2026-10-04 (2)
- Corrigido `Config::boot()` (`app/Models/Config.php`): usava `$model->api_token` (variável inexistente) em vez de `$item`. `empty()` nunca lançava erro em variável indefinida, só sempre avaliava `true`, sobrescrevendo qualquer `api_token` fornecido. Agora o guard funciona de verdade.
- Corrigido `CheckoutController::store` (`app/Http/Controllers/CheckoutController.php`): `payment_method`/`status` desconhecidos na resposta do provedor (`tryFrom` retornando `null`) quebravam com `ErrorException` **depois** do pedido já criado no provedor. Agora cai para `PaymentMethodEnum::PIX`/`StatusPaymentEnum::PENDING` e loga `warning` com o `id` do pedido pra investigação manual — decisão: time preferiu não deixar o pagamento órfão (sem `Payment` local) nesse cenário raro.
- Testes atualizados (`tests/Feature/Models/ConfigTest.php`, `tests/Feature/Api/CreatePaymentTest.php`) para cobrir o comportamento corrigido em vez do bug.

## 2026-10-04
- Adicionada suíte de testes (PHPUnit) cobrindo 81,7% das linhas de `app/`: factories (`Account`, `Config`, `Payment`), fluxo completo de `POST /api/create-payment` (sucesso, erro do provedor, validação, duplicidade de `refExternal`), `app:process-payment`/`app:process-webhook` (incl. backoff e limite de tentativas), `ApiTokenMiddleware`, componentes Livewire do checkout, e smoke tests do painel Filament (login, singleton create→edit de `AccountResource`/`ConfigResource`, widgets de stats). Banco de testes usa a base `testing` já provisionada pelo Sail (`docker/mysql/create-testing-database.sh`), configurada em `phpunit.xml`.
- `database/factories/ConfigFactory` cria registros via `Config::withoutEvents` porque `Config::boot()` sobrescreve `account_id` a partir do usuário autenticado em toda criação (sem guarda, diferente de `Payment`) — necessário para fixtures de teste sem usuário logado.
- Bugs confirmados por teste, não corrigidos (fora do escopo desta tarefa, ver `DOCUMENTACAO.md` §8): item 5 (`Config::boot` usa `$model` inexistente — token sempre regenerado, nunca falha silenciosamente por `empty()` não lançar erro em variável indefinida) e item 9 (`CheckoutController` lança `ErrorException` ao receber status desconhecido do provedor, após o pedido já ter sido criado upstream).
- Removido `tests/Feature/ExampleTest.php`: testava `GET /` mas a rota não existe em `routes/web.php` (só `/checkout/{id}`).

## 2026-10-03
- Adicionado `CLAUDE.md` com guia de arquitetura e comandos para o Claude Code (baseado em `DOCUMENTACAO.md`, `composer.json`, `package.json`, `phpunit.xml`, `Kernel.php`).
- Projeto rodado localmente do zero via Docker/Sail: `.env` criado manualmente (sem `.env.example` no repo), `composer install`/`key:generate` via container descartável `laravelsail/php81-composer`, `npm install && npm run build`, containers (`mysql` + `laravel.test`) subidos e migrados, primeiro `Account`/`User` criado via tinker. Documentado em `CLAUDE.md` § Environment.
- Descoberto conflito de porta 80 com Apache do host (mascarava respostas da app com 404 do Apache) — contornado com `APP_PORT=8080` no `.env`. Documentado como "gotcha" em `CLAUDE.md`.
- Confirmado bug já catalogado em `DOCUMENTACAO.md` §8 item 5 (`Config::boot` usa `$model` inexistente): `Config` precisa ser criado via tinker, não pelo formulário do Filament, até ser corrigido.
