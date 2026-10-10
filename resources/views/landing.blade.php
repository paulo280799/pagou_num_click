<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Gere cobranças PIX por API, entregue um link de pagamento com QR Code e receba a confirmação por webhook.">
<title>Pagou num Click</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=Instrument+Sans:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap">
<style>
/* Layout: faixa de prévia, hero em 2 colunas (texto + checkout de exemplo), seções em coluna única de ~1080px */
:root{
  --bg:#F7F7F4; --surface:#FFFFFF; --ink:#141413; --muted:#6B6A64; --line:#E8E6DF;
  --accent:#00B389; --accent-ink:#00553F; --accent-soft:#E4F7EF; --warn:#C1440E; --warn-soft:#FBEAE3;
  --code-bg:#141413; --code-fg:#EDEDE8;
  --display:"Bricolage Grotesque","Helvetica Neue",Arial,sans-serif;
  --body:"Instrument Sans","Helvetica Neue",Arial,sans-serif;
  --mono:"JetBrains Mono",ui-monospace,Menlo,monospace;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --bg:#101110; --surface:#181A18; --ink:#F0F0EA; --muted:#A3A39A; --line:#2A2D2A;
  --accent:#19D3A5; --accent-ink:#8BEFD3; --accent-soft:#0F2B24; --warn:#F08A5D; --warn-soft:#2E1A12;
  --code-bg:#0A0B0A; --code-fg:#EDEDE8; color-scheme:dark}}
:root[data-theme="dark"]{
  --bg:#101110; --surface:#181A18; --ink:#F0F0EA; --muted:#A3A39A; --line:#2A2D2A;
  --accent:#19D3A5; --accent-ink:#8BEFD3; --accent-soft:#0F2B24; --warn:#F08A5D; --warn-soft:#2E1A12;
  --code-bg:#0A0B0A; --code-fg:#EDEDE8; color-scheme:dark}

*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 var(--body);-webkit-font-smoothing:antialiased}
a{color:inherit}
:focus-visible{outline:2px solid var(--accent);outline-offset:3px;border-radius:4px}
.wrap{max-width:1080px;margin-inline:auto;padding-inline:20px}
h1,h2,h3{font-family:var(--display);font-weight:700;line-height:1.08;letter-spacing:-.02em;margin:0;text-wrap:balance}
h2{font-size:clamp(1.9rem,4vw,2.7rem)}
h3{font-size:1.2rem;letter-spacing:-.01em}
p{margin:0}
.eyebrow{font:500 .78rem/1 var(--mono);letter-spacing:.08em;text-transform:uppercase;color:var(--accent-ink)}

/* nav */
nav{border-bottom:1px solid var(--line)}
nav .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;padding-block:16px}
.logo{font:700 1.2rem var(--display);letter-spacing:-.02em;text-decoration:none;display:flex;align-items:center;gap:8px}
.logo i{width:22px;height:22px;border-radius:6px;background:var(--accent);display:grid;place-items:center}
.logo i::after{content:"";width:8px;height:8px;border-radius:2px;background:var(--bg)}
nav ul{display:flex;gap:26px;list-style:none;margin:0;padding:0;font-size:.95rem;color:var(--muted)}
nav ul a{text-decoration:none}
nav ul a:hover{color:var(--ink)}
.btn{display:inline-flex;align-items:center;gap:8px;font:600 .98rem var(--body);padding:13px 22px;border-radius:10px;text-decoration:none;border:1px solid transparent;cursor:pointer}
.btn-primary{background:var(--accent);color:#06251C}
.btn-primary:hover{filter:brightness(.95)}
.btn-ghost{border-color:var(--line);background:var(--surface)}
.btn-ghost:hover{border-color:var(--muted)}
.btn-sm{padding:9px 16px;font-size:.9rem}

/* hero */
.hero{padding-block:72px 88px}
.hero .wrap{display:grid;grid-template-columns:1.1fr .9fr;gap:56px;align-items:center}
.hero h1{font-size:clamp(2.5rem,6vw,4.3rem);margin-block:18px 20px}
.hero h1 em{font-style:normal;color:var(--accent-ink);background:linear-gradient(transparent 62%,var(--accent-soft) 62%)}
.hero p.lead{font-size:1.15rem;color:var(--muted);max-width:34em}
.cta-row{display:flex;gap:12px;flex-wrap:wrap;margin-top:30px}
.proof{display:flex;gap:22px;flex-wrap:wrap;margin-top:34px;font-size:.88rem;color:var(--muted)}
.proof span::before{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--accent);margin-right:8px}

