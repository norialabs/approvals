<?php

declare(strict_types=1);

namespace Noria\Approvals\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Noria\Approvals\Contracts\ApprovableAction;
use Noria\Approvals\Models\Approval;
use Noria\Approvals\PendingChange;
use Noria\Approvals\Requirement;
use Noria\Approvals\RequiresApproval;
use Noria\Approvals\Step;

trait KeyedByConfig
{
    abstract public static function keyConfig(): string;

    public function getIncrementing(): bool
    {
        return config(static::keyConfig()) === 'int';
    }

    public function getKeyType(): string
    {
        return config(static::keyConfig()) === 'int' ? 'int' : 'string';
    }

    public function usesUniqueIds(): bool
    {
        return config(static::keyConfig()) !== 'int';
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return [$this->getKeyName()];
    }

    public function newUniqueId(): string
    {
        return config(static::keyConfig()) === 'uuid' ? (string) Str::orderedUuid() : strtolower((string) Str::ulid());
    }
}

/**
 * @property int|string $id
 * @property string $name
 * @property string|null $role
 */
final class User extends Authenticatable
{
    use KeyedByConfig;

    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('approvals.actors.table');

        return is_string($table) ? $table : 'users';
    }

    public static function keyConfig(): string
    {
        return 'approvals.actors.key';
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }
}

/** @property int|string $id */
final class Organisation extends Model
{
    use KeyedByConfig;

    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('approvals.tenancy.table');

        return is_string($table) ? $table : 'tenants';
    }

    public static function keyConfig(): string
    {
        return 'approvals.tenancy.key';
    }
}

/**
 * @property int $id
 * @property string $number
 * @property string $amount
 * @property string $status
 * @property string|null $notes
 * @property string|null $secret
 * @property string|null $viewed_at
 */
final class Invoice extends Model
{
    use RequiresApproval;
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected $casts = ['amount' => 'decimal:2'];

    /** @var list<string> */
    protected array $approvalIgnored = ['viewed_at'];

    /** @var list<string> */
    protected array $approvalEncrypted = ['secret'];

    /** @var list<int|string|null> */
    public static array $applied = [];

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot('note');
    }

    /** @param array<string, mixed> $extras */
    public function applyApprovalExtras(array $extras, ?Approval $approval): void
    {
        $tags = $extras['tags'] ?? [];

        if (is_array($tags)) {
            $this->tags()->sync($tags);
        }
    }

    public function approvalApplied(Approval $approval): void
    {
        $key = $approval->getKey();
        self::$applied[] = is_int($key) || is_string($key) ? $key : null;
    }
}

/**
 * @property string $id
 * @property string $title
 */
class Document extends Model
{
    use HasUlids;
    use RequiresApproval;

    protected $guarded = [];

    protected $table = 'documents';

    protected bool $hidesUnapprovedCreations = true;
}

final class Drawing extends Document
{
    public function discardUnapprovedCreation(Approval $approval): void
    {
        $this->forceFill(['title' => 'Rejected: '.$this->title])->saveWithoutApproval();
    }
}

/**
 * @property string $id
 * @property string $title
 * @property string $value
 */
final class Contract extends Model
{
    use HasUuids;
    use RequiresApproval;

    protected $guarded = [];

    /** @var list<string> */
    protected array $approvalOperations = ['update', 'delete'];

    public function approvalRequirement(PendingChange $change): ?Requirement
    {
        $value = $change->value('value');

        if (is_numeric($value) && (float) $value >= 1000) {
            return Requirement::of(new Step('legal'), new Step('board', approvals: 2));
        }

        return null;
    }
}

/**
 * @property int $id
 * @property string $body
 */
final class Memo extends Model
{
    use RequiresApproval;
    use SoftDeletes;

    public const CREATED_AT = 'written_on';

    public const UPDATED_AT = 'revised_on';

    public const DELETED_AT = 'removed_on';

    protected $guarded = [];

    /** @var list<string> */
    protected array $approvalOperations = ['update'];
}

/** @property int $id */
final class Tag extends Model
{
    protected $guarded = [];
}

final class Payout implements ApprovableAction
{
    /** @var list<array{account: string, amount: int, approval: int|string|null}> */
    public static array $paid = [];

    public function __construct(public readonly string $account, public readonly int $amount) {}

    public function payload(): array
    {
        return ['account' => $this->account, 'amount' => $this->amount];
    }

    public static function fromPayload(array $payload): static
    {
        $account = $payload['account'] ?? '';
        $amount = $payload['amount'] ?? 0;

        return new self(is_string($account) ? $account : '', is_int($amount) ? $amount : 0);
    }

    public function handle(?Approval $approval): void
    {
        $key = $approval?->getKey();
        self::$paid[] = ['account' => $this->account, 'amount' => $this->amount, 'approval' => is_int($key) || is_string($key) ? $key : null];
    }
}
