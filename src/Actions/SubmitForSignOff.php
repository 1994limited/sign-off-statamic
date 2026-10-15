<?php

namespace NineteenNinetyFour\SignOff\Actions;

use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

class SubmitForSignOff extends SignOffAction
{
    protected $icon = 'arrow-up-right';

    protected $confirm = true;

    protected $fields = [
        'note' => [
            'type' => 'textarea',
            'display' => 'Note for reviewers',
            'instructions' => 'Optional. What changed, or anything they should check.',
        ],
    ];

    public static function title()
    {
        return __('sign-off::messages.action_submit');
    }

    protected function shows(Entry $entry): bool
    {
        return $this->signOff()->open($entry) === null && $this->signOff()->hasChanges($entry);
    }

    protected function allowed(UserContract $user, Entry $entry): bool
    {
        return $this->signOff()->whyCannotSubmit($user, $entry) === null;
    }

    public function buttonText()
    {
        return __('sign-off::messages.action_submit');
    }

    public function confirmationText()
    {
        return __('sign-off::messages.confirm_submit');
    }

    public function dirtyWarningText()
    {
        return __('sign-off::messages.dirty_warning');
    }

    public function run($items, $values)
    {
        $submission = $this->signOff()->submit(User::current(), $this->entry($items), $values['note'] ?? null);

        return __('sign-off::messages.submitted', ['step' => $submission->currentStep()['title'] ?? '']);
    }
}
