<?php

namespace NineteenNinetyFour\SignOff\Database;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use NineteenNinetyFour\SignOff\Contracts\SubmissionRepository;
use NineteenNinetyFour\SignOff\Submission;

class EloquentSubmissionRepository implements SubmissionRepository
{
    public function find(string $id): ?Submission
    {
        $model = SubmissionModel::find($id);

        return $model ? $this->fromModel($model) : null;
    }

    public function forEntry(string $entryId): Collection
    {
        return SubmissionModel::where('entry_id', $entryId)->orderByDesc('submitted_at')->get()
            ->map(fn (SubmissionModel $model) => $this->fromModel($model))->values();
    }

    public function openFor(string $entryId): ?Submission
    {
        $model = SubmissionModel::where('entry_id', $entryId)->where('state', Submission::IN_REVIEW)->orderByDesc('submitted_at')->first();

        return $model ? $this->fromModel($model) : null;
    }

    public function open(): Collection
    {
        return SubmissionModel::where('state', Submission::IN_REVIEW)->orderBy('submitted_at')->get()
            ->map(fn (SubmissionModel $model) => $this->fromModel($model))->values();
    }

    public function paginate(int $perPage = 25, int $page = 1, ?string $state = null, ?string $collection = null): LengthAwarePaginator
    {
        $paginator = SubmissionModel::query()
            ->when($state, fn ($query) => $query->where('state', $state))
            ->when($collection, fn ($query) => $query->where('collection', $collection))
            ->orderByDesc('updated_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return $paginator->setCollection($paginator->getCollection()->map(fn (SubmissionModel $model) => $this->fromModel($model)));
    }

    public function all(): Collection
    {
        return SubmissionModel::all()->map(fn (SubmissionModel $model) => $this->fromModel($model))->values();
    }

    public function save(Submission $submission): void
    {
        $data = $submission->fileData();

        SubmissionModel::query()->updateOrCreate(['id' => $submission->id()], [
            'entry_id' => $data['entry'],
            'collection' => $data['collection'],
            'site' => $data['site'] ?? '',
            'state' => $data['state'],
            'submitted_by' => $data['submitted_by'] ?? null,
            'submitted_at' => $submission->submittedAt()?->format('Y-m-d H:i:s.u'),
            'note' => $data['note'] ?? null,
            'fingerprint' => $data['fingerprint'] ?? null,
            'steps' => $data['steps'] ?? [],
            'history' => $data['history'] ?? [],
            'updated_at' => $submission->updatedAt()?->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function delete(Submission $submission): void
    {
        SubmissionModel::query()->whereKey($submission->id())->delete();
    }

    protected function fromModel(SubmissionModel $model): Submission
    {
        return Submission::fromArray($model->id, [
            'entry' => $model->entry_id,
            'collection' => $model->collection,
            'site' => $model->site,
            'state' => $model->state,
            'submitted_by' => $model->submitted_by,
            'submitted_at' => $model->submitted_at,
            'note' => $model->note,
            'fingerprint' => $model->fingerprint,
            'steps' => $model->steps,
            'history' => $model->history,
            'updated_at' => $model->updated_at,
        ]);
    }
}
