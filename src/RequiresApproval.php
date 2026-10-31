<?php

declare(strict_types=1);

namespace Noria\Approvals;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Noria\Approvals\Models\Approval;

/** @phpstan-require-extends Model */
trait RequiresApproval
{
    private bool $bypassingApproval = false;

    private ?string $approvalNotes = null;

    private ?Approval $heldApproval = null;

    private bool $restoringWithApproval = false;

    public static function bootRequiresApproval(): void
    {
        $hold = fn (Model $model, Operation $operation): ?bool => app(Approvals::class)->intercept($model, $operation) ? null : false;

        static::creating(fn (Model $model): ?bool => $hold($model, Operation::Create));
        static::updating(fn (Model $model): ?bool => $hold($model, Operation::Update));
        static::deleting(fn (Model $model): ?bool => method_exists($model, 'isForceDeleting') && $model->isForceDeleting() === true
            ? null
            : $hold($model, Operation::Delete));

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::registerModelEvent('restoring', function (Model $model) use ($hold): ?bool {
                $held = $hold($model, Operation::Restore);

                if ($held === null && method_exists($model, 'markRestoringWithApproval')) {
                    $model->markRestoringWithApproval(true);
                }

                return $held;
            });
            static::registerModelEvent('restored', function (Model $model): void {
                if (method_exists($model, 'markRestoringWithApproval')) {
                    $model->markRestoringWithApproval(false);
                }
            });
            static::registerModelEvent('forceDeleting', fn (Model $model): ?bool => $hold($model, Operation::ForceDelete));
        }

        if ((new \ReflectionClass(static::class))->getDefaultProperties()['hidesUnapprovedCreations'] ?? false) {
            static::addGlobalScope('approvals.unapproved', function (Builder $query): void {
                $query->where(fn (Builder $visible) => $visible
                    ->whereDoesntHave('approvals', fn (Builder $approvals) => $approvals->where('operation', Operation::Create->value))
                    ->orWhereHas('approvals', fn (Builder $approvals) => $approvals->where('operation', Operation::Create->value)->where('status', Status::Approved->value)));
            });
        }
    }

    /** @return MorphMany<Approval, $this> */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Settings::approvalModel(), 'subject');
    }

    public function pendingApproval(): ?Approval
    {
        if ($this->getKey() === null) {
            return null;
        }

        return $this->approvals()->withoutGlobalScopes()->where('status', Status::Pending->value)->latest()->first();
    }

    public function hasPendingApproval(): bool
    {
        return $this->pendingApproval() !== null;
    }

    public function heldApproval(): ?Approval
    {
        return $this->heldApproval;
    }

    public function wasHeldForApproval(): bool
    {
        return $this->heldApproval !== null;
    }

    public function rememberApproval(Approval $approval): void
    {
        $this->heldApproval = $approval;
    }

    public function withApprovalNotes(?string $notes): static
    {
        $this->approvalNotes = $notes;

        return $this;
    }

    public function pullApprovalNotes(): ?string
    {
        $notes = $this->approvalNotes;
        $this->approvalNotes = null;

        return $notes;
    }

    public function isRestoringWithApproval(): bool
    {
        return $this->restoringWithApproval;
    }

    public function markRestoringWithApproval(bool $restoring): void
    {
        $this->restoringWithApproval = $restoring;
    }

    public function isBypassingApproval(): bool
    {
        return $this->bypassingApproval;
    }

    public function submitForApproval(?string $notes = null): ?Approval
    {
        $approval = app(Approvals::class)->submitCreation($this, $notes);

        if ($approval !== null) {
            $this->heldApproval = $approval;
        }

        return $approval;
    }

    public function approvalRelation(string $relation): PendingRelation
    {
        return new PendingRelation($this, $relation);
    }

    /** @param array{touch?: bool|null} $options */
    public function saveWithoutApproval(array $options = []): bool
    {
        return $this->bypassingApproval(fn (): bool => $this->save($options));
    }

    /** @param array<string, mixed> $attributes */
    public function updateWithoutApproval(array $attributes = []): bool
    {
        return $this->bypassingApproval(fn (): bool => $this->update($attributes));
    }

    public function deleteWithoutApproval(): ?bool
    {
        return $this->bypassingApproval(fn (): ?bool => $this->delete());
    }

    public function restoreWithoutApproval(): bool
    {
        return $this->bypassingApproval(fn (): bool => method_exists($this, 'restore') && $this->restore() === true);
    }

    public function forceDeleteWithoutApproval(): ?bool
    {
        return $this->bypassingApproval(fn (): ?bool => $this->forceDelete());
    }

    /** @param Builder<static> $query */
    public function scopeWithUnapproved(Builder $query): void
    {
        $query->withoutGlobalScope('approvals.unapproved');
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function bypassingApproval(\Closure $callback): mixed
    {
        $previous = $this->bypassingApproval;
        $this->bypassingApproval = true;

        try {
            return $callback();
        } finally {
            $this->bypassingApproval = $previous;
        }
    }
}
