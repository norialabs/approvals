<?php

declare(strict_types=1);

namespace Noria\Approvals\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Noria\Approvals\Settings;

/**
 * @property int|string $id
 * @property int|string $delegator_id
 * @property int|string $delegate_id
 * @property string|null $subject_type
 * @property CarbonInterface $starts_at
 * @property CarbonInterface|null $ends_at
 * @property CarbonInterface|null $revoked_at
 */
class ApprovalDelegation extends Model
{
    use Concerns;

    protected $guarded = [];

    public function getTable(): string
    {
        return Settings::table('delegations');
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query, ?CarbonInterface $at = null): void
    {
        $at ??= now();

        $query->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where(fn (Builder $window) => $window->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    /** @param Builder<self> $query */
    public function scopeCovering(Builder $query, string $subjectType): void
    {
        $query->where(fn (Builder $scope) => $scope->whereNull('subject_type')->orWhere('subject_type', $subjectType));
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->revoked_at === null
            && $this->starts_at->lessThanOrEqualTo($at)
            && ($this->ends_at === null || $this->ends_at->greaterThan($at));
    }

    /** @return BelongsTo<Model, $this> */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(self::actorModel(), 'delegator_id', Settings::actorColumns()['references']);
    }

    /** @return BelongsTo<Model, $this> */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(self::actorModel(), 'delegate_id', Settings::actorColumns()['references']);
    }

    /** @return class-string<Model> */
    private static function actorModel(): string
    {
        return Settings::actorModel() ?? throw new LogicException('Set approvals.actors.model to load approval actors.');
    }
}
