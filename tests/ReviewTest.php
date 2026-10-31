<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Noria\Approvals\Events\ApprovalAdvanced;
use Noria\Approvals\Events\ApprovalClosed;
use Noria\Approvals\Events\ApprovalFailed;
use Noria\Approvals\Exceptions\ApprovalException;
use Noria\Approvals\Group;
use Noria\Approvals\Models\Approval;
use Noria\Approvals\Models\ApprovalEvent;
use Noria\Approvals\Operation;
use Noria\Approvals\Requirement;
use Noria\Approvals\Status;
use Noria\Approvals\Step;
use Noria\Approvals\Tests\Fixtures\Contract;
use Noria\Approvals\Tests\Fixtures\Invoice;
use Noria\Approvals\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->maker = person('Amina');
    $this->checker = person('Brian', 'finance');
    $this->other = person('Cate', 'finance');
    $this->actingAs($this->maker);
});

function heldInvoice(string $amount = '1500.00'): Invoice
{
    $invoice = new Invoice(['number' => 'INV-100', 'amount' => '100.00']);
    $invoice->saveWithoutApproval();
    $invoice->update(['amount' => $amount]);

    return $invoice;
}

function chain(Step ...$steps): void
{
    config(['approvals.subject_policies' => [Invoice::class => Requirement::of(...$steps)->toArray()]]);
}

describe('separation of duties', function (): void {
    it('never lets the maker review their own request', function (): void {
        $approval = heldInvoice()->heldApproval();

        expect(fn () => approvals()->approve($approval, $this->maker->id))->toThrow(ApprovalException::class, 'own request')
            ->and(fn () => approvals()->decline($approval, $this->maker->id))->toThrow(ApprovalException::class, 'own request')
            ->and(approvals()->canReview($approval, $this->maker->id))->toBeFalse()
            ->and(approvals()->canReview($approval, $this->checker->id))->toBeTrue();
    });

    it('uses the signed-in user when no reviewer is passed', function (): void {
        $approval = heldInvoice()->heldApproval();
        $this->actingAs($this->checker);

        expect(approvals()->approve($approval)->status)->toBe(Status::Approved)
            ->and((string) $approval->fresh()?->closer_id)->toBe((string) $this->checker->id);
    });

    it('refuses to review without anyone signed in', function (): void {
        $approval = heldInvoice()->heldApproval();
        auth()->logout();

        expect(fn () => approvals()->approve($approval))->toThrow(ApprovalException::class, 'signed-in user')
            ->and(approvals()->canReview($approval))->toBeFalse();
    });

    it('lets anyone review a request that has no maker', function (): void {
        auth()->logout();
        Invoice::query()->create(['number' => 'INV-200']);

        expect(approvals()->approve(latestApproval(), $this->checker->id)->status)->toBe(Status::Approved);
    });
});

describe('deciding', function (): void {
    it('closes a declined request and leaves the record alone', function (): void {
        Event::fake([ApprovalClosed::class]);
        $invoice = heldInvoice('9000.00');

        $declined = approvals()->decline($invoice->heldApproval(), $this->checker->id, 'Too high');

        expect($declined->status)->toBe(Status::Declined)
            ->and($declined->closed_at)->not->toBeNull()
            ->and((float) $invoice->fresh()?->amount)->toBe(100.0)
            ->and($declined->events()->pluck('notes', 'action')->all())->toMatchArray(['declined' => 'Too high']);
        Event::assertDispatched(ApprovalClosed::class, fn (ApprovalClosed $event): bool => $event->approval->status === Status::Declined);
    });

    it('closes a request sent back for changes', function (): void {
        $approval = approvals()->requestChanges(heldInvoice()->heldApproval(), $this->checker->id, 'Attach the quote');

        expect($approval->status)->toBe(Status::ChangesRequested);
    });

    it('lets only the maker cancel', function (): void {
        $approval = heldInvoice()->heldApproval();

        expect(fn () => approvals()->cancel($approval, $this->checker->id))->toThrow(ApprovalException::class, 'Only the person')
            ->and(approvals()->cancel($approval, $this->maker->id)->status)->toBe(Status::Cancelled);
    });

    it('refuses any decision on a closed request', function (): void {
        $approval = approvals()->decline(heldInvoice()->heldApproval(), $this->checker->id);

        expect(fn () => approvals()->approve($approval, $this->other->id))->toThrow(ApprovalException::class, 'already declined');
    });

    it('frees the record for a new request once the last one closes', function (): void {
        $invoice = heldInvoice();
        approvals()->decline($invoice->heldApproval(), $this->checker->id);

        expect($invoice->fresh()?->update(['amount' => '1200.00']))->toBeFalse()
            ->and($invoice->fresh()?->hasPendingApproval())->toBeTrue();
    });
});

