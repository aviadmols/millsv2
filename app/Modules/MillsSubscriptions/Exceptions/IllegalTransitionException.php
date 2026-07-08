<?php

namespace App\Modules\MillsSubscriptions\Exceptions;

use BackedEnum;
use RuntimeException;

/**
 * Thrown when code attempts a status transition that is not in the model's
 * allowed table. Fail loud — an illegal transition is a bug, never swallowed.
 */
class IllegalTransitionException extends RuntimeException
{
    public function __construct(object $model, BackedEnum $from, BackedEnum $to)
    {
        parent::__construct(sprintf(
            'Illegal transition on %s: %s → %s',
            class_basename($model),
            $from->value,
            $to->value,
        ));
    }
}
