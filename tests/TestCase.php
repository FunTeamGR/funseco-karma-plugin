<?php

namespace Funseco\Karma\Tests;

use Funseco\Core\Testing\TestCase as CoreTestCase;
use Funseco\Karma\KarmaServiceProvider;

abstract class TestCase extends CoreTestCase
{
    /** Karma plus its declared plugin dependencies (none beyond core). */
    protected function plugins(): array
    {
        return [KarmaServiceProvider::class];
    }
}
