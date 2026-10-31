<?php

declare(strict_types=1);

use Noria\Approvals\Contracts\Policy;
use Noria\Approvals\Contracts\Reviewers;
use Noria\Approvals\Defaults\ConfiguredPolicy;
use Noria\Approvals\Group;
use Noria\Approvals\Models\Approval;
use Noria\Approvals\Models\ApprovalPolicy;
use Noria\Approvals\Operation;
use Noria\Approvals\PendingChange;
use Noria\Approvals\Requirement;
use Noria\Approvals\Step;
use Noria\Approvals\Tests\Fixtures\Contract;
use Noria\Approvals\Tests\Fixtures\Invoice;

beforeEach(function (): void {
    $this->maker = person('Amina');
    $this->actingAs($this->maker);
});

/**
 * @param  array<string, mixed>  $new
 * @param  array<string, mixed>|null  $current
 */
function change(array $new, ?array $current = null, Operation $operation = Operation::Update, int|string|null $actor = 7): PendingChange
{
    return new PendingChange($operation, Invoice::class, (new Invoice)->getMorphClass(), 1, null, $current, $new, ['account' => 'A-1'], $actor, null);
}

/** @param array<string, mixed> $definition */
function stepsFor(array $definition, PendingChange $change): array
{
    return array_map(fn (Step $step): string => $step->name, (new ConfiguredPolicy)->evaluate($definition, $change)->steps);
}

describe('where a policy comes from', function (): void {
    it('falls back to one approval from anyone but the maker', function (): void {
        $requirement = app(Policy::class)->requirementFor(change(['amount' => 5]));

        expect($requirement->steps)->toHaveCount(1)
            ->and($requirement->steps[0]->name)->toBe('review')
            ->and($requirement->steps[0]->isOpenToAnyone())->toBeTrue();
    });

    it('uses the host default when it sets one', function (): void {
        config(['approvals.default' => ['steps' => [['name' => 'ops', 'approvals' => 2]]]]);

        expect(app(Policy::class)->requirementFor(change([]))->steps[0])->name->toBe('ops')->approvalsNeeded()->toBe(2);
    });

    it('prefers a subject policy over the default', function (): void {
        config(['approvals.subject_policies' => [Invoice::class => ['steps' => [['name' => 'finance']]]]]);

        expect(stepsFor(app(ConfiguredPolicy::class)->definitionFor(change([])), change([])))->toBe(['finance']);
    });

    it('asks the model first and falls back when it declines to decide', function (): void {
        $contract = Contract::query()->create(['title' => 'Supply', 'value' => '10.00']);

        $contract->update(['value' => '5000.00']);
        expect($contract->heldApproval()->requirement()->steps)->toHaveCount(2);

        approvals()->cancel($contract->heldApproval(), $this->maker->id);
        $contract->update(['value' => '20.00']);
        expect($contract->heldApproval()->currentStep()?->name)->toBe('review');
    });

    it('reads stored policies before config when the host turns them on', function (): void {
        config([
            'approvals.database_policies' => true,
            'approvals.subject_policies' => [Invoice::class => ['steps' => [['name' => 'config']]]],
        ]);
        ApprovalPolicy::query()->create(['subject_type' => (new Invoice)->getMorphClass(), 'definition' => ['steps' => [['name' => 'stored']]]]);
        ApprovalPolicy::query()->create(['subject_type' => (new Invoice)->getMorphClass(), 'definition' => ['steps' => [['name' => 'inactive']]], 'active' => false]);

        expect(app(Policy::class)->requirementFor(change([]))->steps[0]->name)->toBe('stored');
    });

    it('refuses to store a policy it could not read', function (): void {
        expect(fn () => ApprovalPolicy::query()->create(['subject_type' => 'invoice', 'definition' => ['steps' => [['approvals' => 0]]]]))
            ->toThrow(InvalidArgumentException::class, 'at least one approval');
    });

    it('accepts a policy class of the host’s own', function (): void {
        config(['approvals.policy' => NoApprovalPolicy::class]);
        app()->forgetScopedInstances();

        expect(Invoice::query()->create(['number' => 'INV-1'])->exists)->toBeTrue();
    });

    it('accepts a reviewer resolver of the host’s own', function (): void {
        app()->bind(Reviewers::class, OnlyEvenIds::class);
        app()->forgetScopedInstances();
        $even = person('Even');
        $odd = person('Odd');
        $invoice = new Invoice(['number' => 'INV-2']);
        $invoice->saveWithoutApproval();
        $invoice->update(['amount' => '1.00']);

        expect([$even->id, $odd->id])->toBe([2, 3])
            ->and(approvals()->canReview($invoice->heldApproval(), $even->id))->toBeTrue()
            ->and(approvals()->canReview($invoice->heldApproval(), $odd->id))->toBeFalse()
            ->and(approvals()->reviewersFor($invoice->heldApproval()))->toBe([2, 4]);
    });

    it('rejects a configured class that does not implement the contract', function (): void {
        config(['approvals.reviewers' => stdClass::class]);

        expect(fn () => app(Reviewers::class))->toThrow(InvalidArgumentException::class, 'must implement');
    });
});

