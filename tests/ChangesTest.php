<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Noria\Approvals\Events\ApprovalRequested;
use Noria\Approvals\Exceptions\ApprovalException;
use Noria\Approvals\Exceptions\ApprovalRequired;
use Noria\Approvals\Operation;
use Noria\Approvals\Snapshot;
use Noria\Approvals\Status;
use Noria\Approvals\Tests\Fixtures\Contract;
use Noria\Approvals\Tests\Fixtures\Document;
use Noria\Approvals\Tests\Fixtures\Drawing;
use Noria\Approvals\Tests\Fixtures\Invoice;
use Noria\Approvals\Tests\Fixtures\Memo;
use Noria\Approvals\Tests\Fixtures\Payout;
use Noria\Approvals\Tests\Fixtures\Tag;

beforeEach(function (): void {
    $this->maker = person('Amina');
    $this->checker = person('Brian');
    $this->actingAs($this->maker);
});

function invoice(array $attributes = []): Invoice
{
    $invoice = new Invoice(['number' => 'INV-001', 'amount' => '1500.00', ...$attributes]);
    $invoice->saveWithoutApproval();

    return $invoice;
}

describe('creating', function (): void {
    it('holds a new record and writes nothing until it is approved', function (): void {
        Event::fake([ApprovalRequested::class]);
        $invoice = new Invoice(['number' => 'INV-001', 'amount' => '1500.00']);

        expect($invoice->save())->toBeFalse()
            ->and($invoice->exists)->toBeFalse()
            ->and(Invoice::query()->count())->toBe(0)
            ->and($invoice->wasHeldForApproval())->toBeTrue();

        $approval = $invoice->heldApproval();
        expect($approval->operation)->toBe(Operation::Create)
            ->and($approval->status)->toBe(Status::Pending)
            ->and((string) $approval->maker_id)->toBe((string) $this->maker->id)
            ->and($approval->new_state)->toMatchArray(['number' => 'INV-001', 'amount' => '1500.00'])
            ->and($approval->subject_id)->toBeNull();
        Event::assertDispatched(ApprovalRequested::class, fn (ApprovalRequested $event): bool => $event->approval->is($approval));
    });

    it('creates the record once approved and links the approval to it', function (): void {
        Invoice::query()->create(['number' => 'INV-002', 'amount' => '10.00']);
        $approval = latestApproval();

        approvals()->approve($approval, $this->checker->id);

        $invoice = Invoice::query()->sole();
        expect($invoice->number)->toBe('INV-002')
            ->and((string) $approval->fresh()?->subject_id)->toBe((string) $invoice->id)
            ->and($approval->fresh()?->status)->toBe(Status::Approved);
    });

    it('keeps a known key, so a ulid record is created with the id it was given', function (): void {
        $document = new Document(['title' => 'Site plan']);
        $document->save();
        $approval = $document->heldApproval();

        expect($approval->subject_id)->toBe($document->id);

        approvals()->approve($approval, $this->checker->id);

        expect(Document::query()->sole()->id)->toBe($document->id);
    });
});

