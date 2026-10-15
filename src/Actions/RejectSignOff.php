<?php

namespace NineteenNinetyFour\SignOff\Actions;

use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

class RejectSignOff extends SignOffAction
{
    protected $icon = 'x';

    protected $dangerous = true;

    protected $fields = [
        'reason' => [
            'type' => 'textarea',
            'display' => 'What needs to change?',
            'validate' => ['required'],
        ],
    ];

    public static function title()
    {
        return __('sign-off::messages.action_reject');
    }

    protected function shows(Entry $entry): bool
    {
        return $this->signOff()->open($entry) !== null;
    }

    protected function allowed(UserContract $user, Entry $entry): bool
    {
        return $this->signOff()->canReview($user, $entry);
    }

    public function buttonText()
    {
        return __('sign-off::messages.action_reject');
    }

    public function confirmationText()
    {
        return __('sign-off::messages.confirm_reject');
    }

    public function run($items, $values)
    {
        $this->signOff()->reject(User::current(), $this->entry($items), (string) ($values['reason'] ?? ''));

        return __('sign-off::messages.rejected');
    }
}
