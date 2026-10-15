<?php

namespace NineteenNinetyFour\SignOff\Tests\Feature;

use Inertia\Testing\AssertableInertia;
use NineteenNinetyFour\SignOff\Panel;
use NineteenNinetyFour\SignOff\Rules;
use NineteenNinetyFour\SignOff\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Mistakes in the setup are found, explained to the people who can fix
 * them, and never quietly let entries skip a step.
 */
class SetupTest extends TestCase
{
    protected function rules(): Rules
    {
        return app(Rules::class);
    }

    protected function messages(): array
    {
        return collect($this->rules()->problems())->map(fn ($problem) => ($problem['collection'] ? "{$problem['collection']}: " : '').$problem['message'])->all();
    }

    /**
     * Docs: set up for sign-off, but without revisions.
     */
    protected function docs(): \Statamic\Contracts\Entries\Entry
    {
        Collection::make('docs')->routes('/docs/{slug}')->revisionsEnabled(false)->save();
        config(['sign-off.collections.docs' => ['steps' => [['handle' => 'review', 'approvers' => 'publishers']]]]);

        $entry = Entry::make()->collection('docs')->slug('setup')->published(false)->data(['title' => 'Setup']);
        $entry->save();

        return $entry;
    }

    #[Test]
    public function a_working_setup_has_no_problems(): void
    {
        $this->assertSame([], $this->rules()->problems());
        $this->artisan('sign-off:check')->assertSuccessful()->expectsOutputToContain('Sign-off is set up and running.');
    }

    #[Test]
    public function what_stops_sign_off_is_named(): void
    {
        $this->docs();
        config([
            'sign-off.collections.missing' => ['steps' => [['handle' => 'one']]],
            'sign-off.collections.news' => ['steps' => []],
        ]);

        $this->assertSame([
            'docs: Revisions are off for this collection, so sign-off isn\'t running for it. Turn on revisions in the collection\'s settings.',
            'missing: There is no collection with this handle.',
            'news: It has no steps, so sign-off isn\'t running for it.',
        ], $this->messages());

        config(['statamic.editions.pro' => false, 'statamic.revisions.enabled' => false]);
        $this->assertSame('Sign-off needs Statamic Pro. Until then, entries are published as usual.', $this->messages()[0]);
        $this->assertStringStartsWith('Revisions are off, so sign-off isn\'t running.', $this->messages()[1]);
    }

    #[Test]
    public function a_step_without_a_handle_still_has_to_be_approved(): void
    {
        config(['sign-off.collections.pages.steps' => [
            ['title' => 'Legal review', 'approvers' => ['roles' => ['legal']]],
            ['handle' => 'publisher', 'approvers' => 'publishers'],
        ]]);
        $page = $this->draft();

        $this->signOff()->submit($this->author(), $page);
        $this->assertSame('legal_review', $this->signOff()->open($page)->currentStep()['handle']);
        $this->assertFalse($this->signOff()->canReview($this->publisher(), $page));
        $this->assertStringContainsString('Step 1 (Legal review) has no handle, so it uses "legal_review"', $this->messages()[0]);
    }

    #[Test]
    public function two_steps_with_one_handle_are_both_needed(): void
    {
        config(['sign-off.collections.pages.steps' => [
            ['handle' => 'check', 'title' => 'Legal', 'approvers' => ['roles' => ['legal']]],
            ['handle' => 'check', 'title' => 'Publisher', 'approvers' => 'publishers'],
        ]]);
        $page = $this->draft();

        $this->signOff()->submit($this->author(), $page);
        $this->signOff()->approve($this->lawyer(), $page);

        // Not published: the second "check" is a step of its own.
        $this->assertFalse($this->fresh($page)->published());
        $this->assertSame(['handle' => 'check_2', 'title' => 'Publisher'], $this->signOff()->open($page)->currentStep());
        $this->assertStringContainsString('Two steps have the handle "check"', $this->messages()[0]);
    }

