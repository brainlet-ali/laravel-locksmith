<?php

namespace BrainletAli\Locksmith\Tests\Unit\Notifications;

use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Notifications\SecretRotationFailedNotification;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Slack\SlackMessage;

class SecretRotationFailedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_can_be_created(): void
    {
        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);

        $notification = new SecretRotationFailedNotification($secret, 'Validation failed');

        $this->assertInstanceOf(SecretRotationFailedNotification::class, $notification);
        $this->assertSame($secret, $notification->secret);
        $this->assertEquals('Validation failed', $notification->reason);
    }

    public function test_notification_via_returns_configured_channels(): void
    {
        config(['locksmith.notifications.channels' => ['mail', 'slack']]);

        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);
        $notification = new SecretRotationFailedNotification($secret, 'Validation failed');

        $this->assertEquals(['mail', 'slack'], $notification->via(null));
    }

    public function test_notification_to_mail_returns_mail_message(): void
    {
        $secret = Secret::create(['key' => 'test.secret', 'value' => 'test_value']);
        $notification = new SecretRotationFailedNotification($secret, 'API key validation failed');
        $mail = $notification->toMail(null);

        $this->assertInstanceOf(MailMessage::class, $mail);
    }

    public function test_notification_to_slack_returns_slack_message(): void
    {
        $secret = Secret::create(['key' => 'test.secret', 'value' => 'test_value']);
        $notification = new SecretRotationFailedNotification($secret, 'API key validation failed');
        $slack = $notification->toSlack(null);

        $this->assertInstanceOf(SlackMessage::class, $slack);
    }
}
