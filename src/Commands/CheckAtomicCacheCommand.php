<?php

namespace KaziOmar\AtomicCache\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use KaziOmar\AtomicCache\Traits\AutoClearsCache;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

class CheckAtomicCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'atomic-cache:check
                            {--path= : Custom path to scan for Eloquent models}
                            {--filter= : Filter models by status (active, recommended, exempt)}
                            {--json : Output report as JSON}';

    /**
     * The console command description.
     */
    protected $description = 'Audit Eloquent models and inspect active cache invalidation architecture';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $scanPath = $this->option('path') ? base_path($this->option('path')) : app_path('Models');

        if (!is_dir($scanPath)) {
            $scanPath = app_path();
        }

        if (!is_dir($scanPath)) {
            $this->warn("Model scan directory not found at [{$scanPath}].");
            return self::SUCCESS;
        }

        if (!$this->option('json')) {
            $this->output->writeln('<info>Atomic Cache Architecture Audit</info>');
            $this->output->writeln("<comment>Target Path:</comment> {$scanPath}");
            $this->newLine();
        }

        $models = $this->findModels($scanPath);

        if (empty($models)) {
            if ($this->option('json')) {
                $this->output->writeln(json_encode([
                    'total_models' => 0,
                    'active' => [],
                    'exempt' => [],
                    'recommended' => [],
                    'health_score' => 100,
                ], JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }
            $this->warn("No Eloquent models found in [{$scanPath}].");
            return self::SUCCESS;
        }

        $activeModels = [];
        $exemptModels = [];
        $recommendedModels = [];

        foreach ($models as $class) {
            $usesTrait = in_array(AutoClearsCache::class, class_uses_recursive($class), true);
            $instance = null;

            try {
                $instance = new $class();
            } catch (\Throwable) {
                // If constructor requires arguments, skip instantiating
            }

            if ($usesTrait) {
                $tags = 'N/A';
                $related = 'None';

                if ($instance instanceof Model) {
                    $table = $instance->getTable();
                    $tags = "{$table}, {$table}:{id}";

                    $resolvedRelated = $this->extractRelatedTags($instance);
                    if (!empty($resolvedRelated)) {
                        $related = implode(', ', $resolvedRelated);
                    }
                }

                $activeModels[] = [
                    'class' => $class,
                    'status' => 'Active',
                    'primary_tags' => $tags,
                    'details' => $related,
                ];
            } else {
                $exemptReason = $instance ? $this->resolveExemptReason($class, $instance) : null;

                if ($exemptReason !== null) {
                    $exemptModels[] = [
                        'class' => $class,
                        'status' => 'Exempt',
                        'primary_tags' => 'N/A',
                        'details' => "{$exemptReason} (Uncached by design)",
                    ];
                } else {
                    $recommendedModels[] = [
                        'class' => $class,
                        'status' => 'Recommended',
                        'primary_tags' => 'N/A',
                        'details' => 'Domain model (Candidate for AutoClearsCache)',
                    ];
                }
            }
        }

        $filter = strtolower((string) $this->option('filter'));

        $totalCount = count($models);
        $activeCount = count($activeModels);
        $exemptCount = count($exemptModels);
        $recommendedCount = count($recommendedModels);

        // Optimal coverage: active + exempt models are intentionally handled
        $healthScore = $totalCount > 0 ? (int) round((($activeCount + $exemptCount) / $totalCount) * 100) : 100;

        if ($this->option('json')) {
            $payload = [
                'total_models' => $totalCount,
                'active' => $activeModels,
                'exempt' => $exemptModels,
                'recommended' => $recommendedModels,
                'health_score' => $healthScore,
            ];
            $this->output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $rows = [];

        if (empty($filter) || $filter === 'active') {
            foreach ($activeModels as $item) {
                $rows[] = [
                    $item['class'],
                    '<info>Active</info>',
                    $item['primary_tags'],
                    $item['details'],
                ];
            }
        }

        if (empty($filter) || $filter === 'recommended') {
            foreach ($recommendedModels as $item) {
                $rows[] = [
                    $item['class'],
                    '<comment>Recommended</comment>',
                    $item['primary_tags'],
                    $item['details'],
                ];
            }
        }

        if (empty($filter) || $filter === 'exempt') {
            foreach ($exemptModels as $item) {
                $rows[] = [
                    $item['class'],
                    '<fg=cyan>Exempt</>',
                    $item['primary_tags'],
                    $item['details'],
                ];
            }
        }

        $this->table(
            ['Model', 'Status', 'Primary Invalidation', 'Tags / Notes'],
            $rows
        );

        $this->newLine();
        $this->displaySummaryBox($totalCount, $activeCount, $exemptCount, $recommendedCount, $healthScore);

        return self::SUCCESS;
    }

    /**
     * Display a clean, professional summary card.
     */
    protected function displaySummaryBox(int $total, int $active, int $exempt, int $recommended, int $score): void
    {
        $this->line('+-------------------------------------------------------------------------+');
        $this->line(sprintf('| %-71s |', "Summary: {$total} models scanned"));
        $this->line(sprintf('| %-71s |', "• Active (AutoClearsCache): {$active}"));
        $this->line(sprintf('| %-71s |', "• Exempt (Telemetry / Ephemeral): {$exempt}"));
        $this->line(sprintf('| %-71s |', "• Recommended: {$recommended}"));
        $this->line('|                                                                         |');
        $this->line(sprintf('| %-71s |', "Architecture Health Score: {$score}% (Optimal Balance)"));
        $this->line('+-------------------------------------------------------------------------+');

        if ($recommended > 0) {
            $this->newLine();
            $this->line("<comment>Guidance:</comment> Models marked [Recommended] can benefit from 'use \\KaziOmar\\AtomicCache\\Traits\\AutoClearsCache;'");
        }
    }

    /**
     * Resolve why a model is categorized as exempt from caching.
     */
    protected function resolveExemptReason(string $class, Model $instance): ?string
    {
        // 1. Explicit developer declaration
        if (property_exists($instance, 'cacheExempt') && $instance->cacheExempt === true) {
            return 'Explicitly Exempt';
        }

        if (property_exists($instance, 'isEphemeral') && $instance->isEphemeral === true) {
            return 'Ephemeral Entity';
        }

        // 2. Pivot association tables
        if ($instance instanceof Pivot) {
            return 'Pivot Table';
        }

        $shortName = class_basename($class);

        // 3. Domain heuristics
        $patterns = [
            '/(Log|History|Audit|Metric|Stat)$/i' => 'Telemetry / Log',
            '/(Token|Session|Device)$/i'          => 'Ephemeral Token / Session',
            '/(Message|Conversation|Chat)$/i'     => 'Real-time Stream',
            '/(Route|Track|Swipe)$/i'             => 'High-Frequency Stream',
            '/(Transaction|PaymentRecord)$/i'     => 'Audit Ledger',
            '/(Bookmark|Reaction)$/i'             => 'Interaction Pivot',
        ];

        foreach ($patterns as $pattern => $reason) {
            if (preg_match($pattern, $shortName)) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Clean and format related tags, ensuring clean placeholders for unpersisted keys.
     */
    protected function extractRelatedTags(Model $instance): array
    {
        $related = [];

        if (property_exists($instance, 'relatedCacheTags') && is_array($instance->relatedCacheTags)) {
            $related = array_merge($related, $instance->relatedCacheTags);
        }

        if (method_exists($instance, 'getRelatedCacheTags')) {
            $related = array_merge($related, (array) $instance->getRelatedCacheTags());
        }

        $cleaned = [];
        foreach ($related as $tag) {
            if (is_string($tag)) {
                // If tag ends with trailing colon due to uninstantiated foreign key, replace with placeholder
                $formatted = preg_replace('/:+$/', ':{id}', trim($tag));
                $cleaned[] = str_replace('::', ':{id}:', $formatted);
            }
        }

        return array_values(array_unique(array_filter($cleaned)));
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

        sort($models);
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
