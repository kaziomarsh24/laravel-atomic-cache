# Upgrade Guide & Testing

Atomic Cache adheres strictly to [Semantic Versioning (SemVer)](https://semver.org/).

---

## Version Upgrade Reference

| Release | Change | Impacted Workloads | Recommended Action |
| :--- | :--- | :--- | :--- |
| **`v1.6.0` (2026-11-14)** | **Stale-While-Revalidate (`flexible`), Query Pagination/Aggregation Macros, and Robust Multi-Runtime Resiliency** | All workloads. | Added `AtomicCache::flexible()` for non-blocking background revalidation, query macros `atomicCachePaginate`, `atomicCacheSimplePaginate`, `atomicCacheCount`, `atomicCacheExists`, separated `$perUser` and `$tags` in `HasAtomicCache::cache()`, and fixed container resolution in CLI/Queue/stateless environments. |
| **`v1.5.0` (2026-10-28)** | **Transaction Guard, Scoped Query Macro, $cacheIgnoreColumns, Strict Mode, and Universal OCC** | All workloads. | OCC now operates universally via atomic UUID version tokens across all drivers. Added Transaction Guard (`bypass_during_transactions`), `$cacheIgnoreColumns` for counter updates, Strict Mode validation, `scope:` query macro parameter, and `AtomicCache::invalidating()` wrapper. |

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
