<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Noria\Approvals\Contracts\Tenancy;
use Noria\Approvals\Defaults\ConfiguredTenancy;
use Noria\Approvals\Models\Approval;
use Noria\Approvals\Models\ApprovalDelegation;
use Noria\Approvals\Models\ApprovalEvent;
use Noria\Approvals\Models\ApprovalPolicy;
use Noria\Approvals\Requirement;
use Noria\Approvals\Status;
use Noria\Approvals\Step;
use Noria\Approvals\Tests\Fixtures\Invoice;
use Noria\Approvals\Tests\Fixtures\Organisation;
use Noria\Approvals\Tests\Fixtures\User;

final class RecordingTenancy implements Tenancy
{
    /** @var list<int|string|null> */
    public array $entered = [];

    public function __construct(private readonly ConfiguredTenancy $configured) {}

    public function of(Model $subject): int|string|null
    {
        return $this->configured->of($subject);
    }

    public function current(): int|string|null
    {
        return $this->configured->current();
    }

    public function within(int|string|null $tenant, Closure $callback): mixed
    {
        $this->entered[] = $tenant;

        return $callback();
    }
}

beforeEach(function (): void {
    $this->rebuild([
        'approvals.tenancy.enabled' => true,
        'approvals.tenancy.column' => 'organisation_id',
        'approvals.tenancy.table' => 'organisations',
        'approvals.tenancy.key' => 'ulid',
    ]);
    $this->north = Organisation::query()->create(['name' => 'North']);
    $this->south = Organisation::query()->create(['name' => 'South']);
    $this->maker = person('Amina', attributes: ['organisation_id' => $this->north->id]);
    $this->checker = person('Brian', attributes: ['organisation_id' => $this->north->id]);
    $this->outsider = person('Sam', attributes: ['organisation_id' => $this->south->id]);
});

function tenantInvoice(Organisation $organisation, User $maker): Invoice
{
    test()->actingAs($maker);
    $invoice = new Invoice(['number' => 'INV-'.$organisation->name, 'organisation_id' => $organisation->id]);
    $invoice->saveWithoutApproval();
    $invoice->update(['amount' => '300.00']);

    return $invoice;
}

it('adds the tenant column the host names to every package table', function (): void {
    foreach (['approvals', 'approval_events', 'approval_delegations', 'approval_policies'] as $table) {
        expect(Schema::hasColumn($table, 'organisation_id'))->toBeTrue()
            ->and(Schema::hasColumn($table, 'tenant_id'))->toBeFalse();
    }
});

it('stamps requests and their trail with the record’s tenant', function (): void {
    $approval = tenantInvoice($this->north, $this->maker)->heldApproval();
    approvals()->approve($approval, $this->checker->id);

    expect($approval->tenantKey())->toBe($this->north->id)
        ->and(ApprovalEvent::query()->pluck('organisation_id')->unique()->all())->toBe([$this->north->id]);
});

it('takes the tenant from the signed-in user when the record has none', function (): void {
    $this->actingAs($this->maker);
    Invoice::query()->create(['number' => 'INV-X']);

    expect(latestApproval()->tenantKey())->toBe($this->north->id);
});

it('lets a model name its own tenant', function (): void {
    $invoice = new class extends Model
    {
        public function approvalTenant(): ?string
        {
            $id = Organisation::query()->where('name', 'South')->value('id');

            return is_string($id) ? $id : null;
        }
    };

    expect(app(Tenancy::class)->of($invoice))->toBe($this->south->id);
});

it('shows each tenant only its own requests', function (): void {
    tenantInvoice($this->north, $this->maker);
    tenantInvoice($this->south, $this->outsider);

    $this->actingAs($this->checker);
    expect(Approval::query()->count())->toBe(1)
        ->and(Approval::query()->forTenant($this->south->id)->count())->toBe(1);

    auth()->logout();
    expect(Approval::query()->count())->toBe(2);

    config(['approvals.tenancy.scope_queries' => false]);
    $this->actingAs($this->checker);
    expect(Approval::query()->count())->toBe(2);
});

it('decides and sweeps inside each request’s tenant', function (): void {
    $recording = app(RecordingTenancy::class);
    app()->instance(Tenancy::class, $recording);
    app()->forgetScopedInstances();
    config(['approvals.subject_policies' => [Invoice::class => Requirement::of(new Step('review'))->expiringAfter(5)->toArray()]]);
    $north = tenantInvoice($this->north, $this->maker)->heldApproval();
    tenantInvoice($this->south, $this->outsider);

    approvals()->approve($north, $this->checker->id);
    $recording->entered = [];
    $this->travel(10)->minutes();
    auth()->logout();

    expect(approvals()->expire())->toBe(1)
        ->and($recording->entered)->toBe([$this->south->id]);
});

it('prefers a tenant’s own stored policy over the shared one', function (): void {
    config(['approvals.database_policies' => true]);
    ApprovalPolicy::query()->create(['subject_type' => (new Invoice)->getMorphClass(), 'organisation_id' => null, 'definition' => ['steps' => [['name' => 'shared']]]]);
    ApprovalPolicy::query()->create(['subject_type' => (new Invoice)->getMorphClass(), 'organisation_id' => $this->north->id, 'definition' => ['steps' => [['name' => 'north']]]]);

    expect(tenantInvoice($this->north, $this->maker)->heldApproval()->currentStep()?->name)->toBe('north')
        ->and(tenantInvoice($this->south, $this->outsider)->heldApproval()->currentStep()?->name)->toBe('shared');
});

it('keeps delegations inside the tenant that made them', function (): void {
    config(['approvals.default' => ['steps' => [['name' => 'owner', 'users' => [$this->checker->id]]]]]);
    $this->actingAs($this->outsider);
    approvals()->delegate($this->checker->id, $this->outsider->id);

    $approval = tenantInvoice($this->north, $this->maker)->heldApproval();
    $this->actingAs($this->checker);

    expect(ApprovalDelegation::query()->count())->toBe(0)
        ->and(approvals()->canReview($approval, $this->outsider->id))->toBeFalse()
        ->and(approvals()->approve($approval, $this->checker->id)->status)->toBe(Status::Approved);
});

it('works with integer tenant keys too', function (): void {
    $this->rebuild([
        'approvals.tenancy.enabled' => true,
        'approvals.tenancy.column' => 'company_id',
        'approvals.tenancy.table' => 'companies',
        'approvals.tenancy.key' => 'int',
    ]);
    $company = Organisation::query()->create(['name' => 'Acme']);
    $maker = person('Amina', attributes: ['company_id' => $company->id]);
    $this->actingAs($maker);
    Invoice::query()->create(['number' => 'INV-I', 'company_id' => $company->id]);

    expect(latestApproval()->tenantKey())->toBe($company->id)
        ->and($company->id)->toBeInt();
});

it('ignores tenancy entirely while it is off', function (): void {
    $this->rebuild(['approvals.tenancy.enabled' => false]);

    expect(Schema::hasColumn('approvals', 'organisation_id'))->toBeFalse()
        ->and(app(Tenancy::class)->current())->toBeNull()
        ->and(app(Tenancy::class)->of(new Invoice))->toBeNull()
        ->and((new Approval)->tenantKey())->toBeNull();
});
