<?php

namespace App\Enums;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;

/** Períodos do filtro do dashboard e como o gráfico agrupa cada um (hora, dia ou mês). */
enum DashboardPeriodEnum: string
{
    case TODAY = 'today';
    case WEEK = 'week';
    case THIRTY_DAYS = 'thirty_days';
    case MONTH = 'month';
    case YEAR = 'year';

    private const MONTHS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    public static function fromFilters(?array $filters): self
    {
        return self::tryFrom($filters['period'] ?? '') ?? self::TODAY;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }

    public function label(): string
    {
        return match ($this) {
            self::TODAY => 'Hoje',
            self::WEEK => 'Últimos 7 dias',
            self::THIRTY_DAYS => 'Últimos 30 dias',
            self::MONTH => 'Este mês',
            self::YEAR => 'Este ano',
        };
    }

    public function start(): Carbon
    {
        return match ($this) {
            self::TODAY => Carbon::today(),
            self::WEEK => Carbon::today()->subDays(6),
            self::THIRTY_DAYS => Carbon::today()->subDays(29),
            self::MONTH => Carbon::now()->startOfMonth(),
            self::YEAR => Carbon::now()->startOfYear(),
        };
    }

    public function end(): Carbon
    {
        return Carbon::now();
    }

    public function applyTo(Builder $query): Builder
    {
        return $query->whereBetween('created_at', [$this->start(), $this->end()]);
    }

    /** Formato MySQL (DATE_FORMAT) da chave de agrupamento. */
    public function sqlBucketFormat(): string
    {
        return match ($this) {
            self::TODAY => '%Y-%m-%d %H',
            self::YEAR => '%Y-%m',
            default => '%Y-%m-%d',
        };
    }

    /**
     * Todos os baldes do período, em ordem, mesmo os sem pagamentos.
     *
     * @return array<string, string> chave do balde (igual ao sqlBucketFormat) => rótulo do eixo
     */
    public function buckets(): array
    {
        [$step, $key, $label] = match ($this) {
            self::TODAY => ['1 hour', 'Y-m-d H', fn (CarbonInterface $d) => $d->format('H:i')],
            self::YEAR => ['1 month', 'Y-m', fn (CarbonInterface $d) => self::MONTHS[$d->month - 1]],
            default => ['1 day', 'Y-m-d', fn (CarbonInterface $d) => $d->format('d/m')],
        };

        $start = $this === self::TODAY ? $this->start() : $this->start()->startOf($this === self::YEAR ? 'month' : 'day');

        $buckets = [];
        foreach (CarbonPeriod::create($start, $step, $this->end()) as $date) {
            $buckets[$date->format($key)] = $label($date);
        }

        return $buckets;
    }
}
