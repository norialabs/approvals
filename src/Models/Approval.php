<?php

declare(strict_types=1);

namespace Noria\Approvals\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Noria\Approvals\Operation;
use Noria\Approvals\Requirement;
use Noria\Approvals\Settings;
use Noria\Approvals\Snapshot;
use Noria\Approvals\Status;
use Noria\Approvals\Step;
use Noria\Approvals\Tally;

/**
 * @property int|string $id
 * @property string $subject_type
 * @property int|string|null $subject_id
 * @property Operation $operation
 * @property Status $status
 * @property int|string|null $maker_id
 * @property array<string, mixed> $requirement
 * @property int $step
 * @property int $stage
 * @property array<string, mixed>|null $current_state
 * @property array<string, mixed>|null $new_state
 * @property array<string, mixed>|null $payload
 * @property string|null $maker_notes
 * @property int|string|null $closer_id
 * @property CarbonInterface|null $closed_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $escalates_at
 * @property CarbonInterface|null $reminded_at
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Approval extends Model
{
    use Concerns;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'step' => 0,
        'stage' => 1,
    ];

    public function getTable(): string
    {
        return Settings::table('approvals');
    }

    protected function casts(): array
    {
        return [
            'operation' => Operation::class,
            'status' => Status::class,
            'requirement' => 'array',
            'step' => 'integer',
            'stage' => 'integer',
            'current_state' => 'array',
            'new_state' => 'array',
            'payload' => 'array',
            'meta' => 'array',
            'closed_at' => 'datetime',
            'expires_at' => 'datetime',
            'escalates_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    /** @return BelongsTo<Model, $this> */
    public function maker(): BelongsTo
    {
        return $this->belongsTo(self::actorModel(), 'maker_id', Settings::actorColumns()['references']);
    }

    /** @return BelongsTo<Model, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(self::actorModel(), 'closer_id', Settings::actorColumns()['references']);
    }

    /** @return HasMany<ApprovalEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Settings::eventModel(), 'approval_id')->orderBy('created_at')->orderBy((new (Settings::eventModel()))->getKeyName());
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', Status::Pending->value);
    }

    /** @param Builder<self> $query */
    public function scopeForSubject(Builder $query, Model $subject): void
    {
        $query->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey());
    }

    /** @param Builder<self> $query */
    public function scopeMadeBy(Builder $query, int|string $actorId): void
    {
        $query->where('maker_id', $actorId);
    }

    /** @return array<string, mixed> */
    public function extras(): array
    {
        $extras = $this->payload['extras'] ?? [];

        return is_array($extras) ? Settings::stringKeys($extras) : [];
    }

    public function reason(): ?string
    {
        $reason = $this->requirement['automatic'] ?? $this->requirement['reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    public function isAutomatic(): bool
    {
        return isset($this->requirement['automatic']);
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isExpired(?CarbonInterface $at = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($at ?? now());
    }

    public function requirement(): Requirement
    {
        return Requirement::fromArray($this->requirement);
    }

    public function currentStep(): ?Step
    {
        return $this->isOpen() ? $this->requirement()->step($this->step) : null;
    }

    /** @return class-string|null */
    public function subjectClass(): ?string
    {
        $class = Relation::getMorphedModel($this->subject_type) ?? $this->subject_type;

        return class_exists($class) ? $class : null;
    }

    public function resolveSubject(bool $lock = false): ?Model
    {
        $class = $this->subjectClass();

        if ($class === null || ! is_a($class, Model::class, true) || $this->subject_id === null) {
            return null;
        }

        $query = $class::query()->withoutGlobalScopes();

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->find($this->subject_id);
    }

    /** @return list<array{field: string, from: mixed, to: mixed}> */
    public function changes(): array
    {
        $class = $this->subjectClass();
        $hidden = $class !== null && is_a($class, Model::class, true) ? Snapshot::hidden(new $class) : [];

        return Snapshot::changes($this->current_state, $this->new_state, $hidden);
    }

    /** @return list<int|string> */
    public function approverIds(?int $stage = null): array
    {
        $query = Settings::eventModel()::query()
            ->withoutGlobalScopes()
            ->where('approval_id', $this->getKey())
            ->where('action', 'approved');

        if ($stage !== null) {
            $query->where('stage', $stage);
        }

        $ids = [];

        foreach ($query->get(['actor_id', 'principal_id']) as $event) {
            foreach ([$event->actor_id, $event->principal_id] as $id) {
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }

    public function approvalsAtStage(): int
    {
        return count($this->votes());
    }

    /** @return list<list<string>> */
    public function votes(): array
    {
        $fallback = $this->requirement()->step($this->step)?->groups[0]->name;
        $votes = [];

        $events = Settings::eventModel()::query()
            ->withoutGlobalScopes()
            ->where('approval_id', $this->getKey())
            ->where('stage', $this->stage)
            ->where('action', 'approved')
            ->get(['payload']);

        foreach ($events as $event) {
            $groups = $event->payload['groups'] ?? null;
            $votes[] = is_array($groups) ? array_values(array_filter($groups, is_string(...))) : ($fallback === null ? [] : [$fallback]);
        }

        return $votes;
    }

    public function tally(): Tally
    {
        $step = $this->requirement()->step($this->step) ?? throw new LogicException('This approval has no step at its current position.');

        return new Tally($step, $this->votes());
    }

    public function summary(): string
    {
        $subject = class_basename($this->subjectClass() ?? $this->subject_type);

        return trim($this->operation->label().' '.$subject.($this->subject_id === null ? '' : ' '.$this->subject_id));
    }

    public function usesSoftDeletes(): bool
    {
        $class = $this->subjectClass();

        return $class !== null && in_array(SoftDeletes::class, class_uses_recursive($class), true);
    }

    /** @return class-string<Model> */
    private static function actorModel(): string
    {
        return Settings::actorModel() ?? throw new LogicException('Set approvals.actors.model to load approval actors.');
    }
}
