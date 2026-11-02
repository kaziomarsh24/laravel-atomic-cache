# Layer 6: CLI Diagnostics & Auditing

Atomic Cache provides powerful Artisan commands for inspecting model architecture health, running real-time benchmarks, and safely invalidating caches.

---

### 6.1 Model Architecture Auditor (`atomic-cache:check`)

Scan your entire application's Eloquent models to verify cache invalidation tags, identify missing relations, and inspect architectural health:

```bash
# Scan default model path (app/Models):
php artisan atomic-cache:check

# Scan a custom domain or module directory:
php artisan atomic-cache:check --path=app/Domain/Shop/Models

# Filter to show only models needing attention:
php artisan atomic-cache:check --filter=recommended

# Export audit results as JSON for CI pipelines:
php artisan atomic-cache:check --json
```

---

### 6.2 Performance Benchmark CLI (`atomic-cache:bench`)

Benchmark your cache latency, Gzip payload reduction, and chunking integrity in real-time across drivers:

```bash
# Benchmark the active cache driver:
php artisan atomic-cache:bench

# Benchmark a specific driver with custom iterations:
php artisan atomic-cache:bench --driver=redis --iterations=10
```

---

### 6.3 Safe Cache Clearing CLI (`atomic-cache:clear`)

```bash
# Clear a specific tag or key:
php artisan atomic-cache:clear products

# Clear multiple tags simultaneously:
php artisan atomic-cache:clear --tags=products,categories

# Clear entire cache store (requires confirmation in production):
php artisan atomic-cache:clear --all

# Clear entire store non-interactively (e.g. CI / deployments):
php artisan atomic-cache:clear --all --force
```

::: warning Shared Store Notice
Running `--all` flushes the **entire** cache store via `flush()`. If your cache store is shared with Redis sessions or queue workers, use `--tags=` or configure a dedicated cache store (`ATOMIC_CACHE_STORE`) to avoid purging session data.
:::