describe('several approvers and steps', function (): void {
    it('waits for the number of approvals a step needs', function (): void {
        chain(new Step('finance', approvals: 2));
        $approval = heldInvoice()->heldApproval();

        approvals()->approve($approval, $this->checker->id);
        expect($approval->fresh()?->status)->toBe(Status::Pending);

        approvals()->approve($approval, $this->other->id);
        expect($approval->fresh()?->status)->toBe(Status::Approved);
    });

    it('stops one person approving twice', function (): void {
        chain(new Step('finance', approvals: 2));
        $approval = heldInvoice()->heldApproval();
        approvals()->approve($approval, $this->checker->id);

        expect(fn () => approvals()->approve($approval, $this->checker->id))->toThrow(ApprovalException::class, 'already approved');
    });

    it('moves through steps in order and applies after the last', function (): void {
        Event::fake([ApprovalAdvanced::class, ApprovalClosed::class]);
        $director = person('Dan', 'director');
        chain(new Step('finance', roles: ['finance']), new Step('director', roles: ['director']));
        $invoice = heldInvoice('5000.00');
        $approval = $invoice->heldApproval();

        expect(approvals()->canReview($approval, $director->id))->toBeFalse();

        approvals()->approve($approval, $this->checker->id);
        expect($approval->fresh()?->currentStep()?->name)->toBe('director')
            ->and(approvals()->canReview($approval->fresh(), $this->other->id))->toBeFalse();
        Event::assertDispatched(ApprovalAdvanced::class, fn (ApprovalAdvanced $event): bool => $event->reason === ApprovalAdvanced::STEP_COMPLETED && $event->fromStep === 'finance');

        approvals()->approve($approval->fresh(), $director->id);
        expect((float) $invoice->fresh()?->amount)->toBe(5000.0);
        Event::assertDispatched(ApprovalClosed::class);
    });

    it('lets a person approve one step and another when reviewers need not be distinct', function (): void {
        config(['approvals.distinct_reviewers' => false]);
        chain(new Step('first'), new Step('second'));
        $approval = heldInvoice()->heldApproval();

        approvals()->approve($approval, $this->checker->id);
        approvals()->approve($approval->fresh(), $this->checker->id);

        expect($approval->fresh()?->status)->toBe(Status::Approved);
    });
});