describe('updating', function (): void {
    it('holds an edit and leaves the stored row as it was', function (): void {
        $invoice = invoice();

        expect($invoice->update(['amount' => '2500.00']))->toBeFalse()
            ->and($invoice->fresh()?->amount)->toBe('1500.00');

        $approval = $invoice->heldApproval();
        expect($approval->operation)->toBe(Operation::Update)
            ->and($approval->new_state)->toBe(['amount' => '2500.00'])
            ->and($approval->changes())->toHaveCount(1)
            ->and($approval->changes()[0]['field'])->toBe('amount')
            ->and((float) $approval->changes()[0]['from'])->toBe(1500.0);
    });

    it('applies only the fields the request changed', function (): void {
        $invoice = invoice();
        $invoice->update(['amount' => '2500.00']);
        $approval = $invoice->heldApproval();
        Invoice::query()->whereKey($invoice->id)->update(['notes' => 'Added later']);

        approvals()->approve($approval, $this->checker->id);

        expect($invoice->fresh())->amount->toBe('2500.00')->notes->toBe('Added later');
    });

    it('lets edits to ignored fields through without review', function (): void {
        $invoice = invoice();

        expect($invoice->update(['viewed_at' => now()]))->toBeTrue()
            ->and($invoice->fresh()?->viewed_at)->not->toBeNull()
            ->and($invoice->approvals()->count())->toBe(0);
    });

    it('lets the model’s own timestamps change without review, whatever they are named', function (): void {
        $invoice = invoice();
        $memo = Memo::query()->create(['body' => 'Site visit at 9']);
        $this->travel(1)->day();

        expect($invoice->touch())->toBeTrue()
            ->and($memo->touch())->toBeTrue()
            ->and($memo->update(['written_on' => now()->subYear()]))->toBeTrue()
            ->and($memo->update(['body' => 'Site visit at 10']))->toBeFalse()
            ->and(array_keys($memo->heldApproval()->new_state ?? []))->toBe(['body']);
    });

    it('lets the host ignore more fields on every model', function (): void {
        config(['approvals.ignored' => ['notes']]);
        $invoice = invoice();

        expect($invoice->update(['notes' => 'Called the supplier']))->toBeTrue()
            ->and($invoice->update(['notes' => 'Again', 'amount' => '9.00']))->toBeFalse()
            ->and($invoice->heldApproval()->new_state)->toMatchArray(['notes' => 'Again', 'amount' => '9.00']);
    });

    it('restores without review when restoring needs none, yet still holds a hand-set deletion', function (): void {
        $memo = Memo::query()->create(['body' => 'Draft']);

        expect($memo->delete())->toBeTrue()
            ->and($memo->restore())->toBeTrue()
            ->and(Memo::query()->count())->toBe(1)
            ->and($memo->isRestoringWithApproval())->toBeFalse()
            ->and($memo->update(['removed_on' => now()]))->toBeFalse()
            ->and(Memo::query()->count())->toBe(1);
    });

    it('refuses a second request while one is open', function (): void {
        $invoice = invoice();
        $invoice->update(['amount' => '2500.00']);

        expect(fn () => $invoice->fresh()?->update(['amount' => '3000.00']))
            ->toThrow(ApprovalException::class, 'already has a change waiting');
    });

    it('fails a stale request when someone changed the same field since', function (): void {
        $invoice = invoice();
        $invoice->update(['amount' => '2500.00']);
        $approval = $invoice->heldApproval();
        Invoice::query()->whereKey($invoice->id)->update(['amount' => '1800.00']);

        expect(fn () => approvals()->approve($approval, $this->checker->id))
            ->toThrow(ApprovalException::class, 'changed after this request was made: amount');
        expect($approval->fresh()?->status)->toBe(Status::Pending)
            ->and($approval->events()->pluck('action')->all())->toBe(['submitted', 'failed']);
    });

    it('overwrites a stale field when the host prefers the request to win', function (): void {
        config(['approvals.on_conflict' => 'overwrite']);
        $invoice = invoice();
        $invoice->update(['amount' => '2500.00']);
        Invoice::query()->whereKey($invoice->id)->update(['amount' => '1800.00']);

        approvals()->approve($invoice->heldApproval(), $this->checker->id);

        expect($invoice->fresh()?->amount)->toBe('2500.00');
    });
});

