<?php

namespace BrainletAli\Locksmith\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/** Notification for low pool key count. */
class PoolLowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $secretKey,
        public int $remaining,
        public int $threshold
    ) {}

    /** Get the notification's delivery channels. */
    public function via(mixed $notifiable): array
    {
        return config('locksmith.notifications.channels', ['mail']);
    }

    /** Get the mail representation of the notification. */
    public function toMail(mixed $notifiable): MailMessage
    {
        $urgency = $this->remaining === 0 ? 'CRITICAL' : ($this->remaining === 1 ? 'URGENT' : 'Warning');

        return (new MailMessage)
            ->subject("[{$urgency}] Key Pool Low: {$this->secretKey}")
            ->line("The key pool for [{$this->secretKey}] is running low.")
            ->line("Remaining keys: {$this->remaining}")
            ->line("Threshold: {$this->threshold}")
            ->line('')
            ->line($this->remaining === 0
                ? 'The pool is EMPTY! Automatic rotation has stopped.'
                : 'Please add more keys to the pool during business hours.')
            ->action('Add Keys', url('/'));
    }

    /** Get the Slack representation of the notification. */
    public function toSlack(mixed $notifiable): SlackMessage
    {
        $emoji = $this->remaining === 0 ? ':rotating_light:' : ':warning:';
        $urgency = $this->remaining === 0 ? 'CRITICAL' : ($this->remaining === 1 ? 'URGENT' : 'Low');

        return (new SlackMessage)
            ->text("{$emoji} Key Pool {$urgency}: {$this->secretKey}")
            ->headerBlock("{$emoji} Key Pool {$urgency}")
            ->sectionBlock(function (SectionBlock $block) {
                $block->text("*Secret:* {$this->secretKey}");
            })
            ->sectionBlock(function (SectionBlock $block) {
                $block->field("*Remaining:*\n{$this->remaining}")->markdown();
                $block->field("*Threshold:*\n{$this->threshold}")->markdown();
            })
            ->sectionBlock(function (SectionBlock $block) {
                $message = $this->remaining === 0
                    ? ':x: Pool is EMPTY! Rotation stopped.'
                    : ':clock1: Add more keys during business hours.';
                $block->text($message);
            });
    }
}
