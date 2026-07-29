<?php

namespace App\Console\Commands;

use App\Services\TranslationWorkflowStore;
use Illuminate\Console\Command;

class PruneTranslationWorkflows extends Command
{
    /**
     * @var string
     */
    protected $signature = 'translations:prune';

    /**
     * @var string
     */
    protected $description = 'Prune expired translation workflow cache entries and private audio files';

    public function handle(TranslationWorkflowStore $store): int
    {
        $removed = $store->cleanupExpired();

        $this->info("Pruned {$removed} expired translation workflow artifact(s).");

        return self::SUCCESS;
    }
}
