<?php

namespace NineteenNinetyFour\SignOff\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the person who submitted an entry, when the last step publishes it.
 */
class EntrySignedOff extends SignOffNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $details = $this->details;

        return $this->message()
            ->subject(__('Signed off and published: :entry', ['entry' => $details['entry']]))
            ->line(__('“:entry” has been signed off and is published.', $details))
            ->when($details['approvers'] ?? null, fn ($message, $approvers) => $message->line(__('Approved by :approvers.', ['approvers' => $approvers])))
            ->when($details['url'], fn ($message, $url) => $message->action(__('Open the entry'), $url));
    }
}
