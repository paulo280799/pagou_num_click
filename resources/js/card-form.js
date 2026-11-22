export const onlyDigits = (v) => String(v ?? '').replace(/\D+/g, '')

export function luhn(pan) {
    const digits = onlyDigits(pan)
    if (digits.length < 13 || digits.length > 19) return false
    let sum = 0
    let alt = false
    for (let i = digits.length - 1; i >= 0; i--) {
        let n = Number(digits[i])
        if (alt) {
            n *= 2
            if (n > 9) n -= 9
        }
        sum += n
        alt = !alt
    }
    return sum % 10 === 0
}

export function isExpired(month, year2) {
    const m = Number(month)
    const y = 2000 + Number(year2)
    if (!(m >= 1 && m <= 12) || !(y >= 2000)) return true
    const now = new Date()
    return y < now.getFullYear() || (y === now.getFullYear() && m < now.getMonth() + 1)
}

export const formatPan = (v) => onlyDigits(v).slice(0, 19).replace(/(.{4})/g, '$1 ').trim()

export function formatExpiry(v) {
    const d = onlyDigits(v).slice(0, 4)
    return d.length > 2 ? `${d.slice(0, 2)}/${d.slice(2)}` : d
}

export const formatZip = (v) => {
    const d = onlyDigits(v).slice(0, 8)
    return d.length > 5 ? `${d.slice(0, 5)}-${d.slice(5)}` : d
}

const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO']

// SDK do Mercado Pago carregado sob demanda: só quem cai no fallback paga o custo do script
const mpInstances = {}
async function mercadoPago(publicKey) {
    if (!window.MercadoPago) {
        await new Promise((resolve, reject) => {
            const script = document.createElement('script')
            script.src = 'https://sdk.mercadopago.com/js/v2'
            script.onload = resolve
            script.onerror = () => reject(new Error('tokenize'))
            document.head.appendChild(script)
        })
    }
    mpInstances[publicKey] ??= new window.MercadoPago(publicKey, { locale: 'pt-BR' })
    return mpInstances[publicKey]
}

// Device ID: o SDK v2 grava window.MP_DEVICE_SESSION_ID de forma assíncrona após carregar (antifraude do MP)
async function mpDeviceId() {
    for (let i = 0; i < 10 && !window.MP_DEVICE_SESSION_ID; i++) {
        await new Promise((r) => setTimeout(r, 100))
    }
    return window.MP_DEVICE_SESSION_ID ?? null
}

const brl = (cents) => (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })

