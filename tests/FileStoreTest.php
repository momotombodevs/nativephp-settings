<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Momotombo\NativephpSettings\Settings;
use Momotombo\NativephpSettings\Stores\FileStore;
use Momotombo\NativephpSettings\Tests\TestCase;

require_once __DIR__.'/TestCase.php';
require_once __DIR__.'/Support/TemporaryDirectory.php';

uses(TestCase::class);

beforeEach(function () {
    $this->directory = createSettingsTemporaryDirectory();
    $this->path = $this->directory.'/nested/settings.json';
});

afterEach(function () {
    deleteSettingsTemporaryDirectory($this->directory);
});

/**
 * @return list<string>
 */
function settingsFilesIn(string $directory): array
{
    return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
}

function writeSettingsFile(string $path, string $contents): void
{
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $contents);
}

it('creates the directory and persists values across store instances', function () {
    (new Settings(new FileStore($this->path)))->set('app.language', 'es');

    expect((new Settings(new FileStore($this->path)))->get('app.language'))->toBe('es')
        ->and(json_decode((string) file_get_contents($this->path), true))->toBe(['app.language' => 'es']);
});

it('writes a JSON object with mode 0644 and leaves no temporary files behind', function () {
    $settings = new Settings(new FileStore($this->path));

    $settings->set('app.language', 'es');
    $settings->forget('app.language');

    clearstatcache();

    expect(file_get_contents($this->path))->toBe('{}')
        ->and(fileperms($this->path) & 0777)->toBe(0644)
        ->and(settingsFilesIn(dirname($this->path)))->toBe(['settings.json', 'settings.json.lock']);
});

it('preserves the permissions of an existing file', function () {
    writeSettingsFile($this->path, '{}');
    chmod($this->path, 0600);

    (new Settings(new FileStore($this->path)))->set('app.language', 'es');

    clearstatcache();

    expect(fileperms($this->path) & 0777)->toBe(0600);
});

it('treats unreadable files as empty and moves them aside before the next write', function (string $contents) {
    writeSettingsFile($this->path, $contents);
    Log::spy();

    $settings = new Settings(new FileStore($this->path));

    expect($settings->all())->toBe([])
        ->and($settings->get('secret.token', 'none'))->toBe('none');

    $settings->set('app.language', 'en');

    $backups = array_values(array_filter(settingsFilesIn(dirname($this->path)), fn (string $file): bool => str_starts_with($file, 'settings.json.corrupt-')));

    expect(json_decode((string) file_get_contents($this->path), true))->toBe(['app.language' => 'en'])
        ->and($backups)->toHaveCount(1)
        ->and(file_get_contents(dirname($this->path).'/'.$backups[0]))->toBe($contents);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => array_key_exists('path', $context)
            && ! str_contains($message.json_encode($context), 'abc123'))
        ->atLeast()->once();
})->with([
    'invalid JSON' => ['{"secret.token": "abc123"'],
    'not an object' => ['"abc123"'],
    'too deep' => ['{"secret.token":'.str_repeat('[', 70).'"abc123"'.str_repeat(']', 70).'}'],
]);

it('treats a file larger than the size cap as corrupt without reading it', function () {
    writeSettingsFile($this->path, '{"app.language":"'.str_repeat('x', FileStore::MAX_FILE_BYTES).'"}');
    Log::spy();

    expect((new Settings(new FileStore($this->path)))->all())->toBe([]);

    Log::shouldHaveReceived('warning')->once();
});
