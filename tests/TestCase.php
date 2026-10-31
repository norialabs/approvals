<?php

declare(strict_types=1);

namespace Noria\Approvals\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Gate;
use Noria\Approvals\ApprovalsServiceProvider;
use Noria\Approvals\Tests\Fixtures\Invoice;
use Noria\Approvals\Tests\Fixtures\Payout;
use Noria\Approvals\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ApprovalsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $config->set('approvals.actors.model', User::class);
        $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $url = getenv('APPROVALS_TEST_PG');

        if (is_string($url) && $url !== '') {
            $config->set('database.connections.approvals_pg', [
                'driver' => 'pgsql',
                'url' => $url,
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ]);
            $config->set('database.default', 'approvals_pg');
        } else {
            $config->set('database.default', 'testing');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->rebuild();
        Payout::$paid = [];
        Invoice::$applied = [];
        Invoice::$refuseApplying = null;
        Gate::define('approve-invoices', fn (User $user): bool => $user->role === 'finance');
    }

    /** @param array<string, mixed> $config */
    public function rebuild(array $config = []): void
    {
        config($config);
        $this->app?->make('migrator')->path(__DIR__.'/Fixtures/migrations');
        $this->artisan('migrate:fresh');
    }
}