/* checkout de exemplo */
.pay{background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:24px;max-width:380px;margin-inline:auto;width:100%;box-shadow:0 24px 50px -28px rgba(0,0,0,.35)}
.pay-top{display:flex;justify-content:space-between;align-items:center;font-size:.85rem;color:var(--muted)}
.pill{font:500 .72rem var(--mono);padding:4px 9px;border-radius:99px;background:var(--accent-soft);color:var(--accent-ink);display:inline-flex;gap:6px;align-items:center}
.pill::before{content:"";width:6px;height:6px;border-radius:50%;background:var(--accent);animation:pulse 1.6s ease-in-out infinite}
@keyframes pulse{50%{opacity:.25}}
.amount{font:700 2.2rem var(--display);letter-spacing:-.02em;margin-block:14px 2px;font-variant-numeric:tabular-nums}
.amount small{font-size:1rem;color:var(--muted);font-weight:500;margin-right:4px}
.pay .desc{font-size:.88rem;color:var(--muted)}
.qr{display:grid;grid-template-columns:repeat(21,1fr);gap:0;width:176px;aspect-ratio:1;margin:20px auto 16px;padding:10px;border:1px solid var(--line);border-radius:12px;background:#fff}
.qr b{background:#141413}
.qr b.o{background:transparent}
.copy{display:flex;align-items:center;gap:10px;border:1px dashed var(--line);border-radius:10px;padding:9px 12px;font:400 .76rem var(--mono);color:var(--muted)}
.copy span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.copy button{font:600 .8rem var(--body);background:var(--ink);color:var(--bg);border:0;border-radius:7px;padding:7px 12px;cursor:pointer}
.timer{display:flex;justify-content:space-between;font-size:.82rem;color:var(--muted);margin-top:14px}
.timer b{color:var(--ink);font-family:var(--mono);font-weight:500}

/* seções */
section{padding-block:84px}
.band{background:var(--surface);border-block:1px solid var(--line)}
.head{max-width:36em;display:grid;gap:14px;margin-bottom:44px}
.head p{color:var(--muted);font-size:1.08rem}

/* fluxo real em 4 etapas */
.flow{display:grid;grid-template-columns:repeat(4,1fr);gap:0;border:1px solid var(--line);border-radius:16px;overflow:hidden;background:var(--bg)}
.flow>div{padding:26px 22px;border-right:1px solid var(--line);display:grid;gap:10px;align-content:start}
.flow>div:last-child{border-right:0}
.flow .n{font:500 .78rem var(--mono);color:var(--accent-ink)}
.flow p{color:var(--muted);font-size:.95rem}

.feat{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.feat article{border:1px solid var(--line);background:var(--surface);border-radius:14px;padding:26px;display:grid;gap:10px;align-content:start}
.feat article:first-child{grid-column:span 2;background:var(--accent-soft);border-color:transparent}
.feat p{color:var(--muted);font-size:.96rem}
.feat article:first-child p{color:var(--ink);opacity:.8}
.ico{width:38px;height:38px;border-radius:10px;border:1px solid var(--line);display:grid;place-items:center;font:600 .9rem var(--mono);color:var(--accent-ink);background:var(--bg)}
.feat article:first-child .ico{background:var(--surface);border-color:transparent}

/* api */
.split{display:grid;grid-template-columns:.9fr 1.1fr;gap:48px;align-items:center}
.split ul{list-style:none;padding:0;margin:22px 0 0;display:grid;gap:12px;color:var(--muted)}
.split li{padding-left:26px;position:relative}
.split li::before{content:"";position:absolute;left:0;top:.5em;width:12px;height:2px;background:var(--accent)}
.code{background:var(--code-bg);color:var(--code-fg);border-radius:14px;padding:22px;font:400 .84rem/1.7 var(--mono);overflow-x:auto;min-width:0}
.code .c{color:#7C7D74}.code .k{color:#19D3A5}.code .s{color:#F3C58B}
.code pre{margin:0}

/* planos */
.plans{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;align-items:stretch}
.plan{border:1px solid var(--line);background:var(--surface);border-radius:16px;padding:28px;display:grid;gap:16px;align-content:start}
.plan.hot{border:2px solid var(--accent)}
.plan .price{font:700 2.2rem var(--display);letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.plan .price small{font:500 .9rem var(--body);color:var(--muted);letter-spacing:0}
.plan ul{list-style:none;margin:0;padding:0;display:grid;gap:9px;font-size:.95rem;color:var(--muted)}
.plan li::before{content:"✓";color:var(--accent-ink);font-weight:700;margin-right:9px}
.tbd{display:inline-block;font:500 .9rem var(--mono);color:var(--accent-ink);background:var(--accent-soft);padding:6px 12px;border-radius:5px;letter-spacing:.04em}

/* faq */
.faq{max-width:760px;display:grid;gap:0;border-top:1px solid var(--line)}
details{border-bottom:1px solid var(--line);padding-block:20px}
summary{cursor:pointer;font:600 1.08rem var(--body);list-style:none;display:flex;justify-content:space-between;gap:16px}
summary::-webkit-details-marker{display:none}
summary::after{content:"+";font-family:var(--mono);color:var(--accent-ink)}
details[open] summary::after{content:"–"}
details p{color:var(--muted);margin-top:12px;max-width:60ch}

.final{background:var(--ink);color:var(--bg);border-radius:22px;padding:56px 36px;display:grid;gap:22px;justify-items:start}
.final h2{max-width:16em}
.final p{opacity:.75;max-width:34em}
footer{padding-block:34px 56px;color:var(--muted);font-size:.88rem}
footer .wrap{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}

@media (max-width:900px){
  .hero .wrap,.split{grid-template-columns:1fr;gap:40px}
  .flow{grid-template-columns:1fr 1fr}
  .flow>div:nth-child(2){border-right:0}
  .flow>div:nth-child(-n+2){border-bottom:1px solid var(--line)}
  .feat,.plans{grid-template-columns:1fr}
  .feat article:first-child{grid-column:auto}
  nav ul{display:none}
  section{padding-block:60px}
  .hero{padding-block:48px 60px}
}
@media (max-width:520px){
  .flow{grid-template-columns:1fr}
  .flow>div{border-right:0!important;border-bottom:1px solid var(--line)}
  .flow>div:last-child{border-bottom:0}
  .final{padding:40px 22px}
}
@media (prefers-reduced-motion:reduce){.pill::before{animation:none}html{scroll-behavior:auto}}
</style>
</head>
<body>

<nav><div class="wrap">
  <a class="logo" href="#topo"><i></i>Pagou num Click</a>
  <ul>
    <li><a href="#como-funciona">Como funciona</a></li>
    <li><a href="#recursos">Recursos</a></li>
    <li><a href="#integracao">Integração</a></li>
    <li><a href="#planos">Planos</a></li>
    <li><a href="#duvidas">Dúvidas</a></li>
  </ul>
  <a class="btn btn-primary btn-sm" href="{{ $contactUrl }}">Quero contratar</a>
</div></nav>

<header class="hero" id="topo"><div class="wrap">
  <div>
    <span class="eyebrow">Cobrança via PIX para sistemas</span>
    <h1>Seu sistema gera a cobrança. O cliente <em>paga num click.</em></h1>
    <p class="lead">Uma chamada de API cria o PIX, entrega um link de pagamento com QR Code e avisa o seu sistema assim que o dinheiro cair. Sem montar tela de pagamento, sem consultar status manualmente.</p>
    <div class="cta-row">
      <a class="btn btn-primary" href="{{ $contactUrl }}">Quero contratar</a>
      <a class="btn btn-ghost" href="#integracao">Ver a integração</a>
    </div>
    <div class="proof"><span>Pagamento via PIX</span><span>Webhook de confirmação</span><span>Painel para acompanhar</span></div>
  </div>

  <div class="pay" aria-label="Exemplo da tela de pagamento">
    <div class="pay-top"><span>Pedido #4821</span><span class="pill">Aguardando pagamento</span></div>
    <div class="amount"><small>R$</small>149,90</div>
    <div class="desc">Plano Mensal · Loja Exemplo</div>
    <div class="qr" id="qr" aria-hidden="true"></div>
    <div class="copy"><span>00020126580014br.gov.bcb.pix0136a1b2c3d4-e5f6-7890</span><button type="button" id="copy-btn">Copiar</button></div>
    <div class="timer"><span>Expira em</span><b id="timer">14:59</b></div>
  </div>
</div></header>

<section class="band" id="como-funciona"><div class="wrap">
  <div class="head">
    <span class="eyebrow">Como funciona</span>
    <h2>Da cobrança à confirmação, sem intervenção manual</h2>
    <p>O fluxo roda sozinho. O seu sistema só faz a primeira chamada e recebe o resultado.</p>
  </div>
  <div class="flow">
    <div><span class="n">ETAPA 1</span><h3>Cria a cobrança</h3><p>Seu sistema envia valor e dados do pagador para a API.</p></div>
    <div><span class="n">ETAPA 2</span><h3>Recebe o link</h3><p>A resposta traz o link de pagamento pronto para enviar ao cliente.</p></div>
    <div><span class="n">ETAPA 3</span><h3>Cliente paga</h3><p>Ele abre o link, escaneia o QR Code ou copia o código PIX.</p></div>
    <div><span class="n">ETAPA 4</span><h3>Você é avisado</h3><p>Ao confirmar, enviamos um webhook para o seu sistema.</p></div>
  </div>
</div></section>

<section id="recursos"><div class="wrap">
  <div class="head">
    <span class="eyebrow">Recursos</span>
    <h2>O que já vem pronto</h2>
  </div>
  <div class="feat">
    <article><div class="ico">QR</div><h3>Página de pagamento pronta</h3><p>Link com QR Code e PIX copia e cola, que atualiza sozinho quando o pagamento é confirmado. Você não precisa desenvolver nenhuma tela.</p></article>
    <article><div class="ico">↻</div><h3>Webhook com reenvio</h3><p>Se o seu sistema estiver fora do ar, tentamos de novo em intervalos crescentes, até 5 vezes.</p></article>
    <article><div class="ico">⏱</div><h3>Validade configurável</h3><p>Defina por quanto tempo o PIX fica válido. Cobranças vencidas são canceladas automaticamente.</p></article>
    <article><div class="ico">→</div><h3>Redirecionamento</h3><p>Depois do pagamento, o cliente volta para a página que você escolher.</p></article>
    <article><div class="ico">▤</div><h3>Painel de acompanhamento</h3><p>Veja cobranças, status e valores recebidos em um só lugar, com resumo por período.</p></article>
  </div>
</div></section>

<section class="band" id="integracao"><div class="wrap split">
  <div>
    <span class="eyebrow">Integração</span>
    <h2 style="margin-top:14px">Uma chamada. Um link.</h2>
    <ul>
      <li>Autenticação por token exclusivo da sua conta.</li>
      <li>Retorno imediato com o link de pagamento.</li>
      <li>Cada conta enxerga apenas as próprias cobranças.</li>
      <li>Notificação enviada para a URL que você configurar.</li>
    </ul>
  </div>
  <div class="code"><pre><span class="c"># Criar uma cobrança</span>
<span class="k">POST</span> /api/create-payment
Authorization: Bearer <span class="s">seu_token</span>

{
  <span class="s">"amount"</span>: 149.90,
  <span class="s">"description"</span>: <span class="s">"Plano Mensal"</span>,
  <span class="s">"customer"</span>: { <span class="s">"name"</span>: <span class="s">"Maria Souza"</span> }
}

<span class="c"># Resposta</span>
{
  <span class="s">"payment_link"</span>: <span class="s">"https://…/checkout/9f2c…"</span>
}</pre></div>
</div></section>

<section id="planos"><div class="wrap">
  <div class="head">
    <span class="eyebrow">Planos</span>
    <h2>Planos para cada volume</h2>
    <p>Valores combinados conforme o seu volume de cobranças.</p>
  </div>
  <div class="plans">
    <div class="plan"><h3>Início</h3><div class="price"><span class="tbd">Sob consulta</span></div>
      <ul><li>Link de pagamento com PIX</li><li>Webhook de confirmação</li><li>Painel de acompanhamento</li></ul>
      <a class="btn btn-ghost" href="{{ $contactUrl }}">Falar com a gente</a></div>
    <div class="plan hot"><h3>Negócio</h3><div class="price"><span class="tbd">Sob consulta</span></div>
      <ul><li>Tudo do Início</li><li>Validade e redirecionamento personalizados</li><li>Suporte prioritário</li></ul>
      <a class="btn btn-primary" href="{{ $contactUrl }}">Quero contratar</a></div>
    <div class="plan"><h3>Sob medida</h3><div class="price"><span class="tbd">Sob consulta</span></div>
      <ul><li>Volume alto de cobranças</li><li>Condições negociadas</li><li>Acompanhamento na integração</li></ul>
      <a class="btn btn-ghost" href="{{ $contactUrl }}">Falar com a gente</a></div>
  </div>
</div></section>

<section class="band" id="duvidas"><div class="wrap">
  <div class="head"><span class="eyebrow">Dúvidas</span><h2>Perguntas frequentes</h2></div>
  <div class="faq">
    <details><summary>Preciso ter uma tela de pagamento no meu sistema?</summary><p>Não. A resposta da API já traz um link de pagamento hospedado por nós, com QR Code e código PIX copia e cola.</p></details>
    <details><summary>Como sei que o cliente pagou?</summary><p>Enviamos um webhook para a URL cadastrada na sua conta assim que o status muda. Se a entrega falhar, tentamos novamente até 5 vezes.</p></details>
    <details><summary>Por quanto tempo o PIX fica válido?</summary><p>Você define a validade nas configurações da conta. Passado o prazo, a cobrança é cancelada automaticamente.</p></details>
    <details><summary>Posso acompanhar os pagamentos sem usar a API?</summary><p>Sim. O painel mostra todas as cobranças da sua conta, com status e valores.</p></details>
    <details><summary>Quais são as taxas?</summary><p>Dependem do plano e do volume de cobranças. Fale com a gente para receber a proposta.</p></details>
  </div>
</div></section>

<section id="contato"><div class="wrap">
  <div class="final">
    <h2>Pronto para receber por PIX sem montar checkout?</h2>
    <p>Fale com a gente e libere sua conta. A integração leva poucas chamadas de API.</p>
    <a class="btn btn-primary" href="{{ $contactUrl }}">Quero contratar</a>
  </div>
</div></section>

<footer><div class="wrap"><span>© Pagou num Click</span></div></footer>

<script>
// QR decorativo (padrão fixo, só para a prévia)
(function(){
  var n=21,el=document.getElementById('qr'),s=7,h='';
  function finder(r,c){return (r<7&&c<7)||(r<7&&c>=n-7)||(r>=n-7&&c<7)}
  function fv(r,c){r=r%(n-7>r?n:n);var rr=r>=n-7?r-(n-7):r,cc=c>=n-7?c-(n-7):c;
    return rr==0||rr==6||cc==0||cc==6||(rr>=2&&rr<=4&&cc>=2&&cc<=4)}
  for(var r=0;r<n;r++)for(var c=0;c<n;c++){
    var on;
    if(finder(r,c))on=fv(r,c);
    else{s=(s*1103515245+12345)&0x7fffffff;on=(s>>8)%100<48}
    h+='<b class="'+(on?'':'o')+'"></b>';
  }
  el.innerHTML=h;
  var t=899,te=document.getElementById('timer');
  setInterval(function(){t=t>0?t-1:899;var m=String(Math.floor(t/60)).padStart(2,'0'),x=String(t%60).padStart(2,'0');te.textContent=m+':'+x},1000);
  var b=document.getElementById('copy-btn');
  b.addEventListener('click',function(){
    var txt=b.parentNode.querySelector('span').textContent;
    var done=function(){b.textContent='Copiado';setTimeout(function(){b.textContent='Copiar'},1500)};
    try{navigator.clipboard.writeText(txt).then(done,function(){})}catch(e){}
  });
})();
</script>
</body>
</html>
