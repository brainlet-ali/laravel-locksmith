<?php

namespace BrainletAli\Locksmith\Tests\Unit\Notifications;

use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Notifications\SecretRotatedNotification;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Slack\SlackMessage;

class SecretRotatedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_can_be_created(): void
    {
        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => \BrainletAli\Locksmith\Enums\RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $notification = new SecretRotatedNotification($secret, $log);

        $this->assertInstanceOf(SecretRotatedNotification::class, $notification);
        $this->assertSame($secret, $notification->secret);
        $this->assertSame($log, $notification->log);
    }

    public function test_notification_via_returns_configured_channels(): void
    {
        config(['locksmith.notifications.channels' => ['mail', 'slack']]);

        $secret = Secret::create(['key' => 'test.key', 'value' => 'test_value']);
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => \BrainletAli\Locksmith\Enums\RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $notification = new SecretRotatedNotification($secret, $log);

        $this->assertEquals(['mail', 'slack'], $notification->via(null));
    }

    public function test_notification_to_mail_returns_mail_message(): void
    {
        $secret = Secret::create(['key' => 'test.secret', 'value' => 'test_value']);
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => \BrainletAli\Locksmith\Enums\RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $notification = new SecretRotatedNotification($secret, $log);
        $mail = $notification->toMail(null);

        $this->assertInstanceOf(MailMessage::class, $mail);
    }

    public function test_notification_to_slack_returns_slack_message(): void
    {
        $secret = Secret::create(['key' => 'test.secret', 'value' => 'test_value']);
        $log = RotationLog::create([
            'secret_id' => $secret->id,
            'status' => \BrainletAli\Locksmith\Enums\RotationStatus::Success,
            'rotated_at' => now(),
        ]);

        $notification = new SecretRotatedNotification($secret, $log);
        $slack = $notification->toSlack(null);

        $this->assertInstanceOf(SlackMessage::class, $slack);
    }
}
