<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Support;

use Native\Mobile\System;
use Native\Mobile\Testing\FakeBridge;
use ReflectionFunction;

/**
 * Snapshot of where PHP is running, used to resolve the settings driver.
 *
 * How the runtimes are told apart (NativePHP Mobile ^4.5):
 * - On a device, `nativephp_call()` is provided by the embedded C extension,
 *   so it is an internal function.
 * - Off the device, NativePHP core defines a user-land `nativephp_call()`
 *   fallback (`jump_bridge_functions.php`) that relays calls to a phone over
 *   `native:jump` (detected with {@see System::runningInJump()}) or to a
 *   test {@see FakeBridge}. Without either, there is no device to talk to.
 */
final readonly class RuntimeEnvironment
{
    public function __construct(
        public bool $onDevice,
        public bool $runningInJump,
        public bool $fakeBridgeBound,
        public bool $settingsBridgeAvailable,
        public bool $runningUnitTests,
        public bool $localEnvironment,
    ) {}

    /**
     * @param  bool  $localEnvironment  Whether the app environment is `local` or `testing`.
     */
    public static function detect(bool $runningUnitTests, bool $localEnvironment): self
    {
        $bridgeDefined = function_exists('nativephp_call');
        $onDevice = $bridgeDefined && (new ReflectionFunction('nativephp_call'))->isInternal();
        $runningInJump = ! $onDevice && $bridgeDefined && System::runningInJump();
        $fakeBridgeBound = ! $onDevice && $bridgeDefined && FakeBridge::current() !== null;

        return new self(
            onDevice: $onDevice,
            runningInJump: $runningInJump,
            fakeBridgeBound: $fakeBridgeBound,
            settingsBridgeAvailable: $onDevice
                || (($runningInJump || $fakeBridgeBound) && function_exists('nativephp_can') && nativephp_can('Settings.Get')),
            runningUnitTests: $runningUnitTests,
            localEnvironment: $localEnvironment,
        );
    }
}
