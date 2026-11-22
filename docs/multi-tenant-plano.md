# Plano — multi-tenancy completo (SaaS)

Hoje: isolamento por `account_id` (UserScope) já funciona em painel e API. Falta transformar em produto com vários clientes independentes. Lacunas, em ordem de risco:

| # | Lacuna | Hoje | Risco |
|---|--------|------|-------|
| L1 | Credenciais de provedor | globais (`config('integration.*')`, `gateways.mercadopago.*`, lidas dentro de `PagarmeGateway`/`MercadoPagoGateway`) | todo dinheiro cai na mesma conta de recebimento |
| L2 | `api_token` | texto puro no banco e no painel; API loga o "primeiro User" da conta | vazamento do banco = acesso a todas as contas |
| L3 | Cadastro de contas | só tinker; registro desligado | não escala, sem onboarding |
| L4 | Circuit breaker, webhook MP de entrada, `cancelar()` | breaker global em `Cache`; segredo MP único; `PaymentTimer::cancelar()` público | falha de um tenant afeta todos; payer cancela cobrança |
| L5 | Escopo | `UserScope` quebra sem usuário logado; todo caminho sem login precisa `withoutGlobalScopes()` | esquecer = vazamento ou 500 |
| L6 | Papéis | qualquer User da conta vê tudo; sem super-admin | não há quem administre as contas |

## Decisões

- **D1 (decidido) Recebimento:** cada cliente recebe na própria conta. Ele obtém as credenciais do Pagar.me e/ou do Mercado Pago e as cadastra no sistema. A plataforma não recebe nem repassa (sem split, sem regulação nova).
- **D2 (decidido) Failover MP:** só funciona se o tenant configurou o Mercado Pago. Sem MP configurado e Pagar.me fora do ar, não há o que fazer: `create-payment` responde 503 e nenhum `Payment` é criado (comportamento atual do "ambos fora"). Nunca emprestar credencial de outro tenant. O painel deve avisar o tenant que está sem redundância.
- **D3 (decidido) Cadastro:** convite pelo super-admin (opção A). O super-admin cria a conta (nome + e-mail), o sistema envia link assinado para o cliente definir a senha. Autocadastro fica fora de escopo; pode entrar depois por cima (verificação de e-mail, antifraude, termos).
- **D4 (adotado, padrão recomendado) Token da API:** **hash (SHA-256) + prefixo visível + exibição única na criação**, com rotação; token antigo válido por janela curta na migração.
- D5 Isolamento no banco: **mesmo schema + `account_id`** (já existe).

### Regras derivadas de D1/D2
- Tenant precisa de ao menos um gateway válido para criar cobrança. Sem nenhum: `create-payment` responde 422/503 com erro claro ("gateway não configurado"), sem chamar provedor.
- Só Mercado Pago configurado: MP vira o gateway primário daquele tenant (sem failover para Pagar.me inexistente). Só Pagar.me: comportamento de hoje, sem failover. Ambos: Pagar.me primário, MP failover.
- Ordem de prioridade por tenant (`primary_gateway`) fica opcional; padrão Pagar.me, depois MP.
- Cartão: precisa da chave pública do gateway do tenant (`pk_...` Pagar.me / `MP_PUBLIC_KEY`); sem ela a aba cartão não renderiza (regra atual, agora por tenant).
- Cobrança já criada segue sempre o gateway que a criou (`pix_gateway`/`card_gateway`), mesmo se o tenant remover a credencial depois: remover credencial com cobranças `PENDING` abertas deve ser bloqueado ou avisar.
- Env global deixa de ser fonte de credencial para tenants. Fica só como fallback da conta dona atual da plataforma durante a migração (produção em hospedagem sem SSH, não renomear env).

## Fase 1 — Higiene de isolamento (sem mudar produto) — FEITA (PR feature/multi-tenant-fase1)
Nota: `UserScope` ficou como escopo + `TenantContext`; trait `BelongsToAccount` não foi necessária.
- [x] Teste de vazamento entre contas: 2 contas, API + painel + checkout + scheduler; nenhuma lê/escreve dados da outra (inclui `AccountResource` e `Payment` por UUID).
- [x] `UserScope`: sem usuário logado, não quebrar (`auth()->user()?->account_id`); decidir se vira "fail closed" (retorna nada) e listar os pontos que precisam `withoutGlobalScopes()` explícito. Trait `BelongsToAccount` em vez de escopo por atributo.
- [x] `api.token`: autenticar a **conta** (contexto de tenant) sem `loginUsingId` de "primeiro usuário"; `TenantContext` (singleton) lido pelo scope e pelo `creating`.
- [x] ~~Fechar `PaymentTimer::cancelar()` público~~ — já estava privado no código; falta só corrigir DOCUMENTACAO §8 (item desatualizado).

