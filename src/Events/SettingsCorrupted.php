<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Events;

/**
 * Dispatched by Settings::all() when stored entries could not be read and were skipped.
 *
 * Only keys are reported; stored values are never included.
 */
final class SettingsCorrupted
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(public readonly array $keys) {}
}
