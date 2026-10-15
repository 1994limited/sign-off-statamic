<?php

namespace NineteenNinetyFour\SignOff\Concerns;

use Carbon\CarbonImmutable;
use NineteenNinetyFour\SignOff\Submission;
use Statamic\Facades\User as Users;

/**
 * Submissions in words, for the Control Panel: people's names, dates and
 * what happened.
 */
trait DescribesSubmissions
{
    /** @var array<string, string> */
    protected array $names = [];

    protected function label(string $type): string
    {
        return match ($type) {
            'submitted' => __('Submitted'),
            'approved' => __('Approved'),
            'rejected' => __('Sent back'),
            'withdrawn' => __('Withdrawn'),
            'published' => __('Published'),
            'changed' => __('Changed after submission'),
            'published_without_sign_off' => __('Published without sign-off'),
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    protected function stepTitle(Submission $submission, ?string $handle): ?string
    {
        if ($handle === null) {
            return null;
        }

        return collect($submission->steps())->firstWhere('handle', $handle)['title'] ?? $handle;
    }

    protected function name(string $id): string
    {
        if ($id === '') {
            return __('Someone');
        }

        return $this->names[$id] ??= (function () use ($id) {
            $user = Users::find($id);

            return $user ? ($user->name() ?: $user->email()) : __('A former user');
        })();
    }

    /**
     * @return array{iso: string, human: string, full: string}|null
     */
    protected function when(?CarbonImmutable $date): ?array
    {
        return $date ? [
            'iso' => $date->toIso8601String(),
            'human' => $date->diffForHumans(),
            'full' => $date->format('j M Y, H:i'),
        ] : null;
    }

    /**
     * One event in a round, as the Control Panel shows it.
     */
    protected function event(Submission $submission, array $event): array
    {
        return [
            'type' => $event['type'],
            'label' => $this->label($event['type']),
            'step' => $this->stepTitle($submission, $event['step'] ?? null),
            // Changes made in code have nobody to name.
            'by' => ($event['user'] ?? '') === '' ? null : $this->name($event['user']),
            'at' => $this->when(Submission::date($event['at'] ?? null)),
            'comment' => $event['comment'] ?? null,
        ];
    }
}