document.addEventListener('alpine:init', () => {
    window.Alpine.data('cardForm', (cfg) => ({
        ufs: UFS,
        number: '',
        holder: '',
        expiry: '',
        cvv: '',
        installments: 1,
        cpf: '',
        // gateway vigente no render; o do submit vem de cfg.configUrl (o formulário vive em wire:ignore e este valor congela)
        gateway: cfg.gateway ?? 'pagarme',
        zip: '',
        street: '',
        addrNumber: '',
        zipLoading: false,
        zipLookupFailed: false,
        city: '',
        state: '',
        errors: {},
        status: 'idle', // idle | tokenizing | sending | processing | blocked
        message: '',
        declined: false,

        maskPan: formatPan,
        maskExpiry: formatExpiry,
        maskZip: formatZip,
        maskCpf(v) {
            const d = onlyDigits(v).slice(0, 11)
            return d.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2')
        },

        get amountLabel() {
            return brl(cfg.amountCents)
        },

        get busy() {
            return this.status === 'tokenizing' || this.status === 'sending'
        },

        get locked() {
            return this.status === 'processing' || this.status === 'blocked'
        },

        // Autocompleta rua/cidade/UF pelo CEP (ViaCEP). Falha é silenciosa: o pagador preenche à mão.
        async lookupZip() {
            const cep = onlyDigits(this.zip)
            if (cep.length !== 8) return
            this.zipLoading = true
            this.zipLookupFailed = false
            try {
                const res = await fetch(`https://viacep.com.br/ws/${cep}/json/`, { credentials: 'omit', signal: AbortSignal.timeout(5000) })
                const data = res.ok ? await res.json() : null
                if (!data || data.erro) {
                    this.zipLookupFailed = true
                    return
                }
                this.street = data.logradouro || this.street
                this.city = data.localidade || this.city
                if (UFS.includes(data.uf)) this.state = data.uf
                this.errors = { ...this.errors, zip: undefined, street: undefined, city: undefined, state: undefined }
            } catch {
                this.zipLookupFailed = true
            } finally {
                this.zipLoading = false
            }
        },

        installmentOptions() {
            return Array.from({ length: cfg.maxInstallments }, (_, i) => {
                const n = i + 1
                return { n, label: n === 1 ? `1x de ${brl(cfg.amountCents)}` : `${n}x de ${brl(Math.ceil(cfg.amountCents / n))} sem juros` }
            })
        },

        validate(gateway) {
            const e = {}
            if (!luhn(this.number)) e.number = 'Número de cartão inválido'
            if (this.holder.trim().length < 2) e.holder = 'Informe o nome impresso no cartão'
            const [mm, yy] = this.expiry.split('/')
            if (!mm || !yy || yy.length !== 2 || isExpired(mm, yy)) e.expiry = 'Validade inválida'
            if (!/^\d{3,4}$/.test(this.cvv)) e.cvv = 'CVV inválido'
            if (gateway === 'mercadopago') {
                // o MP não pede endereço de cobrança, mas exige o documento do titular para tokenizar
                if (onlyDigits(this.cpf).length !== 11) e.cpf = 'CPF inválido'
            } else {
                if (onlyDigits(this.zip).length !== 8) e.zip = 'CEP inválido'
                if (this.street.trim().length < 3) e.street = 'Informe a rua'
                if (this.addrNumber.trim() === '') e.addrNumber = 'Informe o número'
                if (this.city.trim().length < 2) e.city = 'Informe a cidade'
                if (!UFS.includes(this.state)) e.state = 'Selecione o estado'
            }
            this.errors = e
            return Object.keys(e).length === 0
        },

        // Gateway vigente no momento do clique. Se a consulta falhar, segue com a config do render.
        async liveConfig() {
            try {
                const res = await fetch(cfg.configUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                if (res.status === 409) throw new Error('blocked')
                if (!res.ok) return cfg
                return { ...cfg, ...(await res.json()) }
            } catch (err) {
                if (err.message === 'blocked') throw err
                return cfg
            }
        },

        // `held` guarda número e CVV só durante o submit (o fallback re-tokeniza com eles); nunca vai para o estado do Alpine.
        async tokenize(live, held) {
            const [mm, yy] = this.expiry.split('/')

            if (live.gateway === 'mercadopago') {
                const mp = await mercadoPago(live.publicKey)
                const methods = await mp.getPaymentMethods({ bin: held.number.slice(0, 8) })
                const method = methods?.results?.[0]
                if (!method?.id) throw new Error('tokenize')
                // issuer só quando o MP pede (additional_info_needed); enviar o "issuer" sugerido de qualquer jeito
                // dá erro 10111 (BIN sem esse emissor configurado)
                let issuerId = null
                if (method.additional_info_needed?.includes('issuer_id')) {
                    const issuers = await mp.getIssuers({ paymentMethodId: method.id, bin: held.number.slice(0, 8) })
                    issuerId = issuers?.[0]?.id ?? null
                }
                const token = await mp.createCardToken({
                    cardNumber: held.number,
                    cardholderName: this.holder.trim(),
                    cardExpirationMonth: String(Number(mm)),
                    cardExpirationYear: String(2000 + Number(yy)),
                    securityCode: held.cvv,
                    identificationType: 'CPF',
                    identificationNumber: onlyDigits(this.cpf),
                })
                if (!token?.id) throw new Error('tokenize')
                return { token: token.id, paymentMethodId: method.id, issuerId }
            }

            const res = await fetch(`${live.tokenUrl}?appId=${encodeURIComponent(live.publicKey)}`, {
                method: 'POST',
                credentials: 'omit',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    type: 'card',
                    card: {
                        number: held.number,
                        holder_name: this.holder.trim(),
                        exp_month: Number(mm),
                        exp_year: Number(yy),
                        cvv: held.cvv,
                    },
                }),
            })
            if (!res.ok) throw new Error('tokenize')
            const data = await res.json()
            if (!data?.id) throw new Error('tokenize')
            return { token: data.id }
        },

        async payBody(live, tokenized) {
            if (live.gateway === 'mercadopago') {
                return {
                    device_id: await mpDeviceId(),
                    gateway: 'mercadopago',
                    card_token: tokenized.token,
                    payment_method_id: tokenized.paymentMethodId,
                    issuer_id: tokenized.issuerId,
                    installments: 1,
                }
            }
            return {
                gateway: 'pagarme',
                card_token: tokenized.token,
                installments: Number(this.installments),
                billing_address: {
                    line_1: `${this.street.trim()}, ${this.addrNumber.trim()}`,
                    zip_code: onlyDigits(this.zip),
                    city: this.city.trim(),
                    state: this.state,
                    country: 'BR',
                },
            }
        },

        async submit() {
            if (this.busy || this.locked) return
            this.message = ''
            this.declined = false

            let held = null
            try {
                this.status = 'tokenizing'
                let live = await this.liveConfig()
                this.gateway = live.gateway ?? 'pagarme'
                if (!this.validate(this.gateway)) {
                    this.status = 'idle'
                    return
                }

                held = { number: onlyDigits(this.number), cvv: this.cvv }
                // dado sensível some do estado/DOM assim que sai do formulário
                this.number = ''
                this.cvv = ''

                // 2 voltas: a segunda só existe se o backend pedir para tokenizar em outro gateway (fallback)
                for (let attempt = 0; attempt < 2; attempt++) {
                    this.status = 'tokenizing'
                    const tokenized = await this.tokenize(live, held)

                    this.status = 'sending'
                    const res = await fetch(cfg.payUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        },
                        body: JSON.stringify(await this.payBody(live, tokenized)),
                    })
                    const body = await res.json().catch(() => ({}))

                    if (body.status === 'fallback' && attempt === 0) {
                        live = { ...cfg, ...body }
                        this.gateway = live.gateway
                        if (!this.validate(this.gateway)) {
                            // faltam dados do outro gateway (ex.: CPF no MP): o pagador completa e envia de novo
                            this.status = 'idle'
                            this.message = 'Para concluir, complete os dados abaixo e digite o cartão novamente.'
                            return
                        }
                        continue
                    }

                    this.handle(res, body)
                    return
                }
            } catch (err) {
                // o SDK do MP rejeita com objeto/array sem `.message`: só o tipo vai ao console (nunca dados do cartão)
                console.error('card-form:', err?.message ?? JSON.stringify(err)?.slice(0, 300))
                this.status = 'idle'
                if (err.message === 'blocked') {
                    this.status = 'blocked'
                    this.message = 'Este pagamento não pode mais ser pago no cartão.'
                    this.$wire.refreshStatus()
                } else {
                    this.message = err.message === 'tokenize'
                        ? 'Não foi possível validar o cartão. Confira os dados e tente novamente.'
                        : 'Falha de conexão. Tente novamente.'
                }
            } finally {
                held = null
            }
        },

        handle(res, body) {
            if (res.status === 200) {
                this.status = 'blocked'
                this.message = 'Pagamento aprovado!'
                this.$wire.refreshStatus()
            } else if (res.status === 422 && body.status === 'declined') {
                this.status = 'idle'
                this.declined = true
                this.message = body.message
            } else if (res.status === 422) {
                this.status = 'idle'
                const first = Object.values(body.errors ?? {})[0]
                this.message = Array.isArray(first) ? first[0] : 'Confira os dados informados.'
            } else if (res.status === 202 || res.status === 503) {
                this.status = 'processing'
                this.message = 'Pagamento em análise. Não pague novamente: esta página será atualizada.'
            } else if (res.status === 409) {
                this.status = 'blocked'
                this.message = 'Este pagamento não pode mais ser pago no cartão.'
                this.$wire.refreshStatus()
            } else if (res.status === 429) {
                this.status = 'blocked'
                this.message = cfg.pixEnabled ? 'Muitas tentativas. Aguarde alguns minutos ou pague com Pix.' : 'Muitas tentativas. Aguarde alguns minutos e tente novamente.'
                setTimeout(() => { if (this.status === 'blocked') this.status = 'idle' }, 60000)
            } else {
                this.status = 'idle'
                this.message = 'Não foi possível concluir o pagamento. Tente novamente.'
            }
        },
    }))
})
