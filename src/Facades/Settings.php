<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void set(string $key, mixed $value)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool has(string $key)
 * @method static void forget(string $key)
 * @method static array<string|int, mixed> all()
 *
 * @see \Momotombo\NativephpSettings\Settings
 */
class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Momotombo\NativephpSettings\Settings::class;
    }
}
