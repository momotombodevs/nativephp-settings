<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings;

use Illuminate\Support\ServiceProvider;
use Momotombo\NativephpSettings\Bridge\NativePhpSettingsBridge;
use Momotombo\NativephpSettings\Commands\CopyAssetsCommand;
use Momotombo\NativephpSettings\Contracts\SettingsBridge;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsBridge::class, NativePhpSettingsBridge::class);
        $this->app->singleton(Settings::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CopyAssetsCommand::class]);
        }
    }
}
