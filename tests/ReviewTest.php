<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Noria\Approvals\Events\ApprovalAdvanced;
use Noria\Approvals\Events\ApprovalClosed;
use Noria\Approvals\Events\ApprovalFailed;
use Noria\Approvals\Exceptions\ApprovalException;
use Noria\Approvals\Models\Approval;
use Noria\Approvals\Models\ApprovalEvent;
use Noria\Approvals\Operation;
use Noria\Approvals\Requirement;
use Noria\Approvals\Status;
use Noria\Approvals\Step;
use Noria\Approvals\Tests\Fixtures\Contract;
use Noria\Approvals\Tests\Fixtures\Invoice;

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
