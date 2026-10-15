# Events and the PHP API

## Events

All in `NineteenNinetyFour\SignOff\Events`. Each has the `Statamic\Contracts\Entries\Entry`, the `NineteenNinetyFour\SignOff\Submission` (the round of review), and the `Statamic\Contracts\Auth\User` who acted.

| Event | When | Also has |
| --- | --- | --- |
| `EntrySubmitted` | An entry is submitted | |
| `EntryApproved` | A step is approved (the last one too) | `$step`, the step's handle |
| `EntryRejected` | A step sends it back | `$step`, `$reason` |
| `SubmissionWithdrawn` | The author withdraws it | |
| `EntrySignedOff` | Every step is approved and it's published | |

```php
use Illuminate\Support\Facades\Event;
use NineteenNinetyFour\SignOff\Events\EntrySignedOff;

Event::listen(EntrySignedOff::class, function (EntrySignedOff $event) {
    // Purge a CDN, post to Slack, start a translation…
    $event->entry->url();
    $event->submission->approvers(); // user IDs, in step order
});
```

At the last step, `EntryApproved` is dispatched first, then the entry is published (Statamic's own `EntrySaved` and `RevisionSaved` events), then `EntrySignedOff`.

## The PHP API

`NineteenNinetyFour\SignOff\SignOff` does everything the Control Panel does, with the same rules. Get it from the container:

```php
use NineteenNinetyFour\SignOff\SignOff;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

$signOff = app(SignOff::class);
$entry = Entry::find('…');
$user = User::find('…');
```

### Submitting, approving, sending back

```php
$signOff->submit($user, $entry, 'Ready for checks');   // note optional
$signOff->approve($user, $entry, 'Fine by legal');      // comment optional; the last step publishes
$signOff->reject($user, $entry, 'Shorter, please.');    // reason required
$signOff->withdraw($user, $entry);
```

Each returns the `Submission`. When something isn't allowed, they throw `NineteenNinetyFour\SignOff\SignOffException` with a message for people ("This entry is waiting for Legal, and you can't approve that step."). To ask first:

```php
$signOff->whyCannotSubmit($user, $entry);   // null when it can, or the reason
$signOff->whyCannotReview($user, $entry);
$signOff->whyCannotWithdraw($user, $entry);
$signOff->canReview($user, $entry);         // bool
```

### Reading where an entry is

```php
$signOff->enforced($entry);   // does sign-off apply to it (Pro, steps, revisions)
$signOff->open($entry);       // the Submission in review, or null
$signOff->history($entry);    // every round, newest first
$signOff->isLocked($entry);   // in review and locked
$signOff->hasChanges($entry); // anything to submit
```

### A submission

```php
$submission->state();          // in_review, rejected, withdrawn, published, outdated or skipped
$submission->isOpen();
$submission->currentStep();    // ['handle' => 'legal', 'title' => 'Legal'], or null
$submission->steps();          // the steps as they were when it was submitted
$submission->approvedSteps();  // handles
$submission->approvers();      // user IDs
$submission->submittedBy();    // user ID
$submission->submittedAt();    // CarbonImmutable
$submission->waitingSince();   // when the current step started waiting
$submission->note();
$submission->history();        // every event: type, step, user, at, comment
$submission->entry();
```

The `Submission` constants (`Submission::IN_REVIEW` and so on) name the states.

### Listing submissions

```php
use NineteenNinetyFour\SignOff\Contracts\SubmissionRepository;

$submissions = app(SubmissionRepository::class);

$submissions->open();                         // everything in review, oldest first
$submissions->forEntry($entry->id());         // newest first
$submissions->submittedBy($user->id(), 10);
$submissions->paginate(25, 1, ['rejected'], ['pages']);
```

`$signOff->rules()` answers questions about the config: `covers($collection)`, `steps($collection)`, `problems()`.

## Publishing from code

Sign-off works through Statamic's permission checks, so code that saves or publishes entries directly (`$entry->publish()`, `$entry->save()`) isn't stopped. Use `$signOff->approve()` to publish through sign-off. If code changes content that's in review, the review ends and the entry has to be submitted again. See [What sign-off covers](how-it-works.md#what-sign-off-covers).

## Storage

Bind your own `NineteenNinetyFour\SignOff\Contracts\SubmissionRepository` in a service provider to keep submissions somewhere else. Use the file and database repositories in the package as a guide.
