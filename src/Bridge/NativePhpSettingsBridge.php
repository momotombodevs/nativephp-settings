<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Bridge;

use JsonException;
use Momotombo\NativephpSettings\Contracts\SettingsBridge;
use Momotombo\NativephpSettings\Exceptions\SettingsBridgeException;

final class NativePhpSettingsBridge implements SettingsBridge
{
    public function call(string $method, array $parameters = []): array
    {
        if (! function_exists('nativephp_call')) {
            throw new SettingsBridgeException(
                'BRIDGE_UNAVAILABLE',
                'NativePHP settings are only available inside a NativePHP runtime.'
            );
        }

        try {
            $payload = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            $response = nativephp_call($method, $payload);
        } catch (JsonException $exception) {
            throw new SettingsBridgeException('INVALID_REQUEST', 'The settings request could not be encoded as JSON.', $exception);
        }

        if (! is_string($response) || $response === '') {
            throw new SettingsBridgeException('INVALID_RESPONSE', 'The NativePHP settings bridge returned no response.');
        }

        try {
            $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SettingsBridgeException('INVALID_RESPONSE', 'The NativePHP settings bridge returned malformed JSON.', $exception);
        }

        if (! is_array($decoded)) {
            throw new SettingsBridgeException('INVALID_RESPONSE', 'The NativePHP settings bridge returned an invalid response.');
        }

        if (($decoded['status'] ?? null) === 'error') {
            throw new SettingsBridgeException(
                is_string($decoded['code'] ?? null) ? $decoded['code'] : 'NATIVE_ERROR',
                is_string($decoded['message'] ?? null) ? $decoded['message'] : 'The native settings operation failed.'
            );
        }

        return $decoded;
    }
}
