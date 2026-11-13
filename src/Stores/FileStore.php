<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Stores;

use Closure;
use Illuminate\Support\Facades\Log;
use JsonException;
use Momotombo\NativephpSettings\Contracts\SettingsStore;
use Momotombo\NativephpSettings\Exceptions\SettingsBridgeException;

/**
 * Persists settings in a JSON file. Used off the device (local development,
 * Jump without the plugin compiled in) so code paths behave as on a phone.
 *
 * Writes hold an exclusive lock on a sibling `.lock` file and replace the JSON
 * file atomically (temporary file in the same directory + rename), keeping the
 * existing file's permissions (0644 for a new file).
 *
 * A file that is not a JSON object, is nested too deeply, or is larger than
 * {@see self::MAX_FILE_BYTES} is treated as empty. Before the next write it is
 * moved aside to `<path>.corrupt-<YmdHis>` so nothing is silently overwritten.
 * Only the path is logged, never the contents. Whole-file corruption has no
 * recoverable keys, so it is not reported through `SettingsCorrupted`.
 */
final class FileStore implements SettingsStore
{
    public const MAX_FILE_BYTES = 8 * 1024 * 1024;

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** Generous for values nested 32 levels inside the top-level object. */
    private const MAX_JSON_DEPTH = 64;

    private const DEFAULT_FILE_MODE = 0644;

    public function __construct(private readonly string $path) {}

    public function get(string $key): mixed
    {
        return $this->read()['values'][$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->read()['values']);
    }

    public function set(string $key, mixed $value): void
    {
        $this->mutate(function (array $values) use ($key, $value): array {
            $values[$key] = $value;

            return $values;
        });
    }

    public function forget(string $key): void
    {
        $this->mutate(function (array $values) use ($key): array {
            unset($values[$key]);

            return $values;
        });
    }

    public function all(): array
    {
        return ['values' => $this->read()['values'], 'corrupt' => []];
    }

    /**
     * @return array{values: array<array-key, mixed>, corrupt: bool}
     */
    private function read(): array
    {
        clearstatcache(true, $this->path);

        if (! is_file($this->path)) {
            return ['values' => [], 'corrupt' => false];
        }

        $size = @filesize($this->path);

        if ($size === false) {
            throw new SettingsBridgeException('STORAGE_FAILED', 'The settings file could not be read.');
        }

        if ($size > self::MAX_FILE_BYTES) {
            return $this->corrupt();
        }

        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            throw new SettingsBridgeException('STORAGE_FAILED', 'The settings file could not be read.');
        }

        try {
            $values = json_decode($contents, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->corrupt();
        }

        return is_array($values) ? ['values' => $values, 'corrupt' => false] : $this->corrupt();
    }

    /**
     * @return array{values: array<array-key, mixed>, corrupt: bool}
     */
    private function corrupt(): array
    {
        Log::warning('The NativePHP settings file is corrupt and was treated as empty.', ['path' => $this->path]);

        return ['values' => [], 'corrupt' => true];
    }

    /**
     * @param  Closure(array<array-key, mixed>): array<array-key, mixed>  $mutation
     */
    private function mutate(Closure $mutation): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new SettingsBridgeException('STORAGE_FAILED', 'The settings directory could not be created.');
        }

        $lock = @fopen($this->path.'.lock', 'c');

        if ($lock === false) {
            throw new SettingsBridgeException('STORAGE_FAILED', 'The settings lock file could not be opened.');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new SettingsBridgeException('STORAGE_FAILED', 'The settings file could not be locked.');
            }

            $current = $this->read();

            if ($current['corrupt']) {
                $this->quarantine();
            }

            $this->write($directory, $mutation($current['values']));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function quarantine(): void
    {
        $backup = $this->path.'.corrupt-'.date('YmdHis');

        if (file_exists($backup)) {
            $backup .= '-'.bin2hex(random_bytes(3));
        }

        if (! @rename($this->path, $backup)) {
            throw new SettingsBridgeException('STORAGE_FAILED', 'The corrupt settings file could not be moved aside.');
        }

        Log::warning('The corrupt NativePHP settings file was moved aside.', ['path' => $this->path, 'backup' => $backup]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function write(string $directory, array $values): void
    {
        try {
            // Cast to an object so an empty or list-like map is still written as a JSON object.
            $json = json_encode((object) $values, self::JSON_FLAGS);
        } catch (JsonException $exception) {
            throw new SettingsBridgeException('STORAGE_FAILED', 'The settings could not be encoded as JSON.', $exception);
        }

        $mode = is_file($this->path) ? (@fileperms($this->path) ?: self::DEFAULT_FILE_MODE) & 0777 : self::DEFAULT_FILE_MODE;

        // tempnam() silently falls back to the system temp directory, and a
        // rename across filesystems would not be atomic.
        $temporary = @tempnam($directory, '.nativephp-settings-');

        if ($temporary === false || realpath(dirname($temporary)) !== realpath($directory)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }

            throw new SettingsBridgeException('STORAGE_FAILED', 'A temporary settings file could not be created next to the settings file.');
        }

        if (@file_put_contents($temporary, $json) === false || ! @chmod($temporary, $mode) || ! @rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new SettingsBridgeException('STORAGE_FAILED', 'The settings file could not be written.');
        }
    }
}
