<?php

declare(strict_types=1);

use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Settings;
use Momotombo\NativephpSettings\Stores\ArrayStore;
use Momotombo\NativephpSettings\Stores\FileStore;
use Momotombo\NativephpSettings\Stores\NativeStore;
use Momotombo\NativephpSettings\Tests\TestCase;

require_once __DIR__.'/TestCase.php';
require_once __DIR__.'/Support/SettingsContractBridge.php';
require_once __DIR__.'/Support/TemporaryDirectory.php';

uses(TestCase::class);

dataset('stores', [
    'array' => fn (): SettingsStore => new ArrayStore,
    'file' => function (): SettingsStore {
        $directory = createSettingsTemporaryDirectory();
        test()->temporaryDirectory = $directory;

        return new FileStore($directory.'/settings.json');
    },
    'native (fake bridge)' => fn (): SettingsStore => new NativeStore(new SettingsContractBridge),
]);

afterEach(function () {
    if (is_string($this->temporaryDirectory ?? null)) {
        deleteSettingsTemporaryDirectory($this->temporaryDirectory);
    }
});

it('round trips every supported value type', function (SettingsStore $store) {
    $settings = new Settings($store);
    $nested = ['show_email' => true, 'shortcuts' => ['home', 'search'], 'scale' => 1.5];

    $settings->set('profile.name', 'Ana Pérez');
    $settings->set('feature.enabled', false);
    $settings->set('layout.columns', 0);
    $settings->set('display.scale', 1.0);
    $settings->set('profile.options', $nested);

    expect($settings->get('profile.name'))->toBe('Ana Pérez')
        ->and($settings->get('feature.enabled'))->toBeFalse()
        ->and($settings->get('layout.columns'))->toBe(0)
        ->and($settings->get('display.scale'))->toBe(1.0)
        ->and($settings->get('profile.options'))->toBe($nested)
        ->and($settings->all())->toBe([
            'profile.name' => 'Ana Pérez',
            'feature.enabled' => false,
            'layout.columns' => 0,
            'display.scale' => 1.0,
            'profile.options' => $nested,
        ]);
})->with('stores');

it('reports absent keys with found semantics', function (SettingsStore $store) {
    $settings = new Settings($store);

    expect($store->get('missing.key'))->toBeNull()
        ->and($store->has('missing.key'))->toBeFalse()
        ->and($settings->get('missing.key', 'fallback'))->toBe('fallback')
        ->and($store->all())->toBe(['values' => [], 'corrupt' => []]);

    $settings->forget('missing.key');

    expect($settings->has('missing.key'))->toBeFalse();
})->with('stores');

it('replaces and forgets values without touching other keys', function (SettingsStore $store) {
    $settings = new Settings($store);

    $settings->set('app.language', 'es');
    $settings->set('app.language', 'en');
    $settings->set('profile.name', 'Ana');
    $settings->forget('profile.name');
    $settings->forget('profile.name');

    expect($settings->all())->toBe(['app.language' => 'en'])
        ->and($settings->has('profile.name'))->toBeFalse();
})->with('stores');