describe('groups within a step', function (): void {
    beforeEach(function (): void {
        $this->manager = person('Mary', 'manager');
        $this->third = person('Tom', 'finance');
    });

    it('needs every group filled, in any order', function (): void {
        chain(new Step('sign-off', groups: [new Group('finance', 2, roles: ['finance']), new Group('management', roles: ['manager'])]));
        $approval = heldInvoice('8000.00')->heldApproval();

        approvals()->approve($approval, $this->manager->id);
        approvals()->approve($approval->fresh(), $this->checker->id);
        expect($approval->fresh()?->status)->toBe(Status::Pending)
            ->and(approvals()->progress($approval->fresh()))->toBe([
                ['group' => 'finance', 'approvals' => 2, 'given' => 1],
                ['group' => 'management', 'approvals' => 1, 'given' => 1],
            ]);

        approvals()->approve($approval->fresh(), $this->other->id);
        expect($approval->fresh()?->status)->toBe(Status::Approved);
    });

    it('turns away an approval a full group no longer needs, but still lets that person decline', function (): void {
        chain(new Step('sign-off', groups: [new Group('finance', roles: ['finance']), new Group('management', roles: ['manager'])]));
        $approval = heldInvoice()->heldApproval();
        approvals()->approve($approval, $this->checker->id);

        expect(fn () => approvals()->approve($approval->fresh(), $this->other->id))->toThrow(ApprovalException::class, 'finance approvals for the sign-off step are already complete')
            ->and(approvals()->canReview($approval->fresh(), $this->other->id))->toBeFalse()
            ->and(approvals()->canReview($approval->fresh(), $this->manager->id))->toBeTrue()
            ->and(approvals()->decline($approval->fresh(), $this->other->id)->status)->toBe(Status::Declined);
    });

    it('counts someone who belongs to two groups wherever the step still needs them', function (): void {
        chain(new Step('sign-off', groups: [new Group('finance', roles: ['finance']), new Group('owner', users: [$this->checker->id])]));
        $approval = heldInvoice()->heldApproval();

        approvals()->approve($approval, $this->checker->id);
        approvals()->approve($approval->fresh(), $this->other->id);

        expect($approval->fresh()?->status)->toBe(Status::Approved);
    });

    it('closes on whichever group fills first when any one will do', function (string $route): void {
        $cfo = person('Cleo', 'cfo');
        $director = person('Dan', 'director');
        $secondDirector = person('Dora', 'director');
        chain(new Step('release', mode: Step::ANY, groups: [new Group('cfo', roles: ['cfo']), new Group('directors', 2, roles: ['director'])]));
        $approval = heldInvoice()->heldApproval();

        if ($route === 'cfo') {
            approvals()->approve($approval, $cfo->id);
        } else {
            approvals()->approve($approval, $director->id);
            expect($approval->fresh()?->status)->toBe(Status::Pending);
            approvals()->approve($approval->fresh(), $secondDirector->id);
        }

        expect($approval->fresh()?->status)->toBe(Status::Approved);
    })->with(['cfo', 'directors']);

    it('lists the reviewers of the groups still waiting', function (): void {
        chain(new Step('sign-off', groups: [new Group('finance', users: [$this->checker->id]), new Group('management', users: [$this->manager->id, $this->maker->id])]));
        $approval = heldInvoice()->heldApproval();

        expect(approvals()->reviewersFor($approval))->toBe([$this->checker->id, $this->manager->id]);

        approvals()->approve($approval, $this->checker->id);

        expect(approvals()->reviewersFor($approval->fresh()))->toBe([$this->manager->id]);
    });

    it('fills a group through a delegate', function (): void {
        $cover = person('Faith');
        approvals()->delegate($this->manager->id, $cover->id);
        chain(new Step('sign-off', groups: [new Group('finance', roles: ['finance']), new Group('management', roles: ['manager'])]));
        $approval = heldInvoice()->heldApproval();

        approvals()->approve($approval, $this->checker->id);
        approvals()->approve($approval->fresh(), $cover->id);

        expect($approval->fresh()?->status)->toBe(Status::Approved)
            ->and($approval->events()->where('action', 'approved')->get()->pluck('payload.groups')->all())->toBe([['finance'], ['management']]);
    });

    it('starts the groups of an escalation step afresh', function (): void {
        chain(new Step('sign-off', escalateAfter: 5, escalateTo: new Step('board', groups: [new Group('chair', users: [$this->manager->id]), new Group('finance', roles: ['finance'])]), groups: [new Group('finance', 2, roles: ['finance'])]));
        $approval = heldInvoice()->heldApproval();
        approvals()->approve($approval, $this->checker->id);

        $this->travel(6)->minutes();
        approvals()->escalateDue();

        expect(approvals()->progress($approval->fresh()))->toBe([
            ['group' => 'chair', 'approvals' => 1, 'given' => 0],
            ['group' => 'finance', 'approvals' => 1, 'given' => 0],
        ]);
    });
});

describe('who may review a step', function (): void {
    it('limits a step to named users', function (): void {
        chain(new Step('named', users: [$this->other->id]));
        $approval = heldInvoice()->heldApproval();

        expect(fn () => approvals()->approve($approval, $this->checker->id))->toThrow(ApprovalException::class, 'not a reviewer for the named step')
            ->and(approvals()->approve($approval, $this->other->id)->status)->toBe(Status::Approved)
            ->and(approvals()->reviewersFor($approval))->toBe([]);
    });

    it('limits a step to a gate ability', function (): void {
        chain(new Step('finance', ability: 'approve-invoices'));
        $outsider = person('Eve', 'site');
        $approval = heldInvoice()->heldApproval();

        expect(approvals()->canReview($approval, $outsider->id))->toBeFalse()
            ->and(approvals()->canReview($approval, $this->checker->id))->toBeTrue();
    });

    it('limits a step to roles', function (): void {
        chain(new Step('finance', roles: ['finance']));
        $approval = heldInvoice()->heldApproval();

        expect(approvals()->canReview($approval, person('Eve', 'site')->id))->toBeFalse()
            ->and(approvals()->canReview($approval, $this->checker->id))->toBeTrue();
    });

    it('lists the reviewers still able to act when the step names them', function (): void {
        chain(new Step('named', approvals: 2, users: [$this->maker->id, $this->checker->id, $this->other->id]));
        $approval = heldInvoice()->heldApproval();

        expect(approvals()->reviewersFor($approval))->toBe([$this->checker->id, $this->other->id]);

        approvals()->approve($approval, $this->checker->id);

        expect(approvals()->reviewersFor($approval->fresh()))->toBe([$this->other->id]);
    });

    it('reports reviewers as unknown when the step names nobody', function (): void {
        expect(approvals()->reviewersFor(heldInvoice()->heldApproval()))->toBeNull();
    });
});