describe('deleting and restoring', function (): void {
    it('holds a soft delete, a restore and a permanent delete', function (): void {
        $invoice = invoice();

        expect($invoice->delete())->toBeFalse();
        approvals()->approve($invoice->heldApproval(), $this->checker->id);
        expect(Invoice::query()->count())->toBe(0)->and(Invoice::withTrashed()->count())->toBe(1);

        $trashed = Invoice::withTrashed()->sole();
        expect($trashed->restore())->toBeFalse()
            ->and($trashed->heldApproval()->operation)->toBe(Operation::Restore);
        approvals()->approve($trashed->heldApproval(), $this->checker->id);
        expect(Invoice::query()->count())->toBe(1);

        $live = Invoice::query()->sole();
        expect($live->forceDelete())->toBeFalse()
            ->and($live->heldApproval()->operation)->toBe(Operation::ForceDelete);
        approvals()->approve($live->heldApproval(), $this->checker->id);
        expect(Invoice::withTrashed()->count())->toBe(0);
    });

    it('records the state being deleted so reviewers can see it', function (): void {
        $invoice = invoice();
        $invoice->delete();

        expect($invoice->heldApproval()->current_state)->toMatchArray(['number' => 'INV-001'])
            ->and($invoice->heldApproval()->changes())->toContain(['field' => 'number', 'from' => 'INV-001', 'to' => null]);
    });

    it('reports a missing record instead of applying to nothing', function (): void {
        $invoice = invoice();
        $invoice->delete();
        $approval = $invoice->heldApproval();
        DB::table('invoices')->delete();

        expect(fn () => approvals()->approve($approval, $this->checker->id))->toThrow(ApprovalException::class, 'no longer exists');
    });
});

describe('choosing what needs approval', function (): void {
    it('only holds the operations a model lists', function (): void {
        $contract = Contract::query()->create(['title' => 'Supply', 'value' => '50.00']);

        expect($contract->exists)->toBeTrue()
            ->and($contract->update(['value' => '60.00']))->toBeFalse();
    });

    it('skips review inside withoutApproval and restores enforcement afterwards', function (): void {
        approvals()->withoutApproval(function (): void {
            Invoice::query()->create(['number' => 'INV-010']);
            Invoice::query()->create(['number' => 'INV-011']);
        });

        expect(Invoice::query()->count())->toBe(2)
            ->and(approvals()->bypassed())->toBeFalse();

        Invoice::query()->create(['number' => 'INV-012']);
        expect(Invoice::query()->count())->toBe(2);
    });

    it('skips review for one record without touching others', function (): void {
        $first = new Invoice(['number' => 'INV-020']);
        $second = new Invoice(['number' => 'INV-021']);

        expect($first->saveWithoutApproval())->toBeTrue()
            ->and($first->isBypassingApproval())->toBeFalse()
            ->and($second->save())->toBeFalse();
    });

    it('offers a bypass for every write on the record itself', function (): void {
        $invoice = invoice();

        expect($invoice->updateWithoutApproval(['amount' => '10.00']))->toBeTrue()
            ->and($invoice->deleteWithoutApproval())->toBeTrue()
            ->and($invoice->restoreWithoutApproval())->toBeTrue()
            ->and($invoice->forceDeleteWithoutApproval())->toBeTrue()
            ->and(Invoice::withTrashed()->count())->toBe(0)
            ->and($invoice->approvals()->count())->toBe(0);
    });

    it('skips review entirely when approvals are switched off', function (): void {
        config(['approvals.enforced' => false]);

        expect(Invoice::query()->create(['number' => 'INV-030'])->exists)->toBeTrue();
    });

    it('throws instead of returning false when the host asks it to', function (): void {
        config(['approvals.intercept' => 'throw']);

        try {
            Invoice::query()->create(['number' => 'INV-040']);
            $this->fail('The create was not held.');
        } catch (ApprovalRequired $required) {
            expect($required->approval->operation)->toBe(Operation::Create);
        }
    });

    it('keeps the maker notes with the request', function (): void {
        $invoice = invoice();
        $invoice->withApprovalNotes('Supplier raised the price')->update(['amount' => '1600.00']);

        expect($invoice->heldApproval()->maker_notes)->toBe('Supplier raised the price')
            ->and($invoice->pullApprovalNotes())->toBeNull();
    });
});

