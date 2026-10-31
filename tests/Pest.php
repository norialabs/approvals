<?php

declare(strict_types=1);

use Noria\Approvals\Approvals;
use Noria\Approvals\Models\Approval;
use Noria\Approvals\Tests\Fixtures\User;
use Noria\Approvals\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/** @param array<string, mixed> $attributes */
function person(string $name, ?string $role = null, array $attributes = []): User
{
    return User::query()->create(['name' => $name, 'role' => $role, ...$attributes]);
}

function approvals(): Approvals
{
    return app(Approvals::class);
}

function latestApproval(): Approval
{
    $approval = Approval::query()->withoutGlobalScopes()->latest('created_at')->latest('id')->first();

    if (! $approval instanceof Approval) {
        throw new UnexpectedValueException('No approval was recorded.');
    }

    return $approval;
}
