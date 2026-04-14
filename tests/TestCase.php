<?php

declare(strict_types=1);

namespace Deniscsz\HorizonClusterScaling\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Deniscsz\HorizonClusterScaling\HorizonClusterScalingServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            HorizonClusterScalingServiceProvider::class,
        ];
    }
}