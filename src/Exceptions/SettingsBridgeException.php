<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Exceptions;

use RuntimeException;

class SettingsBridgeException extends RuntimeException
{
    public function __construct(
        public readonly string $bridgeCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
