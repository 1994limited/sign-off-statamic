<?php

namespace NineteenNinetyFour\SignOff\Actions;

use NineteenNinetyFour\SignOff\Submission;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

class ApproveSignOff extends SignOffAction
{
    protected $icon = 'checkmark';

    protected $fields = [
        'comment' => [
            'type' => 'textarea',
            'display' => 'Comment',
            'instructions' => 'Optional.',
        ],
    ];

    public static function title()
    {
        return __('sign-off::messages.action_approve');
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
        $open = $this->items?->first() instanceof Entry ? $this->signOff()->open($this->items->first()) : null;
        $step = $open?->currentStep();

        return $step && $open->isLastStep($step['handle'])
            ? __('sign-off::messages.action_approve_publish')
            : __('sign-off::messages.action_approve');
    }

    public function confirmationText()
    {
        return __('sign-off::messages.confirm_approve');
    }

    public function run($items, $values)
    {
        $submission = $this->signOff()->approve(User::current(), $this->entry($items), $values['comment'] ?? null);

        return $submission->state() === Submission::PUBLISHED
            ? __('sign-off::messages.signed_off')
            : __('sign-off::messages.approved', ['step' => $submission->currentStep()['title'] ?? '']);
    }
}
