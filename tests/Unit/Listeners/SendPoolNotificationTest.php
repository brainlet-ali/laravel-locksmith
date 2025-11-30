<?php

namespace BrainletAli\Locksmith\Tests\Unit\Listeners;

use BrainletAli\Locksmith\Events\PoolLow;
use BrainletAli\Locksmith\Listeners\SendPoolNotification;
use BrainletAli\Locksmith\Notifications\PoolLowNotification;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

class SendPoolNotificationTest extends TestCase
{
    public function test_does_not_send_when_notifications_disabled(): void
    {
        Notification::fake();
        Http::fake();

        config(['locksmith.notifications.enabled' => false]);

        $event = new PoolLow('test.key', 2, 5);
        $listener = new SendPoolNotification;
        $listener->handle($event);

        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_sends_email_notification_when_configured(): void
    {
        Notification::fake();
        Http::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => 'ops@example.com',
            'locksmith.notifications.slack.webhook_url' => null,
        ]);

        $event = new PoolLow('test.key', 2, 5);
        $listener = new SendPoolNotification;
        $listener->handle($event);

        Notification::assertSentOnDemand(PoolLowNotification::class);
    }

    public function test_does_not_send_email_when_not_configured(): void
    {
        Notification::fake();
        Http::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => null,
            'locksmith.notifications.slack.webhook_url' => null,
        ]);

        $event = new PoolLow('test.key', 2, 5);
        $listener = new SendPoolNotification;
        $listener->handle($event);

        Notification::assertNothingSent();
    }

    public function test_sends_slack_notification_when_configured(): void
    {
        Notification::fake();
        Http::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => null,
            'locksmith.notifications.slack.webhook_url' => 'https://hooks.slack.com/test',
        ]);

        $event = new PoolLow('test.key', 2, 5);
        $listener = new SendPoolNotification;
        $listener->handle($event);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.slack.com/test'
                && str_contains($request->body(), 'Key Pool');
        });
    }

    public function test_slack_notification_shows_warning_for_low_count(): void
    {
        Notification::fake();
        Http::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => null,
            'locksmith.notifications.slack.webhook_url' => 'https://hooks.slack.com/test',
        ]);

        $event = new PoolLow('test.key', 3, 5);
        $listener = new SendPoolNotification;
        $listener->handle($event);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, ':warning:')
                && str_contains($body, 'Low')
                && str_contains($body, 'Add more keys during business hours');
        });
    }

    public function test_slack_notification_shows_urgent_for_one_remaining(): void
    {
        Notification::fake();
        Http::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => null,
            'locksmith.notifications.slack.webhook_url' => 'https://hooks.slack.com/test',
        ]);

        $event = new PoolLow('test.key', 1, 5);
        $listener = new SendPoolNotification;
        $listener->handle($event);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, ':warning:')
                && str_contains($body, 'URGENT');
        });
    }

    public function test_slack_notification_shows_critical_for_zero_remaining(): void
    {
        Notification::fake();
        Http::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => null,
            'locksmith.notifications.slack.webhook_url' => 'https://hooks.slack.com/test',
        ]);

        $event = new PoolLow('test.key', 0, 5);
        $listener = new SendPoolNotification;
        $listener->handle($event);

        Http::assertSent(function ($request) {
            $body = $request->body();

            return str_contains($body, ':rotating_light:')
                && str_contains($body, 'CRITICAL')
                && str_contains($body, 'Pool is EMPTY');
        });
    }

    public function test_does_not_send_slack_when_not_configured(): void
    {
        Notification::fake();
        Http::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => null,
            'locksmith.notifications.slack.webhook_url' => null,
        ]);

        $event = new PoolLow('test.key', 2, 5);
        $listener = new SendPoolNotification;
        $listener->handle($event);

        Http::assertNothingSent();
    }

    public function test_sends_both_email_and_slack(): void
    {
        Notification::fake();
        Http::fake();

        config([
            'locksmith.notifications.enabled' => true,
            'locksmith.notifications.mail.to' => 'ops@example.com',
            'locksmith.notifications.slack.webhook_url' => 'https://hooks.slack.com/test',
        ]);

        $event = new PoolLow('test.key', 2, 5);
        $listener = new SendPoolNotification;
        $listener->handle($event);

        Notification::assertSentOnDemand(PoolLowNotification::class);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.slack.com/test';
        });
    }
}
