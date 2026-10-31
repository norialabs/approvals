<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Noria\Approvals\ApprovalsServiceProvider;
use Noria\Approvals\Models\Approval;
use Noria\Approvals\Models\ApprovalDelegation;
use Noria\Approvals\Models\ApprovalEvent;
use Noria\Approvals\Models\ApprovalPolicy;
use Noria\Approvals\Settings;
use Noria\Approvals\Status;
use Noria\Approvals\Tests\Fixtures\Contract;
use Noria\Approvals\Tests\Fixtures\Document;
use Noria\Approvals\Tests\Fixtures\Invoice;

final class UlidApproval extends Approval
{
    use HasUlids;
}

final class CustomApproval extends Approval
{
    public function headline(): string
    {
        return 'Custom: '.$this->summary();
    }
}

function subjectFor(string $key): Model
{
    $subject = match ($key) {
        'ulid' => new Document(['title' => 'Plan']),
        'uuid' => new Contract(['title' => 'Supply', 'value' => '10.00']),
        default => new Invoice(['number' => 'INV-1']),
    };
    $subject->saveWithoutApproval();

    return $subject;
}

function editSubject(Model $subject): void
{
    $subject->update($subject instanceof Invoice ? ['amount' => '99.00'] : ($subject instanceof Contract ? ['value' => '20.00'] : ['title' => 'Plan B']));
}

it('runs the whole cycle with every key type for its own tables, actors and subjects', function (string $own, string $actor, string $subject): void {
    $this->rebuild(['approvals.keys' => $own, 'approvals.actors.key' => $actor, 'approvals.subjects.key' => $subject]);
    $maker = person('Amina');
    $checker = person('Brian');
    $delegate = person('Cate');
    config(['approvals.default' => ['steps' => [['name' => 'owner', 'users' => [$checker->getKey()]]]]]);
    $this->actingAs($maker);
    $model = subjectFor($subject);

    editSubject($model);
    $approval = $model->heldApproval();
    approvals()->delegate($checker->id, $delegate->id);
    approvals()->approve(Approval::query()->findOrFail($approval?->getKey()), $delegate->id);

    $fresh = Approval::query()->findOrFail($approval?->getKey());
    $id = (string) $fresh->getKey();
    expect($fresh->status)->toBe(Status::Approved)
        ->and(match ($own) {
            'int' => ctype_digit($id),
            'ulid' => Str::isUlid($id),
            default => Str::isUuid($id),
        })->toBeTrue()
        ->and($fresh->maker?->getKey())->toBe($maker->getKey())
        ->and($fresh->closer?->getKey())->toBe($delegate->getKey())
        ->and((string) $fresh->subject_id)->toBe((string) $model->getKey())
        ->and($fresh->subject?->getKey())->toBe($model->getKey())
        ->and($fresh->events()->where('action', 'approved')->sole()->principal?->getKey())->toBe($checker->getKey())
        ->and(ApprovalDelegation::query()->sole()->delegate?->getKey())->toBe($delegate->getKey())
        ->and(ApprovalPolicy::query()->create(['subject_type' => 'invoice', 'definition' => ['steps' => []]])->getKey())->not->toBeNull();
})->with(['int', 'ulid', 'uuid'])->with(['int', 'ulid', 'uuid'])->with(['int', 'ulid', 'uuid', 'string']);

it('creates an integer-keyed record and links it once it has a key', function (): void {
    $this->rebuild(['approvals.subjects.key' => 'int']);
    $this->actingAs(person('Amina'));
    Invoice::query()->create(['number' => 'INV-2']);
    approvals()->approve(latestApproval(), person('Brian')->id);

    expect(latestApproval()->subject?->getKey())->toBe(Invoice::query()->sole()->getKey());
});

it('names every table as the host chooses, including the actors table', function (): void {
    $this->rebuild([
        'approvals.tables' => ['approvals' => 'reviews', 'events' => 'review_log', 'delegations' => 'review_cover', 'policies' => 'review_rules'],
        'approvals.actors.table' => 'staff',
    ]);

    foreach (['reviews', 'review_log', 'review_cover', 'review_rules', 'staff'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect(Schema::hasTable('approvals'))->toBeFalse();

    $this->actingAs(person('Amina'));
    Invoice::query()->create(['number' => 'INV-3']);

    expect(Approval::query()->count())->toBe(1)
        ->and(ApprovalEvent::query()->count())->toBe(1)
        ->and((new Approval)->getTable())->toBe('reviews');
});

it('can leave actor columns unconstrained for actors kept elsewhere', function (): void {
    $this->rebuild(['approvals.actors.constrained' => false]);
    $this->actingAs(person('Amina'));

    approvals()->delegate(999, 1000);
    Invoice::query()->create(['number' => 'INV-4']);

    expect(ApprovalDelegation::query()->sole()->delegator_id)->toBe(999);
});

it('uses the host’s own approval model', function (): void {
    config(['approvals.models.approval' => CustomApproval::class]);
    $this->actingAs(person('Amina'));
    $invoice = new Invoice(['number' => 'INV-5']);
    $invoice->save();

    expect($invoice->heldApproval())->toBeInstanceOf(CustomApproval::class)
        ->and(CustomApproval::query()->sole()->headline())->toBe('Custom: Create Invoice');
});

it('accepts a subclass that brings Laravel’s own unique-id trait', function (): void {
    config(['approvals.models.approval' => UlidApproval::class]);
    $this->actingAs(person('Amina'));
    $invoice = new Invoice(['number' => 'INV-6']);
    $invoice->save();

    expect($invoice->heldApproval())->toBeInstanceOf(UlidApproval::class)
        ->and(Str::isUlid((string) $invoice->heldApproval()->getKey()))->toBeTrue();
});

it('rejects settings it cannot honour', function (array $config, string $message): void {
    config($config);

    expect(fn () => match (true) {
        isset($config['approvals.keys']) => Settings::keyType(),
        isset($config['approvals.models.approval']) => Settings::approvalModel(),
        isset($config['approvals.intercept']) => Settings::throwsOnIntercept(),
        default => Settings::table('reports'),
    })->toThrow(InvalidArgumentException::class, $message);
})->with([
    'string keys for own tables' => [['approvals.keys' => 'string'], 'int, ulid or uuid'],
    'unknown key type' => [['approvals.keys' => 'snowflake'], 'must be one of'],
    'model that is not an approval' => [['approvals.models.approval' => stdClass::class], 'must extend'],
    'unknown intercept mode' => [['approvals.intercept' => 'ignore'], 'must be one of'],
    'unknown table' => [[], 'Unknown approvals table'],
]);

it('reads settings that arrive as strings from the environment', function (): void {
    config(['approvals.enforced' => 'false', 'approvals.reminders.after' => 'soon']);

    expect(Settings::enforced())->toBeFalse()
        ->and(Settings::reminderMinutes())->toBe(1440)
        ->and(Settings::subjectPolicy(Invoice::class))->toBeNull();
});

it('leaves its migrations to the host when asked', function (): void {
    config(['approvals.run_migrations' => false]);
    $paths = app()->make('migrator')->paths();

    (new ApprovalsServiceProvider(app()))->boot();

    expect(app()->make('migrator')->paths())->toBe($paths);
});
