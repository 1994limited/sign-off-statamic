<?php

namespace NineteenNinetyFour\SignOff\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

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

    /**
     * A line of the email, with people's words (titles, names, notes and
     * comments) put in as plain text: emails are written in Markdown, so
     * "[a link](…)" in a comment would otherwise become a real link.
     */
    protected function say(string $line, array $replace = []): HtmlString
    {
        return new HtmlString(__($line, array_map(fn ($value) => is_scalar($value) ? static::plain((string) $value) : $value, $replace)));
    }

    /**
     * Text that reads the same in the HTML and plain-text parts of the
     * email, and that Markdown leaves alone.
     */
    public static function plain(string $text): string
    {
        return strtr(e($text), [
            '[' => '&#91;', ']' => '&#93;', '(' => '&#40;', ')' => '&#41;',
            '*' => '&#42;', '_' => '&#95;', '`' => '&#96;', '#' => '&#35;', '!' => '&#33;',
            '\\' => '&#92;',
        ]);
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
