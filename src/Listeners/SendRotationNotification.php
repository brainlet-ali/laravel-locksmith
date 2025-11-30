<?php

namespace BrainletAli\Locksmith\Listeners;

use BrainletAli\Locksmith\Events\SecretRotated;
use BrainletAli\Locksmith\Events\SecretRotationFailed;
use BrainletAli\Locksmith\Notifications\SecretRotatedNotification;
use BrainletAli\Locksmith\Notifications\SecretRotationFailedNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** Listener to send rotation notifications. */
class SendRotationNotification
{
    /** Handle rotation events and send notifications. */
    public function handle(SecretRotated|SecretRotationFailed $event): void
    {
        if (! config('locksmith.notifications.enabled', false)) {
            return;
        }

        $this->sendEmailNotification($event);
        $this->sendSlackNotification($event);
    }

    /** Send email notification if configured. */
    protected function sendEmailNotification(SecretRotated|SecretRotationFailed $event): void
    {
        $email = config('locksmith.notifications.mail.to');

        if (empty($email)) {
            return;
        }

        $notification = $this->buildNotification($event);
        Notification::route('mail', $email)->notify($notification);
    }

    /** Send Slack webhook notification if configured. */
    protected function sendSlackNotification(SecretRotated|SecretRotationFailed $event): void
    {
        $webhookUrl = config('locksmith.notifications.slack.webhook_url');

        if (empty($webhookUrl)) {
            return;
        }

        $payload = $this->buildSlackPayload($event);
        Http::post($webhookUrl, $payload);
    }

    /** Build Slack webhook payload. */
    protected function buildSlackPayload(SecretRotated|SecretRotationFailed $event): array
    {
        if ($event instanceof SecretRotated) {
            return [
                'text' => "Secret [{$event->secret->key}] rotated successfully",
                'blocks' => [
                    [
                        'type' => 'header',
                        'text' => ['type' => 'plain_text', 'text' => 'Secret Rotated Successfully'],
                    ],
                    [
                        'type' => 'section',
                        'fields' => [
                            ['type' => 'mrkdwn', 'text' => "*Secret:*\n{$event->secret->key}"],
                            ['type' => 'mrkdwn', 'text' => "*Status:*\n{$event->log->status->label()}"],
                        ],
                    ],
                    [
                        'type' => 'context',
                        'elements' => [
                            ['type' => 'mrkdwn', 'text' => "Rotated at: {$event->log->rotated_at->format('Y-m-d H:i:s')}"],
                        ],
                    ],
                ],
            ];
        }

        return [
            'text' => "Secret [{$event->secret->key}] rotation failed!",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => ['type' => 'plain_text', 'text' => 'Secret Rotation Failed'],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Secret:*\n{$event->secret->key}"],
                        ['type' => 'mrkdwn', 'text' => "*Reason:*\n{$event->reason}"],
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [
                        ['type' => 'mrkdwn', 'text' => 'Time: '.now()->format('Y-m-d H:i:s')],
                    ],
                ],
            ],
        ];
    }

    /** Build the appropriate notification for the event. */
    protected function buildNotification(SecretRotated|SecretRotationFailed $event): SecretRotatedNotification|SecretRotationFailedNotification
    {
        if ($event instanceof SecretRotated) {
            return new SecretRotatedNotification($event->secret, $event->log);
        }

        return new SecretRotationFailedNotification($event->secret, $event->reason, $event->log);
    }
}
