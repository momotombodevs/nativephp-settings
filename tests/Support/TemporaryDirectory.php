<?php

declare(strict_types=1);

function createSettingsTemporaryDirectory(): string
{
    $directory = sys_get_temp_dir().'/nativephp-settings-'.bin2hex(random_bytes(8));
    mkdir($directory, 0755, true);

    return $directory;
}

/**
 * Recursively delete a directory created by createSettingsTemporaryDirectory().
 */
function deleteSettingsTemporaryDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        $path = $directory.'/'.$entry;

        is_dir($path) ? deleteSettingsTemporaryDirectory($path) : unlink($path);
    }

    rmdir($directory);
}
