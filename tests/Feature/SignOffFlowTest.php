<?php

namespace NineteenNinetyFour\SignOff\Tests\Feature;

use Illuminate\Support\Facades\Event;
use NineteenNinetyFour\SignOff\Events\EntryApproved;
use NineteenNinetyFour\SignOff\Events\EntryRejected;
use NineteenNinetyFour\SignOff\Events\EntrySignedOff;
use NineteenNinetyFour\SignOff\Events\EntrySubmitted;
use NineteenNinetyFour\SignOff\SignOffException;
use NineteenNinetyFour\SignOff\Submission;
use NineteenNinetyFour\SignOff\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Revision;

/**
 * An entry's way through sign-off, with the file driver here and the
 * database driver in DatabaseSignOffFlowTest.
 */
class SignOffFlowTest extends TestCase
{
    #[Test]
    public function a_new_page_goes_through_every_step_and_the_last_approval_publishes_it(): void
    {
        Event::fake([EntrySubmitted::class, EntryApproved::class, EntrySignedOff::class]);
        $page = $this->draft();

        $submission = $this->signOff()->submit($this->author(), $page, 'Ready for checks');
        $this->assertSame(Submission::IN_REVIEW, $submission->state());
        $this->assertSame('legal', $submission->currentStep()['handle']);
        Event::assertDispatched(EntrySubmitted::class);

        $this->signOff()->approve($this->lawyer(), $page, 'Fine by legal');
        $this->assertSame('publisher', $this->signOff()->open($page)->currentStep()['handle']);
        $this->assertFalse($this->fresh($page)->published());

        $submission = $this->signOff()->approve($this->publisher(), $page);

        $this->assertSame(Submission::PUBLISHED, $submission->state());
        $this->assertNull($this->signOff()->open($page));
        $this->assertTrue($this->fresh($page)->published());
        $this->assertSame(['submitted', 'approved', 'approved', 'published'], $submission->history()->pluck('type')->all());
        $this->assertSame(['lawyer', 'publisher'], $submission->approvers());
        Event::assertDispatchedTimes(EntryApproved::class, 2);
        Event::assertDispatched(EntrySignedOff::class);

        $revision = Revision::whereKey('collections/pages/default/'.$page->id())->last();
        $this->assertSame('publish', $revision->action());
        $this->assertSame('Signed off: Legal, Publisher', $revision->message());
        $this->assertSame('publisher', $revision->user()->id());
    }

    #[Test]
    public function changes_to_a_live_page_stay_in_the_working_copy_until_signed_off(): void
    {
        $page = $this->changedLivePage();
        $this->assertSame('Old opening hours.', $page->get('body'));

        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->approve($this->lawyer(), $page);
        $this->assertSame('Old opening hours.', $this->fresh($page)->get('body'));

        $this->signOff()->approve($this->publisher(), $page);

        $live = $this->fresh($page);
        $this->assertSame('New opening hours.', $live->get('body'));
        $this->assertFalse($live->hasWorkingCopy());
    }

    #[Test]
    public function steps_are_approved_in_order_by_the_right_people(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);