describe('delegation', function (): void {
    it('lets a delegate act for an absent reviewer and records both', function (): void {
        chain(new Step('named', users: [$this->checker->id]));
        $delegate = person('Faith');
        approvals()->delegate($this->checker->id, $delegate->id, endsAt: now()->addWeek());
        $approval = heldInvoice()->heldApproval();

        approvals()->approve($approval, $delegate->id, 'Covering for Brian');

        $vote = $approval->events()->where('action', 'approved')->sole();
        expect($approval->fresh()?->status)->toBe(Status::Approved)
            ->and((string) $vote->actor_id)->toBe((string) $delegate->id)
            ->and((string) $vote->principal_id)->toBe((string) $this->checker->id);
    });

    it('ignores delegations that are revoked, expired, not started or scoped elsewhere', function (string $case): void {
        chain(new Step('named', users: [$this->checker->id]));
        $delegate = person('Faith');
        $delegation = match ($case) {
            'revoked' => approvals()->revokeDelegation(approvals()->delegate($this->checker->id, $delegate->id)),
            'ended' => approvals()->delegate($this->checker->id, $delegate->id, now()->subWeek(), now()->subDay()),
            'future' => approvals()->delegate($this->checker->id, $delegate->id, now()->addDay()),
            default => approvals()->delegate($this->checker->id, $delegate->id, subjectType: Contract::class),
        };

        expect($delegation->isActive())->toBe($case === 'scoped')
            ->and(approvals()->canReview(heldInvoice()->heldApproval(), $delegate->id))->toBeFalse();
    })->with(['revoked', 'ended', 'future', 'scoped']);

    it('never lets a delegate approve for the maker', function (): void {
        $delegate = person('Faith');
        chain(new Step('named', users: [$this->maker->id]));
        approvals()->delegate($this->maker->id, $delegate->id);

        expect(approvals()->canReview(heldInvoice()->heldApproval(), $delegate->id))->toBeFalse();
    });

    it('stops a reviewer approving again through a delegate', function (): void {
        chain(new Step('finance', approvals: 2, users: [$this->checker->id, $this->other->id]));
        $delegate = person('Faith');
        approvals()->delegate($this->checker->id, $delegate->id);
        $approval = heldInvoice()->heldApproval();
        approvals()->approve($approval, $this->checker->id);

        expect(approvals()->canReview($approval->fresh(), $delegate->id))->toBeFalse();
    });

    it('rejects delegating to oneself or ending before starting', function (): void {
        expect(fn () => approvals()->delegate($this->checker->id, $this->checker->id))->toThrow(InvalidArgumentException::class)
            ->and(fn () => approvals()->delegate($this->checker->id, $this->other->id, now(), now()->subDay()))->toThrow(InvalidArgumentException::class);
    });
});