describe('rules', function (): void {
    it('only reviews the operations a policy lists', function (): void {
        $definition = ['operations' => ['delete'], 'steps' => [['name' => 'review']]];

        expect(stepsFor($definition, change([])))->toBe([])
            ->and(stepsFor($definition, change([], [], Operation::Delete)))->toBe(['review']);
    });

    it('lets the first matching rule decide', function (): void {
        $definition = [
            'steps' => [['name' => 'manager']],
            'rules' => [
                ['when' => [['amount', '>=', 100000]], 'steps' => [['name' => 'manager'], ['name' => 'director']]],
                ['when' => [['amount', '<', 1000]], 'approve' => false],
            ],
        ];

        expect(stepsFor($definition, change(['amount' => '250000.00'])))->toBe(['manager', 'director'])
            ->and(stepsFor($definition, change(['amount' => '500'])))->toBe([])
            ->and(stepsFor($definition, change(['amount' => '5000'])))->toBe(['manager']);
    });

    it('compares with every operator', function (array $condition, bool $matches): void {
        $change = change(
            ['amount' => '5000', 'status' => 'sent', 'tags' => ['urgent'], 'number' => 'INV-77', 'notes' => null],
            ['amount' => '4000', 'status' => 'sent', 'tags' => ['urgent'], 'number' => 'INV-77'],
        );

        expect((new ConfiguredPolicy)->matches($condition, $change))->toBe($matches);
    })->with([
        'equal' => [['status', '=', 'sent'], true],
        'not equal' => [['status', '!=', 'sent'], false],
        'greater' => [['amount', '>', 4999], true],
        'greater or equal' => [['amount', '>=', 5000], true],
        'less' => [['amount', '<', 5000], false],
        'less or equal' => [['amount', '<=', '5000.00'], true],
        'less than for a missing value' => [['missing', '<', 10], false],
        'in' => [['status', 'in', ['draft', 'sent']], true],
        'not in' => [['status', 'not_in', ['draft', 'sent']], false],
        'between' => [['amount', 'between', [1000, 6000]], true],
        'outside' => [['amount', 'between', [6000, 9000]], false],
        'null' => [['notes', 'null'], true],
        'not null' => [['notes', 'not_null'], false],
        'changed' => [['amount', 'changed'], true],
        'unchanged' => [['status', 'changed', false], true],
        'contains in a list' => [['tags', 'contains', 'urgent'], true],
        'contains in text' => [['number', 'contains', '-7'], true],
        'starts with' => [['number', 'starts_with', 'INV'], true],
        'ends with' => [['number', 'ends_with', '78'], false],
        'text order' => [['status', '>', 'draft'], true],
        'list order' => [['tags', '>', 'a'], false],
        'old value' => [['old.amount', '=', 4000], true],
        'payload' => [['payload.account', '=', 'A-1'], true],
        'operation' => [['operation', '=', 'update'], true],
        'actor' => [['actor', 'in', [7, 8]], true],
        'tenant' => [['tenant', 'null'], true],
        'any' => [['any' => [['status', '=', 'draft'], ['amount', '>', 1000]]], true],
        'all' => [['all' => [['status', '=', 'draft'], ['amount', '>', 1000]]], false],
    ]);

    it('rejects a malformed condition', function (): void {
        expect(fn () => (new ConfiguredPolicy)->matches(['amount', 'like', '%5'], change([])))->toThrow(InvalidArgumentException::class, 'one of');
    });

    it('carries an expiry from the policy onto the request', function (): void {
        config(['approvals.subject_policies' => [Invoice::class => ['steps' => [['name' => 'review']], 'expires_after' => 60]]]);
        $this->freezeTime();
        Invoice::query()->create(['number' => 'INV-9']);

        expect(latestApproval()->expires_at?->toDateTimeString())->toBe(now()->addHour()->toDateTimeString());
    });

    it('keeps the requirement a request was made under when the policy changes later', function (): void {
        Invoice::query()->create(['number' => 'INV-9']);
        config(['approvals.default' => ['steps' => [['name' => 'later', 'approvals' => 3]]]]);

        expect(latestApproval()->currentStep()?->name)->toBe('review');
    });
});

