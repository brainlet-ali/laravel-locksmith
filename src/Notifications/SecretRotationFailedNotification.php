<?php

namespace BrainletAli\Locksmith\Notifications;

use BrainletAli\Locksmith\Models\RotationLog;
use BrainletAli\Locksmith\Models\Secret;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/** Notification for failed secret rotation. */
class SecretRotationFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Secret $secret,
        public string $reason,
        public ?RotationLog $log = null
    ) {}

    /** Get the notification's delivery channels. */
    public function via(mixed $notifiable): array
    {
        return config('locksmith.notifications.channels', ['mail']);
    }

    /** Get the mail representation of the notification. */
    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject('Secret Rotation Failed')
            ->line("The secret [{$this->secret->key}] failed to rotate.")
            ->line("Reason: {$this->reason}")
            ->line('Please investigate and retry the rotation manually.');
    }

    /** Get the Slack representation of the notification. */
    public function toSlack(mixed $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->text("Secret [{$this->secret->key}] rotation failed!")
            ->headerBlock('Secret Rotation Failed')
            ->sectionBlock(function (SectionBlock $block) {
                $block->text("*Secret:* {$this->secret->key}");
            })
            ->sectionBlock(function (SectionBlock $block) {
                $block->field("*Reason:*\n{$this->reason}")->markdown();
                $block->field("*Time:*\n".now()->format('Y-m-d H:i:s'))->markdown();
            });
    }
}
