<?php

declare(strict_types=1);

namespace Noria\Approvals\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Noria\Approvals\Settings;

/**
 * @property int|string $id
 * @property int|string $approval_id
 * @property string $action
 * @property int|null $stage
 * @property string|null $step
 * @property int|string|null $actor_id
 * @property int|string|null $principal_id
 * @property string|null $notes
 * @property array<string, mixed>|null $payload
 * @property CarbonInterface|null $created_at
 */
class ApprovalEvent extends Model
{
    use Concerns;

    public const UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return Settings::table('events');
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Approval events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Approval events are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Approval, $this> */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Settings::approvalModel(), 'approval_id');
    }

    /** @return BelongsTo<Model, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(self::actorModel(), 'actor_id', Settings::actorColumns()['references']);
    }

    /** @return BelongsTo<Model, $this> */
    public function principal(): BelongsTo
    {
        return $this->belongsTo(self::actorModel(), 'principal_id', Settings::actorColumns()['references']);
    }

    /** @return class-string<Model> */
    private static function actorModel(): string
    {
        return Settings::actorModel() ?? throw new LogicException('Set approvals.actors.model to load approval actors.');
    }
}
