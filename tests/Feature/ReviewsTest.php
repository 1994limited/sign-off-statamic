<?php

namespace NineteenNinetyFour\SignOff\Tests\Feature;

use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use NineteenNinetyFour\SignOff\Reviews;
use NineteenNinetyFour\SignOff\Tests\TestCase;
use NineteenNinetyFour\SignOff\Widgets\SignOffWidget;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Entry;

/**
 * The Sign-off review page and the dashboard widget.
 */
class ReviewsTest extends TestCase
{
    protected function reviews(): Reviews
    {
        return app(Reviews::class);
    }

    protected function titles($submissions): array
    {
        return $submissions->map(fn ($submission) => Entry::find($submission->entryId())->get('title'))->all();
    }

    protected function page($viewer, array $query = []): AssertableInertia
    {
        $response = $this->actingAs($viewer)->get(cp_route('sign-off.index', $query))->assertOk();

        return AssertableInertia::fromTestResponse($response);
    }

    #[Test]
    public function waiting_for_you_lists_what_you_can_approve_now_longest_wait_first(): void
    {
        CarbonImmutable::setTestNow('2026-10-01 09:00');
        $first = $this->draft('First');
        $this->signOff()->submit($this->author(), $first);

        CarbonImmutable::setTestNow('2026-10-02 09:00');
        $second = $this->draft('Second');
        $this->signOff()->submit($this->author(), $second);

        // Approved by legal last of all, so it has waited least for the publisher.
        CarbonImmutable::setTestNow('2026-10-03 09:00');
        $this->signOff()->approve($this->lawyer(), $first);

        $this->assertSame(['Second'], $this->titles($this->reviews()->waitingFor($this->lawyer())));
        $this->assertSame(['First'], $this->titles($this->reviews()->waitingFor($this->publisher())));
        $this->assertSame([], $this->titles($this->reviews()->waitingFor($this->author())));
        $this->assertSame(['Second', 'First'], $this->titles($this->reviews()->waitingFor($this->admin())));
        $this->assertSame(['First', 'Second'], $this->titles($this->reviews()->inReview($this->author())));
    }

    #[Test]
    public function people_only_see_collections_they_can_view(): void
    {
        // Can see news, which doesn't need sign-off, but not pages.
        $outsider = $this->user('reporter', 'reporter');
        $this->signOff()->submit($this->author(), $this->draft());

        $this->assertSame([], $this->reviews()->collectionsFor($outsider));
        $this->actingAs($outsider)->get(cp_route('sign-off.index'))->assertForbidden();
        $this->assertNull((new SignOffWidget)->component());
    }

    #[Test]
    public function the_page_opens_on_what_is_waiting_for_you(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page, 'Check the prices');

        $this->page($this->lawyer())
            ->component('sign-off::Reviews')
            ->where('tab', 'waiting')
            ->where('counts', ['waiting' => 1, 'in_review' => 1])
            ->where('rows.0.entry.title', 'About us')
            ->where('rows.0.entry.edit_url', $page->editUrl())
            ->where('rows.0.step', 'Legal')
            ->where('rows.0.progress', '0 of 2 approved')
            ->where('rows.0.submitted_by', 'Author')
            ->where('rows.0.note', 'Check the prices')
            ->where('rows.0.can_review', true);

        // Nothing for the author to do: they see what's in review instead.
        $this->page($this->author())
            ->where('tab', 'in_review')
            ->where('rows.0.can_review', false);
    }

    #[Test]
    public function history_shows_how_each_round_ended(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->reject($this->lawyer(), $page, 'Shorter.');
        $this->signOff()->submit($this->author(), $this->fresh($page));
        $this->signOff()->approve($this->lawyer(), $page);
        $this->signOff()->approve($this->publisher(), $page);

        $this->page($this->author(), ['tab' => 'history'])
            ->where('pagination.total', 2)
            ->where('rows.0.state', 'published')
            ->where('rows.0.last.by', 'Publisher')
            ->where('rows.1.state', 'rejected')
            ->where('rows.1.last.label', 'Sent back')
            ->where('rows.1.last.step', 'Legal')
            ->where('rows.1.last.comment', 'Shorter.');
    }

    #[Test]
    public function deleted_entries_leave_the_queues_but_stay_in_history(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->reject($this->lawyer(), $page, 'No.');
        $gone = $this->draft('Gone');
        $this->signOff()->submit($this->author(), $gone);
        $gone->delete();

        $this->assertSame([], $this->titles($this->reviews()->waitingFor($this->lawyer())));
        $this->page($this->admin(), ['tab' => 'history'])->where('pagination.total', 1);
    }

    #[Test]
    public function an_author_sees_what_was_sent_back_until_they_submit_again(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->reject($this->lawyer(), $page, 'Shorter.');

        $this->assertSame(['About us'], $this->titles($this->reviews()->sentBackTo($this->author())));

        $this->signOff()->submit($this->author(), $this->fresh($page));

        $this->assertSame([], $this->titles($this->reviews()->sentBackTo($this->author())));
        $this->assertSame(['About us'], $this->titles($this->reviews()->submittedBy($this->author())));
    }

    #[Test]
    public function the_widget_shows_each_persons_part(): void
    {
        foreach (['One', 'Two', 'Three'] as $title) {
            $this->signOff()->submit($this->author(), $this->draft($title));
        }

        $this->actingAs($this->lawyer());
        $widget = new SignOffWidget;
        $widget->setConfig(['limit' => 2]);
        $props = $widget->component()->toArray()['props'];

        $this->assertSame('sign-off-widget', (new SignOffWidget)->component()->toArray()['name']);
        $this->assertCount(2, $props['waiting']);
        $this->assertSame(3, $props['waitingTotal']);
        $this->assertSame([], $props['yours']);

        $this->actingAs($this->author());
        $props = (new SignOffWidget)->component()->toArray()['props'];
        $this->assertSame([], $props['waiting']);
        $this->assertCount(3, $props['yours']);
    }

    #[Test]
    public function a_change_made_in_code_is_described_without_a_name(): void
    {
        $page = $this->changedLivePage();
        $this->signOff()->submit($this->author(), $page);
        $this->fresh($page)->fromWorkingCopy()->set('body', 'Changed in code.')->makeWorkingCopy()->save();

        $this->page($this->admin(), ['tab' => 'history'])
            ->where('rows.0.state', 'outdated')
            ->where('rows.0.last.label', 'Changed after submission')
            ->where('rows.0.last.by', null);
    }
}
