<?php

namespace BrainletAli\Locksmith\Listeners;

use BrainletAli\Locksmith\Events\PoolLow;
use BrainletAli\Locksmith\Notifications\PoolLowNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** Listener to send pool low notifications. */
class SendPoolNotification
{
    /** Handle pool events and send notifications. */
    public function handle(PoolLow $event): void
    {
        if (! config('locksmith.notifications.enabled', false)) {
            return;
        }

        $this->sendEmailNotification($event);
        $this->sendSlackNotification($event);
    }

    /** Send email notification if configured. */
    protected function sendEmailNotification(PoolLow $event): void
    {
        $email = config('locksmith.notifications.mail.to');

        if (empty($email)) {
            return;
        }

        $notification = new PoolLowNotification(
            $event->secretKey,
            $event->remaining,
            $event->threshold
        );

        Notification::route('mail', $email)->notify($notification);
    }

    /** Send Slack webhook notification if configured. */
    protected function sendSlackNotification(PoolLow $event): void
    {
        $webhookUrl = config('locksmith.notifications.slack.webhook_url');

        if (empty($webhookUrl)) {
            return;
        }

        $emoji = $event->remaining === 0 ? ':rotating_light:' : ':warning:';
        $urgency = $event->remaining === 0 ? 'CRITICAL' : ($event->remaining === 1 ? 'URGENT' : 'Low');

        $payload = [
            'text' => "{$emoji} Key Pool {$urgency}: {$event->secretKey}",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => ['type' => 'plain_text', 'text' => "{$emoji} Key Pool {$urgency}"],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Secret:*\n{$event->secretKey}"],
                        ['type' => 'mrkdwn', 'text' => "*Remaining:*\n{$event->remaining}"],
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $event->remaining === 0
                            ? ':x: Pool is EMPTY! Rotation has stopped.'
                            : ':clock1: Add more keys during business hours.',
                    ],
                ],
            ],
        ];

        Http::post($webhookUrl, $payload);
    }
}
