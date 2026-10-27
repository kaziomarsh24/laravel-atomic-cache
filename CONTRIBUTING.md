# Contributing to Laravel Atomic Cache

Thank you for considering contributing to Laravel Atomic Cache!

## Development Setup

1. Fork and clone the repository.
2. Install dependencies:
   ```bash
   composer install
   ```
3. Run the test suite:
   ```bash
   composer test
   ```

## Pull Request Guidelines

- **Tests:** Add tests for any new features or bug fixes. Ensure existing tests pass:
  ```bash
  vendor/bin/phpunit
  ```
- **Code Style:** Follow PSR-12 and Laravel coding standards.
- **Documentation:** If you add or modify behavior, update `README.md`, `docs/index.html`, and add an entry under `## [Unreleased]` in `CHANGELOG.md`.
- **Atomic Commits:** Keep commits focused and provide clear commit messages.

## Issue Guidelines

- When reporting bugs, please provide a minimal reproduction code snippet or test case.
- Specify your PHP version, Laravel version, and cache driver (e.g. Redis, File, Database).
