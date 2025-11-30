# Laravel Locksmith

**Secrets rotation orchestration for Laravel.**

Manage the complete lifecycle of credential rotation: generate, validate, swap, and cleanup - with zero downtime.

## Why Locksmith?

- **Zero-downtime rotation** - Grace periods let both old and new keys work during transition
- **Self-cleaning** - Old keys automatically deleted from providers after grace period
- **Rotation lifecycle** - Generate → Validate → Swap → Cleanup, all orchestrated
- **Notifications** - Get alerted on rotation success/failure via Mail or Slack
- **Audit logging** - Track every rotation with correlation IDs
- **Open source** - MIT licensed, forever free

## Installation

```bash
composer require brainlet-ali/laravel-locksmith
php artisan locksmith:install
```

## Two Ways to Rotate

| Method | For | How |
|--------|-----|-----|
| **Recipes** | Services with key-generation APIs | Locksmith creates new keys via API |
| **Key Pools** | Services without APIs | You pre-add keys, Locksmith rotates through them |

## Quick Start

**Recipe-based (AWS IAM):**
```bash
php artisan locksmith:init aws.credentials       # Stores username + first key
php artisan locksmith:rotate aws.credentials --recipe=aws
```

Output:
```
Discarding previous key for [aws.credentials]...
Rotating secret [aws.credentials]...
Secret [aws.credentials] rotated successfully.
```

**Key Pool (services without APIs):**
```bash
php artisan locksmith:pool api.secret --add     # Add pre-generated keys
php artisan locksmith:pool api.secret --rotate  # Rotate to next key
```

**Read secrets:**
```php
$secret = Locksmith::get('api.secret');
```

## Commands

```bash
locksmith:install         # Interactive setup
locksmith:init            # Initialize credentials
locksmith:rotate          # Rotate with recipe
locksmith:rollback        # Rollback to previous
locksmith:status          # View secrets status
locksmith:clear-expired   # Clear expired grace periods
locksmith:prune-logs      # Remove old rotation logs
locksmith:pool            # Manage key pools
locksmith:pool-rotate     # Rotate all configured pools
```

## Self-Cleaning Rotation

Locksmith automatically manages key lifecycle:

```
┌─────────────────────────────────────────────────────────────────┐
│  ROTATE                                                         │
│  ├── Discard previous_value immediately (if exists)             │
│  ├── Generate new key via recipe                                │
│  ├── Validate new key works                                     │
│  ├── Store: value=new, previous_value=old                       │
│  └── Schedule cleanup job (runs after grace period)             │
│                                                                 │
│  GRACE PERIOD (60 min default)                                  │
│  ├── Both old and new keys work                                 │
│  └── Applications gradually switch to new key                   │
│                                                                 │
│  AFTER GRACE PERIOD (automatic)                                 │
│  ├── Job runs: deletes old key from provider (AWS, etc.)        │
│  └── Clears previous_value from database                        │
│                                                                 │
│  ROTATE AGAIN (on-demand)                                       │
│  ├── Discards current previous_value BEFORE generating new      │
│  └── Prevents hitting provider key limits (AWS = 2 keys max)    │
└─────────────────────────────────────────────────────────────────┘
```

## Built-in Recipe

| Recipe | What it does |
|--------|--------------|
| `AwsRecipe` | Rotates IAM access keys with self-managed credentials |

**Self-managed AWS credentials:**
- Username, Access Key ID, and Secret stored encrypted in Locksmith
- No `.env` variables needed for AWS
- User rotates their own keys (IAM permissions on self)
- Old keys auto-deleted from AWS after grace period

## Features

- AES-256 encryption at rest
- Dual-key grace periods (zero downtime)
- Self-cleaning rotation with scheduled jobs
- On-demand discard for immediate rotation
- Scheduled rotation via Laravel scheduler
- Mail & Slack notifications
- Audit logging with correlation IDs
- Caching layer (reduces DB calls)

## Requirements

- PHP 8.1+
- Laravel 9.x, 10.x, or 11.x

## Documentation

Full docs: [docs/DOCUMENTATION.md](docs/DOCUMENTATION.md)

## License

MIT
