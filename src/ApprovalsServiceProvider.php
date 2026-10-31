<?php

declare(strict_types=1);

namespace Noria\Approvals;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Noria\Approvals\Contracts\Actors;
use Noria\Approvals\Contracts\Policy;
use Noria\Approvals\Contracts\Reviewers;
use Noria\Approvals\Contracts\Tenancy;
use Noria\Approvals\Defaults\AuthActors;
use Noria\Approvals\Defaults\ConfiguredPolicy;
use Noria\Approvals\Defaults\ConfiguredTenancy;
use Noria\Approvals\Defaults\GateReviewers;

final class ApprovalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/approvals.php', 'approvals');

        $this->app->bindIf(Actors::class, fn (): Actors => Settings::resolve('actors.resolver', Actors::class, AuthActors::class));
        $this->app->bindIf(Tenancy::class, fn (): Tenancy => Settings::resolve('tenancy.resolver', Tenancy::class, ConfiguredTenancy::class));
        $this->app->bindIf(Policy::class, fn (): Policy => Settings::resolve('policy', Policy::class, ConfiguredPolicy::class));
        $this->app->bindIf(Reviewers::class, fn (): Reviewers => Settings::resolve('reviewers', Reviewers::class, GateReviewers::class));
        $this->app->scoped(Approvals::class, fn (Application $app): Approvals => new Approvals(
            $app->make(Policy::class),
            $app->make(Reviewers::class),
            $app->make(Tenancy::class),
            $app->make(Actors::class),
            $app->make('events'),
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/approvals.php' => $this->app->configPath('approvals.php')], 'approvals-config');
        $this->publishes([__DIR__.'/../database/migrations' => $this->app->databasePath('migrations')], 'approvals-migrations');

        if (Settings::runsMigrations()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SweepCommand::class]);
        }
    }
}
