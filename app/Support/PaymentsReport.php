<?php

namespace App\Support;

use App\Enums\DashboardPeriodEnum;
use App\Enums\StatusPaymentEnum;
use App\Filament\Resources\PaymentResource;
use App\Models\Payment;
use Illuminate\Support\Carbon;

/** Relatório CSV (separador `;`, UTF-8 com BOM, para abrir direto no Excel pt-BR) dos pagamentos de um período. */
class PaymentsReport
{
    private const HEADER = [
        'Referência', 'ID', 'Meio', 'Valor', 'Status', 'Tarifa (%)', 'Tarifa (R$)', 'Líquido', 'Criado em', 'Pago em',
    ];

    public function __construct(private readonly DashboardPeriodEnum $period) {}

    public function filename(): string
    {
        return 'relatorio-pagamentos-'.str_replace('_', '-', $this->period->value).'-'.now()->format('Ymd').'.csv';
    }

    /** Escreve o CSV em `$out`; o escopo da conta vem do usuário autenticado (UserScope). */
    public function write($out): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::HEADER, ';');

        $payments = $this->period->applyTo(Payment::query())->orderByDesc('created_at')->cursor();

        foreach ($payments as $payment) {
            fputcsv($out, $this->row($payment), ';');
        }
    }

    /** @return array<int, string> */
    private function row(Payment $payment): array
    {
        $paid = $payment->status === StatusPaymentEnum::PAID;
        // só o pago tem tarifa definitiva (snapshot); o pendente seria estimativa e custaria uma consulta por linha
        $fee = $paid ? $payment->feeSummary() : ['percent' => null, 'amount' => null];

        return [
            self::text($payment->refExternal),
            $payment->id,
            PaymentResource::paymentMethodShort($payment),
            self::money($payment->amount),
            $payment->status->getLabel(),
            $fee['percent'] === null ? '' : self::money($fee['percent']),
            $fee['amount'] === null ? '' : self::money($fee['amount']),
            $paid ? self::money($payment->amount - ($fee['amount'] ?? 0)) : '',
            $payment->created_at?->format('d/m/Y H:i') ?? '',
            $payment->paymentDate ? Carbon::parse($payment->paymentDate)->format('d/m/Y H:i') : '',
        ];
    }

    private static function money(float|int|string $value): string
    {
        return number_format((float) $value, 2, ',', '');
    }

    /** A referência vem do sistema do cliente: neutraliza fórmulas (`=`, `+`, `-`, `@`) ao abrir no Excel. */
    private static function text(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
