<?php

namespace KaziOmar\AtomicCache\Commands;

use Illuminate\Console\Command;
use KaziOmar\AtomicCache\Facades\AtomicCache;

class ClearAtomicCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'atomic-cache:clear 
                            {tag? : The specific cache tag or key to clear}
                            {--all : Clear all entries in the cache store}';

    /**
     * The console command description.
     */
    protected $description = 'Clear atomic cache tags, keys, or the entire store';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tag = $this->argument('tag');
        $all = $this->option('all');

        if ($all) {
            AtomicCache::getRawStore()->flush();
            $this->info('Atomic Cache: All entries cleared successfully.');
            return self::SUCCESS;
        }

        if ($tag) {
            AtomicCache::clearTags([$tag]);
            AtomicCache::clear($tag);
            $this->info("Atomic Cache: Cleared tag and key [{$tag}].");
            return self::SUCCESS;
        }

        $this->warn('Please specify a tag or use --all.');
        $this->line('Example: <info>php artisan atomic-cache:clear users</info>');
        $this->line('Example: <info>php artisan atomic-cache:clear --all</info>');

        return self::FAILURE;
    }
}
