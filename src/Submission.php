<?php

namespace NineteenNinetyFour\SignOff;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\File;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;

/**
 * One round of review for one entry: submitted, then approved step by step
 * until it's published, or rejected or withdrawn along the way. Everything
 * that happens is kept in its history.
 */
class Submission
{
    public const IN_REVIEW = 'in_review';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const PUBLISHED = 'published';

    /** Changed after it was submitted, so the approvals no longer apply. */
    public const OUTDATED = 'outdated';

    /** Published directly by someone allowed to skip sign-off. */
    public const SKIPPED = 'skipped';

    protected string $id;

    protected string $entry;

    protected string $collection;

    protected string $site = '';

    protected string $state = self::IN_REVIEW;

    protected string $submittedBy = '';

    protected ?CarbonImmutable $submittedAt = null;

    protected ?string $note = null;

    /** A hash of the content that was submitted (see Fingerprint). */
    protected string $fingerprint = '';

    /** @var array<int, array{handle: string, title: string}> the steps as they were when submitted */
    protected array $steps = [];

    /** @var array<int, array<string, mixed>> */
    protected array $history = [];

    protected ?CarbonImmutable $updatedAt = null;

    protected ?string $initialPath = null;

    /**
     * @param  array<int, Step>  $steps
     */
    public static function make(string $entry, string $collection, string $site, array $steps): static
    {
        $submission = new static;
        $submission->id = (string) Str::uuid();
        $submission->entry = $entry;
        $submission->collection = $collection;
        $submission->site = $site;
        $submission->steps = array_map(fn (Step $step) => $step->toArray(), $steps);

        return $submission;
    }

    public static function fromArray(string $id, array $data): static
    {
        $text = fn ($value) => is_scalar($value) ? (string) $value : '';

        $submission = new static;
        $submission->id = $id;
        $submission->entry = $text($data['entry'] ?? '');
        $submission->collection = $text($data['collection'] ?? '');
        $submission->site = $text($data['site'] ?? '');
        $submission->state = in_array($data['state'] ?? null, self::states(), true) ? $data['state'] : self::OUTDATED;
        $submission->submittedBy = $text($data['submitted_by'] ?? '');
        $submission->submittedAt = static::date($data['submitted_at'] ?? null);
        $submission->note = is_string($data['note'] ?? null) ? $data['note'] : null;
        $submission->fingerprint = $text($data['fingerprint'] ?? '');
        $submission->updatedAt = static::date($data['updated_at'] ?? null);

        foreach (is_array($data['steps'] ?? null) ? $data['steps'] : [] as $step) {
            if (is_array($step) && is_scalar($step['handle'] ?? null)) {
                $submission->steps[] = ['handle' => (string) $step['handle'], 'title' => $text($step['title'] ?? $step['handle'])];
            }
        }

        foreach (is_array($data['history'] ?? null) ? $data['history'] : [] as $event) {
            if (is_array($event) && is_string($event['type'] ?? null)) {
                $submission->history[] = $event;
            }
        }

        return $submission;
    }

    /**
     * @return array<int, string>
     */
    public static function states(): array
    {
        return [self::IN_REVIEW, self::REJECTED, self::WITHDRAWN, self::PUBLISHED, self::OUTDATED, self::SKIPPED];
    }

    /**
     * Dates keep microseconds, so two things in the same second stay in order.
     */
    public static function stamp(?CarbonImmutable $date): ?string
    {
        return $date?->format('Y-m-d\\TH:i:s.uP');
    }

