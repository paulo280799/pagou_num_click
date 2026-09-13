<div>
    <img class="qr-code" src="{{ $qrCode }}" alt="QR Code Pix">
    <div class="instruction">Escaneie o código com seu celular</div>
    <div class="info">Abra o app do seu banco no celular, escolha Pix e aponte a câmera para o código</div>
    <div class="value">Valor da compra: R$ {{ number_format(floor($amount * 100) / 100, 2, ',', '.') }}</div>
    <div class="copy-area" id="pix-key">{{ $copyPaste }}</div>
    <button class="copy-btn" onclick="copyToClipboard()">Copiar código</button>
</div>

<script>
    function copyToClipboard() {
        const text = document.getElementById("pix-key");
        if (text) {
            navigator.clipboard.writeText(text.innerText).then(() => {
                alert("Código Pix copiado!");
            });
        }
    }
</script>
