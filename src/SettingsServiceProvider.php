<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Momotombo\NativephpSettings\Bridge\NativePhpSettingsBridge;
use Momotombo\NativephpSettings\Contracts\SettingsBridge;
use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Stores\StoreResolver;
use Momotombo\NativephpSettings\Support\RuntimeEnvironment;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nativephp-settings.php', 'nativephp-settings');

        $this->app->singleton(SettingsBridge::class, NativePhpSettingsBridge::class);

        $this->app->singleton(SettingsStore::class, function (Application $app): SettingsStore {
            $config = $app['config'];

            $resolver = new StoreResolver(
                $app->make(SettingsBridge::class),
                (string) $config->get('nativephp-settings.file_path', storage_path('app/nativephp-settings.json')),
            );

            return $resolver->resolve(
                (string) $config->get('nativephp-settings.driver', 'auto'),
                RuntimeEnvironment::detect($app->runningUnitTests(), $app->environment(['local', 'testing'])),
            );
        });

        $this->app->singleton(Settings::class, fn (Application $app): Settings => new Settings(
            $app->make(SettingsStore::class),
            (bool) $app['config']->get('nativephp-settings.strict', false),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nativephp-settings.php' => config_path('nativephp-settings.php'),
            ], 'nativephp-settings-config');
        }
    }
}
