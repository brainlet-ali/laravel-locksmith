# Laravel Locksmith Documentation

Laravel Locksmith is a secrets rotation orchestration package for Laravel. It manages the complete rotation lifecycle - generate, validate, swap, and cleanup - with zero-downtime grace periods.

## Two Rotation Strategies

Locksmith supports two rotation strategies depending on whether the service supports programmatic key generation:

| Strategy | Use When | Example |
|----------|----------|---------|
| **Recipes** | Service has API for key generation/deletion | AWS IAM |
| **Key Pools** | Keys must be created manually in dashboard | Any service without API |

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Basic Usage](#basic-usage)
- [Secret Management](#secret-management)
- [Self-Cleaning Rotation](#self-cleaning-rotation)
- [Rotation Methods](#rotation-methods)
- [Built-in Recipes](#built-in-recipes) (Programmatic)
  - [AWS IAM Recipe](#aws-iam-recipe)
- [Custom Recipes](#custom-recipes) (Programmatic)
- [Key Pools](#key-pools)
- [Artisan Commands](#artisan-commands)
- [Scheduling](#scheduling)
- [Notifications](#notifications)
- [Queue Integration](#queue-integration)
- [Events](#events)
- [System Logging](#system-logging)
- [Log Management](#log-management)
- [Testing](#testing)

---

## Installation

Install via Composer:

```bash
composer require brainlet-ali/laravel-locksmith
```

Run the interactive installer:

```bash
php artisan locksmith:install
```

The installer will:
1. Publish the configuration file
2. Run database migrations
3. Let you select which recipes to install (AWS)
4. Automatically install required dependencies

### Manual Installation

If you prefer manual setup:

```bash
# Publish config
php artisan vendor:publish --tag=locksmith-config

# Run migrations
php artisan migrate
```

---

## Configuration

The configuration file is located at `config/locksmith.php`:

```php
return [
    // Custom recipes (register your own recipe classes)
    // Simple format:
    // 'recipes' => [
    //     'twilio' => \App\Recipes\TwilioRecipe::class,
    // ],
    //
    // Full format with provider_cleanup option:
    'recipes' => [
        // 'twilio' => [
        //     'class' => \App\Recipes\TwilioRecipe::class,
        //     'provider_cleanup' => false,  // Skip deleting old keys from provider
        // ],
    ],

    // Default grace period for dual-key validity during rotation
    'grace_period_minutes' => env('LOCKSMITH_GRACE_PERIOD', 60),

    // Log retention (days before pruning)
    'log_retention_days' => env('LOCKSMITH_LOG_RETENTION_DAYS', 90),

    // System logging
    'logging' => [
        'enabled' => env('LOCKSMITH_LOGGING_ENABLED', false),
        'channel' => env('LOCKSMITH_LOG_CHANNEL'),
    ],

    // AWS recipe configuration
    'aws' => [
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'validation_retries' => env('LOCKSMITH_AWS_VALIDATION_RETRIES', 3),
    ],

    // Notifications
    'notifications' => [
        'enabled' => env('LOCKSMITH_NOTIFICATIONS_ENABLED', false),
        'channels' => ['mail'],
        'mail' => ['to' => env('LOCKSMITH_NOTIFICATION_EMAIL')],
        'slack' => ['webhook_url' => env('LOCKSMITH_SLACK_WEBHOOK')],
    ],

];
```

### Environment Variables

```env
# Grace period in minutes (default: 60)
LOCKSMITH_GRACE_PERIOD=60

# Log retention days for pruning (default: 90)
LOCKSMITH_LOG_RETENTION_DAYS=90

# System logging to Laravel logs
LOCKSMITH_LOGGING_ENABLED=true
LOCKSMITH_LOG_CHANNEL=stack

# Notifications
LOCKSMITH_NOTIFICATIONS_ENABLED=true
LOCKSMITH_NOTIFICATION_EMAIL=ops@example.com
LOCKSMITH_SLACK_WEBHOOK=https://hooks.slack.com/services/...
```

---

## Basic Usage

### Storing and Retrieving Secrets

```php
use BrainletAli\Locksmith\Facades\Locksmith;

// Store a secret
Locksmith::set('api.key', 'your_secret_key');

// Retrieve a secret
$apiKey = Locksmith::get('api.key');

// Check if a secret exists
if (Locksmith::has('api.key')) {
    // ...
}

// Delete a secret
Locksmith::delete('api.key');

// Get all secret keys
$keys = Locksmith::all();
```

---

## Secret Management

### Grace Period (Zero-Downtime Rotation)

During rotation, both old and new values remain valid for a configurable grace period:

```php
// Get all valid values during grace period
$validValues = Locksmith::getValidValues('api.key');

// Check if secret is in grace period
if (Locksmith::isInGracePeriod('api.key')) {
    $expiresAt = Locksmith::gracePeriodExpiresAt('api.key');
}

// Get the previous value
$previousValue = Locksmith::getPreviousValue('api.key');
```

---

## Self-Cleaning Rotation

Locksmith automatically manages the full lifecycle of rotated keys, including cleanup of old keys from external providers.

### How It Works

```
┌─────────────────────────────────────────────────────────────────┐
│  1. ROTATE COMMAND                                              │
│     ├── Discard previous_value immediately (if exists)          │
│     ├── Generate new key via recipe                             │
│     ├── Validate new key works                                  │
│     ├── Store: value=new, previous_value=old                    │
│     └── Schedule GracePeriodCleanupJob (delayed by grace period)│
│                                                                 │
│  2. GRACE PERIOD (configurable, default 60 min)                 │
│     ├── Both old and new keys remain valid                      │
│     └── Applications gradually switch to new key                │
│                                                                 │
│  3. AFTER GRACE PERIOD (automatic via job)                      │
│     ├── GracePeriodCleanupJob runs                              │
│     ├── Calls recipe.discard() to delete from provider          │
│     └── Clears previous_value from database                     │
└─────────────────────────────────────────────────────────────────┘
```

### On-Demand Discard

When you rotate again before the grace period expires, Locksmith immediately discards the existing `previous_value` **before** generating a new key:

```bash
php artisan locksmith:rotate aws.credentials --recipe=aws
```

Output:
```
Discarding previous key for [aws.credentials]...
Rotating secret [aws.credentials]...
Secret [aws.credentials] rotated successfully.
```

This is critical for providers with key limits (AWS IAM allows max 2 access keys per user). By discarding the old key first, Locksmith ensures the rotation can proceed.

### GracePeriodCleanupJob

The cleanup job is dispatched with a delay equal to the grace period:

```php
GracePeriodCleanupJob::dispatch($secret->key, $oldValue, $providerCleanup)
    ->delay(now()->addMinutes($gracePeriodMinutes));
```

The `$providerCleanup` parameter controls whether old keys are deleted from the provider. Use `--no-provider-cleanup` flag to skip provider cleanup for services that allow unlimited keys.

The job performs these safety checks:
1. **Secret still exists** - Silently exits if deleted
2. **Value matches** - Only discards if `previous_value` matches what was scheduled (prevents race conditions)
3. **Recipe is discardable** - Only calls `discard()` if recipe implements `DiscardableRecipe`
4. **Provider key exists** - Handles `NoSuchEntity` errors gracefully (key already deleted)

### Discard Logging

Discard operations are logged to the `locksmith_rotation_logs` table:

| Status | When |
|--------|------|
| `DiscardSuccess` | Old key successfully deleted from provider |
| `DiscardFailed` | Provider API error (key still cleared from database) |

Log metadata includes:
- `correlation_id` - Unique ID for the discard operation
- `duration_ms` - How long the discard took
- `source` - Always `queue` for scheduled discards
- `triggered_by` - Always `scheduled` for grace period expiry
- `recipe` - The recipe class name (e.g., `AwsRecipe`)
- `operation` - Always `discard`

### Error Handling

| Scenario | Behavior |
|----------|----------|
| Secret deleted from database | Job exits silently |
| Another rotation already ran | Job exits (value mismatch) |
| Provider key already deleted | Handled gracefully, logged as success |
| Provider API error | Logged as `DiscardFailed`, grace period still cleared |

---

## Rotation Methods

Locksmith provides two rotation mechanisms:

| Method | Use Case |
|--------|----------|
| **Recipes** | Services with key-generation APIs (AWS IAM, Twilio, etc.) |
| **Key Pools** | Services without APIs (pre-generated keys you add manually) |

---

## Built-in Recipes

### AWS IAM Recipe

Rotate AWS IAM access keys for a single user. Credentials are **self-managed** - stored encrypted in Locksmith, no `.env` variables needed.

**IAM Permissions Required:**

The IAM user must have permissions to manage its own keys:

```json
{
    "Version": "2012-10-17",
    "Statement": [{
        "Effect": "Allow",
        "Action": [
            "iam:CreateAccessKey",
            "iam:DeleteAccessKey",
            "iam:ListAccessKeys"
        ],
        "Resource": "arn:aws:iam::*:user/${aws:username}"
    }]
}
```

**Setup (Self-Managed Credentials):**

Initialize credentials using the interactive command:

```bash
php artisan locksmith:init aws.credentials
```

> **Important:** The `aws.` prefix is required. It tells Locksmith to use the AWS recipe, which prompts for IAM-specific fields and handles AWS API calls during rotation.

The command prompts for:
- IAM Username
- Access Key ID
- Secret Access Key

Everything is stored encrypted in the database. No `.env` variables needed for AWS credentials.

**Multiple AWS Users:**

Initialize multiple credentials with different keys (all must start with `aws.`):

```bash
php artisan locksmith:init aws.s3.credentials      # S3 service user
php artisan locksmith:init aws.ses.credentials     # SES service user
php artisan locksmith:init aws.lambda.credentials  # Lambda service user
```

Each is independent and rotates separately. The `aws.` prefix ensures all use the AWS recipe.

**Schedule Rotation:**

Add to your Laravel scheduler (`app/Console/Kernel.php`):

```php
// Rotate each AWS credential on its own schedule
$schedule->command('locksmith:rotate aws.s3.credentials --recipe=aws')->weekly();
$schedule->command('locksmith:rotate aws.ses.credentials --recipe=aws')->monthly();

// Clear expired grace periods
$schedule->command('locksmith:clear-expired')->daily();
// Or target specific secrets
$schedule->command('locksmith:clear-expired aws.s3.credentials')->hourly();
```

**Reading Credentials:**

```php
use BrainletAli\Locksmith\Facades\Locksmith;

$credentials = json_decode(Locksmith::get('aws.credentials'), true);
$accessKeyId = $credentials['access_key_id'];
$secretAccessKey = $credentials['secret_access_key'];
```

**Automatic Cleanup (Self-Cleaning):**

Locksmith automatically cleans up old AWS keys in two ways:

1. **Scheduled Job** - After each rotation, a `GracePeriodCleanupJob` is scheduled to run after the grace period. It automatically deletes the old key from AWS (unless `--no-provider-cleanup` was used).

2. **On-Demand Cleanup** - If you rotate again before the grace period expires, the old key is immediately deleted from AWS before generating a new one (unless `--no-provider-cleanup` was used). This prevents hitting provider key limits (AWS allows max 2 keys per user).

3. **Manual Cleanup** - Run `locksmith:clear-expired` to immediately clear any expired grace periods:

```bash
php artisan locksmith:clear-expired
```

**Error Handling:**

The AWS recipe gracefully handles `NoSuchEntity` errors - if the key was already deleted (manually or by another process), the cleanup succeeds without error.

> **Note:** Automatic provider cleanup only works for recipes that implement `DiscardableRecipe`. The AWS recipe implements this interface.

**Validation Retries:**

New AWS access keys may take a few seconds to propagate. Locksmith retries validation (configurable via `validation_retries`) with 5-second delays between attempts.

---

## Custom Recipes

For services not included as built-in recipes, implement your own using the available contracts.

### Recipe Contracts

Locksmith provides three contracts for building recipes:

| Contract | Purpose |
|----------|---------|
| `Recipe` | Core interface - `generate()` and `validate()` |
| `InitializableRecipe` | Interactive initialization via `locksmith:init` command |
| `DiscardableRecipe` | Auto-delete old keys from provider after grace period |

A complete recipe implements all three. The built-in `AwsRecipe` implements all three.

### Recipe Interface

The base interface every recipe must implement:

```php
namespace BrainletAli\Locksmith\Contracts;

interface Recipe
{
    /** Generate a new secret value. */
    public function generate(): string;

    /** Validate the new secret value works. */
    public function validate(string $value): bool;
}
```

**Example:**

```php
namespace App\Recipes;

use BrainletAli\Locksmith\Contracts\Recipe;

class TwilioRecipe implements Recipe
{
    public function generate(): string
    {
        // Call Twilio API to create new API key
        $key = $this->twilioClient->newKeys->create();

        return json_encode([
            'sid' => $key->sid,
            'secret' => $key->secret,
        ]);
    }

    public function validate(string $value): bool
    {
        $credentials = json_decode($value, true);

        // Test the key works
        try {
            $client = new TwilioClient($credentials['sid'], $credentials['secret']);
            $client->api->accounts->read();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
```

### InitializableRecipe Interface

Implement this to enable interactive initialization via `locksmith:init`:

```php
namespace BrainletAli\Locksmith\Contracts;

interface InitializableRecipe
{
    /** Prompt user for initial credentials and return the value to store. */
    public function init(): ?string;
}
```

**Example:**

```php
namespace App\Recipes;

use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Contracts\InitializableRecipe;

use function Laravel\Prompts\text;
use function Laravel\Prompts\password;

class TwilioRecipe implements Recipe, InitializableRecipe
{
    public function init(): ?string
    {
        $accountSid = text('Twilio Account SID');
        $apiKeySid = text('API Key SID');
        $apiKeySecret = password('API Key Secret');

        $value = json_encode([
            'account_sid' => $accountSid,
            'sid' => $apiKeySid,
            'secret' => $apiKeySecret,
        ]);

        // Validate before storing
        if (!$this->validate($value)) {
            return null; // Validation failed
        }

        return $value;
    }

    // ... generate() and validate() methods
}
```

When `locksmith:init twilio.credentials` is run:
- If recipe implements `InitializableRecipe`, calls `init()` for interactive prompts
- If not, falls back to simple password prompt

### DiscardableRecipe Interface

Implement this to auto-delete old keys from the provider after grace period:

```php
namespace BrainletAli\Locksmith\Contracts;

interface DiscardableRecipe
{
    /** Discard an old secret value from the provider (e.g., delete old API key). */
    public function discard(string $value): void;
}
```

**Example:**

```php
namespace App\Recipes;

use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Contracts\DiscardableRecipe;

class TwilioRecipe implements Recipe, DiscardableRecipe
{
    public function discard(string $value): void
    {
        $credentials = json_decode($value, true);

        if (!isset($credentials['sid'])) {
            return;
        }

        try {
            $this->twilioClient->keys($credentials['sid'])->delete();
        } catch (NotFoundException $e) {
            // Key already deleted - not an error
            return;
        }
    }

    // ... generate() and validate() methods
}
```

**When `discard()` is called:**

1. **After grace period** - `GracePeriodCleanupJob` runs and calls `discard()` on old value (if enabled)
2. **On-demand rotation** - If rotating again before grace period expires, old key is discarded immediately
3. **Manual cleanup** - `locksmith:clear-expired` calls `discard()` for expired grace periods

**Best practices for `discard()`:**
- Handle "not found" errors gracefully (key may already be deleted)
- Don't throw exceptions for missing keys
- Log errors but don't fail the cleanup

### Full Recipe Example

A complete recipe implementing all optional interfaces:

```php
namespace App\Recipes;

use BrainletAli\Locksmith\Contracts\DiscardableRecipe;
use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Contracts\InitializableRecipe;
use Twilio\Rest\Client as TwilioClient;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class TwilioRecipe implements Recipe, InitializableRecipe, DiscardableRecipe
{
    protected ?TwilioClient $client = null;

    /** Interactive initialization for locksmith:init command. */
    public function init(): ?string
    {
        $accountSid = text('Twilio Account SID');
        $apiKeySid = text('API Key SID');
        $apiKeySecret = password('API Key Secret');

        $value = json_encode([
            'account_sid' => $accountSid,
            'sid' => $apiKeySid,
            'secret' => $apiKeySecret,
        ]);

        if (! $this->validate($value)) {
            return null;
        }

        return $value;
    }

    /** Generate new API key via Twilio API. */
    public function generate(): string
    {
        $key = $this->getClient()->newKeys->create(['friendlyName' => 'Locksmith Rotation']);

        return json_encode([
            'account_sid' => $this->getAccountSid(),
            'sid' => $key->sid,
            'secret' => $key->secret,
        ]);
    }

    /** Validate credentials work. */
    public function validate(string $value): bool
    {
        $creds = json_decode($value, true);

        try {
            $client = new TwilioClient($creds['sid'], $creds['secret'], $creds['account_sid']);
            $client->api->accounts->read();

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /** Delete old API key from Twilio. */
    public function discard(string $value): void
    {
        $creds = json_decode($value, true);

        if (! isset($creds['sid'])) {
            return;
        }

        try {
            $this->getClient()->keys($creds['sid'])->delete();
        } catch (\Twilio\Exceptions\RestException $e) {
            if ($e->getCode() === 20404) {
                return; // Already deleted
            }
            throw $e;
        }
    }

    protected function getClient(): TwilioClient
    {
        // ... get authenticated client
    }
}
```

### Using Custom Recipes

Custom recipes are used directly - no registration needed:

```php
use App\Recipes\TwilioRecipe;
use BrainletAli\Locksmith\Facades\Locksmith;

// Rotate using your custom recipe
$recipe = new TwilioRecipe();
$log = Locksmith::rotate('twilio.credentials', $recipe, gracePeriodMinutes: 60);
```

**Register in config for command-line usage:**

```php
// config/locksmith.php
'recipes' => [
    // Simple format (provider_cleanup defaults to true)
    'twilio' => \App\Recipes\TwilioRecipe::class,

    // Full format with provider_cleanup option
    'sendgrid' => [
        'class' => \App\Recipes\SendGridRecipe::class,
        'provider_cleanup' => false,  // SendGrid allows unlimited keys
    ],
],
```

The `provider_cleanup` option controls whether Locksmith calls `recipe->discard()` to delete old keys from the provider:
- `true` (default): Old keys are deleted from provider after grace period and before re-rotation
- `false`: Old keys are NOT deleted (use for providers with unlimited key limits like Twilio)

Then rotate via command:

```bash
php artisan locksmith:rotate twilio.credentials --recipe=twilio

# Override config with command flag (skip provider cleanup for this rotation only)
php artisan locksmith:rotate aws.credentials --recipe=aws --no-provider-cleanup
```

### Supported Services

The following services support programmatic API key rotation. Implement the `Recipe` contract using their respective APIs:

| Service | API Documentation |
|---------|------------------|
| **Twilio** | [Twilio API Keys](https://www.twilio.com/docs/iam/keys/api-key) |
| **SendGrid** | [SendGrid API Keys](https://www.twilio.com/docs/sendgrid/api-reference/api-keys/create-api-keys) |
| **Mailgun** | [Mailgun Keys API](https://documentation.mailgun.com/docs/mailgun/user-manual/api-key-mgmt/rbac-mgmt) |
| **Auth0** | [Auth0 Rotate Client Secret](https://auth0.com/docs/get-started/applications/rotate-client-secret) |
| **Okta** | [Okta Client Secret Rotation](https://developer.okta.com/docs/guides/client-secret-rotation-key/main/) |

---

## Key Pools

For services that **don't support programmatic key generation**, use Key Pools. Pre-create multiple keys during business hours, and Locksmith rotates through them automatically.

### Why Key Pools?

Some services require you to create API keys manually in their dashboard. With Key Pools, you avoid 2 AM wake-up calls by pre-generating keys during work hours.

### How It Works

```
Monday 10 AM (business hours):
┌────────────────────────────────────────────┐
│  Create 5 tokens in DigitalOcean           │
│  Add all to Locksmith pool                 │
└────────────────────────────────────────────┘
                    │
                    ▼
        Locksmith stores encrypted pool
                    │
                    ▼
    ┌───────────────────────────────────────┐
    │  Auto-rotates weekly/monthly          │
    │  No human intervention for months     │
    │  Notifies when pool runs low          │
    └───────────────────────────────────────┘
```

### Creating Keys in Advance

1. Go to [DigitalOcean → API → Tokens](https://cloud.digitalocean.com/account/api/tokens)
2. Generate New Token with needed scopes
3. Repeat with same scopes for multiple tokens
4. Copy all token strings

### Adding Keys to Pool

```php
use BrainletAli\Locksmith\Facades\Locksmith;

// Add multiple pre-created keys
Locksmith::pool('digitalocean.token')->add([
    'dop_v1_abc123...',
    'dop_v1_def456...',
    'dop_v1_ghi789...',
]);

// First key becomes active immediately
$key = Locksmith::get('digitalocean.token');
```

### Using Pool Keys

```php
// Your application code - works exactly like regular secrets
$token = Locksmith::get('digitalocean.token');
```

### Pool Management

```php
$pool = Locksmith::pool('digitalocean.token');

// Check pool status
$pool->count();        // Total keys in pool
$pool->remaining();    // Queued keys available

// Get pool status summary
$status = $pool->status();
// ['total' => 5, 'queued' => 3, 'active' => 1, 'used' => 1, 'expired' => 0]

// Manually rotate to next key
$pool->rotateNext(gracePeriodMinutes: 60);

// Remove used/expired keys
$pool->prune();
```

### Pool Commands

```bash
# Add keys interactively
php artisan locksmith:pool digitalocean.token --add

# View pool status
php artisan locksmith:pool digitalocean.token --status

# Rotate to next key manually
php artisan locksmith:pool digitalocean.token --rotate

# Clean up used keys
php artisan locksmith:pool digitalocean.token --prune
```

### Scheduled Pool Rotation

Configure pools in `config/locksmith.php`:

```php
'pools' => [
    'digitalocean.token' => [
        'grace' => 60,  // Grace period in minutes
    ],
    'notify_below' => 2,  // Alert when this many keys remain
],
```

Add to scheduler:

```php
// Rotate pool secrets on schedule
$schedule->command('locksmith:pool-rotate')->weekly();
```

### Pool Low Notifications

When pool runs low (default: 2 keys remaining), Locksmith sends notifications:

```
📧 Subject: [Warning] Key Pool Low: digitalocean.token

The key pool for [digitalocean.token] is running low.
Remaining keys: 2
Please add more keys to the pool during business hours.
```

### Validation (Optional)

Validate keys before activation:

```php
Locksmith::pool('digitalocean.token')
    ->withValidator(fn ($key) => str_starts_with($key, 'dop_v1_'))
    ->rotateNext();
```

Invalid keys are marked as expired and skipped.

---

## Artisan Commands

### Install

```bash
php artisan locksmith:install
```

Interactive installer with recipe selection.

### Initialize a Secret

```bash
# AWS credentials - uses AWS recipe prompts (key must start with aws.)
php artisan locksmith:init aws.credentials

# Generic secret - prompts for single password value
php artisan locksmith:init api.secret
```

The key prefix determines which recipe handles initialization:
- `aws.*` → AWS recipe (prompts for IAM Username, Access Key ID, Secret Access Key)
- Other keys → Generic prompt (single secret value)

If the key already exists, you'll be prompted to confirm overwrite.

### Rotate a Secret

```bash
php artisan locksmith:rotate aws.credentials --recipe=aws --grace=120
```

Available recipes: `aws` (plus any custom recipes registered in config)

**Options:**

| Option | Description |
|--------|-------------|
| `--recipe=` | Recipe to use (required) |
| `--grace=60` | Grace period in minutes |
| `--no-provider-cleanup` | Skip calling provider API to delete old keys |

**Skip provider cleanup for services with unlimited keys:**

```bash
# E.g Service that allows unlimited API keys - may skip cleanup
php artisan locksmith:rotate twilio.credentials --recipe=twilio --no-provider-cleanup
```

When `--no-provider-cleanup` is used, Locksmith will NOT call `recipe->discard()` for:
- Scheduled cleanup after grace period expires
- On-demand cleanup when re-rotating before grace period expires

Use this flag for providers that allow unlimited keys (like Twilio). Do NOT use this flag for providers with key limits (like AWS IAM which allows max 2 keys per user).

### View Status

```bash
php artisan locksmith:status
```

### Rollback

```bash
php artisan locksmith:rollback aws.credentials
```

### Clear Expired Grace Periods

```bash
# Clear all expired grace periods
php artisan locksmith:clear-expired

# Clear specific secret only
php artisan locksmith:clear-expired aws.credentials
```

Clears expired grace periods from the database. If the recipe implements `DiscardableRecipe`, old keys are also deleted from the provider (e.g., AWS deletes old IAM keys). This behavior is determined by the recipe itself.

### Prune Old Logs

```bash
php artisan locksmith:prune-logs --days=30
```

### Pool Management

```bash
# Add keys to pool interactively
php artisan locksmith:pool digitalocean.token --add

# View pool status
php artisan locksmith:pool digitalocean.token --status

# Rotate to next key
php artisan locksmith:pool digitalocean.token --rotate

# Clear all keys
php artisan locksmith:pool digitalocean.token --clear

# Remove used/expired keys
php artisan locksmith:pool digitalocean.token --prune

# Scheduled pool rotation (all configured pools)
php artisan locksmith:pool-rotate
```

---

## Scheduling

Add rotation commands to your Laravel scheduler (`app/Console/Kernel.php`):

```php
// Rotate AWS credentials weekly
$schedule->command('locksmith:rotate aws.credentials --recipe=aws')->weekly();

// Rotate pool keys monthly
$schedule->command('locksmith:pool-rotate')->monthly();

// Clear expired grace periods (recipes decide if old keys are deleted)
$schedule->command('locksmith:clear-expired')->daily();

// Or clear specific secrets on different schedules
$schedule->command('locksmith:clear-expired aws.credentials')->hourly();
$schedule->command('locksmith:clear-expired digitalocean.token')->daily();

// Prune old rotation logs monthly
$schedule->command('locksmith:prune-logs')->monthly();
```

---

## Notifications

Configure email and Slack notifications for rotation events:

```php
'notifications' => [
    'enabled' => true,
    'mail' => ['to' => 'ops@example.com'],
    'slack' => ['webhook_url' => env('LOCKSMITH_SLACK_WEBHOOK')],
],
```

---

## Queue Integration

```php
use BrainletAli\Locksmith\Jobs\RotateSecretJob;
use BrainletAli\Locksmith\Recipes\Aws\AwsRecipe;

RotateSecretJob::dispatch('aws.credentials', AwsRecipe::class, gracePeriod: 60);
```

---

## Events

### Rotation Events

| Event | Description |
|-------|-------------|
| `SecretRotating` | Before rotation begins |
| `SecretRotated` | After successful rotation |
| `SecretRotationFailed` | When rotation fails |

### Pool Events

| Event | Description |
|-------|-------------|
| `PoolKeyActivated` | When a new pool key becomes active |
| `PoolLow` | When pool falls below threshold |

---

## System Logging

Locksmith can write rotation events to Laravel's logging system for integration with external services like Sentry, Bugsnag, or centralized log management.

### Configuration

```php
// config/locksmith.php
'logging' => [
    'enabled' => env('LOCKSMITH_LOGGING_ENABLED', false),
    'channel' => env('LOCKSMITH_LOG_CHANNEL'),  // null = default channel
],
```

```env
LOCKSMITH_LOGGING_ENABLED=true
LOCKSMITH_LOG_CHANNEL=stack
```

### What Gets Logged

| Event | Level | Message |
|-------|-------|---------|
| Rotation success | `info` | Secret [key] rotated successfully |
| Rotation failure | `error` | Secret [key] rotation failed: {error} |
| Discard success | `info` | Previous value for [key] discarded |
| Discard failure | `warning` | Failed to discard previous value for [key] |

### Log Context

Each log entry includes metadata:

```php
[
    'secret_key' => 'aws.credentials',
    'correlation_id' => 'abc123-def456',
    'duration_ms' => 1250,
    'source' => 'command',  // or 'queue', 'api'
    'recipe' => 'AwsRecipe',
]
```

### External Service Integration

**Sentry:**
```php
// config/logging.php
'channels' => [
    'sentry' => [
        'driver' => 'sentry',
        'level' => 'error',
    ],
],

// .env
LOCKSMITH_LOG_CHANNEL=sentry
```

**Bugsnag:**
```php
LOCKSMITH_LOG_CHANNEL=bugsnag
```

---

## Log Management

Locksmith stores rotation logs in the `locksmith_rotation_logs` table for auditing.

### Querying Logs

```php
use BrainletAli\Locksmith\Facades\Locksmith;

// Get logs by status
$failures = Locksmith::getLogsByStatus(RotationStatus::Failed);

// Get recent failures (last 24 hours)
$recentFailures = Locksmith::getRecentFailures();

// Get logs between dates
$logs = Locksmith::getLogsBetween(
    now()->subDays(7),
    now()
);

// Get log statistics
$stats = Locksmith::getLogStats();
// ['total' => 150, 'success' => 145, 'failed' => 5, 'pending' => 0]
```

### Pruning Old Logs

Remove logs older than a specified number of days:

```bash
# Prune logs older than 30 days
php artisan locksmith:prune-logs --days=30

# Use default retention (from config)
php artisan locksmith:prune-logs
```

**Configuration:**

```php
// config/locksmith.php
'log_retention_days' => env('LOCKSMITH_LOG_RETENTION_DAYS', 90),
```

**Scheduled Pruning:**

```php
// app/Console/Kernel.php
$schedule->command('locksmith:prune-logs')->monthly();
```

### Log Schema

| Column | Description |
|--------|-------------|
| `id` | Primary key |
| `secret_id` | Foreign key to secrets table |
| `status` | Enum: Pending, Success, Failed, DiscardSuccess, DiscardFailed |
| `rotated_at` | Timestamp of rotation |
| `metadata` | JSON: correlation_id, duration_ms, source, recipe, error |

---

## Testing

Mock recipes in tests using Mockery:

```php
use BrainletAli\Locksmith\Contracts\Recipe;
use BrainletAli\Locksmith\Facades\Locksmith;
use Mockery;

$recipe = Mockery::mock(Recipe::class);
$recipe->shouldReceive('generate')->once()->andReturn('new_secret_value');
$recipe->shouldReceive('validate')->once()->andReturn(true);

$log = Locksmith::rotate('api.key', $recipe);
```

Or use the TestRecipe helper for simple cases:

```php
use BrainletAli\Locksmith\Tests\Support\TestRecipe;

$recipe = TestRecipe::make(
    generate: fn () => 'new_value',
    validate: fn ($value) => true,
);

Locksmith::rotate('api.key', $recipe);
```

---

## Security

### Encryption at Rest

All secrets and pool keys are **encrypted using AES-256-CBC** via Laravel's encryption system. Raw credentials are never stored in the database.

```php
// Secrets are automatically encrypted when stored
Locksmith::set('api.key', 'sk_live_xxx');  // Stored as encrypted blob

// And decrypted when retrieved
$key = Locksmith::get('api.key');  // Returns plaintext
```

Database stores only encrypted values:
```
| key        | value (encrypted)                              |
|------------|------------------------------------------------|
| digitalocean.token | eyJpdiI6IkxKN3... (AES-256-CBC encrypted blob) |
```

### Security Considerations

1. **APP_KEY** - Your Laravel `APP_KEY` is used for encryption. Keep it secure and backed up.
2. **Database Security** - Secure your database as it becomes the source of truth for secrets.
3. **Audit Trail** - All rotations are logged with timestamps and metadata.
4. **Grace Period** - Use appropriate periods for zero-downtime without leaving compromised keys valid too long.
