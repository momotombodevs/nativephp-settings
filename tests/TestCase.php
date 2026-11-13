<?php

declare(strict_types=1);

namespace Momotombo\NativephpSettings\Tests;

use Momotombo\NativephpSettings\SettingsServiceProvider;
use Native\Mobile\NativeServiceProvider;

/*
 * The package tests run standalone (with Orchestra Testbench from this package's
 * vendor directory) and from a consuming NativePHP app's test runner, where the
 * app's own Tests\TestCase boots Laravel with this package auto-discovered.
 * Test files require this file directly because a consuming app's runner only
 * loads its own tests/Pest.php.
 */
if (class_exists(\Orchestra\Testbench\TestCase::class)) {
    abstract class BaseTestCase extends \Orchestra\Testbench\TestCase
    {
        /**
         * @return list<class-string>
         */
        protected function getPackageProviders($app): array
        {
            return [NativeServiceProvider::class, SettingsServiceProvider::class];
        }
    }
} else {
    abstract class BaseTestCase extends \Tests\TestCase {}
}

abstract class TestCase extends BaseTestCase {}
