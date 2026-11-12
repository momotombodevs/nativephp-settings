<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Facades;

use BackedEnum;
use Illuminate\Support\Facades\Facade;
use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Exceptions\SettingTypeMismatch;
use Momotombo\NativephpSettings\Settings as SettingsService;
use Momotombo\NativephpSettings\Testing\SettingsFake;

/**
 * @method static void set(string $key, mixed $value)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool has(string $key)
 * @method static void forget(string $key)
 * @method static array<string|int, mixed> all()
 * @method static array{values: array<string|int, mixed>, corrupt: list<string>} allWithReport()
 * @method static bool getBool(string $key, bool $default)
 * @method static int getInt(string $key, int $default)
 * @method static float getFloat(string $key, float $default)
 * @method static string getString(string $key, string $default)
 * @method static array<array-key, mixed> getArray(string $key, array $default)
 *
 * @see SettingsService
 */
class Settings extends Facade
{
    /**
     * Replace settings storage with an in-memory fake for the current test.
     *
     * Initial values are validated like real writes but are not recorded as writes.
     *
     * @param  array<string, mixed>  $initial
     *
     * @throws \InvalidArgumentException When an initial key or value violates the storage contract.
     */
    public static function fake(array $initial = []): SettingsFake
    {
        $fake = new SettingsFake;
        $settings = new SettingsService($fake, (bool) static::$app['config']->get('nativephp-settings.strict', false));

        foreach ($initial as $key => $value) {
            $settings->set((string) $key, $value);
        }

        $fake->clearRecorded();

        static::$app->instance(SettingsStore::class, $fake);
        static::swap($settings);

        return $fake;
    }

    /**
     * Read a backed enum case of the default's enum class.
     *
     * Declared here, rather than as a `@method` tag, so static analysis and
     * IDEs keep the concrete enum type of the default.
     *
     * @template TEnum of BackedEnum
     *
     * @param  TEnum  $default
     * @return TEnum
     *
     * @throws SettingTypeMismatch In strict mode when the stored value is not a case of the enum.
     */
    public static function getEnum(string $key, BackedEnum $default): BackedEnum
    {
        return static::getFacadeRoot()->getEnum($key, $default);
    }

    protected static function getFacadeAccessor(): string
    {
        return SettingsService::class;
    }
}
