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
                    'advisories' => [],
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
        $advisories = [];

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

                    $modelAdvisories = $this->inspectModelBelongsToAdvisories($class, $instance, $resolvedRelated);
                    if (!empty($modelAdvisories)) {
                        $advisories = array_merge($advisories, $modelAdvisories);
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
                'advisories' => $advisories,
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

        if (!empty($advisories)) {
            $this->newLine();
            $this->displayAdvisoriesBox($advisories);
        }

        return self::SUCCESS;
    }

    /**
     * Display a clean, professional summary card.
     */
    protected function displaySummaryBox(int $total, int $active, int $exempt, int $recommended, int $score): void
    {
        $rating = $score >= 90 ? 'Optimal' : ($score >= 70 ? 'Good' : 'Needs Attention');

        $this->line('+-------------------------------------------------------------------------+');
        $this->line($this->formatBoxLine("Summary: {$total} models scanned"));
        $this->line($this->formatBoxLine("• Active (AutoClearsCache): {$active}"));
        $this->line($this->formatBoxLine("• Exempt (Telemetry / Ephemeral): {$exempt}"));
        $this->line($this->formatBoxLine("• Recommended: {$recommended}"));
        $this->line('|                                                                         |');
        $this->line($this->formatBoxLine("Architecture Health Score: {$score}% ({$rating})"));
        $this->line('+-------------------------------------------------------------------------+');

        if ($recommended > 0) {
            $this->newLine();
            $this->line("<comment>Guidance:</comment> Models marked [Recommended] can benefit from 'use \\KaziOmar\\AtomicCache\\Traits\\AutoClearsCache;'");
        }
    }

    /**
     * Display friendly proactive invalidation guidance for unlinked relationships.
     */
    protected function displayAdvisoriesBox(array $advisories): void
    {
        $count = count($advisories);
        $this->line("<comment>💡 Invalidation Architecture Guidance ({$count} suggested " . ($count === 1 ? 'rule' : 'rules') . '):</comment>');
        $this->newLine();

        foreach ($advisories as $item) {
            $shortModel = class_basename($item['model']);
            $this->line("  <info>• {$item['model']}:</info>");
            $this->line("    BelongsTo <comment>{$item['parent_class']}</comment> detected via <comment>{$item['relation']}()</comment>, but parent cache is not linked.");
            $this->line("    👉 Quick Fix: Add <info>{$item['suggested_code']}</info> to {$shortModel}");
            $this->line("    ✨ Auto-invalidates: <comment>\"{$item['suggested_tag']}\"</comment>");
            $this->newLine();
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
     * Clean and format related tags, extracting declared parent relations and custom related tags.
     */
    protected function extractRelatedTags(Model $instance): array
    {
        $related = [];

        // 1. Extract declared parent relations ($cacheTouches, $clearsRelations, $cacheParents) safely via reflection
        $configuredParents = $this->getDeclaredParentRelations($instance);
        $childTable = $instance->getTable();

        foreach ($configuredParents as $relationName) {
            if (!is_string($relationName) || trim($relationName) === '') {
                continue;
            }

            $relationName = trim($relationName);

            if (method_exists($instance, $relationName)) {
                try {
                    $ref = new \ReflectionMethod($instance, $relationName);
                    if ($ref->getNumberOfRequiredParameters() === 0) {
                        $relation = $instance->{$relationName}();
                        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo
                            && !$relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo) {
                            $parentTable = $relation->getRelated()->getTable();
                            $related[] = "{$parentTable}:{id}:{$childTable}";
                            $related[] = "{$parentTable}:{id}";
                        }
                    }
                } catch (\Throwable) {
                    // Safe fallback
                }
            }
        }

        // 2. Extract legacy related tags
        if (property_exists($instance, 'relatedCacheTags') && is_array($instance->relatedCacheTags)) {
            $related = array_merge($related, $instance->relatedCacheTags);
        }

        if (method_exists($instance, 'getRelatedCacheTags')) {
            $related = array_merge($related, (array) $instance->getRelatedCacheTags());
        }

        $cleaned = [];
        foreach ($related as $tag) {
            if (is_string($tag)) {
                $formatted = preg_replace('/:+$/', ':{id}', trim($tag));
                $cleaned[] = str_replace('::', ':{id}:', $formatted);
            }
        }

        return array_values(array_unique(array_filter($cleaned)));
    }

    /**
     * Inspect model for BelongsTo relationships that are not linked to cache invalidation.
     */
    protected function inspectModelBelongsToAdvisories(string $class, Model $instance, array $existingRelatedTags): array
    {
        $advisories = [];
        $refClass = new ReflectionClass($class);
        $childTable = $instance->getTable();

        $configuredParents = $this->getDeclaredParentRelations($instance);

        foreach ($refClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Only inspect non-static methods declared directly on user models (exclude Eloquent base model and magic methods)
            if ($method->isStatic() || $method->class === Model::class || $method->class === \Illuminate\Database\Eloquent\Model::class) {
                continue;
            }

            $methodName = $method->getName();

            if (str_starts_with($methodName, "__") || $method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            // Skip if already declared in cacheTouches / clearsRelations / cacheParents
            if (in_array($methodName, $configuredParents, true)) {
                continue;
            }

            // Guard 1: Skip if method has a non-relation return type (e.g. void, int, string, bool, array)
            if ($method->hasReturnType()) {
                $returnType = $method->getReturnType();
                if ($returnType instanceof \ReflectionNamedType) {
                    $typeName = $returnType->getName();
                    if ($returnType->isBuiltin() || (!is_subclass_of($typeName, \Illuminate\Database\Eloquent\Relations\Relation::class) && $typeName !== \Illuminate\Database\Eloquent\Relations\Relation::class && $typeName !== \Illuminate\Database\Eloquent\Relations\BelongsTo::class)) {
                        continue;
                    }
                }
            }

            // Guard 2: If file is accessible, inspect source code of the method to ensure it contains "belongsTo"
            if ($method->getFileName() && file_exists($method->getFileName())) {
                try {
                    $fileLines = file($method->getFileName());
                    $body = implode("", array_slice($fileLines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                    if (!str_contains($body, "belongsTo")) {
                        continue;
                    }
                } catch (\Throwable) {
                    // Safe fallback
                }
            }

            try {
                $relation = $instance->{$methodName}();

                if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo
                    && !$relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo) {

                    $foreignKey = $relation->getForeignKeyName();
                    $parentModel = $relation->getRelated();
                    $parentTable = $parentModel->getTable();
                    $parentClass = class_basename($parentModel);

                    // Check if already covered by custom string tag
                    $alreadyHandled = false;
                    foreach ($existingRelatedTags as $existing) {
                        if (str_starts_with($existing, "{$parentTable}:")) {
                            $alreadyHandled = true;
                            break;
                        }
                    }

                    if (!$alreadyHandled) {
                        $advisories[] = [
                            'model' => $class,
                            'relation' => $methodName,
                            'parent_class' => $parentClass,
                            'foreign_key' => $foreignKey,
                            'parent_table' => $parentTable,
                            'suggested_code' => "protected array \$cacheParents = ['{$methodName}'];",
                            'suggested_tag' => "{$parentTable}:{\$this->{$foreignKey}}:{$childTable}",
                        ];
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $advisories;
    }

    /**
     * Safely extract declared parent relations from protected/public model properties using reflection.
     */
    protected function getDeclaredParentRelations(Model $instance): array
    {
        $ref = new ReflectionClass($instance);

        foreach (['cacheParents', 'cacheTouches', 'clearsRelations'] as $prop) {
            if ($ref->hasProperty($prop)) {
                $property = $ref->getProperty($prop);
                $value = $property->getValue($instance);
                if (is_array($value)) {
                    return $value;
                }
                if (is_string($value) && trim($value) !== '') {
                    return [trim($value)];
                }
            }
        }

        return [];
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
     * Format a line inside the summary card ensuring perfect border alignment.
     */
    protected function formatBoxLine(string $text, int $targetWidth = 71): string
    {
        $currentWidth = mb_strwidth($text);
        $padLength = max(0, $targetWidth - $currentWidth);
        return '| ' . $text . str_repeat(' ', $padLength) . ' |';
    }

    /**
     * Extract class name including namespace from file path safely using PHP tokens.
     */
    protected function getClassFromFile(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);
        $tokens = token_get_all($contents);
        $namespace = '';
        $class = '';
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === ';') {
                        break;
                    }
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_NAME_QUALIFIED, T_STRING])) {
                        $namespace .= $tokens[$j][1];
                    }
                }
            }

            if ($tokens[$i][0] === T_CLASS) {
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === '{' || $tokens[$j] === ';') {
                        break;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $class = $tokens[$j][1];
                        break 2;
                    }
                }
            }
        }

        return ($namespace && $class) ? "{$namespace}\\{$class}" : null;
    }
}
