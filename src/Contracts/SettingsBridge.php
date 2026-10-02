<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Contracts;

interface SettingsBridge
{
    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function call(string $method, array $parameters = []): array;
}
