# Requirements & Installation

## System Requirements

Before installing, ensure your environment meets the minimum version requirements:

* **PHP:** `8.2`, `8.3`, `8.4`, or `8.5`
* **Laravel Framework:** `10.x`, `11.x`, `12.x`, or `13.x`
* **PHP Extensions:** `ext-zlib` (optional for automatic Gzip compression, enabled by default in 99% of standard PHP installations).

---

## Installation via Composer

Install the package into your project using Composer:

```bash
composer require thekaziomar/laravel-atomic-cache
```

---

## Publishing the Configuration

Publish the configuration file to customize defaults such as TTL, lock durations, compression thresholds, and strict mode:

```bash
php artisan vendor:publish --tag="atomic-cache-config"
```

This creates `config/atomic-cache.php`.

::: tip Zero-Configuration Default
Publishing the configuration is optional. Atomic Cache runs out of the box with sensible, production-tested defaults matching Laravel's active cache driver.
:::
