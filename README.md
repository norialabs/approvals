# norialabs/approvals

Maker-checker approvals for Laravel. A change to an Eloquent model, a many-to-many relationship or
any action you define is held until the right people approve it. The person who made the change can
never approve it.

- Holds creates, updates, deletes, restores and permanent deletes automatically through model events.
- Supports one approver, several approvers per step, groups within a step ("2 finance and 1
  manager", or "the CFO alone or 2 directors"), chains of steps, and rules that pick the steps from
  the change itself, for example a second approver above a set amount.
- Has delegation, expiry, reminders and escalation, with an append-only audit trail.
- Lets you choose every key type (int, ULID, UUID) and every table name, including your actors and
  tenant tables, and replace every decision with your own class.
- Is safe under Octane: no state outlives a request.

Requires PHP 8.3+ and Laravel 12 or 13. Tested on SQLite and PostgreSQL 18.

## Installation

```
composer require norialabs/approvals
php artisan vendor:publish --tag=approvals-config
php artisan migrate
```

Set the config before you migrate: the migration reads it to choose key types, table names and the
tenant column. To own the migration instead, publish it with `--tag=approvals-migrations` and set
`run_migrations` to `false`.

Schedule the sweep that expires, escalates and sends reminders:

```php
Schedule::command('approvals:sweep')->everyFiveMinutes();
```

## Holding changes on a model

```php
use Noria\Approvals\RequiresApproval;

class Invoice extends Model
{
    use RequiresApproval;

    protected array $approvalIgnored = ['viewed_at'];
    protected array $approvalEncrypted = ['bank_account'];
    protected array $approvalHidden = ['internal_notes'];
    protected array $approvalOperations = ['update', 'delete'];
}
```

Every property is optional:

- `approvalIgnored`: fields that can change without review. The model's own created and updated
  timestamps and its remember token are always ignored, under whatever names the model gives them,
  so `touch()` never needs approval. Business dates such as `paid_at` are real data and are not
  ignored. List fields to ignore on every model in the `ignored` config key.
- `approvalEncrypted`: fields encrypted inside the stored request, and shown as `[hidden]`.
- `approvalHidden`: fields shown as `[hidden]` to reviewers, on top of the model's `$hidden`.
- `approvalOperations`: which operations need approval. Leave it out to cover all of them.

A restore that needs no approval saves its `deleted_at` change without review. Setting the soft
delete column by hand is still held, so it can't be used to delete without approval.

A held write returns `false` and changes nothing:

```php
$invoice->withApprovalNotes('Supplier raised the price')->update(['amount' => 2500]);

$invoice->wasHeldForApproval();   // true
$invoice->heldApproval();         // the Approval waiting for review
$invoice->pendingApproval();      // the open approval for this record, from the database
```

Set `intercept` to `throw` to get an `ApprovalRequired` exception instead of `false`.

A record can have only one open request at a time. A second write throws `ApprovalException` with
reason `pending_exists`.

### Bypassing review

```php
$invoice->saveWithoutApproval();
$invoice->updateWithoutApproval([...]);
$invoice->deleteWithoutApproval();
$invoice->restoreWithoutApproval();
$invoice->forceDeleteWithoutApproval();

app(Approvals::class)->withoutApproval(fn () => Invoice::query()->create([...]));
```

`APPROVALS_ENFORCED=false` turns review off everywhere, for seeders and local work.

### Records saved before approval

Some records must exist while they wait, such as a draft with attachments. Save it, then submit it:

```php
class Drawing extends Model
{
    use RequiresApproval;

    protected bool $hidesUnapprovedCreations = true;
}

$drawing->saveWithoutApproval();
$drawing->submitForApproval('Ready for sign-off');
```

Queries hide the record until it is approved. Use `Drawing::query()->withUnapproved()` to see it.
When the request is declined, cancelled or expires, the record is deleted, or permanently deleted
if the model uses soft deletes. Define `discardUnapprovedCreation(Approval $approval)` on the model
to handle that yourself. When changes are requested, the record stays hidden for the maker to
rework and submit again.

### Many-to-many writes

Pivot writes fire no model events, so send them through `approvalRelation`:

```php
$invoice->approvalRelation('tags')->withNotes('Flag for site')->attach($tagIds, ['note' => 'urgent']);
```

`attach`, `detach`, `sync`, `syncWithoutDetaching`, `syncWithPivotValues`, `toggle` and
`updateExistingPivot` are supported. They return the `Approval`, or `null` when they wrote
straight through.

### Actions

To approve something that is not a model write, such as a payout or an export, implement
`ApprovableAction`:

```php
final class Payout implements ApprovableAction
{
    public function __construct(public readonly string $account, public readonly int $amount) {}

    public function payload(): array
    {
        return ['account' => $this->account, 'amount' => $this->amount];
    }

    public static function fromPayload(array $payload): static
    {
        return new self($payload['account'], $payload['amount']);
    }

    public function handle(?Approval $approval): void
    {
        // runs once approved, or at once when no approval is needed
    }
}

$approvals->action(new Payout('0712345678', 2500), 'Weekly wages');
```

Pass `subject: $transaction` to tie the action to the record it's about. The approval is then
filed against that record, and the record can have only one open request at a time, just as with a
model write.

### Dependent data

A request often carries data stored elsewhere, such as an order's lines or a user's roles. Attach it
as extras, and apply it in the model:

```php
$order->withApprovalExtras(['lines' => $lines])->save();

class Order extends Model
{
    use RequiresApproval;

    public function applyApprovalExtras(array $extras, ?Approval $approval): void
    {
        // write the lines; runs in the same transaction as the approved change
    }
}
```

Extras are applied together with the change: when it's approved, or straight after the write when no
approval is needed. Policies can read them as `payload.extras.*`. When only the extras change,
nothing on the model is dirty and Eloquent skips the save, so request the change explicitly:

```php
$user->withApprovalExtras(['roles' => [3, 4]])->requestApproval(notes: 'Promote to finance');
$invoice->requestApproval(['amount' => 2500]);
```

### After a change is applied

Define `approvalApplied(Approval $approval)` on a model to run follow-up work, such as notifications
or derived records, once an approved change has been applied. It also runs after an automatic
approval.

## Reviewing

```php
$approvals = app(Approvals::class);

$approvals->approve($approval, notes: 'Matches the quote');
$approvals->decline($approval, notes: 'Too high');
$approvals->requestChanges($approval, notes: 'Attach the quote');
$approvals->cancel($approval);

$approvals->approveMany($ids);           // ['done' => [...], 'failed' => [id => reason]]
$approvals->declineMany($ids);

$approvals->canReview($approval, $userId);
$approvals->reviewersFor($approval);     // ids still able to act, or null when unknown
$approvals->progress($approval);         // [['group' => 'finance', 'approvals' => 2, 'given' => 1], ...]
```

The reviewer is the signed-in user unless you pass an id. Approving runs in a transaction and locks
the request, so two reviewers acting at once cannot double-count or apply a change twice.

Only the person who made a request can cancel it. With `distinct_reviewers` on (the default), one
person approves once per request, across all its steps.

An approved update writes only the fields it changed. If someone changed one of those fields after
the request was made, approval fails with reason `stale`, and the request stays open. Set
`on_conflict` to `overwrite` to let the request win instead. A failed apply leaves the request open,
records a `failed` event and fires `ApprovalFailed`.

Query requests with the `open()`, `forSubject($model)`, `madeBy($id)` and `forTenant($id)` scopes.
`$approval->changes()` lists each field's old and new value, with hidden fields concealed.

## Policies

A policy decides which steps a change needs. It is looked up in this order:

1. `approvalRequirement(PendingChange $change): ?Requirement` on the model, when it returns one.
2. A stored policy in the `approval_policies` table, when `database_policies` is on. A tenant's own
   row beats a shared one.
3. `subject_policies` in config, keyed by model or action class.
4. `default` in config: one approval from anyone but the maker.

The policy is copied onto each request when it is made, so editing a policy never changes requests
already waiting.

```php
'subject_policies' => [
    App\Models\PurchaseOrder::class => [
        'operations' => ['create', 'update'],
        'steps' => [['name' => 'manager', 'roles' => ['manager']]],
        'expires_after' => 4320,
        'rules' => [
            ['when' => [['total', '>=', 500000]], 'steps' => [
                ['name' => 'manager', 'roles' => ['manager']],
                ['name' => 'director', 'approvals' => 2, 'ability' => 'approve-large-orders',
                 'escalate_after' => 1440, 'escalate_to' => ['name' => 'owner', 'users' => [1]]],
            ]],
            ['when' => [['status', '=', 'draft']], 'approve' => false],
        ],
    ],
],
```

A step takes:

- `name`
- `approvals`: how many approvals it needs. Defaults to 1.
- Who may approve: `users` (ids), `ability` (a Gate ability, checked with the approval), and
  `roles` (checked with the actor's `hasAnyRole` or `hasRole`). Every constraint you set must pass.
  A step with none is open to anyone but the maker.
- `escalate_after` and `escalate_to`: hand the step to another step when it is overdue.

### Groups

To need approvals from different kinds of people in the same step, split the step into `groups`.
Each group has its own `name`, `approvals`, `users`, `ability` and `roles`. A step sets its approvers
either on the step or in groups, never both.

```php
['name' => 'sign-off', 'groups' => [
    ['name' => 'finance', 'approvals' => 2, 'roles' => ['finance']],
    ['name' => 'management', 'roles' => ['manager']],
]]

['name' => 'release', 'mode' => 'any', 'groups' => [
    ['name' => 'cfo', 'roles' => ['cfo']],
    ['name' => 'directors', 'approvals' => 2, 'roles' => ['director']],
]]
```

With `mode` set to `all` (the default), the step closes once every group has its approvals, in any
order. With `any`, it closes as soon as one group does. Someone who belongs to two groups is counted
wherever the step still needs them. Once a person's groups are full, their approval is refused, but
they can still decline or request changes.

In code: `new Step('sign-off', groups: [new Group('finance', 2, roles: ['finance']), new Group('management', roles: ['manager'])])`.

The first rule whose conditions all match is merged over the base definition. A condition is
`[field, operator, value]`. The operators are `=`, `!=`, `>`, `>=`, `<`, `<=`, `in`, `not_in`,
`between`, `null`, `not_null`, `changed`, `contains`, `starts_with` and `ends_with`. Group
conditions with `['any' => [...]]` or `['all' => [...]]`.

A field is read from the new state. Prefix it with `old.` to read the current state, or `payload.`
to read an action's payload. `operation`, `actor` and `tenant` are also available.

Build requirements in code with `Requirement::of(new Step('finance', approvals: 2))`.

## Automatic approvals

A policy can approve a change automatically and still keep a record of it:

```php
return Requirement::automatic('system operator acted directly');
// or in config: ['automatic' => 'trusted supplier']
```

The write goes ahead as if no approval were needed. Once it lands, a closed `approved` request is
written for it, with an `auto_approved` event carrying the reason, so every change stays answerable.
`$approval->isAutomatic()` tells these apart.

### Confirmations

Some decisions depend on something the maker confirmed, such as acting alone when nobody else can
approve. Pass the confirmation with the change, and read it in your policy:

```php
$transfer->withApprovalConfirmations('sole_approval')->save();
$approvals->action($payout, subject: $transaction, confirmations: ['sole_approval']);

// in a Policy
return $change->confirmed('sole_approval')
    ? Requirement::automatic('sole approver confirmed')
    : Requirement::of(new Step('finance', approvals: 2));
```

## Escalating by hand

```php
$approvals->escalate($approval, 'Needs head office', $userId);
$approvals->escalate($approval, 'Over my limit', $userId, new Step('board', roles: ['director']));
```

This moves the current step to its `escalate_to` step, or to the step you pass, and starts its count
again. The reason and the person who escalated are recorded on an `escalated` event. Decide in your
own code who may escalate.

## Veto

Set `veto_ability` to a Gate ability. Anyone who passes it can decline any open request, even when
they can't approve it, for example a compliance team. A veto is recorded on the `declined` event as
`{"veto": true}`. Nobody can veto their own request. Check it with `$approvals->canVeto($approval)`.

## Delegation

```php
$approvals->delegate($reviewerId, $coverId, startsAt: now(), endsAt: now()->addWeek(), subjectType: 'invoice');
$approvals->revokeDelegation($delegation);
```

A delegate may act for anyone who could have reviewed the step. The audit trail records both: the
delegate as `actor_id` and the absent reviewer as `principal_id`. Nobody can act for the maker, and
a reviewer cannot approve twice by also acting through a delegate. Delegations belong to a tenant.

## Expiry, escalation and reminders

`approvals:sweep` runs three tasks. Use `--only=expire` to run one, and `--remind-after=60` to
change the reminder interval.

- **Expire** closes requests past `expires_after` as `expired`.
- **Escalate** (`$approvals->escalateDue()`) moves an overdue step to its `escalate_to` step and starts its count again. It does
  the same at once for a step nobody left can approve, when the reviewers are known. A step with
  nowhere to go fires `ApprovalStuck`, once.
- **Remind** fires `ApprovalReminderDue` with the reviewer ids, once per interval
  (`reminders.after`, in minutes).

## Events

Each event fires after the transaction commits:

- `ApprovalRequested`
- `ApprovalAdvanced`: has `reason` (`step_completed` or `escalated`) and `fromStep`.
- `ApprovalClosed`: read `$approval->status` for the outcome.
- `ApprovalReminderDue`
- `ApprovalStuck`
- `ApprovalFailed`

Send notifications from listeners.

Every action also lands in `approval_events`, which refuses updates and deletes: `submitted`,
`auto_approved`, `approved`, `advanced`, `escalated`, `stuck`, `declined`, `changes_requested`,
`cancelled`, `expired`, `applied` and `failed`.

## Configuration

| key | default | |
|---|---|---|
| `enforced` | `true` (`APPROVALS_ENFORCED`) | Off skips review everywhere. |
| `intercept` | `halt` | `halt` returns `false` from the held write; `throw` throws `ApprovalRequired`. |
| `without_actor` | `hold` | With nobody signed in: `hold` for review, `allow` the write through, or `reject` it. |
| `ignored` | `[]` | Fields that change without review on every model, beyond its own timestamps. |
| `distinct_reviewers` | `true` | One person approves once per request, across steps. |
| `on_conflict` | `fail` | `overwrite` lets an approved update win over later edits. |
| `run_migrations` | `true` | Off when you publish and own the migration. |
| `keys` | `ulid` | Primary keys of the package tables: `int`, `ulid` or `uuid`. |
| `tables.*` | `approvals`, `approval_events`, `approval_delegations`, `approval_policies` | Table names. |
| `models.*` | package models | Your subclasses of the package models. |
| `subjects.key` | `string` | `subject_id` column type: `int`, `ulid`, `uuid`, or `string` for mixed models. |
| `actors.model` | `App\Models\User` | The model behind `maker`, `closer` and event actors. |
| `actors.table`, `actors.references` | `users`, `id` | Where actor foreign keys point. |
| `actors.key` | `int` | Actor key type: `int`, `ulid`, `uuid` or `string`. |
| `actors.constrained` | `true` | Off leaves actor columns without foreign keys. |
| `actors.resolver` | `AuthActors` | Who is acting, and how to load them. |
| `tenancy.enabled` | `false` | Adds a tenant column to every package table. |
| `tenancy.column`, `tenancy.table`, `tenancy.references` | `tenant_id`, `tenants`, `id` | Your tenant column and table. |
| `tenancy.key` | `int` | Tenant key type. |
| `tenancy.constrained` | `true` | Off leaves the tenant column without a foreign key. |
| `tenancy.scope_queries` | `true` | Scopes package queries to the current tenant. |
| `tenancy.resolver` | `ConfiguredTenancy` | Reads the tenant from the record, then the signed-in user. |
| `policy` | `ConfiguredPolicy` | Your own `Policy` class. |
| `veto_ability` | `null` | A Gate ability whose holders may decline any open request. |
| `reviewers` | `GateReviewers` | Your own `Reviewers` class. |
| `database_policies` | `false` | Reads policies from `approval_policies`. |
| `default` | one open step | The policy for anything without its own. |
| `subject_policies` | `[]` | Policies keyed by model or action class. |
| `reminders.after` | `1440` | Minutes before reviewers are reminded. |

## Extending

Bind your own implementation of any contract, or name it in config:

- `Contracts\Policy`: which steps a change needs.
- `Contracts\Reviewers`: who may approve for a group, who could (`candidates`, used for reminders
  and deadlock detection), and who may veto (`mayVeto`). A step without groups is one group named
  after the step.
- `Contracts\Tenancy`: the tenant of a record, the current tenant, and `within($tenant, $callback)`.
  Every decision and sweep runs inside `within`, so you can set the database session there (for
  row-level security, for example).
- `Contracts\Actors`: the acting user's id, and how to load an actor.

## Testing

```
composer check
```

This runs Pint, PHPStan at max level and Pest on SQLite. To run on PostgreSQL as well:

```
psql -d postgres -f tests/setup-postgres.sql
APPROVALS_TEST_PG=pgsql://noria_approvals_test:approvals_test_only@127.0.0.1:5432/noria_approvals_test composer test
```

## License

MIT
