<?php

declare(strict_types=1);

use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Facades\Settings;
use Momotombo\NativephpSettings\Settings as SettingsService;
use Momotombo\NativephpSettings\Testing\SettingsFake;
use Momotombo\NativephpSettings\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

require_once __DIR__.'/TestCase.php';

uses(TestCase::class);

enum SettingsFakeLanguage: string
{
    case English = 'en';
}

it('swaps the facade and container bindings for an in-memory fake', function () {
    $fake = Settings::fake();

    Settings::set('app.language', 'es');

    expect($fake)->toBeInstanceOf(SettingsFake::class)
        ->and(app(SettingsStore::class))->toBe($fake)
        ->and(app(SettingsService::class)->get('app.language'))->toBe('es')
        ->and(Settings::all())->toBe(['app.language' => 'es']);
});

it('seeds validated initial values without recording them as writes', function () {
    $fake = Settings::fake(['app.language' => SettingsFakeLanguage::English, 'layout.columns' => 2]);

    expect(Settings::get('app.language'))->toBe('en')
        ->and(Settings::get('layout.columns'))->toBe(2);

    $fake->assertNothingSet();
});

it('rejects invalid initial values', function () {
    Settings::fake(['Invalid Key' => 'value']);
})->throws(InvalidArgumentException::class);

it('still validates keys and values written through the facade', function () {
    $fake = Settings::fake();

    expect(fn () => Settings::set('app.value', null))->toThrow(InvalidArgumentException::class);

    $fake->assertNothingSet();
});

it('asserts writes with and without an expected value', function () {
    $fake = Settings::fake();

    Settings::set('app.language', SettingsFakeLanguage::English);
    Settings::set('layout.columns', 3);

    $fake->assertSet('app.language')
        ->assertSet('app.language', SettingsFakeLanguage::English)
        ->assertSet('app.language', 'en')
        ->assertSet('layout.columns', 3);

    expect(fn () => $fake->assertSet('layout.columns', '3'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertSet('profile.name'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingSet())->toThrow(AssertionFailedError::class);
});

it('asserts forgotten keys', function () {
    $fake = Settings::fake(['profile.name' => 'Ana']);

    Settings::forget('profile.name');

    $fake->assertForgotten('profile.name');

    expect(Settings::has('profile.name'))->toBeFalse()
        ->and(fn () => $fake->assertForgotten('app.language'))->toThrow(AssertionFailedError::class);
});
