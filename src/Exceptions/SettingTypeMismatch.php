<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Exceptions;

use UnexpectedValueException;

/**
 * Thrown by typed getters in strict mode when a stored value has another type.
 *
 * The message names the key and the expected type, never the stored value.
 */
final class SettingTypeMismatch extends UnexpectedValueException
{
    public function __construct(
        public readonly string $key,
        public readonly string $expected,
    ) {
        parent::__construct("The setting [{$key}] is not a valid {$expected}.");
    }
}
