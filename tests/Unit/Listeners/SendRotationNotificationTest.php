<?php

namespace BrainletAli\Locksmith\Tests\Unit\Listeners;

use BrainletAli\Locksmith\Enums\RotationStatus;
use BrainletAli\Locksmith\Events\SecretRotated;
use BrainletAli\Locksmith\Events\SecretRotationFailed;
use BrainletAli\Locksmith\Listeners\SendRotationNotification;
use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Notifications\SecretRotatedNotification;
use BrainletAli\Locksmith\Notifications\SecretRotationFailedNotification;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

class SendRotationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_sends_notification_on_secret_rotated(): void
    {
        Notification::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.channels' => ['mail'],
            'locksmith.notifications.mail.to' => 'ops@example.com',
        ]);

        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $event = new SecretRotated($secret, $log);
        $listener = new SendRotationNotification();
        $listener->handle($event);

        Notification::assertSentOnDemand(SecretRotatedNotification::class);
    }

    public function test_listener_sends_notification_on_secret_rotation_failed(): void
    {
        Notification::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.channels' => ['mail'],
            'locksmith.notifications.mail.to' => 'ops@example.com',
        ]);

        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);
        $event = new SecretRotationFailed($secret, 'Validation failed');

        $listener = new SendRotationNotification();
        $listener->handle($event);

        Notification::assertSentOnDemand(SecretRotationFailedNotification::class);
    }

    public function test_listener_does_not_send_when_notifications_disabled(): void
    {
        Notification::fake();

        config(['locksmith.notifications.enabled' => false]);

        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $event = new SecretRotated($secret, $log);
        $listener = new SendRotationNotification();
        $listener->handle($event);

        Notification::assertNothingSent();
    }

    public function test_listener_does_not_send_when_no_email_configured(): void
    {
        Notification::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => null,
        ]);

        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $event = new SecretRotated($secret, $log);
        $listener = new SendRotationNotification();
        $listener->handle($event);

        Notification::assertNothingSent();
    }

    public function test_sends_slack_notification_for_success(): void
    {
        Http::fake();
        Notification::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => null,
            'locksmith.notifications.slack.webhook_url' => 'https://hooks.slack.com/test',
        ]);

        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $event = new SecretRotated($secret, $log);
        $listener = new SendRotationNotification();
        $listener->handle($event);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.slack.com/test'
                && str_contains($request->body(), 'rotated successfully');
        });
    }

    public function test_sends_slack_notification_for_failure(): void
    {
        Http::fake();
        Notification::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => null,
            'locksmith.notifications.slack.webhook_url' => 'https://hooks.slack.com/test',
        ]);

        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Failed,
            'rotated_at' => now(),
        ]);

        $event = new SecretRotationFailed($secret, 'Test reason', $log);
        $listener = new SendRotationNotification();
        $listener->handle($event);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.slack.com/test'
                && str_contains($request->body(), 'rotation failed');
        });
    }

    public function test_does_not_send_slack_when_not_configured(): void
    {
        Http::fake();
        Notification::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.slack.webhook_url' => null,
        ]);

        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $event = new SecretRotated($secret, $log);
        $listener = new SendRotationNotification();
        $listener->handle($event);

        Http::assertNothingSent();
    }

    public function test_sends_both_email_and_slack(): void
    {
        Http::fake();
        Notification::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => 'test@example.com',
            'locksmith.notifications.slack.webhook_url' => 'https://hooks.slack.com/test',
        ]);

        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $event = new SecretRotated($secret, $log);
        $listener = new SendRotationNotification();
        $listener->handle($event);

        Notification::assertSentOnDemand(SecretRotatedNotification::class);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.slack.com/test';
        });
    }
}
