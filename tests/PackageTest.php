<?php

declare(strict_types=1);

it('enforces approvals unless the host turns them off', function (): void {
    expect(config('approvals.enforced'))->toBeTrue();
});

it('publishes its config for the host to override', function (): void {
    $this->artisan('vendor:publish', ['--tag' => 'approvals-config'])->assertSuccessful();

    expect(config_path('approvals.php'))->toBeFile();
})->after(fn () => @unlink(config_path('approvals.php')));
