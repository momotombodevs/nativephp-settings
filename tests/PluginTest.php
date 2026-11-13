<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Momotombo\NativephpSettings\Facades\Settings as SettingsFacade;
use Momotombo\NativephpSettings\Settings;
use Momotombo\NativephpSettings\SettingsServiceProvider;
use Native\Mobile\Plugins\PluginManifest;

/**
 * @return array<string, mixed>
 */
function settingsPluginJson(string $file): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__).'/'.$file), true, 512, JSON_THROW_ON_ERROR);
}

function settingsPluginSource(string $file): string
{
    return (string) file_get_contents(dirname(__DIR__).'/'.$file);
}

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function settingsBridgeFunctions(): array
{
    $functions = [];

    foreach (settingsPluginJson('nativephp.json')['bridge_functions'] as $function) {
        $functions[$function['name']] = [$function];
    }

    return $functions;
}

describe('manifest', function () {
    it('is accepted by the NativePHP plugin manifest parser', function () {
        $manifest = PluginManifest::fromFile(dirname(__DIR__).'/nativephp.json');

        expect($manifest->namespace)->toBe('Settings')
            ->and($manifest->platforms)->toBe(['android', 'ios'])
            ->and(array_column($manifest->bridgeFunctions, 'name'))->toBe([
                'Settings.Set',
                'Settings.Get',
                'Settings.Has',
                'Settings.Forget',
                'Settings.All',
            ])
            ->and($manifest->hooks)->toBe([]);
    });

    it('matches the composer package metadata', function () {
        $manifest = settingsPluginJson('nativephp.json');
        $composer = settingsPluginJson('composer.json');

        expect($manifest['name'])->toBe($composer['name'])
            ->and($manifest['license'])->toBe($composer['license'])
            ->and($manifest['service_provider'])->toBe(SettingsServiceProvider::class)
            ->and($composer['type'])->toBe('nativephp-plugin')
            ->and($composer['extra']['nativephp']['manifest'])->toBe('nativephp.json')
            ->and($composer['extra']['laravel']['providers'])->toBe([SettingsServiceProvider::class])
            ->and($manifest['keywords'])->not->toBeEmpty();
    });

    it('declares the version of the latest changelog entry', function () {
        preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', settingsPluginSource('CHANGELOG.md'), $matches);

        expect($matches[1] ?? null)->toBe(settingsPluginJson('nativephp.json')['version']);
    });
});

describe('native parity', function () {
    it('implements each bridge function in Swift and Kotlin', function (array $function) {
        $swift = settingsPluginSource('resources/ios/SettingsFunctions.swift');
        $kotlin = settingsPluginSource('resources/android/SettingsFunctions.kt');

        [$swiftContainer, $swiftClass] = explode('.', $function['ios']);
        $androidParts = explode('.', $function['android']);
        $kotlinClass = array_pop($androidParts);
        $kotlinContainer = array_pop($androidParts);
        $kotlinPackage = implode('.', $androidParts);

        expect($swift)->toContain("enum {$swiftContainer} {")
            ->toMatch('/^\s+class '.preg_quote($swiftClass, '/').': BridgeFunction \{/m')
            ->and($kotlin)->toContain("package {$kotlinPackage}\n")
            ->toContain("object {$kotlinContainer} {")
            ->toMatch('/^\s+class '.preg_quote($kotlinClass, '/').'\(private val context: Context\) : BridgeFunction \{/m')
            ->and($function['android_params'] ?? [])->toBe(['context']);
    })->with(settingsBridgeFunctions());

    it('uses the same storage prefixes and limits on both platforms', function () {
        $swift = settingsPluginSource('resources/ios/SettingsFunctions.swift');
        $kotlin = settingsPluginSource('resources/android/SettingsFunctions.kt');

        foreach (['"dev.momotombo.nativephp.settings."', '"mnp-settings:v1:s:"', '"mnp-settings:v1:d:"', '"mnp-settings:v1:j:"', '"mnp-settings:v2:"', '65_536', '= 32'] as $token) {
            expect($swift)->toContain($token)
                ->and($kotlin)->toContain($token);
        }

        expect(Settings::MAX_VALUE_BYTES)->toBe(65_536);
    });

    it('rejects the same reserved key segments in PHP, Swift, Kotlin, and JavaScript', function () {
        $sources = [
            'swift' => settingsPluginSource('resources/ios/SettingsFunctions.swift'),
            'kotlin' => settingsPluginSource('resources/android/SettingsFunctions.kt'),
            'javascript' => str_replace("'", '"', settingsPluginSource('resources/js/settings.js')),
        ];

        foreach ($sources as $platform => $source) {
            expect(preg_match('/RESERVED_KEY_SEGMENTS[^\n]*|reservedKeySegments[^\n]*/i', $source, $match))->toBe(1, $platform);

            preg_match_all('/"([^"]+)"/', $match[0], $segments);

            expect($segments[1])->toBe(Settings::RESERVED_KEY_SEGMENTS, $platform);
        }
    });
});

describe('php entry points', function () {
    it('exposes the facade, service, and provider classes', function () {
        expect(class_exists(SettingsFacade::class))->toBeTrue()
            ->and(class_exists(Settings::class))->toBeTrue()
            ->and(is_subclass_of(SettingsServiceProvider::class, ServiceProvider::class))->toBeTrue()
            ->and(file_exists(dirname(__DIR__).'/config/nativephp-settings.php'))->toBeTrue();
    });
});
