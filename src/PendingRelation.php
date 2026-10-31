<?php

declare(strict_types=1);

namespace Noria\Approvals;

use Illuminate\Database\Eloquent\Model;
use Noria\Approvals\Models\Approval;

final class PendingRelation
{
    private ?string $notes = null;

    public function __construct(private readonly Model $model, private readonly string $relation) {}

    public function withNotes(?string $notes): self
    {
        $this->notes = $notes;

        return $this;
    }

    /** @param array<string, mixed> $attributes */
    public function attach(mixed $ids, array $attributes = []): ?Approval
    {
        return $this->write('attach', [$ids, $attributes]);
    }

    public function detach(mixed $ids = null): ?Approval
    {
        return $this->write('detach', [$ids]);
    }

    public function sync(mixed $ids, bool $detaching = true): ?Approval
    {
        return $this->write('sync', [$ids, $detaching]);
    }

    public function syncWithoutDetaching(mixed $ids): ?Approval
    {
        return $this->write('syncWithoutDetaching', [$ids]);
    }

    /** @param array<string, mixed> $values */
    public function syncWithPivotValues(mixed $ids, array $values, bool $detaching = true): ?Approval
    {
        return $this->write('syncWithPivotValues', [$ids, $values, $detaching]);
    }

    public function toggle(mixed $ids): ?Approval
    {
        return $this->write('toggle', [$ids]);
    }

    /** @param array<string, mixed> $attributes */
    public function updateExistingPivot(mixed $id, array $attributes): ?Approval
    {
        return $this->write('updateExistingPivot', [$id, $attributes]);
    }

    /** @param list<mixed> $arguments */
    private function write(string $method, array $arguments): ?Approval
    {
        $approval = app(Approvals::class)->relation($this->model, $this->relation, $method, $arguments, $this->notes);

        if ($approval !== null && method_exists($this->model, 'rememberApproval')) {
            $this->model->rememberApproval($approval);
        }

        return $approval;
    }
}
