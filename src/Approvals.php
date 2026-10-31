<?php

declare(strict_types=1);

namespace Noria\Approvals;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Noria\Approvals\Contracts\Actors;
use Noria\Approvals\Contracts\ApprovableAction;
use Noria\Approvals\Contracts\Policy;
use Noria\Approvals\Contracts\Reviewers;
use Noria\Approvals\Contracts\Tenancy;
use Noria\Approvals\Events\ApprovalAdvanced;
use Noria\Approvals\Events\ApprovalClosed;
use Noria\Approvals\Events\ApprovalFailed;
use Noria\Approvals\Events\ApprovalReminderDue;
use Noria\Approvals\Events\ApprovalRequested;
use Noria\Approvals\Events\ApprovalStuck;
use Noria\Approvals\Exceptions\ApplyFailed;
use Noria\Approvals\Exceptions\ApprovalException;
use Noria\Approvals\Exceptions\ApprovalRequired;
use Noria\Approvals\Models\Approval;
use Noria\Approvals\Models\ApprovalDelegation;
use Throwable;

final class Approvals
{
    public const RELATION_METHODS = ['attach', 'detach', 'sync', 'syncWithoutDetaching', 'syncWithPivotValues', 'toggle', 'updateExistingPivot'];

    private const ALWAYS_IGNORED = ['created_at', 'updated_at', 'remember_token'];

    private int $bypassDepth = 0;

