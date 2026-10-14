<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    /** @dataProvider tenSecondCommands */
    public function test_ten_second_commands_do_not_overlap(string $command): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, $command));

        $this->assertNotNull($event, "{$command} não está agendado");
        $this->assertTrue($event->withoutOverlapping, "{$command} deve usar withoutOverlapping()");
        $this->assertSame(1, $event->expiresAt);
    }

    public static function tenSecondCommands(): array
    {
        return [
            'process-payment' => ['app:process-payment'],
            'process-webhook' => ['app:process-webhook'],
        ];
    }
}
