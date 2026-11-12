<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Momotombo\NativephpSettings\Events\SettingsCorrupted;
use Momotombo\NativephpSettings\Exceptions\SettingsBridgeException;
use Momotombo\NativephpSettings\Settings;
use Momotombo\NativephpSettings\Stores\ArrayStore;
use Momotombo\NativephpSettings\Tests\TestCase;

require_once __DIR__.'/TestCase.php';
require_once __DIR__.'/Support/SettingsContractBridge.php';

uses(TestCase::class);

it('returns readable settings and reports keys the native side could not decode', function () {
    Event::fake([SettingsCorrupted::class]);
    Log::spy();

    $bridge = new SettingsContractBridge;
    $bridge->responses['Settings.All'] = [
        'values' => ['app.language' => 'es', 'layout.columns' => 2],
        'corrupt' => ['profile.avatar', 42],
    ];

    expect((new Settings($bridge))->all())->toBe(['app.language' => 'es', 'layout.columns' => 2]);

    Event::assertDispatched(SettingsCorrupted::class, fn (SettingsCorrupted $event): bool => $event->keys === ['profile.avatar']);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context === ['keys' => ['profile.avatar']])
        ->once();
});

it('skips invalid keys and values detected in PHP without logging values', function () {
    Event::fake([SettingsCorrupted::class]);
    Log::spy();

    $bridge = new SettingsContractBridge;
    $bridge->responses['Settings.All'] = [
        'values' => [
            'app.language' => 'es',
            'Invalid.Key' => 'secret-one',
            'profile.token' => null,
            'profile.options' => ['nested' => null],
        ],
        'corrupt' => ['profile.token'],
    ];

    expect((new Settings($bridge))->all())->toBe(['app.language' => 'es']);

    Event::assertDispatched(
        SettingsCorrupted::class,
        fn (SettingsCorrupted $event): bool => $event->keys === ['profile.token', 'Invalid.Key', 'profile.options'],
    );
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => ! str_contains($message.json_encode($context), 'secret-one'))
        ->once();
});

it('does not dispatch an event when every entry is readable', function () {
    Event::fake([SettingsCorrupted::class]);

    expect((new Settings(new ArrayStore(['app.language' => 'es'])))->all())->toBe(['app.language' => 'es']);

    Event::assertNotDispatched(SettingsCorrupted::class);
});

it('keeps failing reads of a single corrupt key', function () {
    $bridge = new SettingsContractBridge;
    $bridge->responses['Settings.Get'] = ['found' => true, 'value' => ['nested' => null]];

    expect(fn () => (new Settings($bridge))->get('profile.avatar'))->toThrow(SettingsBridgeException::class);
});

it('reports skipped keys with allWithReport', function () {
    Event::fake([SettingsCorrupted::class]);

    $bridge = new SettingsContractBridge;
    $bridge->responses['Settings.All'] = [
        'values' => ['app.language' => 'es', 'app.__proto__' => 'x', 'profile.avatar' => ['nested' => null]],
        'corrupt' => ['profile.photo', 'profile.photo'],
    ];

    expect((new Settings($bridge))->allWithReport())->toBe([
        'values' => ['app.language' => 'es'],
        'corrupt' => ['profile.photo', 'app.__proto__', 'profile.avatar'],
    ]);

    Event::assertDispatchedTimes(SettingsCorrupted::class, 1);
});

it('reports an empty corrupt list when everything is readable', function () {
    expect((new Settings(new ArrayStore(['app.language' => 'es'])))->allWithReport())
        ->toBe(['values' => ['app.language' => 'es'], 'corrupt' => []]);
});
