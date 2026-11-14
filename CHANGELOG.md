# Changelog

All notable changes to `laravel-atomic-cache` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [v1.6.0] - 2026-11-14

### Added
- **Stale-While-Revalidate (`AtomicCache::flexible()`):** Implemented high-performance Stale-While-Revalidate (SWR) caching matching Laravel 11's `Cache::flexible()` convention (`[fresh_ttl, total_ttl]`). Returns existing cached payloads instantly during the grace window (0ms user latency spike) while deferring background revalidation after response delivery via `defer()` (Laravel 11.23+) or `app()->terminating()`. Features Optimistic Concurrency Control (OCC) to discard stale writes if tags change mid-flight, automated grace duration calculation (`AtomicCache::flexible('key', 3600, ...)`), fluent tagging (`AtomicCache::tags([...])->flexible(...)`), and 30-second error backoff bounded by the original expiration ceiling (`__swr_expires_at__`).
- **Query Builder Pagination Macros (`atomicCachePaginate` & `atomicCacheSimplePaginate`):** Introduced dedicated macros on Eloquent and Query Builders for caching paginated result sets without model collection crashes. Automatically embeds page numbers, page names, per-page limits, columns, joins, and SQL fingerprints into deterministic cache keys and tags, while preserving the paginator's current URL path via `$paginator->setPath(url()->current())`.
- **Query Builder Aggregation Macros (`atomicCacheCount` & `atomicCacheExists`):** Added high-speed macros for caching `COUNT(*)` integer results and `EXISTS` boolean checks directly from the database without hydrating full model instances or Eloquent collections into memory. Supports custom column targets, custom cache keys, and automatic TTL detection.
- **Clean Parameter Separation in `HasAtomicCache::cache()`:** Separated `$this->cache()` parameters into clean, idiomatic types: `cache(string $method, array $args = [], ?Closure $callback = null, mixed $ttl = null, ?bool $perUser = null, array $tags = [], bool $lock = false)`, providing intuitive positional calls and full support for PHP 8 named arguments (`lock: true`, `tags: ['...']`).
- **Documented Missing Environment Variables:** Added full documentation and default values for `ATOMIC_CACHE_BYPASS_DURING_TRANSACTIONS`, `ATOMIC_CACHE_STRICT_MODE`, `ATOMIC_CACHE_COMPRESSION_LEVEL`, `ATOMIC_CACHE_SELF_HEALING`, and `ATOMIC_CACHE_LOG_CORRUPTED` in `.env` reference tables across VitePress documentation and `README.md`.
- **Fluent Tag Scoping on Global Helper (`atomic_cache(['tag1', 'tag2'])`):** Updated the global `atomic_cache()` helper to accept arrays of tags, allowing fluent chained calls like `atomic_cache(['products'])->remember(...)` without `TypeError`.

### Fixed
- **CLI & Queue Worker Request Resolution:** Guarded `shouldIncludeRequestQuery()` and `getSanitizedRequestQuery()` in `HasAtomicCache` to check if `request` is bound in the application container before calling `request()`, eliminating `BindingResolutionException: Target class [request] does not exist` in CLI commands, queue workers, and background jobs.
- **Blade Directive DateTime TTL Support:** Resolved strict `?int` type-hinting on `AtomicCacheBladeHelper::setUp()` so that passing Carbon, `DateTimeInterface`, or `DateInterval` instances to `@atomicCache('key', now()->addHour())` parses properly without throwing a `TypeError`.
- **Virtual Tag Initialization Atomic Add:** Replaced loose `get()` + `forever()` logic during first-time virtual tag initialization with atomic `$store->add()`, completely closing concurrent overwrite race conditions across File, Database, and non-taggable cache drivers.
- **Lock Fallback Cache Re-Check (Stampede Defense):** Added an instant cache re-check before executing the fallback callback when `rememberWithLock` wait budget times out, preventing thundering herds from overwhelming the database if the original lock holder completed computation immediately as waiters timed out.
- **Stateless API & Unbound Session Resolution:** Guarded `forUser()` in `AtomicCache` against `BindingResolutionException: Target class [session] does not exist` and `RuntimeException: A facade root has not been set` when running in stateless API endpoints, console commands, or testing harnesses without session middleware.
- **Plain Container `version()` & `isLocal()` Crash Guard:** Replaced direct `app()->version()` and `app()->isLocal()` invocations with `method_exists()` checks in `ManagesVirtualTags` and `AutoClearsCache` to prevent fatal errors when used with standalone Illuminate containers lacking `Illuminate\Foundation\Application`.
- **Query Macro `DateTimeInterface` / `DateInterval` TTL Parsing:** Resolved an issue where passing Carbon or `DateInterval` instances to `atomicCache()` ignored the provided duration due to strict `is_numeric()` checks; query macros now correctly parse all valid Laravel TTL types via `AtomicCache::parseTtlSeconds()`.
- **Query Macro Expression Columns Serialization:** Fixed an issue where passing `Expression` or `DB::raw()` objects in `$columns` caused `implode()` to throw a string conversion error during query pagination fingerprinting.
- **Safe Serialization in `HasAtomicCache::normalizeArguments()`:** Wrapped object serialization in a `try/catch` fallback to `spl_object_hash()` to prevent fatal crashes when service methods receive non-serializable objects (PDO connections, closures, or generator wrappers).
- **Connection `transactionsManager` Safety in `AutoClearsCache`:** Wrapped `$connection->afterCommit()` in `clearModelCacheOnCommit()` with fallback immediate execution to protect against mock or non-standard connections lacking a transaction manager.
- **Early Flexible Tuple Validation:** Moved flexible cache tuple validation ahead of cache bypass checks so invalid configurations throw exceptions immediately during testing and development.

