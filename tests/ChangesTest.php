<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Noria\Approvals\Events\ApprovalClosed;
use Noria\Approvals\Events\ApprovalRequested;
use Noria\Approvals\Exceptions\ApprovalException;
use Noria\Approvals\Exceptions\ApprovalRequired;
use Noria\Approvals\Models\Approval;
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

    it('keeps a structured column as a structure and applies it over the database\'s own spelling of the same value', function (): void {
        $invoice = invoice();
        Invoice::query()->whereKey($invoice->id)->update(['meta' => '{"source": "manual", "lines": [1]}']);

        $edited = Invoice::query()->findOrFail($invoice->id);
        $edited->update(['meta' => ['source' => 'import', 'lines' => [1, 2]]]);
        $approval = $edited->heldApproval();

        expect($approval?->new_state['meta'] ?? null)->toEqual(['source' => 'import', 'lines' => [1, 2]])
            ->and($approval?->current_state['meta'] ?? null)->toEqual(['source' => 'manual', 'lines' => [1]]);

        Invoice::query()->whereKey($invoice->id)->update(['meta' => '{"lines":[1],"source":"manual"}']);
        approvals()->approve($approval, $this->checker->id);

        expect($invoice->fresh()?->meta)->toEqual(['source' => 'import', 'lines' => [1, 2]]);
    });

    it('compares structured values by content, but keeps list order', function (): void {
        expect(Snapshot::same('{"b": 1, "a": [1, 2]}', ['a' => [1, 2], 'b' => 1]))->toBeTrue()
            ->and(Snapshot::same('[1, 2]', [2, 1]))->toBeFalse()
            ->and(Snapshot::same('{not json', '{not json'))->toBeTrue();
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
    it('hides fields whose casts encrypt or hash them', function (): void {
        $memo = Memo::query()->create(['body' => 'Gate code']);
        $memo->update(['pin' => '4471', 'body' => 'Gate code, new']);

        expect(collect($memo->heldApproval()->changes())->firstWhere('field', 'pin'))->toBe(['field' => 'pin', 'from' => null, 'to' => Snapshot::HIDDEN]);
    });

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

    it('carries extras with a submitted record and applies them on approval, or at once without review', function (): void {
        $tag = Tag::query()->create(['name' => 'urgent']);
        $held = invoice();
        $approval = $held->withApprovalExtras(['tags' => [$tag->id]])->submitForApproval();

        expect($approval?->extras())->toBe(['tags' => [$tag->id]])
            ->and($held->tags()->count())->toBe(0);

        approvals()->approve($approval, $this->checker->id);
        expect($held->tags()->count())->toBe(1);

        config(['approvals.subject_policies' => [Invoice::class => ['approve' => false]]]);
        $direct = invoice(['number' => 'INV-950']);

        expect($direct->withApprovalExtras(['tags' => [$tag->id]])->submitForApproval())->toBeNull()
            ->and($direct->tags()->count())->toBe(1);

        config(['approvals.subject_policies' => [Invoice::class => ['automatic' => 'trusted']]]);
        $automatic = invoice(['number' => 'INV-951']);

        expect($automatic->withApprovalExtras(['tags' => [$tag->id]])->submitForApproval())->toBeNull()
            ->and($automatic->tags()->count())->toBe(1)
            ->and($automatic->approvals()->sole()->extras())->toBe(['tags' => [$tag->id]]);
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

describe('automatic approvals', function (): void {
    beforeEach(fn () => config(['approvals.subject_policies' => [Invoice::class => ['automatic' => 'trusted supplier']]]));

    it('lets a create through and records it as approved, linked to the new row', function (): void {
        Event::fake([ApprovalClosed::class]);

        $invoice = Invoice::query()->create(['number' => 'INV-700', 'amount' => '50.00']);
        $approval = $invoice->approvals()->sole();

        expect($invoice->exists)->toBeTrue()
            ->and($approval->status)->toBe(Status::Approved)
            ->and($approval->isAutomatic())->toBeTrue()
            ->and($approval->closed_at)->not->toBeNull()
            ->and((string) $approval->maker_id)->toBe((string) $this->maker->id)
            ->and($approval->events()->sole()->only(['action', 'notes']))->toBe(['action' => 'auto_approved', 'notes' => 'trusted supplier'])
            ->and(Invoice::$applied)->toBe([$approval->id]);
        Event::assertDispatched(ApprovalClosed::class);
    });

    it('records the fields an automatic update changed, and a delete', function (): void {
        $invoice = invoice();

        expect($invoice->update(['amount' => '75.00']))->toBeTrue()
            ->and($invoice->approvals()->sole()->new_state)->toBe(['amount' => '75.00'])
            ->and($invoice->delete())->toBeTrue()
            ->and($invoice->approvals()->pluck('operation')->map->value->all())->toBe(['update', 'delete']);
    });

    it('records automatic relationship writes, actions and submissions', function (): void {
        config(['approvals.subject_policies' => [
            Invoice::class => ['automatic' => 'trusted'],
            Payout::class => ['automatic' => 'small payout'],
            Document::class => ['automatic' => 'trusted author'],
        ]]);
        $invoice = invoice();
        $tag = Tag::query()->create(['name' => 'urgent']);
        $document = new Document(['title' => 'Plan']);
        $document->saveWithoutApproval();

        expect($invoice->approvalRelation('tags')->attach($tag->id))->toBeNull()
            ->and($invoice->tags()->count())->toBe(1)
            ->and(approvals()->action(new Payout('0700000002', 9)))->toBeNull()
            ->and(Payout::$paid[0]['approval'])->not->toBeNull()
            ->and($document->submitForApproval())->toBeNull()
            ->and(Document::query()->count())->toBe(1)
            ->and(Approval::query()->where('status', 'approved')->count())->toBe(3);
    });
});

describe('extras', function (): void {
    it('holds extras with the change and applies them only on approval', function (): void {
        $tag = Tag::query()->create(['name' => 'urgent']);
        $invoice = new Invoice(['number' => 'INV-800']);

        expect($invoice->withApprovalExtras(['tags' => [$tag->id]])->save())->toBeFalse()
            ->and($invoice->heldApproval()->extras())->toBe(['tags' => [$tag->id]]);

        approvals()->approve($invoice->heldApproval(), $this->checker->id);

        expect(Invoice::query()->sole()->tags()->pluck('name')->all())->toBe(['urgent'])
            ->and(Invoice::$applied)->toBe([$invoice->heldApproval()->id]);
    });

    it('holds a save that changes nothing but its extras', function (): void {
        $invoice = invoice();
        $tag = Tag::query()->create(['name' => 'urgent']);

        $approval = $invoice->withApprovalExtras(['tags' => [$tag->id]])->requestApproval(notes: 'Tag it');

        expect($approval?->extras())->toBe(['tags' => [$tag->id]])
            ->and($approval?->maker_notes)->toBe('Tag it')
            ->and($invoice->tags()->count())->toBe(0);

        approvals()->approve($approval, $this->checker->id);

        expect($invoice->tags()->count())->toBe(1);
    });

    it('requests field changes through the same call, and applies extras at once when no review is needed', function (): void {
        $invoice = invoice();
        $tag = Tag::query()->create(['name' => 'urgent']);

        expect($invoice->requestApproval(['amount' => '99.00'])?->new_state)->toBe(['amount' => '99.00']);

        config(['approvals.subject_policies' => [Invoice::class => ['approve' => false]]]);
        $other = invoice(['number' => 'INV-900']);

        expect($other->withApprovalExtras(['tags' => [$tag->id]])->requestApproval())->toBeNull()
            ->and($other->tags()->count())->toBe(1)
            ->and(fn () => (new Invoice)->requestApproval())->toThrow(InvalidArgumentException::class);
    });

    it('applies extras straight after the write when no review is needed', function (string $how): void {
        $tag = Tag::query()->create(['name' => 'urgent']);
        $invoice = new Invoice(['number' => 'INV-801']);
        $invoice->withApprovalExtras(['tags' => [$tag->id]]);

        match ($how) {
            'bypassed' => $invoice->saveWithoutApproval(),
            default => (function () use ($invoice): void {
                config(['approvals.subject_policies' => [Invoice::class => ['approve' => false]]]);
                $invoice->save();
            })(),
        };

        expect($invoice->tags()->count())->toBe(1)
            ->and($invoice->pullApprovalExtras())->toBeNull();
    })->with(['bypassed', 'no review needed']);

    it('lets a policy read the extras', function (): void {
        config(['approvals.subject_policies' => [Invoice::class => [
            'steps' => [['name' => 'review']],
            'rules' => [['when' => [['payload.extras.tags', 'null']], 'approve' => false]],
        ]]]);

        expect((new Invoice(['number' => 'INV-802']))->save())->toBeTrue()
            ->and((new Invoice(['number' => 'INV-803']))->withApprovalExtras(['tags' => [1]])->save())->toBeFalse();
    });
});

describe('actions', function (): void {
    it('binds an action to the record it is about, one open request at a time', function (): void {
        $invoice = invoice();

        $approval = approvals()->action(new Payout('0712345678', 100), 'Pay this invoice', subject: $invoice);

        expect($approval?->subject_type)->toBe($invoice->getMorphClass())
            ->and((string) $approval?->subject_id)->toBe((string) $invoice->id)
            ->and($approval?->resolveSubject()?->is($invoice))->toBeTrue()
            ->and(fn () => approvals()->action(new Payout('0712345678', 100), subject: $invoice))->toThrow(ApprovalException::class, 'already has a change waiting')
            ->and(fn () => approvals()->action(new Payout('0712345678', 100), subject: new Invoice))->toThrow(InvalidArgumentException::class);

        approvals()->approve($approval, $this->checker->id);

        expect(Payout::$paid)->toHaveCount(1);
    });

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
