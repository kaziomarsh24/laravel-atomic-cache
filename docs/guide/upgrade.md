# Upgrade Guide & Testing

Atomic Cache adheres strictly to [Semantic Versioning (SemVer)](https://semver.org/).

---

## Version Upgrade Reference

| Release | Change | Impacted Workloads | Recommended Action |
| :--- | :--- | :--- | :--- |
| **`v1.5.0` (2026-10-28)** | **Transaction Guard, Scoped Query Macro, $cacheIgnoreColumns, Strict Mode, and Universal OCC** | All workloads. | OCC now operates universally via atomic UUID version tokens across all drivers. Added Transaction Guard (`bypass_during_transactions`), `$cacheIgnoreColumns` for counter updates, Strict Mode validation, `scope:` query macro parameter, and `AtomicCache::invalidating()` wrapper. |
| **`v1.4.3`** | **BackedEnum Foreign Keys & Magic `__isset` Bypass** | Models using PHP 8.1+ Enums as foreign keys. | Foreign keys backed by enums are cleanly unwrapped before tag generation. Fixed reflection lookups to avoid triggering Eloquent magic `__isset`. |
| **`v1.4.2`** | **Transaction-Safe Deferred Parent Invalidation** | Models using `$cacheParents` updated inside `DB::transaction(...)`. | Tags are resolved at event dispatch time before `syncOriginal()`, capturing old & new parent keys accurately after commit. |
| **`v1.4.0`** | **Smart Parent Invalidation & Architecture Linter** | Applications declaring parent relationships (`$cacheParents`). | Run `php artisan atomic-cache:check` to audit Eloquent relationship caching health. |
| **`v1.3.8`** | **Optimistic Concurrency Control (OCC) & Query DoS Limits** | Write-heavy models and long filter query strings. | Cache computation aborted if tags changed mid-flight. Requests exceeding 20 query parameters bypass cache to prevent DoS. |
| **`v1.3.7`** | **Chunk Generation IDs & Tag-Scoped Flush** | Transparent chunking users. | Chunk keys utilize `{key}:__chunk__:{generation}:{i}` to eliminate torn reads. Old chunk formats remain backwards-compatible. |
| **`v1.3.4`** | **Opt-in Deduplication & Event Hooks** | `AutoClearsCache` now listens to `created`, `updated`, `deleted`, `restored`. | `increment()` and `decrement()` trigger invalidation. Deduplication is opt-in via `ATOMIC_CACHE_DEDUPLICATION_ENABLED=true`. |

---

## Automated Testing Suite

Run the full automated test suite using PHPUnit:

```bash
composer test
# or
vendor/bin/phpunit
```

---

## Credits & License

* **Developer:** [Kazi Omar Faruk](https://github.com/kaziomarsh24)
* **Architecture:** Enterprise distributed caching and transaction-safe invalidation engine for modern Laravel applications.
* **License:** Licensed under the [MIT License](https://github.com/kaziomarsh24/laravel-atomic-cache/blob/main/LICENSE).
