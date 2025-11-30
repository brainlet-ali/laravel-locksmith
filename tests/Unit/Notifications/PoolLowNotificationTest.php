<?php

namespace BrainletAli\Locksmith\Tests\Unit\Notifications;

use BrainletAli\Locksmith\Notifications\PoolLowNotification;
use BrainletAli\Locksmith\Tests\TestCase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Slack\SlackMessage;

class PoolLowNotificationTest extends TestCase
{
    public function test_via_returns_configured_channels(): void
    {
        config(['locksmith.notifications.channels' => ['mail', 'slack']]);

        $notification = new PoolLowNotification('test.key', 2, 5);

        $channels = $notification->via(null);

        $this->assertEquals(['mail', 'slack'], $channels);
    }

    public function test_to_mail_returns_mail_message(): void
    {
        $notification = new PoolLowNotification('test.key', 2, 5);

        $message = $notification->toMail(null);

        $this->assertInstanceOf(MailMessage::class, $message);
    }

    public function test_to_mail_shows_warning_for_low_count(): void
    {
        $notification = new PoolLowNotification('test.key', 3, 5);

        $message = $notification->toMail(null);

        $this->assertStringContainsString('Warning', $message->subject);
    }

    public function test_to_mail_shows_urgent_for_one_remaining(): void
    {
        $notification = new PoolLowNotification('test.key', 1, 5);

        $message = $notification->toMail(null);

        $this->assertStringContainsString('URGENT', $message->subject);
    }

    public function test_to_mail_shows_critical_for_zero_remaining(): void
    {
        $notification = new PoolLowNotification('test.key', 0, 5);

        $message = $notification->toMail(null);

        $this->assertStringContainsString('CRITICAL', $message->subject);
    }

    public function test_to_mail_includes_empty_pool_message_when_zero(): void
    {
        $notification = new PoolLowNotification('test.key', 0, 5);

        $message = $notification->toMail(null);

        $found = false;
        foreach ($message->introLines as $line) {
            if (str_contains($line, 'EMPTY')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    public function test_to_mail_includes_add_keys_message_when_not_zero(): void
    {
        $notification = new PoolLowNotification('test.key', 2, 5);

        $message = $notification->toMail(null);

        $found = false;
        foreach ($message->introLines as $line) {
            if (str_contains($line, 'add more keys')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }

    public function test_to_slack_returns_slack_message(): void
    {
        $notification = new PoolLowNotification('test.key', 2, 5);

        $message = $notification->toSlack(null);

        $this->assertInstanceOf(SlackMessage::class, $message);
    }

    public function test_to_slack_shows_critical_for_zero_remaining(): void
    {
        $notification = new PoolLowNotification('test.key', 0, 5);

        $message = $notification->toSlack(null);

        $this->assertInstanceOf(SlackMessage::class, $message);
    }

    public function test_to_slack_shows_urgent_for_one_remaining(): void
    {
        $notification = new PoolLowNotification('test.key', 1, 5);

        $message = $notification->toSlack(null);

        $this->assertInstanceOf(SlackMessage::class, $message);
    }
}
