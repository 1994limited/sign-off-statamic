<?php

namespace NineteenNinetyFour\SignOff\Tests\Feature;

use Inertia\Testing\AssertableInertia;
use NineteenNinetyFour\SignOff\Enforcement;
use NineteenNinetyFour\SignOff\Submission;
use NineteenNinetyFour\SignOff\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Action;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Sign-off's rules hold in Statamic's own Control Panel: publishing,
 * editing, creating, and the actions.
 */
class EnforcementTest extends TestCase
{
    protected function action(string $handle, $entry, array $values = [])
    {
        return $this->postJson(cp_route('collections.entries.actions.run', 'pages'), [
            'action' => $handle,
            'selections' => [$entry->id()],
            'values' => $values,
            'context' => ['view' => 'form'],
        ]);
    }

    #[Test]
    public function a_publisher_cannot_publish_a_covered_entry_directly(): void
    {
        $page = $this->draft();

        $this->assertFalse($this->publisher()->can('publish', $page));
        $this->actingAs($this->publisher())
            ->postJson(cp_route('collections.entries.published.store', ['pages', $page->id()]))
            ->assertForbidden();

        $this->assertFalse($this->fresh($page)->published());
    }

    #[Test]
    public function new_entries_in_covered_collections_start_unpublished(): void
    {
        $collection = Collection::find('pages');

        $this->assertFalse($this->publisher()->can('publish', [\Statamic\Contracts\Entries\Entry::class, $collection]));
        $this->assertTrue($this->publisher()->can('publish', [\Statamic\Contracts\Entries\Entry::class, Collection::find('news')]));
    }

    #[Test]
    public function skipping_sign_off_is_allowed_with_the_permission_and_recorded(): void
    {
        $skipper = $this->user('skipper', 'skipper');
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);

        $this->assertTrue($skipper->can('publish', $page));
        $this->actingAs($skipper)
            ->postJson(cp_route('collections.entries.published.store', ['pages', $page->id()]), ['message' => 'Urgent fix'])
            ->assertOk();

        $this->assertTrue($this->fresh($page)->published());
        $history = $this->signOff()->history($page);
        $this->assertSame([Submission::SKIPPED, Submission::OUTDATED], $history->map->state()->all());
        $this->assertSame('skipper', $history->first()->submittedBy());
        $this->assertSame('Urgent fix', $history->first()->history()->last()['comment']);
    }

    #[Test]
    public function super_users_can_skip_unless_turned_off(): void
    {
        $page = $this->draft();
        $this->assertTrue($this->admin()->can('publish', $page));

        config(['sign-off.super_users_can_skip' => false]);
        $this->assertFalse($this->admin()->can('publish', $page));
    }

    #[Test]
    public function taking_a_page_down_follows_statamics_usual_rules(): void
    {
        $page = Entry::make()->collection('pages')->slug('old')->published(true)->data(['title' => 'Old']);
        $page->save();

        $this->actingAs($this->publisher())
            ->postJson(cp_route('collections.entries.published.destroy', ['pages', $page->id()]))
            ->assertOk();
        $this->assertFalse($this->fresh($page)->published());

        $this->actingAs($this->author())
            ->postJson(cp_route('collections.entries.published.destroy', ['pages', $page->id()]))
            ->assertForbidden();
    }

    #[Test]
    public function an_entry_in_review_is_read_only_for_everyone_until_it_comes_back(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);

        foreach ([$this->author(), $this->lawyer(), $this->admin()] as $user) {
            $this->assertFalse($user->can('edit', $page), "{$user->id()} could edit an entry in review.");
        }

        $this->actingAs($this->author())
            ->get(cp_route('collections.entries.edit', ['pages', $page->id()]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('readOnly', true)->etc());

        $this->actingAs($this->author())
            ->patchJson(cp_route('collections.entries.update', ['pages', $page->id()]), ['title' => 'Changed'])
            ->assertForbidden();

        $this->signOff()->withdraw($this->author(), $page);
        $this->assertTrue($this->author()->can('edit', $page));
    }

    #[Test]
    public function the_lock_can_be_turned_off(): void
    {
        config(['sign-off.lock_during_review' => false]);
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);

        $this->assertTrue($this->author()->can('edit', $page));
    }

    #[Test]
    public function the_whole_flow_runs_through_the_entry_actions(): void
    {
        $page = $this->draft();

        $this->actingAs($this->author())->action('submit_for_sign_off', $page, ['note' => 'Ready'])
            ->assertOk()->assertJsonPath('message', 'Sent for sign-off. Waiting for Legal.');

        // Not the publisher's turn yet.
        $this->actingAs($this->publisher())->action('approve_sign_off', $page)->assertForbidden();

        $this->actingAs($this->lawyer())->action('approve_sign_off', $page, ['comment' => 'OK'])
            ->assertOk()->assertJsonPath('message', 'Approved. Waiting for Publisher.');

        $this->actingAs($this->publisher())->action('approve_sign_off', $page)
            ->assertOk()->assertJsonPath('message', 'Signed off and published.');

        $this->assertTrue($this->fresh($page)->published());
    }

    #[Test]
    public function sending_back_needs_a_reason(): void
    {
        $page = $this->draft();
        $this->signOff()->submit($this->author(), $page);

        $this->actingAs($this->lawyer())->action('reject_sign_off', $page)->assertUnprocessable();
        $this->actingAs($this->lawyer())->action('reject_sign_off', $page, ['reason' => 'Check the dates.'])
            ->assertOk()->assertJsonPath('message', 'Sent back to the author.');
    }

    #[Test]
    public function the_right_actions_are_offered_at_each_stage(): void
    {
        $page = $this->draft();

        // What the entry's menu offers this user, as the Control Panel asks
        // (Statamic's own actions check the signed-in user).
        $offered = function ($user) use ($page) {
            $this->actingAs($user);

            return Action::for($this->fresh($page), ['collection' => 'pages', 'view' => 'form'])
                ->filter(fn ($action) => str_contains($action->handle(), 'sign_off'))
                ->filter(fn ($action) => $action->authorize($user, $this->fresh($page)))
                ->map(fn ($action) => $action->handle())
                ->values()->all();
        };

        $this->assertSame(['submit_for_sign_off'], $offered($this->author()));
        $this->signOff()->submit($this->author(), $page);

        $this->assertSame(['withdraw_sign_off'], $offered($this->author()));
        $this->assertSame(['approve_sign_off', 'reject_sign_off'], $offered($this->lawyer()));
        $this->assertSame([], $offered($this->publisher()));
    }

    #[Test]
    public function without_statamic_pro_nothing_is_enforced(): void
    {
        $page = $this->draft();
        config(['statamic.editions.pro' => false]);

        $this->assertFalse($this->signOff()->enforced($page));
        $this->assertNotFalse(app(Enforcement::class)->check($this->publisher(), 'publish', [$page]));
    }
}