describe('escalating by hand', function (): void {
    it('moves the step to its escalation with the reason and who asked', function (): void {
        Event::fake([ApprovalAdvanced::class]);
        chain(new Step('business', escalateTo: new Step('platform', users: [$this->other->id])));
        $approval = heldInvoice()->heldApproval();

        $escalated = approvals()->escalate($approval, 'Nobody here can judge this', $this->maker->id);

        expect($escalated->currentStep()?->name)->toBe('platform')
            ->and($escalated->stage)->toBe(2)
            ->and($escalated->events()->where('action', 'escalated')->sole()->only(['notes', 'payload']))->toEqual(['notes' => 'Nobody here can judge this', 'payload' => ['from' => 'business', 'to' => 'platform']])
            ->and(approvals()->approve($escalated, $this->other->id)->status)->toBe(Status::Approved);
        Event::assertDispatched(ApprovalAdvanced::class, fn (ApprovalAdvanced $event): bool => $event->reason === ApprovalAdvanced::ESCALATED);
    });

    it('escalates to a step the caller names', function (): void {
        $approval = heldInvoice()->heldApproval();

        expect(approvals()->escalate($approval, 'Over my head', $this->maker->id, new Step('board'))->currentStep()?->name)->toBe('board');
    });

    it('refuses an escalation with nowhere to go or no reason', function (): void {
        $approval = heldInvoice()->heldApproval();

        expect(fn () => approvals()->escalate($approval, 'Help', $this->maker->id))->toThrow(ApprovalException::class, 'nowhere to escalate')
            ->and(fn () => approvals()->escalate($approval, '  ', $this->maker->id, new Step('board')))->toThrow(InvalidArgumentException::class);
    });
});

describe('veto', function (): void {
    beforeEach(function (): void {
        config(['approvals.veto_ability' => 'veto-approvals']);
        Gate::define('veto-approvals', fn (User $user): bool => $user->role === 'compliance');
        $this->compliance = person('Vera', 'compliance');
    });

    it('lets compliance decline a request it cannot approve', function (): void {
        chain(new Step('finance', roles: ['finance']));
        $approval = heldInvoice()->heldApproval();

        expect(approvals()->canReview($approval, $this->compliance->id))->toBeFalse()
            ->and(approvals()->canVeto($approval, $this->compliance->id))->toBeTrue()
            ->and(fn () => approvals()->approve($approval, $this->compliance->id))->toThrow(ApprovalException::class)
            ->and(fn () => approvals()->requestChanges($approval, $this->compliance->id))->toThrow(ApprovalException::class);

        $declined = approvals()->decline($approval, $this->compliance->id, 'Sanctioned supplier');

        expect($declined->status)->toBe(Status::Declined)
            ->and($declined->events()->where('action', 'declined')->sole()->payload)->toBe(['veto' => true]);
    });

    it('never lets the maker veto their own request, and gives nobody a veto by default', function (): void {
        $this->maker->forceFill(['role' => 'compliance'])->save();
        $approval = heldInvoice()->heldApproval();

        expect(approvals()->canVeto($approval, $this->maker->id))->toBeFalse();

        config(['approvals.veto_ability' => null]);

        expect(approvals()->canVeto($approval, $this->compliance->id))->toBeFalse();
    });
});

describe('reviewing in bulk', function (): void {
    it('approves what it can and explains what it could not', function (): void {
        $first = heldInvoice()->heldApproval();
        $second = (function (): Invoice {
            $invoice = new Invoice(['number' => 'INV-300']);
            $invoice->saveWithoutApproval();
            $invoice->update(['amount' => '700.00']);

            return $invoice;
        })()->heldApproval();
        approvals()->decline($second, $this->checker->id);

        $result = approvals()->approveMany([$first, $second->id, 'missing'], $this->other->id);

        expect($result['done'])->toBe([$first->id])
            ->and($result['failed'])->toHaveKeys([$second->id, 'missing'])
            ->and($result['failed'][$second->id])->toContain('already declined');
    });

    it('declines in bulk', function (): void {
        $approval = heldInvoice()->heldApproval();

        expect(approvals()->declineMany([$approval], $this->checker->id)['done'])->toBe([$approval->id])
            ->and($approval->fresh()?->status)->toBe(Status::Declined);
    });
});