describe('changes made with nobody signed in', function (): void {
    beforeEach(fn () => auth()->logout());

    it('holds them for review by default', function (): void {
        Invoice::query()->create(['number' => 'INV-050']);

        expect(latestApproval()->maker_id)->toBeNull()
            ->and(Invoice::query()->count())->toBe(0);
    });

    it('lets them through when the host allows it', function (): void {
        config(['approvals.without_actor' => 'allow']);

        expect(Invoice::query()->create(['number' => 'INV-051'])->exists)->toBeTrue();
    });

    it('rejects them when the host requires a signed-in user', function (): void {
        config(['approvals.without_actor' => 'reject']);

        expect(fn () => Invoice::query()->create(['number' => 'INV-052']))->toThrow(ApprovalException::class, 'signed-in user');
    });
});

describe('sensitive fields', function (): void {
    it('encrypts listed fields in the stored request and restores them on approval', function (): void {
        $invoice = invoice();
        $invoice->update(['secret' => 'pin-4471']);
        $approval = $invoice->heldApproval();
        $stored = DB::table('approvals')->where('id', $approval->id)->value('new_state');

        expect($stored)->toBeString()->not->toContain('pin-4471')
            ->and($approval->changes())->toBe([['field' => 'secret', 'from' => null, 'to' => Snapshot::HIDDEN]]);

        approvals()->approve($approval, $this->checker->id);

        expect($invoice->fresh()?->secret)->toBe('pin-4471');
    });
});

describe('records saved before they are approved', function (): void {
    it('hides a submitted record until it is approved', function (): void {
        $document = new Document(['title' => 'Draft drawing']);
        $document->saveWithoutApproval();
        $approval = $document->submitForApproval('Ready for sign-off');

        expect(Document::query()->count())->toBe(0)
            ->and(Document::query()->withUnapproved()->count())->toBe(1);

        approvals()->approve($approval, $this->checker->id);

        expect(Document::query()->count())->toBe(1);
    });

    it('keeps a record hidden while its maker reworks it, and removes it when declined', function (): void {
        $document = new Document(['title' => 'Draft drawing']);
        $document->saveWithoutApproval();
        approvals()->requestChanges($document->submitForApproval(), $this->checker->id, 'Wrong scale');

        expect(Document::query()->count())->toBe(0)
            ->and(Document::query()->withUnapproved()->count())->toBe(1);

        approvals()->decline($document->submitForApproval(), $this->checker->id);

        expect(Document::query()->withUnapproved()->count())->toBe(0);
    });

    it('lets a model decide how to discard a declined record', function (): void {
        $drawing = new Drawing(['title' => 'Draft']);
        $drawing->saveWithoutApproval();

        approvals()->decline($drawing->submitForApproval(), $this->checker->id);

        expect(Document::query()->withUnapproved()->sole()->title)->toBe('Rejected: Draft');
    });

    it('refuses a submission without anyone signed in when the host requires one', function (): void {
        config(['approvals.without_actor' => 'reject']);
        $document = new Document(['title' => 'Draft']);
        $document->saveWithoutApproval();
        auth()->logout();

        expect(fn () => $document->submitForApproval())->toThrow(ApprovalException::class, 'signed-in user');
    });

    it('refuses to submit a record that was never saved', function (): void {
        expect(fn () => (new Document(['title' => 'Unsaved']))->submitForApproval())->toThrow(InvalidArgumentException::class);
    });
});

