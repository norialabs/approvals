<?php

declare(strict_types=1);

namespace Noria\Approvals\Exceptions;

use Noria\Approvals\Models\Approval;
use RuntimeException;
use Throwable;

final class ApprovalException extends RuntimeException
{
    public const CLOSED = 'closed';

    public const EXPIRED = 'expired';

    public const NOT_ALLOWED = 'not_allowed';

    public const ALREADY_REVIEWED = 'already_reviewed';

    public const PENDING_EXISTS = 'pending_exists';

    public const STALE = 'stale';

    public const MISSING_SUBJECT = 'missing_subject';

    public const NO_ACTOR = 'no_actor';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?Approval $approval = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function closed(Approval $approval): self
    {
        return new self(self::CLOSED, "This approval is already {$approval->status->value}.", $approval);
    }

    public static function expired(Approval $approval): self
    {
        return new self(self::EXPIRED, 'This approval has expired.', $approval);
    }

    public static function notAllowed(Approval $approval, string $why): self
    {
        return new self(self::NOT_ALLOWED, $why, $approval);
    }

    public static function alreadyReviewed(Approval $approval): self
    {
        return new self(self::ALREADY_REVIEWED, 'You have already approved this request.', $approval);
    }

    public static function pendingExists(Approval $pending): self
    {
        return new self(self::PENDING_EXISTS, 'This record already has a change waiting for approval. Resolve it first.', $pending);
    }

    /** @param list<string> $fields */
    public static function stale(Approval $approval, array $fields): self
    {
        return new self(self::STALE, 'The record changed after this request was made: '.implode(', ', $fields).'. Submit the change again.', $approval);
    }

    public static function missingSubject(Approval $approval): self
    {
        return new self(self::MISSING_SUBJECT, 'The record this approval changes no longer exists.', $approval);
    }

    public static function noActor(): self
    {
        return new self(self::NO_ACTOR, 'Approvals need a signed-in user.');
    }

    public static function failed(Approval $approval, Throwable $previous): self
    {
        return new self(self::FAILED, 'The approved change could not be applied: '.$previous->getMessage(), $approval, $previous);
    }
}
