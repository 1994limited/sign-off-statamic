<?php

namespace NineteenNinetyFour\SignOff\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the person who submitted an entry, when a reviewer sends it back.
 */
class EntrySentBack extends SignOffNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        $details = $this->details;

        return $this->message()
            ->subject(__('Sent back: :entry', ['entry' => $details['entry']]))
            ->line(__(':by sent “:entry” back at the :step step:', $details))
            ->line($this->quote($details['comment']) ?? '')
            ->when($details['url'], fn ($message, $url) => $message->action(__('Open the entry'), $url))
            ->line(__('Make the changes, save, then submit it for sign-off again.'));
    }
}