    #[Test]
    public function a_broken_step_does_not_break_the_control_panel(): void
    {
        config(['sign-off.collections.pages.steps' => ['legal', ['handle' => 'publisher']]]);
        $page = $this->draft();

        $this->actingAs($this->publisher())->get(cp_route('collections.entries.edit', ['pages', $page->id()]))->assertOk();
        $this->assertSame(['publisher'], array_map(fn ($step) => $step->handle, $this->rules()->steps('pages')));
        $this->assertSame('pages: Step 1 isn\'t a list of settings, so it\'s ignored.', $this->messages()[0]);
    }

    #[Test]
    public function approvers_that_match_nobody_are_named(): void
    {
        config(['sign-off.collections.pages.steps' => [
            ['handle' => 'a', 'title' => 'A', 'approvers' => ['roles' => ['legal', 'lawyers'], 'groups' => ['board'], 'users' => ['nobody@example.com'], 'role' => 'x']],
            ['handle' => 'b', 'title' => 'B', 'approvers' => ['legal']],
            ['handle' => 'c', 'title' => 'C', 'approvers' => 'publisher'],
        ]]);

        $this->assertSame([
            'pages: A: there is no role "lawyers".',
            'pages: A: there is no user group "board".',
            'pages: A: there is no user "nobody@example.com".',
            'pages: A: "role" isn\'t a kind of approver. Use roles, groups, users, permission or publishers.',
            'pages: B: approvers needs to say what each name is, like [\'roles\' => [\'legal\']]. Only super users can approve it.',
            'pages: C: approvers should be "publishers" or a list of roles, groups, users or a permission, not "publisher". Only super users can approve it.',
        ], $this->messages());

        // Until it's fixed, only super users can approve "C".
        $this->assertFalse($this->rules()->step('pages', 'c')->canBeApprovedBy($this->publisher(), $this->draft()));
    }

    #[Test]
    public function people_who_can_fix_it_see_why_it_isnt_running_on_the_entry(): void
    {
        $doc = $this->docs();

        $this->assertNull(app(Panel::class)->for($doc, $this->publisher())['problems'][0] ?? null);

        $panel = app(Panel::class)->for($doc, $this->admin());
        $this->assertSame('inactive', $panel['state']);
        $this->assertStringStartsWith('Revisions are off for this collection', $panel['problems'][0]);

        $sidebar = function ($user) use ($doc) {
            $response = $this->actingAs($user)->get(cp_route('collections.entries.edit', ['docs', $doc->id()]))->assertOk();

            return collect(AssertableInertia::fromTestResponse($response)->toArray()['props']['blueprint']['tabs'])
                ->flatMap(fn ($tab) => collect($tab['sections'])->flatMap(fn ($section) => $section['fields']))
                ->pluck('handle')->all();
        };

        // (The editor first: within one test, requests share the blueprint.)
        $this->assertNotContains('sign_off', $sidebar($this->user('docs-editor', 'docs-editor')));
        $this->assertContains('sign_off', $sidebar($this->admin()));
    }

    #[Test]
    public function the_review_page_warns_people_who_can_fix_it(): void
    {
        $this->docs();

        $response = $this->actingAs($this->admin())->get(cp_route('sign-off.index'))->assertOk();
        AssertableInertia::fromTestResponse($response)
            ->where('problems.0.collection', 'Docs')
            ->where('problems.0.blocking', true);

        $response = $this->actingAs($this->author())->get(cp_route('sign-off.index'))->assertOk();
        AssertableInertia::fromTestResponse($response)->where('problems', []);
    }

    #[Test]
    public function the_check_command_fails_when_something_is_wrong(): void
    {
        $this->docs();

        $this->artisan('sign-off:check')
            ->assertFailed()
            ->expectsOutputToContain('docs (not running)')
            ->expectsOutputToContain('Revisions are off for this collection');
    }
}
