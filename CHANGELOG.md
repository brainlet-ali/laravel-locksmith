# Changelog

All notable changes to Laravel Locksmith will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0-beta] - 2025-12-07

Initial beta release.

### Added

- **Core Secrets Management**
  - AES-256 encryption at rest via Laravel's encryption
  - `Locksmith::get()`, `set()`, `has()`, `delete()`, `all()` facade methods
  - Grace period support for zero-downtime rotation

- **Recipe-Based Rotation**
  - AWS IAM access key rotation with self-managed credentials
  - Custom recipe support via `Recipe`, `InitializableRecipe`, `DiscardableRecipe` contracts
  - Config-based recipe registration with `provider_cleanup` option

- **Key Pool Rotation**
  - Pre-generate keys for services without APIs (DigitalOcean, etc.)
  - Automatic rotation through pool keys
  - Low pool notifications via `PoolLow` event

- **Self-Cleaning Rotation**
  - Automatic old key deletion from providers after grace period
  - On-demand discard when re-rotating before grace period expires
  - `--no-provider-cleanup` flag for unlimited-key providers

- **Artisan Commands**
  - `locksmith:install` - Interactive setup
  - `locksmith:init` - Initialize credentials
  - `locksmith:rotate` - Rotate with recipe
  - `locksmith:rollback` - Rollback to previous value
  - `locksmith:status` - View all secrets status
  - `locksmith:clear-expired` - Clear expired grace periods
  - `locksmith:prune-logs` - Remove old rotation logs
  - `locksmith:pool` - Manage key pools
  - `locksmith:pool-rotate` - Rotate all configured pools

- **Notifications**
  - Mail and Slack notifications for rotation events
  - Configurable notification channels
  - Pool low alerts

- **Logging & Auditing**
  - Rotation logs with correlation IDs
  - System logging integration (Sentry, Bugsnag compatible)
  - Log retention and pruning

- **Performance**
  - Caching layer to reduce database calls
  - Configurable cache store and TTL
  - Automatic cache invalidation

- **Events**
  - `SecretRotating`, `SecretRotated`, `SecretRotationFailed`
  - `PoolKeyActivated`, `PoolLow`

### Requirements

- PHP 8.1+
- Laravel 9.x, 10.x, or 11.x