    public function __construct(
        private readonly Policy $policy,
        private readonly Reviewers $reviewers,
        private readonly Tenancy $tenancy,
        private readonly Actors $actors,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withoutApproval(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    public function bypassed(): bool
    {
        return $this->bypassDepth > 0 || ! Settings::enforced();
    }

    public function intercept(Model $model, Operation $operation): bool
    {
        if ($this->bypassed() || $this->modelBypassed($model)) {
            return true;
        }

        $operations = Snapshot::listed($model, 'approvalOperations');

        if ($operations !== [] && ! in_array($operation->value, $operations, true)) {
            return true;
        }

        [$current, $new, $stored] = $this->states($model, $operation);

        if ($operation === Operation::Update && $stored === []) {
            return true;
        }

        $actorId = $this->actors->id();

        if ($actorId === null && Settings::withoutActor() === 'allow') {
            return true;
        }

        if ($actorId === null && Settings::withoutActor() === 'reject') {
            throw ApprovalException::noActor();
        }

        $change = new PendingChange(
            operation: $operation,
            subjectClass: $model::class,
            subjectType: $model->getMorphClass(),
            subjectKey: self::key($model->getKey()),
            subject: $model,
            currentState: $current,
            newState: $new,
            payload: null,
            actorId: $actorId,
            tenantId: $this->tenancy->of($model),
        );

        $approval = $this->submit($change, self::pullNotes($model), storedNewState: $stored);

        if ($approval === null) {
            return true;
        }

        if (method_exists($model, 'rememberApproval')) {
            $model->rememberApproval($approval);
        }

        if (Settings::throwsOnIntercept()) {
            throw new ApprovalRequired($approval);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $storedNewState
     * @param  array<string, mixed>  $meta
     */
    public function submit(PendingChange $change, ?string $notes = null, ?Requirement $requirement = null, ?array $storedNewState = null, array $meta = []): ?Approval
    {
        $requirement ??= $this->policy->requirementFor($change);

        if (! $requirement->isNeeded()) {
            return null;
        }

        $subject = $change->subject;

        $approval = $this->tenancy->within($change->tenantId, fn (): Approval => $this->transaction(function (Connection $connection) use ($change, $notes, $requirement, $storedNewState, $meta, $subject): Approval {
            if ($change->subjectKey !== null && $change->operation !== Operation::Create && $subject instanceof Model) {
                $subject->newQueryWithoutScopes()->whereKey($subject->getKey())->lockForUpdate()->first();
                $this->guardAgainstOpenRequest($change);
            }

            $first = $requirement->step(0);
            $sealer = $subject ?? new class extends Model {};
            $model = Settings::approvalModel();
            $approval = new $model;
            $approval->forceFill([
                'subject_type' => $change->subjectType,
                'subject_id' => $change->subjectKey,
                'operation' => $change->operation,
                'status' => Status::Pending,
                'maker_id' => $change->actorId,
                'requirement' => $requirement->toArray(),
                'step' => 0,
                'stage' => 1,
                'current_state' => $change->currentState === null ? null : Snapshot::seal($sealer, $change->currentState),
                'new_state' => ($storedNewState ?? $change->newState) === null ? null : Snapshot::seal($sealer, $storedNewState ?? $change->newState ?? []),
                'payload' => $change->payload,
                'maker_notes' => $notes,
                'expires_at' => $requirement->expiresAfter === null ? null : now()->addMinutes($requirement->expiresAfter),
                'escalates_at' => $first?->escalateAfter === null ? null : now()->addMinutes($first->escalateAfter),
                'meta' => $meta === [] ? null : $meta,
            ]);

            if (Settings::tenancyEnabled()) {
                $approval->setAttribute(Settings::tenantColumn(), $change->tenantId);
            }

            $approval->save();
            $this->record($approval, 'submitted', $change->actorId, notes: $notes, payload: ['operation' => $change->operation->value]);
            $connection->afterCommit(fn () => $this->events->dispatch(new ApprovalRequested($approval)));

            return $approval;
        }));

        return $approval;
    }

    public function submitCreation(Model $model, ?string $notes = null): ?Approval
    {
        if (! $model->exists) {
            throw new InvalidArgumentException('Save the record before submitting its creation for approval.');
        }

        $actorId = $this->actors->id();

        if ($actorId === null && Settings::withoutActor() === 'reject') {
            throw ApprovalException::noActor();
        }

        $attributes = $model->getAttributes();

        return $this->submit(new PendingChange(
            operation: Operation::Create,
            subjectClass: $model::class,
            subjectType: $model->getMorphClass(),
            subjectKey: self::key($model->getKey()),
            subject: $model,
            currentState: null,
            newState: $attributes,
            payload: null,
            actorId: $actorId,
            tenantId: $this->tenancy->of($model),
        ), $notes, meta: ['persisted' => true]);
    }

    /** @param array<mixed> $arguments */
    public function relation(Model $model, string $relation, string $method, array $arguments, ?string $notes = null): ?Approval
    {
        if (! in_array($method, self::RELATION_METHODS, true)) {
            throw new InvalidArgumentException("[{$method}] is not an approvable relationship write.");
        }

        if (! $model->exists) {
            throw new InvalidArgumentException('Save the record before changing its relationships.');
        }

        $builder = $model->{$relation}();

        if (! $builder instanceof BelongsToMany) {
            throw new InvalidArgumentException("[{$relation}] must be a many-to-many relationship.");
        }

        $arguments = self::normalise($arguments);
        $apply = fn () => $this->withoutApproval(fn () => self::writeRelation($builder, $method, $arguments));

        if ($this->bypassed() || $this->modelBypassed($model)) {
            $apply();

            return null;
        }

        $approval = $this->submit(new PendingChange(
            operation: Operation::Relation,
            subjectClass: $model::class,
            subjectType: $model->getMorphClass(),
            subjectKey: self::key($model->getKey()),
            subject: $model,
            currentState: null,
            newState: null,
            payload: ['relation' => $relation, 'method' => $method, 'arguments' => $arguments],
            actorId: $this->actors->id(),
            tenantId: $this->tenancy->of($model),
        ), $notes);

        if ($approval === null) {
            $apply();
        }

        return $approval;
    }

    public function action(ApprovableAction $action, ?string $notes = null, ?Requirement $requirement = null): ?Approval
    {
        if ($this->bypassed()) {
            $this->withoutApproval(fn () => $action->handle(null));

            return null;
        }

        $approval = $this->submit(new PendingChange(
            operation: Operation::Action,
            subjectClass: $action::class,
            subjectType: $action::class,
            subjectKey: null,
            subject: null,
            currentState: null,
            newState: null,
            payload: ['class' => $action::class, 'data' => $action->payload()],
            actorId: $this->actors->id(),
            tenantId: $this->tenancy->current(),
        ), $notes, $requirement);

        if ($approval === null) {
            $this->withoutApproval(fn () => $action->handle(null));
        }

        return $approval;
    }

    public function approve(Approval $approval, int|string|null $actorId = null, ?string $notes = null): Approval
    {
        $actorId ??= $this->actors->id() ?? throw ApprovalException::noActor();

        try {
            return $this->tenancy->within($approval->tenantKey(), fn (): Approval => $this->transaction(function (Connection $connection) use ($approval, $actorId, $notes): Approval {
                $locked = $this->lock($approval);
                $step = $this->openStep($locked);
                $principal = $this->authorise($locked, $step, $actorId);
                $this->record($locked, 'approved', $actorId, $principal, $notes);

                if ($locked->approvalsAtStage() < $step->approvals) {
                    return $locked;
                }

                $next = $locked->requirement()->step($locked->step + 1);

                if ($next !== null) {
                    $locked->forceFill([
                        'step' => $locked->step + 1,
                        'stage' => $locked->stage + 1,
                        'escalates_at' => $next->escalateAfter === null ? null : now()->addMinutes($next->escalateAfter),
                        'reminded_at' => null,
                    ])->save();
                    $this->record($locked, 'advanced', $actorId, payload: ['from' => $step->name, 'to' => $next->name]);
                    $connection->afterCommit(fn () => $this->events->dispatch(new ApprovalAdvanced($locked, ApprovalAdvanced::STEP_COMPLETED, $step->name)));

                    return $locked;
                }

                try {
                    $this->apply($locked);
                } catch (Throwable $exception) {
                    throw new ApplyFailed($exception);
                }

                $this->close($locked, Status::Approved, $actorId);
                $this->record($locked, 'applied', $actorId);
                $connection->afterCommit(fn () => $this->events->dispatch(new ApprovalClosed($locked)));

                return $locked;
            }));
        } catch (ApplyFailed $failure) {
            $this->recordFailure($approval, $actorId, $failure->cause);

            throw $failure->cause instanceof ApprovalException ? $failure->cause : ApprovalException::failed($approval, $failure->cause);
        }
    }

    public function decline(Approval $approval, int|string|null $actorId = null, ?string $notes = null): Approval
    {
        return $this->conclude($approval, Status::Declined, 'declined', $actorId, $notes);
    }

    public function requestChanges(Approval $approval, int|string|null $actorId = null, ?string $notes = null): Approval
    {
        return $this->conclude($approval, Status::ChangesRequested, 'changes_requested', $actorId, $notes);
    }

    public function cancel(Approval $approval, int|string|null $actorId = null, ?string $notes = null): Approval
    {
        $actorId ??= $this->actors->id() ?? throw ApprovalException::noActor();

        return $this->tenancy->within($approval->tenantKey(), fn (): Approval => $this->transaction(function (Connection $connection) use ($approval, $actorId, $notes): Approval {
            $locked = $this->lock($approval);
            $this->openStep($locked);

            if ($locked->maker_id === null || ! Snapshot::same($locked->maker_id, $actorId)) {
                throw ApprovalException::notAllowed($locked, 'Only the person who made this request can cancel it.');
            }

            $this->close($locked, Status::Cancelled, $actorId);
            $this->record($locked, 'cancelled', $actorId, notes: $notes);
            $this->discardCreation($locked);
            $connection->afterCommit(fn () => $this->events->dispatch(new ApprovalClosed($locked)));

            return $locked;
        }));
    }

    /**
     * @param  iterable<Approval|int|string>  $approvals
     * @return array{done: list<int|string>, failed: array<int|string, string>}
     */
    public function approveMany(iterable $approvals, int|string|null $actorId = null, ?string $notes = null): array
    {
        return $this->many($approvals, fn (Approval $approval): Approval => $this->approve($approval, $actorId, $notes));
    }

    /**
     * @param  iterable<Approval|int|string>  $approvals
     * @return array{done: list<int|string>, failed: array<int|string, string>}
     */
    public function declineMany(iterable $approvals, int|string|null $actorId = null, ?string $notes = null): array
    {
        return $this->many($approvals, fn (Approval $approval): Approval => $this->decline($approval, $actorId, $notes));
    }

    public function canReview(Approval $approval, int|string|null $actorId = null): bool
    {
        $actorId ??= $this->actors->id();

        if ($actorId === null) {
            return false;
        }

        $step = $approval->currentStep();

        if ($step === null || $approval->isExpired()) {
            return false;
        }

        try {
            $this->authorise($approval, $step, $actorId);

            return true;
        } catch (ApprovalException) {
            return false;
        }
    }

    /** @return list<int|string>|null */
    public function reviewersFor(Approval $approval): ?array
    {
        $step = $approval->currentStep();

        if ($step === null) {
            return [];
        }

        $candidates = $this->reviewers->candidates($approval, $step);

        if ($candidates === null) {
            return null;
        }

        $excluded = array_map(strval(...), [
            ...($approval->maker_id === null ? [] : [$approval->maker_id]),
            ...$approval->approverIds(Settings::distinctReviewers() ? null : $approval->stage),
        ]);

        return array_values(array_filter($candidates, fn (int|string $id): bool => ! in_array((string) $id, $excluded, true)));
    }

    public function delegate(int|string $delegatorId, int|string $delegateId, \DateTimeInterface|string|null $startsAt = null, \DateTimeInterface|string|null $endsAt = null, ?string $subjectType = null): ApprovalDelegation
    {
        if ((string) $delegatorId === (string) $delegateId) {
            throw new InvalidArgumentException('A reviewer cannot delegate to themselves.');
        }

        $starts = $startsAt === null ? now() : now()->parse($startsAt);
        $ends = $endsAt === null ? null : now()->parse($endsAt);

        if ($ends !== null && $ends->lessThanOrEqualTo($starts)) {
            throw new InvalidArgumentException('A delegation must end after it starts.');
        }

        $model = Settings::delegationModel();
        $delegation = new $model;
        $delegation->forceFill([
            'delegator_id' => $delegatorId,
            'delegate_id' => $delegateId,
            'subject_type' => $subjectType,
            'starts_at' => $starts,
            'ends_at' => $ends,
        ])->save();

        return $delegation;
    }

    public function revokeDelegation(ApprovalDelegation $delegation): ApprovalDelegation
    {
        if ($delegation->revoked_at === null) {
            $delegation->forceFill(['revoked_at' => now()])->save();
        }

        return $delegation;
    }

    public function expire(): int
    {
        $count = 0;

        $this->sweep(fn (Builder $query) => $query->whereNotNull('expires_at')->where('expires_at', '<=', now()), function (Approval $approval) use (&$count): void {
            $expired = $this->tenancy->within($approval->tenantKey(), fn (): bool => $this->transaction(function (Connection $connection) use ($approval): bool {
                $locked = $this->lock($approval);

                if (! $locked->isOpen() || ! $locked->isExpired()) {
                    return false;
                }

                $this->close($locked, Status::Expired, null);
                $this->record($locked, 'expired', null);
                $this->discardCreation($locked);
                $connection->afterCommit(fn () => $this->events->dispatch(new ApprovalClosed($locked)));

                return true;
            }));

            $count += $expired ? 1 : 0;
        });

        return $count;
    }

    public function escalate(): int
    {
        $count = 0;

        $this->sweep(fn (Builder $query) => $query, function (Approval $approval) use (&$count): void {
            $due = $approval->escalates_at !== null && $approval->escalates_at->lessThanOrEqualTo(now());

            if (! $due && $this->reviewersFor($approval) !== []) {
                return;
            }

            $escalated = $this->tenancy->within($approval->tenantKey(), fn (): bool => $this->transaction(function (Connection $connection) use ($approval): bool {
                $locked = $this->lock($approval);
                $step = $locked->currentStep();

                if ($step === null) {
                    return false;
                }

                if ($step->escalateTo === null) {
                    if ($locked->escalates_at === null && ($locked->meta['stuck_stage'] ?? null) === $locked->stage) {
                        return false;
                    }

                    $locked->forceFill(['escalates_at' => null, 'meta' => [...($locked->meta ?? []), 'stuck_stage' => $locked->stage]])->save();
                    $this->record($locked, 'stuck', null, payload: ['step' => $step->name]);
                    $connection->afterCommit(fn () => $this->events->dispatch(new ApprovalStuck($locked)));

                    return false;
                }

                $next = $step->escalateTo;
                $locked->forceFill([
                    'requirement' => $locked->requirement()->replacing($locked->step, $next)->toArray(),
                    'stage' => $locked->stage + 1,
                    'escalates_at' => $next->escalateAfter === null ? null : now()->addMinutes($next->escalateAfter),
                    'reminded_at' => null,
                ])->save();
                $this->record($locked, 'escalated', null, payload: ['from' => $step->name, 'to' => $next->name]);
                $connection->afterCommit(fn () => $this->events->dispatch(new ApprovalAdvanced($locked, ApprovalAdvanced::ESCALATED, $step->name)));

                return true;
            }));

            $count += $escalated ? 1 : 0;
        });

        return $count;
    }

    public function remind(?int $afterMinutes = null): int
    {
        $cutoff = now()->subMinutes($afterMinutes ?? Settings::reminderMinutes());
        $count = 0;

        $this->sweep(
            fn (Builder $query) => $query->where(fn (Builder $due) => $due
                ->where(fn (Builder $never) => $never->whereNull('reminded_at')->where('updated_at', '<=', $cutoff))
                ->orWhere('reminded_at', '<=', $cutoff)),
            function (Approval $approval) use (&$count): void {
                $this->tenancy->within($approval->tenantKey(), function () use ($approval, &$count): void {
                    $approval->forceFill(['reminded_at' => now()])->saveQuietly();
                    $this->events->dispatch(new ApprovalReminderDue($approval, $this->reviewersFor($approval)));
                    $count++;
                });
            },
        );

        return $count;
    }

    private function conclude(Approval $approval, Status $status, string $action, int|string|null $actorId, ?string $notes): Approval
    {
        $actorId ??= $this->actors->id() ?? throw ApprovalException::noActor();

        return $this->tenancy->within($approval->tenantKey(), fn (): Approval => $this->transaction(function (Connection $connection) use ($approval, $status, $action, $actorId, $notes): Approval {
            $locked = $this->lock($approval);
            $step = $this->openStep($locked);
            $principal = $this->authorise($locked, $step, $actorId, deciding: true);
            $this->close($locked, $status, $actorId);
            $this->record($locked, $action, $actorId, $principal, $notes);

            if ($status === Status::Declined) {
                $this->discardCreation($locked);
            }

            $connection->afterCommit(fn () => $this->events->dispatch(new ApprovalClosed($locked)));

            return $locked;
        }));
    }

    private function authorise(Approval $approval, Step $step, int|string $actorId, bool $deciding = false): int|string|null
    {
        if ($approval->maker_id !== null && Snapshot::same($approval->maker_id, $actorId)) {
            throw ApprovalException::notAllowed($approval, 'You cannot review your own request.');
        }

        $voted = array_map(strval(...), $approval->approverIds(Settings::distinctReviewers() ? null : $approval->stage));

        if (! $deciding && in_array((string) $actorId, $voted, true)) {
            throw ApprovalException::alreadyReviewed($approval);
        }

        if ($this->reviewers->allows($approval, $step, $actorId)) {
            return null;
        }

        $delegations = Settings::delegationModel()::query()
            ->forTenant($approval->tenantKey())
            ->active()
            ->covering($approval->subject_type)
            ->where('delegate_id', $actorId)
            ->get();

        foreach ($delegations as $delegation) {
            $principal = $delegation->delegator_id;

            if ($approval->maker_id !== null && Snapshot::same($approval->maker_id, $principal)) {
                continue;
            }

            if (! $deciding && in_array((string) $principal, $voted, true)) {
                continue;
            }

            if ($this->reviewers->allows($approval, $step, $principal)) {
                return $principal;
            }
        }

        throw ApprovalException::notAllowed($approval, "You are not a reviewer for the {$step->name} step.");
    }

    private function openStep(Approval $approval): Step
    {
        if (! $approval->isOpen()) {
            throw ApprovalException::closed($approval);
        }

        if ($approval->isExpired()) {
            throw ApprovalException::expired($approval);
        }

        return $approval->currentStep() ?? throw ApprovalException::closed($approval);
    }

    private function apply(Approval $approval): void
    {
        match ($approval->operation) {
            Operation::Create => $this->applyCreate($approval),
            Operation::Update => $this->applyUpdate($approval),
            Operation::Delete => $this->withoutApproval(fn () => $this->subjectOf($approval)->delete()),
            Operation::Restore => $this->restoreSubject($approval),
            Operation::ForceDelete => $this->withoutApproval(fn () => $this->softDeletable($approval)->forceDelete()),
            Operation::Relation => $this->applyRelation($approval),
            Operation::Action => $this->applyAction($approval),
        };
    }

    private function applyCreate(Approval $approval): void
    {
        if (($approval->meta['persisted'] ?? false) === true) {
            $this->subjectOf($approval);

            return;
        }

        $class = $approval->subjectClass();

        if ($class === null || ! is_a($class, Model::class, true)) {
            throw ApprovalException::missingSubject($approval);
        }

        $model = new $class;
        $model->setRawAttributes(Snapshot::open($approval->new_state ?? []));
        $this->withoutApproval(fn () => $model->save());
        $approval->forceFill(['subject_id' => self::key($model->getKey())]);
    }

    private function applyUpdate(Approval $approval): void
    {
        $subject = $this->subjectOf($approval);
        $requested = Snapshot::open($approval->new_state ?? []);
        $before = Snapshot::open($approval->current_state ?? []);
        $live = $subject->getAttributes();
        $conflicts = [];

        foreach ($requested as $field => $value) {
            if (array_key_exists($field, $before) && array_key_exists($field, $live) && ! Snapshot::same($live[$field], $before[$field]) && ! Snapshot::same($live[$field], $value)) {
                $conflicts[] = $field;
            }
        }

        if ($conflicts !== [] && ! Settings::overwritesConflicts()) {
            throw ApprovalException::stale($approval, $conflicts);
        }

        $subject->setRawAttributes([...$live, ...$requested]);
        $this->withoutApproval(fn () => $subject->save());
    }

    private function applyRelation(Approval $approval): void
    {
        $subject = $this->subjectOf($approval);
        $payload = $approval->payload ?? [];
        $relation = $payload['relation'] ?? null;
        $method = $payload['method'] ?? null;
        $arguments = $payload['arguments'] ?? [];

        if (! is_string($relation) || ! is_string($method) || ! in_array($method, self::RELATION_METHODS, true) || ! is_array($arguments)) {
            throw new InvalidArgumentException('This relationship approval has an unreadable payload.');
        }

        $builder = $subject->{$relation}();

        if (! $builder instanceof BelongsToMany) {
            throw new InvalidArgumentException("[{$relation}] is no longer a many-to-many relationship.");
        }

        $this->withoutApproval(fn () => self::writeRelation($builder, $method, array_values($arguments)));
    }

    private function applyAction(Approval $approval): void
    {
        $payload = $approval->payload ?? [];
        $class = $payload['class'] ?? null;
        $data = $payload['data'] ?? [];

        if (! is_string($class) || ! is_a($class, ApprovableAction::class, true) || ! is_array($data)) {
            throw new InvalidArgumentException('This action approval names no approvable action.');
        }

        $action = $class::fromPayload(Settings::stringKeys($data));
        $this->withoutApproval(fn () => $action->handle($approval));
    }

    private function subjectOf(Approval $approval): Model
    {
        return $approval->resolveSubject(lock: true) ?? throw ApprovalException::missingSubject($approval);
    }

    private function softDeletable(Approval $approval): Model
    {
        if (! $approval->usesSoftDeletes()) {
            throw new InvalidArgumentException('Restoring and permanent deletion need a model that uses SoftDeletes.');
        }

        return $this->subjectOf($approval);
    }

    private function restoreSubject(Approval $approval): void
    {
        $subject = $this->softDeletable($approval);

        if (! method_exists($subject, 'restore')) {
            throw new InvalidArgumentException('Restoring needs a model that uses SoftDeletes.');
        }

        $this->withoutApproval(fn () => $subject->restore());
    }

    private function discardCreation(Approval $approval): void
    {
        if ($approval->operation !== Operation::Create || ($approval->meta['persisted'] ?? false) !== true) {
            return;
        }

        $subject = $approval->resolveSubject(lock: true);

        if ($subject === null) {
            return;
        }

        if (method_exists($subject, 'discardUnapprovedCreation')) {
            $this->withoutApproval(fn () => $subject->discardUnapprovedCreation($approval));

            return;
        }

        $this->withoutApproval(fn () => $approval->usesSoftDeletes() ? $subject->forceDelete() : $subject->delete());
    }

    private function close(Approval $approval, Status $status, int|string|null $actorId): void
    {
        $approval->forceFill([
            'status' => $status,
            'closer_id' => $actorId,
            'closed_at' => now(),
            'escalates_at' => null,
        ])->save();
    }

    /** @param array<string, mixed>|null $payload */
    private function record(Approval $approval, string $action, int|string|null $actorId, int|string|null $principalId = null, ?string $notes = null, ?array $payload = null): void
    {
        $model = Settings::eventModel();
        $event = new $model;
        $event->forceFill([
            'approval_id' => $approval->getKey(),
            'action' => $action,
            'stage' => $approval->stage,
            'step' => $approval->requirement()->step($approval->step)?->name,
            'actor_id' => $actorId,
            'principal_id' => $principalId,
            'notes' => $notes,
            'payload' => $payload,
        ]);

        if (Settings::tenancyEnabled()) {
            $event->setAttribute(Settings::tenantColumn(), $approval->tenantKey());
        }

        $event->save();
    }

    private function recordFailure(Approval $approval, int|string $actorId, Throwable $cause): void
    {
        $this->tenancy->within($approval->tenantKey(), fn () => $this->transaction(function () use ($approval, $actorId, $cause): void {
            $this->record($approval, 'failed', $actorId, notes: $cause->getMessage(), payload: ['exception' => $cause::class]);
        }));

        $this->events->dispatch(new ApprovalFailed($approval, $cause));
    }

    private function guardAgainstOpenRequest(PendingChange $change): void
    {
        $open = Settings::approvalModel()::query()
            ->withoutGlobalScopes()
            ->where('subject_type', $change->subjectType)
            ->where('subject_id', $change->subjectKey)
            ->where('status', Status::Pending->value)
            ->latest()
            ->first();

        if ($open !== null) {
            throw ApprovalException::pendingExists($open);
        }
    }

    private function lock(Approval $approval): Approval
    {
        $locked = $approval->newQueryWithoutScopes()->lockForUpdate()->find($approval->getKey());

        if (! $locked instanceof Approval) {
            throw ApprovalException::closed($approval);
        }

        return $locked;
    }

    /**
     * @param  Closure(Builder<Approval>): mixed  $filter
     * @param  Closure(Approval): void  $each
     */
    private function sweep(Closure $filter, Closure $each): void
    {
        $query = Settings::approvalModel()::query()->withoutGlobalScope('approvals.tenant')->where('status', Status::Pending->value);
        $filter($query);
        $query->chunkById(200, function (Collection $approvals) use ($each): void {
            foreach ($approvals as $approval) {
                if ($approval instanceof Approval) {
                    $each($approval);
                }
            }
        });
    }

    /**
     * @param  iterable<Approval|int|string>  $approvals
     * @param  Closure(Approval): Approval  $decide
     * @return array{done: list<int|string>, failed: array<int|string, string>}
     */
    private function many(iterable $approvals, Closure $decide): array
    {
        $done = [];
        $failed = [];

        foreach ($approvals as $approval) {
            $id = $approval instanceof Approval ? $approval->getKey() : $approval;
            $model = $approval instanceof Approval ? $approval : Settings::approvalModel()::query()->find($approval);
            $key = is_int($id) || is_string($id) ? $id : (string) json_encode($id);

            if (! $model instanceof Approval) {
                $failed[$key] = 'This approval does not exist.';

                continue;
            }

            try {
                $decide($model);
                $done[] = $key;
            } catch (ApprovalException $exception) {
                $failed[$key] = $exception->getMessage();
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * @template T
     *
     * @param  Closure(Connection): T  $callback
     * @return T
     */
    private function transaction(Closure $callback): mixed
    {
        $connection = (new (Settings::approvalModel()))->getConnection();

        return $connection->transaction(fn () => $callback($connection), 3);
    }

    /** @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null, 2: array<string, mixed>|null} */
    private function states(Model $model, Operation $operation): array
    {
        $original = Settings::stringKeys($model->getRawOriginal());

        return match ($operation) {
            Operation::Create => [null, $model->getAttributes(), null],
            Operation::Update => $this->updateStates($model, $original),
            default => [$original, null, null],
        };
    }

    /**
     * @param  array<string, mixed>  $original
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function updateStates(Model $model, array $original): array
    {
        $ignored = [...self::ALWAYS_IGNORED, ...Snapshot::listed($model, 'approvalIgnored')];
        $dirty = $model->getDirty();
        $relevant = array_diff(array_keys($dirty), $ignored);

        if ($relevant === []) {
            return [$original, $model->getAttributes(), []];
        }

        $stored = array_diff_key($dirty, array_flip(self::ALWAYS_IGNORED));

        return [$original, [...$original, ...$model->getAttributes()], $stored];
    }

    private function modelBypassed(Model $model): bool
    {
        return method_exists($model, 'isBypassingApproval') && $model->isBypassingApproval() === true;
    }

    private static function pullNotes(Model $model): ?string
    {
        if (! method_exists($model, 'pullApprovalNotes')) {
            return null;
        }

        $notes = $model->pullApprovalNotes();

        return is_string($notes) && $notes !== '' ? $notes : null;
    }

    private static function key(mixed $key): int|string|null
    {
        return is_int($key) || (is_string($key) && $key !== '') ? $key : null;
    }

    /**
     * @param  BelongsToMany<Model, Model>  $builder
     * @param  list<mixed>  $arguments
     */
    private static function writeRelation(BelongsToMany $builder, string $method, array $arguments): void
    {
        $ids = self::relatedIds($arguments[0] ?? null);
        $second = $arguments[1] ?? [];
        $attributes = is_array($second) ? $second : [];

        match ($method) {
            'attach' => $builder->attach($ids ?? [], $attributes),
            'detach' => $builder->detach($ids),
            'sync' => $builder->sync($ids ?? [], $second !== false),
            'syncWithoutDetaching' => $builder->syncWithoutDetaching($ids ?? []),
            'syncWithPivotValues' => $builder->syncWithPivotValues($ids ?? [], $attributes, ($arguments[2] ?? true) !== false),
            'toggle' => $builder->toggle($ids ?? []),
            'updateExistingPivot' => $builder->updateExistingPivot($ids, $attributes),
            default => throw new InvalidArgumentException("[{$method}] is not an approvable relationship write."),
        };
    }

    /** @return array<mixed>|int|string|null */
    private static function relatedIds(mixed $ids): array|int|string|null
    {
        if ($ids === null || is_array($ids) || is_int($ids) || is_string($ids)) {
            return $ids;
        }

        throw new InvalidArgumentException('Relationship ids must be a key, a list of keys, or keys mapped to pivot attributes.');
    }

    /**
     * @param  array<mixed>  $arguments
     * @return list<mixed>
     */
    private static function normalise(array $arguments): array
    {
        return array_values(array_map(function (mixed $argument): mixed {
            if ($argument instanceof Model) {
                return $argument->getKey();
            }

            if ($argument instanceof Collection) {
                return $argument->map(fn (mixed $item): mixed => $item instanceof Model ? $item->getKey() : $item)->all();
            }

            return $argument;
        }, $arguments));
    }
}
