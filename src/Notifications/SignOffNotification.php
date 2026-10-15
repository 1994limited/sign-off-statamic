<?php

namespace NineteenNinetyFour\SignOff\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An email about an entry in sign-off. It holds only plain values, worked
 * out when it was sent, so it can wait in a queue: on a site with a queue
 * worker it's sent in the background.
 */
abstract class SignOffNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{entry: string, collection: string, url: ?string, by: string, step: ?string, comment: ?string, approvers?: string}  $details
     */
    public function __construct(public readonly array $details) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    abstract public function toMail(object $notifiable): MailMessage;

    protected function message(): MailMessage
    {
        return (new MailMessage)->greeting(__('Hello,'));
    }

    protected function quote(?string $text): ?string
    {
        return $text === null || $text === '' ? null : '“'.$text.'”';
    }

    public function toArray(object $notifiable): array
    {
        return $this->details;
    }
}