describe('relationship writes', function (): void {
    it('holds a pivot write and applies it on approval', function (): void {
        $invoice = invoice();
        $tags = collect([Tag::query()->create(['name' => 'urgent']), Tag::query()->create(['name' => 'site'])]);

        $approval = $invoice->approvalRelation('tags')->withNotes('Flag it')->attach($tags, ['note' => 'from review']);

        expect($invoice->tags()->count())->toBe(0)
            ->and($approval?->operation)->toBe(Operation::Relation)
            ->and($approval?->payload)->toMatchArray(['relation' => 'tags', 'method' => 'attach']);

        approvals()->approve($approval, $this->checker->id);

        expect($invoice->tags()->pluck('name')->sort()->values()->all())->toBe(['site', 'urgent'])
            ->and($invoice->tags()->first()?->pivot?->getAttribute('note'))->toBe('from review');
    });

    it('supports every many-to-many write', function (string $method, array $arguments, array $expected): void {
        $invoice = invoice();
        $tags = collect(['a', 'b', 'c'])->map(fn (string $name) => Tag::query()->create(['name' => $name]));
        $invoice->tags()->attach($tags[0]->id);

        $approval = $invoice->approvalRelation('tags')->{$method}(...array_map(fn (mixed $argument) => is_callable($argument) ? $argument($tags) : $argument, $arguments));
        approvals()->approve($approval, $this->checker->id);

        expect($invoice->tags()->orderBy('name')->pluck('name')->all())->toBe($expected);
    })->with([
        'detach' => ['detach', [fn ($tags) => $tags[0]->id], []],
        'sync' => ['sync', [fn ($tags) => [$tags[1]->id, $tags[2]->id]], ['b', 'c']],
        'sync without detaching' => ['syncWithoutDetaching', [fn ($tags) => [$tags[1]->id]], ['a', 'b']],
        'sync with pivot values' => ['syncWithPivotValues', [fn ($tags) => [$tags[2]->id], ['note' => 'x']], ['c']],
        'toggle' => ['toggle', [fn ($tags) => [$tags[0]->id, $tags[1]->id]], ['b']],
        'update existing pivot' => ['updateExistingPivot', [fn ($tags) => $tags[0]->id, ['note' => 'y']], ['a']],
    ]);

    it('writes straight through when the policy needs no approval', function (): void {
        config(['approvals.subject_policies' => [Invoice::class => ['operations' => ['delete']]]]);
        $invoice = invoice();
        $tag = Tag::query()->create(['name' => 'urgent']);

        expect($invoice->approvalRelation('tags')->attach($tag))->toBeNull()
            ->and($invoice->tags()->count())->toBe(1);
    });

    it('refuses a relationship write on a record that was never saved', function (): void {
        expect(fn () => (new Invoice)->approvalRelation('tags')->attach(1))->toThrow(InvalidArgumentException::class, 'Save the record');
    });

    it('writes straight through when review is bypassed', function (): void {
        $invoice = invoice();
        $tag = Tag::query()->create(['name' => 'urgent']);

        approvals()->withoutApproval(fn () => $invoice->approvalRelation('tags')->attach($tag->id));

        expect($invoice->tags()->count())->toBe(1);
    });

    it('rejects relations that are not many-to-many and methods that are not writes', function (): void {
        $invoice = invoice();

        expect(fn () => approvals()->relation($invoice, 'tags', 'delete', []))->toThrow(InvalidArgumentException::class)
            ->and(fn () => approvals()->relation($invoice, 'approvals', 'attach', [1]))->toThrow(InvalidArgumentException::class);
    });
});

describe('actions', function (): void {
    it('holds an action and runs it once approved', function (): void {
        $approval = approvals()->action(new Payout('0712345678', 2500), 'Weekly wages');

        expect(Payout::$paid)->toBe([])
            ->and($approval?->operation)->toBe(Operation::Action)
            ->and($approval?->summary())->toBe('Action Payout');

        approvals()->approve($approval, $this->checker->id);

        expect(Payout::$paid)->toBe([['account' => '0712345678', 'amount' => 2500, 'approval' => $approval?->id]]);
    });

    it('runs an action at once while review is bypassed', function (): void {
        approvals()->withoutApproval(fn () => approvals()->action(new Payout('0700000001', 5)));

        expect(Payout::$paid)->toHaveCount(1);
    });

    it('runs an action at once when no approval is needed', function (): void {
        config(['approvals.subject_policies' => [Payout::class => ['approve' => false]]]);

        expect(approvals()->action(new Payout('0700000000', 10)))->toBeNull()
            ->and(Payout::$paid)->toHaveCount(1);
    });
});
