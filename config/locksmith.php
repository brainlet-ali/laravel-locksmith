<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Custom Recipes
    |--------------------------------------------------------------------------
    |
    | Register custom rotation recipes here. Each recipe can specify:
    | - class: The recipe class (required)
    | - provider_cleanup: Whether to delete old keys from provider (default: true)
    |
    | Examples:
    |
    | Simple format (provider_cleanup defaults to true):
    | 'recipes' => [
    |     'twilio' => [
    |         'class' => \App\Recipes\TwilioRecipe::class,
    |         'provider_cleanup' => false,  // Twilio allows unlimited keys
    |     ],
    | ],
    |
    | Then rotate: php artisan locksmith:rotate twilio.credentials --recipe=twilio
    |
    */
    'recipes' => [],

    'grace_period_minutes' => env('LOCKSMITH_GRACE_PERIOD', 60),

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Cache secrets to reduce database calls. Secrets are cached on first
    | access and automatically invalidated on rotation, set, or delete.
    |
    | Set 'enabled' to false to always read from database (useful for testing).
    | TTL is in seconds. Set to null for no expiration (invalidation only).
    |
    */
    'cache' => [
        'enabled' => env('LOCKSMITH_CACHE_ENABLED', true),
        'store' => env('LOCKSMITH_CACHE_STORE'),  // null = default cache store
        'ttl' => env('LOCKSMITH_CACHE_TTL', 300),  // 5 minutes
        'prefix' => 'locksmith:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to retain rotation logs before pruning. Used by the
    | locksmith:prune-logs command when --days is not specified.
    |
    */
    'log_retention_days' => env('LOCKSMITH_LOG_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | System Logging
    |--------------------------------------------------------------------------
    |
    | Enable writing rotation events to Laravel's logging system. This is
    | useful for integrating with external services like Sentry, Bugsnag,
    | or centralized log management systems.
    |
    | When 'channel' is null, uses the default Laravel log channel.
    |
    */
    'logging' => [
        'enabled' => env('LOCKSMITH_LOGGING_ENABLED', false),
        'channel' => env('LOCKSMITH_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Key Pools
    |--------------------------------------------------------------------------
    |
    | Configure key pools for services that don't support programmatic key
    | generation (e.g., DigitalOcean). Pre-generate keys during
    | business hours and Locksmith will rotate through them automatically.
    |
    | Example:
    | 'pools' => [
    |     'digitalocean.token' => [
    |         'grace' => 60,           // Grace period in minutes
    |     ],
    | ],
    |
    | Use 'notify_below' to set when to send low pool notifications.
    |
    */
    'pools' => [
        'notify_below' => env('LOCKSMITH_POOL_NOTIFY_BELOW', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Configure notifications for rotation events. Set 'enabled' to true and
    | provide a notifiable instance (user, team, or on-demand notification).
    |
    | Channels can be: 'mail', 'slack', 'database', etc.
    |
    | Example with on-demand notification:
    | 'notifications' => [
    |     'enabled' => true,
    |     'channels' => ['mail', 'slack'],
    |     'mail' => [
    |         'to' => 'ops@example.com',
    |     ],
    |     'slack' => [
    |         'webhook_url' => env('LOCKSMITH_SLACK_WEBHOOK'),
    |     ],
    | ],
    |
    */
    'notifications' => [
        'enabled' => env('LOCKSMITH_NOTIFICATIONS_ENABLED', false),
        'channels' => ['mail'],
        'mail' => [
            'to' => env('LOCKSMITH_NOTIFICATION_EMAIL'),
        ],
        'slack' => [
            'webhook_url' => env('LOCKSMITH_SLACK_WEBHOOK'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AWS Recipe Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the built-in AWS IAM access key rotation recipe.
    | Requires aws/aws-sdk-php to be installed.
    |
    | Credentials and username are stored with `locksmith:seed`. No env
    | variables needed - everything is self-contained in the seeded secret.
    |
    */
    'aws' => [
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'validation_retries' => env('LOCKSMITH_AWS_VALIDATION_RETRIES', 3),
    ],

];
