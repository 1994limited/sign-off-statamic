<?php

namespace NineteenNinetyFour\SignOff;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use NineteenNinetyFour\SignOff\Actions\ApproveSignOff;
use NineteenNinetyFour\SignOff\Actions\RejectSignOff;
use NineteenNinetyFour\SignOff\Actions\SubmitForSignOff;
use NineteenNinetyFour\SignOff\Actions\WithdrawSignOff;
use NineteenNinetyFour\SignOff\Contracts\SubmissionRepository;
use NineteenNinetyFour\SignOff\Database\EloquentSubmissionRepository;
use NineteenNinetyFour\SignOff\Listeners\AddPanel;
use NineteenNinetyFour\SignOff\Listeners\SendNotifications;
use NineteenNinetyFour\SignOff\Listeners\WatchRevisions;
use NineteenNinetyFour\SignOff\Stache\StacheSubmissionRepository;
use NineteenNinetyFour\SignOff\Stache\SubmissionsStore;
use Statamic\Events\AddonSettingsSaved;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\EntrySaved;
use Statamic\Events\RevisionSaved;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\Stache;
use Statamic\Facades\User;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $fieldtypes = [
        Fieldtypes\SignOffPanel::class,
    ];

    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
        'hotFile' => __DIR__.'/../resources/dist/hot',
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
    ];

    protected $widgets = [
        Widgets\SignOffWidget::class,
    ];

    protected $commands = [
        Commands\CheckSetup::class,
    ];

    protected $actions = [
        SubmitForSignOff::class,
        ApproveSignOff::class,
        RejectSignOff::class,
        WithdrawSignOff::class,
        Actions\Unpublish::class,
    ];

    /**
     * The package is 1994/sign-off-statamic; its config and translations
     * are read as plain "sign-off", so they're loaded by hand.
     */
    protected $config = false;

    protected $translations = false;

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/sign-off.php', 'sign-off');

        // The file driver's submissions are cached by the Stache, and
        // Laravel 13's cache unserialises only the classes it is told about.
        $this->registerSerializableClasses([Submission::class, CarbonImmutable::class]);

        $this->app->singleton(SubmissionRepository::class, fn ($app) => $app->make(static::repositoryFor(config('sign-off.driver', 'file'))));
        $this->app->singleton(Rules::class);
        $this->app->singleton(SignOff::class);
        $this->app->bind(Panel::class);
        $this->app->bind(Reviews::class);
    }

    public static function repositoryFor(string $driver): string
    {
        return match ($driver) {
            'file' => StacheSubmissionRepository::class,
            'database' => EloquentSubmissionRepository::class,
            default => throw new InvalidArgumentException("Unknown sign-off driver [{$driver}]. Use file or database."),
        };
    }

    public function bootAddon(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'sign-off');

        // Tools → Addons → Sign-off → Settings. Its blueprint starts from
        // the config file, which saved settings then take the place of.
        $this->registerSettingsBlueprint(fn () => app(ControlPanelSettings::class)->blueprint());
        app(ControlPanelSettings::class)->apply();
        Event::listen(AddonSettingsSaved::class, fn (AddonSettingsSaved $event) => $event->settings->addon()->package() === ControlPanelSettings::PACKAGE
            ? app(ControlPanelSettings::class)->apply()
            : null);

        $this->publishes([__DIR__.'/../config/sign-off.php' => config_path('sign-off.php')], 'sign-off-config');
        $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'sign-off-migrations');

        if (config('sign-off.driver') === 'database') {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        Stache::registerStore(app(SubmissionsStore::class)->directory(config('sign-off.drivers.file.directory', storage_path('sign-off'))));

        // Before Statamic's own checks, so the rules hold for everyone.
        Gate::before(fn ($user, string $ability, array $arguments) => app(Enforcement::class)->check($user, $ability, $arguments));

        Event::listen(RevisionSaved::class, [WatchRevisions::class, 'revisionSaved']);
        Event::listen(EntrySaved::class, [WatchRevisions::class, 'entrySaved']);
        Event::listen(EntryBlueprintFound::class, AddPanel::class);
        Event::listen(Events\EntrySubmitted::class, [SendNotifications::class, 'submitted']);
        Event::listen(Events\EntryApproved::class, [SendNotifications::class, 'approved']);
        Event::listen(Events\EntryRejected::class, [SendNotifications::class, 'rejected']);
        Event::listen(Events\EntrySignedOff::class, [SendNotifications::class, 'signedOff']);

        // For people who can see an entry in a collection that needs sign-off,
        // and for people who can fix its setup when something's wrong.
        Nav::extend(function ($nav) {
            $reviews = app(Reviews::class);

            if (($user = User::current()) && ($reviews->collectionsFor($user) !== [] || $reviews->problemsFor($user) !== [])) {
                $nav->content(__('Sign-off'))->route('sign-off.index')->icon(file_get_contents(__DIR__.'/../resources/svg/sign-off.svg'));
            }
        });

        Permission::extend(function () {
            Permission::group('sign-off', 'Sign-off', function () {
                Permission::register('publish without sign-off')
                    ->label('Publish entries without sign-off (recorded in their history)');
            });
        });
    }
}
