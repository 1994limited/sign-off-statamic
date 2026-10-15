<?php

namespace NineteenNinetyFour\SignOff\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the people who can approve the step an entry has reached.
 */
class ReviewNeeded extends SignOffNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $details = $this->details;

        return $this->message()
            ->subject(__('Waiting for your review: :entry', ['entry' => $details['entry']]))
            ->line(__(':by sent “:entry” (:collection) for sign-off, and it’s waiting for :step.', $details))
            ->when($details['approvers'] ?? null, fn ($message, $approvers) => $message->line(__('Approved so far: :approvers.', ['approvers' => $approvers])))
            ->when($this->quote($details['comment']), fn ($message, $note) => $message->line(__('Their note: :note', ['note' => $note])))
            ->when($details['url'], fn ($message, $url) => $message->action(__('Review the entry'), $url))
            ->line(__('Approve it or send it back from the entry’s ••• menu.'));
    }
}
