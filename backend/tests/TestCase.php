<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Safety net: RefreshDatabase wipes the database. Never let it touch a real one, e.g.
        // when variables exported in the shell override phpunit.xml's sqlite settings.
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        if ($connection !== 'sqlite' && ! str_ends_with($database, '_testing')) {
            throw new RuntimeException("Refusing to run tests against database [{$connection}:{$database}]. Use sqlite or a *_testing database.");
        }
    }
}
