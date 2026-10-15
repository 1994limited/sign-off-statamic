<?php

namespace NineteenNinetyFour\SignOff\Tests;

use NineteenNinetyFour\SignOff\ServiceProvider;
use NineteenNinetyFour\SignOff\SignOff;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\FakesRoles;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use FakesRoles, PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    /** The storage driver the test runs with. */
    protected string $driver = 'file';

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.revisions.enabled', true);
        $app['config']->set('statamic.revisions.path', __DIR__.'/__fixtures__/dev-null/revisions');
        $app['config']->set('sign-off.driver', $this->driver);
        $app['config']->set('sign-off.collections', [
            'pages' => ['steps' => [
                ['handle' => 'legal', 'title' => 'Legal', 'approvers' => ['roles' => ['legal']]],
                ['handle' => 'publisher', 'title' => 'Publisher', 'approvers' => 'publishers'],
            ]],
        ]);
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('view.paths', [__DIR__.'/__fixtures__/views']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->driver === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        Collection::make('pages')->routes('/{slug}')->revisionsEnabled(true)->save();
        Collection::make('news')->routes('/news/{slug}')->revisionsEnabled(true)->save();

        $pages = ['access cp', 'view pages entries', 'edit pages entries', 'create pages entries', 'edit other authors pages entries'];
        $this->setTestRoles([
            'editor' => $pages,
            'legal' => $pages,
            'publisher' => [...$pages, 'publish pages entries', 'publish other authors pages entries', 'view news entries', 'edit news entries', 'publish news entries'],
            'skipper' => [...$pages, 'publish pages entries', 'publish without sign-off'],
        ]);
    }

    protected function user(string $name, ?string $role = null, bool $super = false): UserContract
    {
        $user = User::make()->email("{$name}@example.com")->id($name)->set('name', ucfirst($name));

        if ($role) {
            $user->assignRole($role);
        }

        if ($super) {
            $user->makeSuper();
        }

        $user->save();

        return $user;
    }

    protected function author(): UserContract
    {
        return User::find('author') ?? $this->user('author', 'editor');
    }

    protected function lawyer(): UserContract
    {
        return User::find('lawyer') ?? $this->user('lawyer', 'legal');
    }

    protected function publisher(): UserContract
    {
        return User::find('publisher') ?? $this->user('publisher', 'publisher');
    }

    protected function admin(): UserContract
    {
        return User::find('admin') ?? $this->user('admin', null, true);
    }

    /**
     * A page that was never published, as an author starts one.
     */
    protected function draft(string $title = 'About us', string $collection = 'pages'): EntryContract
    {
        $entry = Entry::make()->collection($collection)->slug(str($title)->slug())->published(false)->data(['title' => $title, 'body' => 'First draft.']);
        $entry->save();

        return $entry;
    }

    /**
     * A published page with changes saved in its working copy.
     */
    protected function changedLivePage(string $title = 'Contact', array $changes = ['body' => 'New opening hours.']): EntryContract
    {
        $entry = Entry::make()->collection('pages')->slug(str($title)->slug())->published(true)->data(['title' => $title, 'body' => 'Old opening hours.']);
        $entry->save();

        // As the Control Panel does: the changes go to a working copy, and
        // the live entry is left as it was. (A copy, since tests share the
        // cached entry object between finds.)
        $changed = clone Entry::find($entry->id());
        $changed->merge($changes);
        $changed->makeWorkingCopy()->user($this->author())->save();

        return Entry::find($entry->id());
    }

    protected function signOff(): SignOff
    {
        return app(SignOff::class);
    }

    protected function fresh(EntryContract $entry): EntryContract
    {
        Blink::flush();

        return Entry::find($entry->id());
    }
}
