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
use NineteenNinetyFour\SignOff\Listeners\WatchRevisions;
use NineteenNinetyFour\SignOff\Stache\StacheSubmissionRepository;
use NineteenNinetyFour\SignOff\Stache\SubmissionsStore;
use Statamic\Events\EntrySaved;
use Statamic\Events\RevisionSaved;
use Statamic\Facades\Permission;
use Statamic\Facades\Stache;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $actions = [
        SubmitForSignOff::class,
        ApproveSignOff::class,
        RejectSignOff::class,
        WithdrawSignOff::class,
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

        Permission::extend(function () {
            Permission::group('sign-off', 'Sign-off', function () {
                Permission::register('publish without sign-off')
                    ->label('Publish entries without sign-off (recorded in their history)');
            });
        });
    }
}