    public static function date(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function entryId(): string
    {
        return $this->entry;
    }

    public function entry(): ?Entry
    {
        return EntryFacade::find($this->entry);
    }

    public function collection(): string
    {
        return $this->collection;
    }

    public function site(): string
    {
        return $this->site;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function isOpen(): bool
    {
        return $this->state === self::IN_REVIEW;
    }

    public function submittedBy(): string
    {
        return $this->submittedBy;
    }

    public function submittedAt(): ?CarbonImmutable
    {
        return $this->submittedAt;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function updatedAt(): ?CarbonImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<int, array{handle: string, title: string}>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function history(): Collection
    {
        return collect($this->history);
    }

    /**
     * The handles of the steps approved so far, in this round.
     *
     * @return array<int, string>
     */
    public function approvedSteps(): array
    {
        return $this->history()->where('type', 'approved')->pluck('step')->values()->all();
    }

    /**
     * The step waiting for approval, or null when there's none.
     *
     * @return array{handle: string, title: string}|null
     */
    public function currentStep(): ?array
    {
        if (! $this->isOpen()) {
            return null;
        }

        $approved = $this->approvedSteps();

        foreach ($this->steps as $step) {
            if (! in_array($step['handle'], $approved, true)) {
                return $step;
            }
        }

        return null;
    }

    public function isLastStep(string $handle): bool
    {
        return ($this->steps[array_key_last($this->steps)]['handle'] ?? null) === $handle;
    }

    /**
     * Who approved this round so far.
     *
     * @return array<int, string>
     */
    public function approvers(): array
    {
        return $this->history()->where('type', 'approved')->pluck('user')->map(fn ($user) => (string) $user)->values()->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Changes (called by the SignOff service)
    |--------------------------------------------------------------------------
    */

    public function submit(string $user, string $fingerprint, ?string $note): static
    {
        $this->state = self::IN_REVIEW;
        $this->submittedBy = $user;
        $this->submittedAt = CarbonImmutable::now();
        $this->fingerprint = $fingerprint;
        $this->note = $note;

        return $this->record('submitted', $user, $note);
    }

    public function approve(string $step, string $user, ?string $comment): static
    {
        return $this->record('approved', $user, $comment, $step);
    }

    public function reject(string $step, string $user, string $comment): static
    {
        $this->state = self::REJECTED;

        return $this->record('rejected', $user, $comment, $step);
    }

    public function withdraw(string $user, ?string $comment = null): static
    {
        $this->state = self::WITHDRAWN;

        return $this->record('withdrawn', $user, $comment);
    }

    public function markPublished(string $user): static
    {
        $this->state = self::PUBLISHED;

        return $this->record('published', $user);
    }

    public function markOutdated(?string $user): static
    {
        $this->state = self::OUTDATED;

        return $this->record('changed', (string) $user);
    }

    public function markSkipped(string $user, ?string $message): static
    {
        $this->state = self::SKIPPED;
        $this->submittedBy = $user;
        $this->submittedAt = CarbonImmutable::now();

        return $this->record('published_without_sign_off', $user, $message);
    }

    protected function record(string $type, string $user, ?string $comment = null, ?string $step = null): static
    {
        $this->history[] = array_filter([
            'type' => $type,
            'step' => $step,
            'user' => $user,
            'at' => static::stamp(CarbonImmutable::now()),
            'comment' => $comment !== null && $comment !== '' ? $comment : null,
        ], fn ($value) => $value !== null);

        return $this;
    }

    public function touch(): static
    {
        $this->updatedAt = CarbonImmutable::now();

        return $this;
    }

    public function fileData(): array
    {
        return array_filter([
            'entry' => $this->entry,
            'collection' => $this->collection,
            'site' => $this->site,
            'state' => $this->state,
            'submitted_by' => $this->submittedBy ?: null,
            'submitted_at' => static::stamp($this->submittedAt),
            'note' => $this->note,
            'fingerprint' => $this->fingerprint ?: null,
            'steps' => $this->steps,
            'history' => $this->history,
            'updated_at' => static::stamp($this->updatedAt),
        ], fn ($value) => $value !== null);
    }

    /*
    |--------------------------------------------------------------------------
    | Flat files (file driver)
    |--------------------------------------------------------------------------
    */

    public function initialPath(?string $path = null): static|string|null
    {
        if (func_num_args() === 0) {
            return $this->initialPath;
        }

        $this->initialPath = $path;

        return $this;
    }

    public function path(): string
    {
        return rtrim(Stache::store('sign-off-submissions')->directory(), '/').'/'.$this->id.'.yaml';
    }

    public function writeFile(): void
    {
        File::put($this->path(), YAML::dump($this->fileData()));
    }

    public function deleteFile(): void
    {
        File::delete($this->path());
    }
}