## Fase 2 — Token seguro (L2) — FEITA (mesma branch feature/multi-tenant-fase1; coluna removida já na migration, a janela vale para a rotação)
- [x] Migration: `configs.api_token_hash`, `api_token_prefix`, `api_token_last_used_at`; backfill com hash dos tokens atuais.
- [x] Middleware compara hash (`hash_equals`); painel mostra só prefixo; ação "gerar/rotacionar token" mostra o valor uma única vez.
- [x] Remover coluna `api_token` em texto após janela de migração (aviso aos clientes: o token em uso continua válido).

## Fase 3 — Credenciais por tenant (L1, L4) — FEITA (mesma branch). Env: `GATEWAYS_ENV_ACCOUNT_ID` indica a conta que segue no .env
- [x] Tabela `gateway_credentials` (`account_id`, `gateway`, `payload` `encrypted:array`, `enabled`, `last_validated_at`); `APP_KEY` estável (já exigido).
- [x] `GatewayManager::for(Account)` injeta credenciais nos gateways (hoje leem `config()`); `PagarmeGateway`/`MercadoPagoGateway` deixam de ler env de credencial. Env global vira fallback só para a conta "dona" da plataforma (compatibilidade com produção atual, sem renomear env — hospedagem sem SSH).
- [x] Circuit breaker com chave por conta+gateway (`gateway:{account}:{gw}`).
- [x] Webhook MP de entrada: segredo e `access_token` por tenant; rota `POST /api/webhooks/mercadopago/{account}` (ou resolver pelo `data.id` e validar com o segredo do dono).
- [x] Chaves públicas do card-config (`pk_...`) por tenant; checkout lê as do dono do `Payment`.
- [x] Tela no painel para o tenant cadastrar/validar credenciais (chamada de teste ao provedor, `GET /users/me` no MP); campos secretos mascarados após salvar; aviso "sem redundância" quando só um gateway está ativo.
- [x] Scheduler (`process-payment`/`process-webhook`): carregar credenciais pelo `payment.account_id`; um tenant com credencial inválida não trava o laço.

## Fase 4 — Onboarding e papéis (L3, L6)
- [ ] Papel `super_admin` (flag em `users`) com painel `/platform` separado: criar conta, convidar usuário, suspender, ver volume. Sem isso, nada de multi-cliente operável.
- [ ] Convite por e-mail (link assinado, define senha); Filament `Register` continua desligado.
- [ ] Conta suspensa: API responde 403, checkout existente continua pagável até expirar.
- [ ] Papéis dentro da conta (`owner`/`viewer`) se pedido; `AccountResource` já filtra pela própria conta.

## Fase 5 — Operação por tenant
- [ ] Rate limit por conta na API; quotas opcionais.
- [ ] Logs/Telescope com `account_id`; métricas por conta.
- [ ] Documentar em DOCUMENTACAO.md §3/§7/§8, atualizar OpenAPI (token e erros 403) e CHANGELOG.

## Ordem e entrega
Fase 1 e 2 sobem sozinhas (valor imediato, sem mudar contrato). Fase 3 em PRs menores: (a) tabela + `GatewayManager::for`, (b) breaker/webhook, (c) tela. Fase 4 segue D3 (convite). Cada fase com a doc que invalida.

## Riscos
- Migrar produção atual (conta única) sem downtime: conta "dona" mantém env como fallback até cadastrar credencial no banco.
- Credenciais de terceiros em banco: criptografia depende de `APP_KEY`; perder a chave = perder todas as credenciais. Backup da chave obrigatório.
- Cliente que cadastra credencial errada só descobre ao cobrar: validar na hora de salvar e exibir status (`last_validated_at`).
- Sandbox x produção: credencial `TEST-`/chaves de teste em conta de produção gera cobrança fake; guardar o ambiente e mostrá-lo no painel.