---

## [v1.5.0] - 2026-10-28

### Added
- **Active Database Transaction Guard (`bypass_during_transactions`):** Automatically detects active uncommitted database transactions across all opened database connections and bypasses cache reads and writes to eliminate dirty reads and phantom cache entries upon rollback. Includes fluent overrides `AtomicCache::withoutTransactionBypass()` and `withTransactionBypass()`.
- **Transaction-Safe Bulk Invalidation Wrapper (`AtomicCache::invalidating()`):** Safely executes a callback and invalidates targets (Model classes, Model instances, tag strings, or Eloquent Collections/iterables). Automatically defers invalidation to `afterCommit` when executed inside active database transactions, preventing race conditions. Includes recursive iterable support and explicit type validation with helpful, descriptive exceptions.
- **Ignored Cache Columns (`$cacheIgnoreColumns`):** Allows models to declare columns (e.g. `view_count`, `last_login_at`, analytics) that update without purging heavy table collections or parent relations. Single-item caches (`posts:12`) remain invalidated for real-time item views.
- **Scoped Query Macros (`scope:` parameter):** Added `scope:` parameter to `->atomicCache()` and `->atomicCacheFirst()` query builder macros to isolate multi-tenant or parent-scoped query caches without clearing unrelated tenant collections.
- **Parent Relation Strict Mode (`atomic-cache.strict_mode`):** Enforces strict validation of declared `$cacheParents` relations during local development, throwing descriptive `InvalidArgumentException`s when relation methods are misspelled, missing, require parameters, or when class names are passed instead of method names.
- **Architecture Linter Enhancements:** Updated `atomic-cache:check` CLI command to display declared `$cacheIgnoreColumns` alongside active models.
- **Universal OCC Version Tokens Across All Drivers:** Resolved native Redis tag OCC limitation by implementing universal atomic UUID version tokens (`atomic:__tag_v__:{tag}`) rotated during `clearTags()` across all cache drivers (Redis, Memcached, File, Database, Array). Closes TOCTOU race condition gaps completely on virtual tag drivers via pre-computation version-baked keys, and minimizes stale write windows to microsecond intervals on native tag stores (Redis/Memcached). Also optimized version token reads using `$store->many()` batch fetching in a single round-trip.
- **Multi-Tag CLI Invalidation (`--tags`):** Added `{--tags=}` option to `ClearAtomicCacheCommand` (`php artisan atomic-cache:clear --tags=users,orders`) to support comma-separated multi-tag purging.
- **Model Manual Flush Helpers:** Added `$model->clearCache()` instance method (expressive alias for `clearModelCacheOnCommit()`) and `Model::flushCache()` static method in `AutoClearsCache` trait for single-line manual cache invalidation after bulk updates, mass deletes, or direct SQL queries.
- **Class-Level In-Memory Static Cache:** Added `static::$configuredParentRelationsCache` in `AutoClearsCache` to cache resolved parent relations per model class, eliminating property inspection overhead during repeated batch saves.
- **Private Property Declarations Supported:** Verified and tested support for models declaring `private array $cacheParents`, ensuring private parent relation definitions are safely extracted across PHP 8.2–8.5 without requiring deprecated `setAccessible()` calls.
- **Core-First Documentation Architecture & Critical Rules:** Reorganized `README.md` to follow a Core-First architecture, detailing exact usage rules alongside comprehensive bullet points outlining what causes each feature not to work (common pitfalls, anti-patterns, and framework boundaries) across all 6 architectural layers.

