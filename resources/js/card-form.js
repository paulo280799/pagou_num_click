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

const brl = (cents) => (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })

document.addEventListener('alpine:init', () => {
    window.Alpine.data('cardForm', (cfg) => ({
        ufs: UFS,
        number: '',
        holder: '',
        expiry: '',
        cvv: '',
        installments: 1,
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

        validate() {
            const e = {}
            if (!luhn(this.number)) e.number = 'Número de cartão inválido'
            if (this.holder.trim().length < 2) e.holder = 'Informe o nome impresso no cartão'
            const [mm, yy] = this.expiry.split('/')
            if (!mm || !yy || yy.length !== 2 || isExpired(mm, yy)) e.expiry = 'Validade inválida'
            if (!/^\d{3,4}$/.test(this.cvv)) e.cvv = 'CVV inválido'
            if (onlyDigits(this.zip).length !== 8) e.zip = 'CEP inválido'
            if (this.street.trim().length < 3) e.street = 'Informe a rua'
            if (this.addrNumber.trim() === '') e.addrNumber = 'Informe o número'
            if (this.city.trim().length < 2) e.city = 'Informe a cidade'
            if (!UFS.includes(this.state)) e.state = 'Selecione o estado'
            this.errors = e
            return Object.keys(e).length === 0
        },

        async tokenize() {
            const [mm, yy] = this.expiry.split('/')
            const res = await fetch(`${cfg.tokenUrl}?appId=${encodeURIComponent(cfg.publicKey)}`, {
                method: 'POST',
                credentials: 'omit',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    type: 'card',
                    card: {
                        number: onlyDigits(this.number),
                        holder_name: this.holder.trim(),
                        exp_month: Number(mm),
                        exp_year: Number(yy),
                        cvv: this.cvv,
                    },
                }),
            })
            // dado sensível some da memória/DOM logo após a tokenização
            this.number = ''
            this.cvv = ''
            if (!res.ok) throw new Error('tokenize')
            const data = await res.json()
            if (!data?.id) throw new Error('tokenize')
            return data.id
        },

        async submit() {
            if (this.busy || this.locked) return
            this.message = ''
            this.declined = false
            if (!this.validate()) return

            try {
                this.status = 'tokenizing'
                const token = await this.tokenize()

                this.status = 'sending'
                const res = await fetch(cfg.payUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({
                        card_token: token,
                        installments: Number(this.installments),
                        billing_address: {
                            line_1: `${this.street.trim()}, ${this.addrNumber.trim()}`,
                            zip_code: onlyDigits(this.zip),
                            city: this.city.trim(),
                            state: this.state,
                            country: 'BR',
                        },
                    }),
                })
                await this.handle(res)
            } catch (err) {
                this.status = 'idle'
                this.message = err.message === 'tokenize'
                    ? 'Não foi possível validar o cartão. Confira os dados e tente novamente.'
                    : 'Falha de conexão. Tente novamente.'
            }
        },

        async handle(res) {
            const body = await res.json().catch(() => ({}))

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
                this.message = 'Muitas tentativas. Aguarde alguns minutos ou pague com Pix.'
                setTimeout(() => { if (this.status === 'blocked') this.status = 'idle' }, 60000)
            } else {
                this.status = 'idle'
                this.message = 'Não foi possível concluir o pagamento. Tente novamente.'
            }
        },
    }))
})
