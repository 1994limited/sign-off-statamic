<?php

namespace NineteenNinetyFour\SignOff\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use NineteenNinetyFour\SignOff\Submission;

/**
 * Where submissions and their history are kept.
 */
interface SubmissionRepository
{
    public function find(string $id): ?Submission;

    /**
     * Every round of review for an entry, newest first.
     *
     * @return Collection<int, Submission>
     */
    public function forEntry(string $entryId): Collection;

    /**
     * The entry's submission in review, if any.
     */
    public function openFor(string $entryId): ?Submission;

    /**
     * Every submission in review, oldest first.
     *
     * @return Collection<int, Submission>
     */
    public function open(): Collection;

    /**
     * For the Control Panel: newest change first, optionally in one state
     * or collection.
     */
    public function paginate(int $perPage = 25, int $page = 1, ?string $state = null, ?string $collection = null): LengthAwarePaginator;

    /**
     * @return Collection<int, Submission>
     */
    public function all(): Collection;

    /**
     * Store the submission as it is, dates included.
     */
    public function save(Submission $submission): void;

    public function delete(Submission $submission): void;
}
