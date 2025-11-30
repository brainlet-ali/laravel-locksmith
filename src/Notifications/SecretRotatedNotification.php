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

/** Notification for successful secret rotation. */
class SecretRotatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Secret $secret,
        public RotationLog $log
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
            ->subject('Secret Rotated Successfully')
            ->line("The secret [{$this->secret->key}] has been rotated successfully.")
            ->line("Status: {$this->log->status->label()}")
            ->line("Rotated at: {$this->log->rotated_at->format('Y-m-d H:i:s')}");
    }

    /** Get the Slack representation of the notification. */
    public function toSlack(mixed $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->text("Secret [{$this->secret->key}] rotated successfully")
            ->headerBlock('Secret Rotated Successfully')
            ->sectionBlock(function (SectionBlock $block) {
                $block->text("*Secret:* {$this->secret->key}");
            })
            ->sectionBlock(function (SectionBlock $block) {
                $block->field("*Status:*\n{$this->log->status->label()}")->markdown();
                $block->field("*Rotated At:*\n{$this->log->rotated_at->format('Y-m-d H:i:s')}")->markdown();
            });
    }
}
