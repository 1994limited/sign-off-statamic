<?php

namespace NineteenNinetyFour\SignOff\Tests\Feature;

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use NineteenNinetyFour\SignOff\ControlPanelSettings;
use NineteenNinetyFour\SignOff\Rules;
use NineteenNinetyFour\SignOff\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\UserGroup;

/**
 * Tools → Addons → Sign-off → Settings.
 */
class SettingsPageTest extends TestCase
{
    protected function tearDown(): void
    {
        File::delete(resource_path('addons/sign-off-statamic.yaml'));

        parent::tearDown();
    }

    protected function save(array $values)
    {
        return $this->actingAs($this->admin())->patchJson(cp_route('addons.settings.update', 'sign-off-statamic'), $values);
    }

    protected function settings(): ControlPanelSettings
    {
        return app(ControlPanelSettings::class);
    }

    #[Test]
    public function the_page_starts_from_the_config_file(): void
    {
        $response = $this->actingAs($this->admin())->get(cp_route('addons.settings.edit', 'sign-off-statamic'))->assertOk();
        $values = AssertableInertia::fromTestResponse($response)->toArray()['props']['values'];

        $this->assertSame('pages', $values['collections'][0]['collection'][0] ?? $values['collections'][0]['collection']);
        $this->assertSame(['Legal', 'Publisher'], array_column($values['collections'][0]['steps'], 'title'));
        $this->assertSame(['legal'], $values['collections'][0]['steps'][0]['roles']);
        $this->assertTrue($values['collections'][0]['steps'][1]['publishers']);
        $this->assertTrue($values['lock_during_review']);
    }

    #[Test]
    public function only_people_allowed_to_edit_the_settings_can(): void
    {
        $this->actingAs($this->publisher())->get(cp_route('addons.settings.edit', 'sign-off-statamic'))->assertRedirect();
        $this->actingAs($this->publisher())->patchJson(cp_route('addons.settings.update', 'sign-off-statamic'), ['mail' => false])->assertForbidden();
    }

    #[Test]
    public function saved_settings_take_the_place_of_the_config_file(): void
    {
        UserGroup::make('editors')->title('Editors')->save();

        $this->save([
            'collections' => [
                ['collection' => ['news'], 'steps' => [
                    ['title' => 'Desk', 'handle' => 'desk', 'groups' => ['editors']],
                    ['title' => 'Editor', 'handle' => 'editor', 'publishers' => true],
                ]],
            ],
            'self_approval' => true,
            'lock_during_review' => false,
            'super_users_can_skip' => false,
            'unpublish_without_sign_off' => true,
            'mail' => false,
        ])->assertOk();

        $this->assertSame([
            'news' => ['steps' => [
                ['handle' => 'desk', 'title' => 'Desk', 'approvers' => ['groups' => ['editors']]],
                ['handle' => 'editor', 'title' => 'Editor', 'approvers' => 'publishers'],
            ]],
        ], config('sign-off.collections'));
        $this->assertTrue(config('sign-off.self_approval'));
        $this->assertFalse(config('sign-off.lock_during_review'));
        $this->assertFalse(config('sign-off.notifications.mail'));

        // News needs sign-off now, and pages no longer do.
        $story = $this->draft('Launch', 'news');
        $this->assertTrue($this->signOff()->enforced($story));
        $this->assertFalse($this->publisher()->can('publish', $story));
        $this->assertFalse($this->signOff()->enforced($this->draft()));
    }

    #[Test]
    public function saved_settings_are_used_on_every_request(): void
    {
        $this->save(['collections' => [], 'mail' => false])->assertOk();
        config(['sign-off.collections' => ['pages' => ['steps' => [['handle' => 'x']]]], 'sign-off.notifications.mail' => true]);

        // As the next request starts.
        $this->settings()->apply();

        $this->assertSame([], config('sign-off.collections'));
        $this->assertFalse(config('sign-off.notifications.mail'));
    }

    #[Test]
    public function approvers_are_written_as_the_config_file_would_have_them(): void
    {
        $this->assertSame(
            ['pages' => ['steps' => [
                ['handle' => 'a', 'title' => 'A', 'approvers' => ['roles' => ['legal'], 'users' => ['sam'], 'permission' => 'approve legal', 'publishers' => true]],
                ['handle' => 'b', 'title' => 'B', 'approvers' => []],
            ]]],
            $this->settings()->collections([
                ['collection' => 'pages', 'steps' => [
                    ['title' => 'A', 'handle' => 'a', 'publishers' => true, 'roles' => ['legal'], 'users' => ['sam'], 'permission' => ' approve legal '],
                    ['title' => 'B', 'handle' => 'b', 'publishers' => false, 'roles' => []],
                ]],
                ['collection' => null, 'steps' => []],
            ]),
        );
    }

    #[Test]
    public function a_step_with_nobody_named_is_a_setup_problem(): void
    {
        config(['sign-off.collections.pages.steps.0.approvers' => []]);

        $this->assertContains('pages: Legal: nobody is named to approve it, so only super users can.', collect(app(Rules::class)->problems())->map(fn ($problem) => "{$problem['collection']}: {$problem['message']}")->all());
    }
}
