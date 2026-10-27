# Changelog

All notable changes to `laravel-atomic-cache` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [v1.5.0] - Unreleased

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
