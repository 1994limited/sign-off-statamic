<?php

namespace NineteenNinetyFour\SignOff\Tests\Feature;

use Inertia\Testing\AssertableInertia;
use NineteenNinetyFour\SignOff\Panel;
use NineteenNinetyFour\SignOff\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Sign-off panel in an entry's sidebar.
 */
class PanelTest extends TestCase
{
    protected function panel($entry, $viewer): array
    {
        return app(Panel::class)->for($this->fresh($entry), $viewer);
    }

    /**
     * The sidebar fields of an entry's edit page, as the Control Panel gets them.
     */
    protected function editPage($entry, $viewer): AssertableInertia
    {
        $response = $this->actingAs($viewer)->get(cp_route('collections.entries.edit', [$entry->collectionHandle(), $entry->id()]))->assertOk();

        return AssertableInertia::fromTestResponse($response);
    }

    #[Test]
    public function covered_entries_get_the_panel_at_the_top_of_their_sidebar(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page, 'Check the prices');

        $this->editPage($page, $this->lawyer())
            ->where('blueprint.tabs.1.handle', 'sidebar')
            ->where('blueprint.tabs.1.sections.0.fields.0.handle', 'sign_off')
            ->where('blueprint.tabs.1.sections.0.fields.0.type', 'sign_off')
            ->where('blueprint.tabs.1.sections.0.fields.0.visibility', 'computed')
            ->where('meta.sign_off.panel.state', 'in_review')
            ->where('meta.sign_off.panel.headline', 'Waiting for Legal')
            ->where('meta.sign_off.panel.next', 'It\'s your turn: Approve or Send back from the ••• menu.');
    }

    #[Test]
    public function other_collections_dont_get_it(): void
    {
        $story = $this->draft('Launch', 'news');

        $fields = collect($this->editPage($story, $this->publisher())->toArray()['props']['blueprint']['tabs'])
            ->flatMap(fn ($tab) => collect($tab['sections'])->flatMap(fn ($section) => $section['fields']))
            ->pluck('handle');
        $this->assertNotContains('sign_off', $fields);
    }

    #[Test]
    public function outside_the_control_panel_the_blueprint_is_left_alone(): void
    {
        $page = $this->draft();

        $this->assertFalse($page->blueprint()->hasField('sign_off'));
        $this->assertArrayNotHasKey('sign_off', $page->toAugmentedArray());
    }

    #[Test]
    public function the_panel_is_never_saved_into_the_entry(): void
    {
        config(['sign-off.lock_during_review' => false]);
        $page = $this->draft();

        $this->actingAs($this->author())
            ->patchJson(cp_route('collections.entries.update', ['pages', $page->id()]), [
                'title' => 'About us',
                'body' => 'Second draft.',
                'sign_off' => ['state' => 'published'],
                '_blueprint' => 'pages',
            ])
            ->assertOk();

        $this->assertFalse($this->fresh($page)->data()->has('sign_off'));
    }

    #[Test]
    public function a_new_entry_shows_its_steps_and_how_to_submit(): void
    {
        $panel = $this->panel($this->draft(), $this->author());

        $this->assertSame('none', $panel['state']);
        $this->assertSame('Not submitted yet', $panel['headline']);
        $this->assertSame([['Legal', 'waiting', 'Role: legal'], ['Publisher', 'waiting', 'Anyone who can publish']], array_map(fn ($step) => [$step['title'], $step['status'], $step['approvers']], $panel['steps']));
        $this->assertSame('When it\'s ready, choose Submit for sign-off from the ••• menu.', $panel['next']);
    }

    #[Test]
    public function in_review_it_shows_who_has_approved_and_whos_next(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page, 'Check the prices');
        $this->signOff()->approve($this->lawyer(), $page, 'Looks fine');

        $panel = $this->panel($page, $this->author());

        $this->assertSame('Waiting for Publisher', $panel['headline']);
        $this->assertSame(['done', 'current'], array_column($panel['steps'], 'status'));
        $this->assertSame('Lawyer', $panel['steps'][0]['by']);
        $this->assertSame('Looks fine', $panel['steps'][0]['comment']);
        $this->assertSame('Author', $panel['submitted']['by']);
        $this->assertSame('Check the prices', $panel['submitted']['note']);
        $this->assertStringContainsString('Withdraw from review', $panel['next']);

        // The publisher is told it's their turn; someone else, nothing.
        $this->assertStringContainsString('your turn', $this->panel($page, $this->publisher())['next']);
        $this->assertNull($this->panel($page, $this->lawyer())['next']);
    }

    #[Test]
    public function after_being_sent_back_the_author_sees_why(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->reject($this->lawyer(), $page, 'Remove the pricing claim.');

        $panel = $this->panel($page, $this->author());

        $this->assertSame('rejected', $panel['state']);
        $this->assertSame('Sent back for changes', $panel['headline']);
        $this->assertSame(['by' => 'Lawyer', 'step' => 'Legal', 'reason' => 'Remove the pricing claim.'], array_intersect_key($panel['rejection'], array_flip(['by', 'step', 'reason'])));
        $this->assertSame('When it\'s ready, choose Submit for sign-off from the ••• menu.', $panel['next']);
    }

    #[Test]
    public function earlier_rounds_are_in_the_history(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->reject($this->lawyer(), $page, 'Shorter.');
        $this->signOff()->submit($this->author(), $this->fresh($page));
        $this->signOff()->approve($this->lawyer(), $page);
        $this->signOff()->approve($this->publisher(), $page);

        $panel = $this->panel($page, $this->author());

        $this->assertSame('Signed off and published', $panel['headline']);
        $this->assertSame(['published', 'rejected'], array_column($panel['history'], 'state'));
        $this->assertSame(['Submitted', 'Sent back'], array_column($panel['history'][1]['events'], 'label'));
        $this->assertSame('Shorter.', $panel['history'][1]['events'][1]['comment']);
    }

    #[Test]
    public function people_who_have_left_are_named_kindly(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->reject($this->lawyer(), $page, 'No.');
        $this->lawyer()->delete();

        $this->assertSame('A former user', $this->panel($page, $this->author())['rejection']['by']);
    }
}