describe('definitions', function (): void {
    it('round-trips a requirement through an array', function (): void {
        $requirement = Requirement::of(
            new Step('finance', approvals: 2, users: [1, 'u-2'], ability: 'approve', roles: ['finance'], escalateAfter: 30, escalateTo: new Step('director')),
        )->expiringAfter(120);

        expect(Requirement::fromArray($requirement->toArray()))->toEqual($requirement);
    });

    it('reads groups and the any mode from config', function (): void {
        $step = Step::fromArray(['name' => 'release', 'mode' => 'any', 'groups' => [
            ['name' => 'cfo', 'users' => [1]],
            ['name' => 'directors', 'approvals' => 2, 'roles' => ['director']],
        ]]);

        expect($step->mode)->toBe(Step::ANY)
            ->and($step->hasGroups())->toBeTrue()
            ->and($step->group('directors')?->approvals)->toBe(2)
            ->and($step->group('missing'))->toBeNull()
            ->and($step->approvalsNeeded())->toBe(1)
            ->and($step->isOpenToAnyone())->toBeFalse()
            ->and(Step::fromArray($step->toArray()))->toEqual($step)
            ->and(Step::fromArray(['name' => 'plain', 'approvals' => 3])->hasGroups())->toBeFalse();
    });

    it('rejects definitions it cannot read', function (array $definition, string $message): void {
        expect(fn () => Requirement::fromArray($definition))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'steps not a list' => [['steps' => 'review'], 'list of step definitions'],
        'step not an array' => [['steps' => ['review']], 'list of step definitions'],
        'expiry not a number' => [['steps' => [], 'expires_after' => 'soon'], 'number of minutes'],
        'expiry too short' => [['steps' => [], 'expires_after' => 0], 'at least one minute'],
        'empty name' => [['steps' => [['name' => '']]], 'needs a name'],
        'name not a string' => [['steps' => [['name' => 5]]], 'string name'],
        'ability not a string' => [['steps' => [['ability' => 5]]], 'ability must be a string'],
        'users not keys' => [['steps' => [['users' => [1.5]]]], 'list of keys'],
        'users not a list' => [['steps' => [['users' => 'all']]], 'list of keys'],
        'roles not names' => [['steps' => [['roles' => [1]]]], 'list of names'],
        'roles not a list' => [['steps' => [['roles' => 'all']]], 'list of names'],
        'escalation delay' => [['steps' => [['escalate_after' => 'later']]], 'number of minutes'],
        'escalation too soon' => [['steps' => [['escalate_after' => 0]]], 'at least one minute'],
        'escalation target' => [['steps' => [['escalate_to' => 'boss']]], 'step definition'],
        'groups and step approvers together' => [['steps' => [['roles' => ['x'], 'groups' => [['name' => 'a']]]]], 'not both'],
        'two groups with one name' => [['steps' => [['groups' => [['name' => 'a'], ['name' => 'a']]]]], 'same name'],
        'unnamed group' => [['steps' => [['groups' => [['approvals' => 2]]]]], 'named group'],
        'groups not a list' => [['steps' => [['groups' => 'finance']]], 'list of group definitions'],
        'unknown mode' => [['steps' => [['mode' => 'most', 'groups' => [['name' => 'a']]]]], 'all or any'],
        'empty group' => [['steps' => [['groups' => [['name' => 'a', 'approvals' => 0]]]]], 'at least one approval'],
    ]);

    it('rejects malformed rules', function (): void {
        expect(fn () => (new ConfiguredPolicy)->evaluate(['rules' => ['bad']], change([])))->toThrow(InvalidArgumentException::class, 'must be an array')
            ->and(fn () => (new ConfiguredPolicy)->evaluate(['rules' => [['when' => 'always']]], change([])))->toThrow(InvalidArgumentException::class, 'list of conditions')
            ->and(fn () => (new ConfiguredPolicy)->evaluate(['rules' => 'none'], change([])))->toThrow(InvalidArgumentException::class);
    });
});

final class NoApprovalPolicy implements Policy
{
    public function requirementFor(PendingChange $change): Requirement
    {
        return Requirement::none();
    }
}

final class OnlyEvenIds implements Reviewers
{
    public function allows(Approval $approval, Group $group, int|string $actorId): bool
    {
        return (int) $actorId % 2 === 0;
    }

    public function candidates(Approval $approval, Group $group): array
    {
        return [1, 2, 4];
    }
}
