<?php

namespace NineteenNinetyFour\SignOff\Actions;

use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

class WithdrawSignOff extends SignOffAction
{
    protected $icon = 'history';

    public static function title()
    {
        return __('sign-off::messages.action_withdraw');
    }

    protected function shows(Entry $entry): bool
    {
        return $this->signOff()->open($entry) !== null;
    }

    protected function allowed(UserContract $user, Entry $entry): bool
    {
        return $this->signOff()->whyCannotWithdraw($user, $entry) === null;
    }

    public function buttonText()
    {
        return __('sign-off::messages.action_withdraw');
    }

    public function confirmationText()
    {
        return __('sign-off::messages.confirm_withdraw');
    }

    public function run($items, $values)
    {
        $this->signOff()->withdraw(User::current(), $this->entry($items));

        return __('sign-off::messages.withdrawn');
    }
}
