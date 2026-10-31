<?php

declare(strict_types=1);

namespace Noria\Approvals\Exceptions;

use RuntimeException;
use Throwable;

/** @internal */
final class ApplyFailed extends RuntimeException
{
    public function __construct(public readonly Throwable $cause)
    {
        parent::__construct($cause->getMessage(), 0, $cause);
    }
}