describe('audit trail', function (): void {
    it('records every step in order and refuses to be edited', function (): void {
        chain(new Step('first'), new Step('second'));
        $approval = heldInvoice()->heldApproval();
        approvals()->approve($approval, $this->checker->id);
        approvals()->approve($approval->fresh(), $this->other->id);

        $events = $approval->events()->get();
        expect($events->pluck('action')->all())->toBe(['submitted', 'approved', 'advanced', 'approved', 'applied'])
            ->and($events->pluck('step')->all())->toBe(['first', 'first', 'second', 'second', 'second'])
            ->and($events[1]->actor?->getKey())->toBe($this->checker->id)
            ->and($approval->fresh()?->maker?->getKey())->toBe($this->maker->id)
            ->and($approval->fresh()?->closer?->getKey())->toBe($this->other->id);

        expect(fn () => $events[0]->update(['notes' => 'rewritten']))->toThrow(LogicException::class)
            ->and(fn () => $events[0]->delete())->toThrow(LogicException::class);
    });

    it('records a failed apply and tells listeners without closing the request', function (): void {
        Event::fake([ApprovalFailed::class]);
        $approval = heldInvoice()->heldApproval();
        Invoice::saving(fn () => throw new RuntimeException('Ledger is locked'));

        expect(fn () => approvals()->approve($approval, $this->checker->id))->toThrow(ApprovalException::class, 'Ledger is locked');

        expect($approval->fresh()?->status)->toBe(Status::Pending)
            ->and(ApprovalEvent::query()->where('action', 'failed')->sole()->notes)->toBe('Ledger is locked')
            ->and(ApprovalEvent::query()->where('action', 'approved')->exists())->toBeFalse();
        Event::assertDispatched(ApprovalFailed::class);
    });

    it('lets the record refuse an approved change just before it lands', function (): void {
        $invoice = heldInvoice('400.00');
        Invoice::$refuseApplying = 'This invoice is locked for audit';

        expect(fn () => approvals()->approve($invoice->heldApproval(), $this->checker->id))->toThrow(ApprovalException::class, 'locked for audit');
        expect((float) $invoice->fresh()?->amount)->toBe(100.0)
            ->and($invoice->heldApproval()->fresh()?->status)->toBe(Status::Pending);

        Invoice::$refuseApplying = null;
        approvals()->approve($invoice->heldApproval()->fresh(), $this->checker->id);

        expect((float) $invoice->fresh()?->amount)->toBe(400.0);
    });

    it('applies structured values stored as arrays, as hand-built and older requests hold them', function (): void {
        $invoice = heldInvoice();
        $invoice->heldApproval()->forceFill([
            'current_state' => [...($invoice->heldApproval()->current_state ?? []), 'meta' => null],
            'new_state' => ['amount' => '1500.00', 'meta' => ['source' => 'import', 'lines' => [1, 2]]],
        ])->save();

        approvals()->approve($invoice->heldApproval()->fresh(), $this->checker->id);

        expect($invoice->fresh()?->meta)->toEqual(['source' => 'import', 'lines' => [1, 2]]);
    });

    it('returns the record a create request points at when it already exists', function (): void {
        $invoice = new Invoice(['number' => 'INV-EXIST']);
        $invoice->saveWithoutApproval();
        $approval = Approval::query()->create([
            'subject_type' => $invoice->getMorphClass(),
            'subject_id' => $invoice->id,
            'operation' => 'create',
            'maker_id' => $this->maker->id,
            'requirement' => Requirement::of(new Step('review'))->toArray(),
            'new_state' => ['number' => 'INV-EXIST'],
        ]);

        expect($approval->step)->toBe(0)
            ->and($approval->stage)->toBe(1)
            ->and($approval->status)->toBe(Status::Pending);

        approvals()->approve($approval, $this->checker->id);

        expect(Invoice::query()->where('number', 'INV-EXIST')->count())->toBe(1)
            ->and($approval->fresh()?->status)->toBe(Status::Approved);
    });

    it('describes a request in one line', function (): void {
        $invoice = heldInvoice();

        expect($invoice->heldApproval()->summary())->toBe('Update Invoice '.$invoice->id)
            ->and(Operation::ForceDelete->label())->toBe('Permanently delete')
            ->and(Status::ChangesRequested->label())->toBe('Changes requested')
            ->and(Status::Approved->label())->toBe('Approved');
    });

    it('finds requests by state, record and maker', function (): void {
        $invoice = heldInvoice();
        approvals()->decline($invoice->heldApproval(), $this->checker->id);
        $invoice->fresh()?->update(['amount' => '400.00']);

        expect(Approval::query()->open()->count())->toBe(1)
            ->and(Approval::query()->forSubject($invoice)->count())->toBe(2)
            ->and(Approval::query()->madeBy($this->maker->id)->count())->toBe(2)
            ->and(Approval::query()->madeBy($this->checker->id)->count())->toBe(0)
            ->and(approvals()->reviewersFor($invoice->approvals()->where('status', 'declined')->sole()))->toBe([]);
    });
});
