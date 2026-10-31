<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Noria\Approvals\Events\ApprovalAdvanced;
use Noria\Approvals\Events\ApprovalClosed;
use Noria\Approvals\Events\ApprovalReminderDue;
use Noria\Approvals\Events\ApprovalStuck;
use Noria\Approvals\Exceptions\ApprovalException;
use Noria\Approvals\Requirement;
use Noria\Approvals\Status;
use Noria\Approvals\Step;
use Noria\Approvals\Tests\Fixtures\Document;
use Noria\Approvals\Tests\Fixtures\Invoice;

beforeEach(function (): void {
    $this->maker = person('Amina');
    $this->checker = person('Brian');
    $this->director = person('Dan');
    $this->actingAs($this->maker);
});

function scheduled(Requirement $requirement): Invoice
{
    config(['approvals.subject_policies' => [Invoice::class => $requirement->toArray()]]);
    $invoice = new Invoice(['number' => 'INV-500']);
    $invoice->saveWithoutApproval();
    $invoice->update(['amount' => '800.00']);

    return $invoice;
}

describe('expiry', function (): void {
    it('expires requests past their deadline and refuses decisions on them', function (): void {
        Event::fake([ApprovalClosed::class]);
        $approval = scheduled(Requirement::of(new Step('review'))->expiringAfter(60))->heldApproval();

        expect(approvals()->expire())->toBe(0);

        $this->travel(61)->minutes();
        expect(fn () => approvals()->approve($approval, $this->checker->id))->toThrow(ApprovalException::class, 'expired')
            ->and(approvals()->canReview($approval, $this->checker->id))->toBeFalse()
            ->and(approvals()->expire())->toBe(1)
            ->and($approval->fresh()?->status)->toBe(Status::Expired)
            ->and(approvals()->expire())->toBe(0);
        Event::assertDispatched(ApprovalClosed::class, fn (ApprovalClosed $event): bool => $event->approval->status === Status::Expired);
    });

    it('removes a submitted record whose request expired', function (): void {
        config(['approvals.subject_policies' => [Document::class => ['steps' => [['name' => 'review']], 'expires_after' => 5]]]);
        $document = new Document(['title' => 'Draft']);
        $document->saveWithoutApproval();
        $document->submitForApproval();

        $this->travel(6)->minutes();
        approvals()->expire();

        expect(Document::query()->withUnapproved()->count())->toBe(0);
    });
});

describe('escalation', function (): void {
    it('hands an overdue step to its escalation step and starts its count afresh', function (): void {
        Event::fake([ApprovalAdvanced::class]);
        $approval = scheduled(Requirement::of(new Step('finance', approvals: 2, escalateAfter: 30, escalateTo: new Step('director', users: [3]))))->heldApproval();
        approvals()->approve($approval, $this->checker->id);

        $this->travel(31)->minutes();
        expect(approvals()->escalate())->toBe(1);

        $escalated = $approval->fresh();
        expect($escalated?->currentStep()?->name)->toBe('director')
            ->and($escalated?->stage)->toBe(2)
            ->and($escalated?->approvalsAtStage())->toBe(0)
            ->and($escalated?->escalates_at)->toBeNull();
        Event::assertDispatched(ApprovalAdvanced::class, fn (ApprovalAdvanced $event): bool => $event->reason === ApprovalAdvanced::ESCALATED && $event->fromStep === 'finance');

        approvals()->approve($escalated, $this->director->id);
        expect($approval->fresh()?->status)->toBe(Status::Approved);
    });

    it('reports an overdue step with nowhere to go, once', function (): void {
        Event::fake([ApprovalStuck::class]);
        scheduled(Requirement::of(new Step('finance', escalateAfter: 10)));

        $this->travel(11)->minutes();
        approvals()->escalate();
        approvals()->escalate();

        Event::assertDispatchedTimes(ApprovalStuck::class, 1);
        expect(latestApproval()->events()->pluck('action')->all())->toBe(['submitted', 'stuck']);
    });

    it('escalates at once when nobody left can approve the step', function (): void {
        $approval = scheduled(Requirement::of(new Step('owner', users: [1], escalateTo: new Step('director', users: [3]))))->heldApproval();

        expect(approvals()->reviewersFor($approval))->toBe([])
            ->and(approvals()->escalate())->toBe(1)
            ->and($approval->fresh()?->currentStep()?->name)->toBe('director');
    });

    it('leaves steps alone that are neither due nor stuck', function (): void {
        scheduled(Requirement::of(new Step('finance', escalateAfter: 60)));

        expect(approvals()->escalate())->toBe(0)
            ->and(latestApproval()->events()->count())->toBe(1);
    });

    it('sets the escalation clock again when a request moves to the next step', function (): void {
        $this->freezeTime();
        $approval = scheduled(Requirement::of(new Step('first'), new Step('second', escalateAfter: 45)))->heldApproval();

        expect($approval->escalates_at)->toBeNull();

        approvals()->approve($approval, $this->checker->id);

        expect($approval->fresh()?->escalates_at?->toDateTimeString())->toBe(now()->addMinutes(45)->toDateTimeString());
    });
});

describe('reminders', function (): void {
    it('reminds reviewers of a waiting request once per interval', function (): void {
        Event::fake([ApprovalReminderDue::class]);
        scheduled(Requirement::of(new Step('named', users: [2, 3])));

        expect(approvals()->remind(60))->toBe(0);

        $this->travel(61)->minutes();
        expect(approvals()->remind(60))->toBe(1)
            ->and(approvals()->remind(60))->toBe(0);
        Event::assertDispatched(ApprovalReminderDue::class, fn (ApprovalReminderDue $event): bool => $event->reviewerIds === [2, 3]);

        $this->travel(61)->minutes();
        expect(approvals()->remind(60))->toBe(1);
    });

    it('uses the configured interval by default', function (): void {
        config(['approvals.reminders.after' => 30]);
        scheduled(Requirement::of(new Step('review')));

        $this->travel(31)->minutes();

        expect(approvals()->remind())->toBe(1);
    });
});

describe('the sweep command', function (): void {
    it('runs every task and reports what it did', function (): void {
        scheduled(Requirement::of(new Step('review'))->expiringAfter(5));
        $this->travel(10)->minutes();

        $this->artisan('approvals:sweep')
            ->expectsOutputToContain('Expire')
            ->expectsOutputToContain('Escalate')
            ->expectsOutputToContain('Remind')
            ->assertSuccessful();

        expect(latestApproval()->status)->toBe(Status::Expired);
    });

    it('runs only the tasks asked for', function (): void {
        scheduled(Requirement::of(new Step('review'))->expiringAfter(5));
        $this->travel(10)->minutes();

        $this->artisan('approvals:sweep', ['--only' => ['remind'], '--remind-after' => 1])->assertSuccessful();

        expect(latestApproval()->status)->toBe(Status::Pending)
            ->and(latestApproval()->reminded_at)->not->toBeNull();
    });

    it('rejects a task it does not know', function (): void {
        $this->artisan('approvals:sweep', ['--only' => ['purge']])->assertFailed();
    });
});
