<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\WebhookController;

class ProcessWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:process-webhook';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $controller = app(WebhookController::class);
        $controller->index();
    }
}