        // The publisher can't approve Legal's step.
        $this->assertFalse($this->signOff()->canReview($this->publisher(), $page));
        $this->expectExceptionObject(new SignOffException('This entry is waiting for Legal, and you can\'t approve that step.'));
        $this->signOff()->approve($this->publisher(), $page);
    }

    #[Test]
    public function an_author_cannot_approve_their_own_submission_unless_allowed(): void
    {
        $lawyer = $this->lawyer();
        $page = $this->draft();
        $this->signOff()->submit($lawyer, $page);

        $this->assertSame('You submitted this entry, so someone else needs to review it.', $this->signOff()->whyCannotReview($lawyer, $page));

        config(['sign-off.self_approval' => true]);
        $this->assertTrue($this->signOff()->canReview($lawyer, $page));
    }

    #[Test]
    public function super_users_can_approve_any_step(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);

        $this->signOff()->approve($this->admin(), $page);
        $this->signOff()->approve($this->admin(), $page);

        $this->assertTrue($this->fresh($page)->published());
    }

    #[Test]
    public function a_rejection_needs_a_reason_and_sends_the_entry_back(): void
    {
        Event::fake([EntryRejected::class]);
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);

        try {
            $this->signOff()->reject($this->lawyer(), $page, '  <b></b> ');
            $this->fail('A rejection without a reason was accepted.');
        } catch (SignOffException $e) {
            $this->assertSame('Say what needs to change.', $e->getMessage());
        }

        $submission = $this->signOff()->reject($this->lawyer(), $page, 'Remove the <b>pricing</b> claim.');

        $this->assertSame(Submission::REJECTED, $submission->state());
        $this->assertSame('Remove the pricing claim.', $submission->history()->last()['comment']);
        $this->assertNull($this->signOff()->open($page));
        $this->assertFalse($this->fresh($page)->published());
        Event::assertDispatched(EntryRejected::class, fn ($event) => $event->step === 'legal' && $event->reason === 'Remove the pricing claim.');
    }

    #[Test]
    public function after_a_rejection_the_author_edits_and_submits_again_as_a_new_round(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->approve($this->lawyer(), $page);
        $this->signOff()->reject($this->publisher(), $page, 'Shorter, please.');

        $page = $this->fresh($page);
        $page->set('body', 'Shorter.')->save();
        $second = $this->signOff()->submit($this->author(), $this->fresh($page));

        // Every step again: the earlier approval doesn't carry over.
        $this->assertSame('legal', $second->currentStep()['handle']);
        $this->assertCount(2, $this->signOff()->history($page));
        $this->assertSame([Submission::IN_REVIEW, Submission::REJECTED], $this->signOff()->history($page)->map->state()->all());
    }

    #[Test]
    public function only_the_submitter_or_a_super_user_can_withdraw(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);

        $this->assertSame('Only the person who submitted this entry can withdraw it.', $this->signOff()->whyCannotWithdraw($this->lawyer(), $page));
        $this->assertNull($this->signOff()->whyCannotWithdraw($this->admin(), $page));

        $submission = $this->signOff()->withdraw($this->author(), $page);
        $this->assertSame(Submission::WITHDRAWN, $submission->state());
        $this->assertNull($this->signOff()->open($page));
    }

    #[Test]
    public function there_must_be_something_to_review(): void
    {
        $page = $this->changedLivePage();
        $this->signOff()->submit($this->author(), $page);
        $this->assertSame('This entry is already in review.', $this->signOff()->whyCannotSubmit($this->author(), $page));

        $this->signOff()->approve($this->lawyer(), $page);
        $this->signOff()->approve($this->publisher(), $page);

        // Published, no working copy: nothing new to sign off.
        $this->assertSame('There are no changes to review: edit and save the entry first.', $this->signOff()->whyCannotSubmit($this->author(), $this->fresh($page)));
    }

    #[Test]
    public function content_changed_after_submission_cannot_be_approved(): void
    {
        $page = $this->changedLivePage();
        $this->signOff()->submit($this->author(), $page);

        // Someone the lock doesn't stop (code, here) changes the working copy.
        $changed = $this->fresh($page)->fromWorkingCopy()->set('body', 'Sneaky change.');
        $changed->makeWorkingCopy()->save();

        $this->assertNull($this->signOff()->open($page), 'The change should have made the submission outdated.');
        $this->assertSame(Submission::OUTDATED, $this->signOff()->history($page)->first()->state());

        $this->expectException(SignOffException::class);
        $this->signOff()->approve($this->lawyer(), $page);
    }

    #[Test]
    public function an_entry_submitted_straight_after_it_was_made_can_still_be_approved_later(): void
    {
        // Submitted from code, in the same process that made it: the
        // in-memory entry hasn't got the blueprint key its file has.
        $page = \Statamic\Facades\Entry::make()->collection('pages')->slug('returns')->published(false)->data(['title' => 'Returns', 'body' => '30 days.']);
        $page->save();
        $this->signOff()->submit($this->author(), $page);

        // Later, read back from its file.
        \Statamic\Facades\Stache::store('entries')->clear();
        $fromDisk = \Statamic\Facades\Entry::find($page->id());

        $this->signOff()->approve($this->lawyer(), $fromDisk);
        $this->signOff()->approve($this->publisher(), \Statamic\Facades\Entry::find($page->id()));

        $this->assertTrue(\Statamic\Facades\Entry::find($page->id())->published());
    }

    #[Test]
    public function collections_without_steps_are_left_alone(): void
    {
        $story = $this->draft('Launch', 'news');

        $this->assertFalse($this->signOff()->enforced($story));
        $this->assertSame('This collection doesn\'t need sign-off.', $this->signOff()->whyCannotSubmit($this->author(), $story));
        $this->assertTrue($this->publisher()->can('publish', $story));
    }

    #[Test]
    public function notes_and_comments_are_plain_text(): void
    {
        $page = $this->draft();
        $submission = $this->signOff()->submit($this->author(), $page, '<script>alert(1)</script>Please check the <i>prices</i>');

        $this->assertSame('alert(1)Please check the prices', $submission->note());
    }
}