### Fixed
- **Explicit Model Touch Cache Busting:** Resolved an edge case in `onlyIgnoredColumnsChanged()` where updating timestamps via `$model->touch()` was erroneously classified as an ignored column change, skipping collection and parent cache clearing. The engine now verifies that at least one declared ignored column actually changed.
- **Collection & Iterable Target Support in `invalidating()`:** Fixed `AtomicCache::invalidating()` to recursively normalize Eloquent `Collection` instances, preventing collections from failing tag resolution.
- **Descriptive Error Messaging on Invalid Targets:** Replaced silent failures in `invalidating()` and `$cacheParents` validation with informative `InvalidArgumentException` messages, guiding developers on expected types and proper usage.

---

## [v1.4.3] - 2026-10-27

### Fixed
- **BackedEnum Object String Conversion in array_unique:** Unwrapped `BackedEnum` and Stringable objects to scalar values before invoking `array_unique()` to prevent PHP fatal errors (`Object of class could not be converted to string`) when updating an enum foreign key from one enum to another.
- **Bypass Eloquent Dynamic Lookups:** Used `\ReflectionProperty` inspection to check property existence and initialization in `resolveConfiguredParentRelations()` to prevent triggering Eloquent's magic `__isset()` and dynamic attribute queries on every save.

---

## [v1.4.2] - 2026-10-27

### Fixed
- **Transaction-Safe Deferred Tag Resolution:** Fixed a critical bug in `clearModelCacheOnCommit()` where updating a foreign key (e.g. moving an order from Store 1 to Store 2) inside `DB::transaction(...)` failed to invalidate the old parent's cache. Cache tags are now resolved immediately at event dispatch time before `finishSave()` executes `syncOriginal()`, ensuring dirty attributes are captured properly before transaction commit.
- **Typed Property Crash Guard:** Added `ReflectionProperty::isInitialized()` check in `CheckAtomicCacheCommand` and `isset()` check in `AutoClearsCache` to prevent uninitialized typed property errors (`Error: Typed property must not be accessed before initialization`) when models declare typed properties like `protected array $cacheParents;` without default values.
- **Strict BelongsTo Inspection in Linter:** Replaced loose substring check with strict regex `preg_match('/return\s+\$this\s*->\s*belongsTo\s*\(/i', $body)` to prevent non-relation methods containing "belongsTo" in comments or strings from being invoked during architecture audits.
- **Custom Owner Key Guard:** Prevented mismatched umbrella tag generation when a `belongsTo` relationship uses a custom owner key (e.g. `slug` or `uuid`) that differs from the parent model's primary key (`id`).
- **PHP BackedEnum Foreign Key Support:** Added automatic unwrapping of `BackedEnum` foreign key values so models with enum-backed foreign keys resolve valid tags cleanly.

---

## [v1.4.1] - 2026-10-27

### Changed
- Removed internal roadmap from `docs/` and configured `.gitattributes` with `export-ignore` rules for clean distribution archive.

---

## [v1.4.0] - 2026-10-27

### Added
- **Declarative Parent Invalidation:** Introduced `$cacheParents` property (with aliases `$cacheTouches` and `$clearsRelations`) in `AutoClearsCache` trait.
  - Automatically invalidates parent collection tag (`{parent}:{id}:{child}`) and umbrella tag (`{parent}:{id}`).
  - Automatically handles dirty foreign key transitions (Store 1 -> Store 2) to prevent ghost data.
  - Scalar and stringable foreign key guard to prevent malformed tags.
  - Automatic exclusion of polymorphic `MorphTo` relations.
- **Architecture Audit & Linter CLI (`atomic-cache:check`):**
  - Added relationship reflection scanner that inspects active models and provides proactive advisories for unlinked `BelongsTo` relationships.
  - Added JSON advisory output (`--json`).
