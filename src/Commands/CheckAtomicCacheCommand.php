<?php

namespace KaziOmar\AtomicCache\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

class CheckAtomicCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'atomic-cache:check
                            {--path= : Custom path to scan for Eloquent models}';

    /**
     * The console command description.
     */
    protected $description = 'Audit Eloquent models and inspect active cache invalidation tags';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Scanning application for Eloquent Models & Cache Tags...');
        $this->newLine();

        $scanPath = $this->option('path') ? base_path($this->option('path')) : app_path('Models');

        if (!is_dir($scanPath)) {
            $scanPath = app_path();
        }

        if (!is_dir($scanPath)) {
            $this->warn("No models directory found at [{$scanPath}].");
            return self::SUCCESS;
        }

        $models = $this->findModels($scanPath);

        if (empty($models)) {
            $this->warn("No Eloquent models found in [{$scanPath}].");
            return self::SUCCESS;
        }

        $rows = [];
        $enabledCount = 0;

        foreach ($models as $class) {
            $usesTrait = in_array(AutoClearsCache::class, class_uses_recursive($class), true);

            if ($usesTrait) {
                $enabledCount++;
                try {
                    $instance = new $class();
                    $table = $instance->getTable();
                    $primaryTags = "{$table}, {$table}:{id}";

                    $related = [];
                    if (property_exists($instance, 'relatedCacheTags') && is_array($instance->relatedCacheTags)) {
                        $related = array_merge($related, $instance->relatedCacheTags);
                    }
                    if (method_exists($instance, 'getRelatedCacheTags')) {
                        $related = array_merge($related, (array) $instance->getRelatedCacheTags());
                    }

                    $relatedStr = !empty($related) ? implode(', ', array_unique($related)) : 'None (Table only)';

                    $rows[] = [
                        $class,
                        '<info>✅ Enabled</info>',
                        $primaryTags,
                        $relatedStr,
                    ];
                } catch (\Throwable $e) {
                    $rows[] = [
                        $class,
                        '<info>✅ Enabled</info>',
                        'Error instantiating',
                        $e->getMessage(),
                    ];
                }
            } else {
                $rows[] = [
                    $class,
                    '<comment>⚠️ Missing Trait</comment>',
                    'N/A',
                    'Consider adding AutoClearsCache',
                ];
            }
        }

        $this->table(
            ['Model Class', 'AutoClearsCache', 'Primary Invalidation Tags', 'Related Custom Tags'],
            $rows
        );

        $this->newLine();
        $total = count($models);
        $this->info("Audit Complete: {$enabledCount} of {$total} models have automatic cache invalidation enabled.");

        if ($enabledCount < $total) {
            $this->comment("💡 Tip: Add 'use \\KaziOmar\\AtomicCache\\Traits\\AutoClearsCache;' to models to auto-flush cache on commit.");
        }

        return self::SUCCESS;
    }

    /**
     * Find all Eloquent model classes in the given directory.
     */
    protected function findModels(string $path): array
    {
        $models = [];
        $finder = new Finder();
        $finder->files()->in($path)->name('*.php');

        foreach ($finder as $file) {
            $class = $this->getClassFromFile($file->getRealPath());
            if ($class && class_exists($class)) {
                $reflection = new ReflectionClass($class);
                if ($reflection->isSubclassOf(Model::class) && !$reflection->isAbstract()) {
                    $models[] = $class;
                }
            }
        }

        return $models;
    }

    /**
     * Extract class name including namespace from file path.
     */
    protected function getClassFromFile(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);
        $namespace = '';
        $class = '';

        if (preg_match('/namespace\s+(.+?);/', $contents, $matches)) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/class\s+([a-zA-Z0-9_]+)/', $contents, $matches)) {
            $class = trim($matches[1]);
        }

        return ($namespace && $class) ? "{$namespace}\\{$class}" : null;
    }
}
