<?php

namespace Tests\Unit;

use Tests\TestCase;

class TestingDatabaseSafetyTest extends TestCase
{
    public function test_automated_tests_are_hard_pinned_to_a_dedicated_sqlite_database(): void
    {
        $connection = (string) config('database.default');

        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', $connection);
        $this->assertSame('sqlite', config("database.connections.{$connection}.driver"));
        $this->assertSame('/tmp/scholarlynest_testing', config("database.connections.{$connection}.database"));
        $this->assertMatchesRegularExpression('/_(test|testing)$/', basename(config("database.connections.{$connection}.database")));
        $this->assertTrue(filter_var(env('ALLOW_DESTRUCTIVE_TEST_DATABASE'), FILTER_VALIDATE_BOOL));
    }
}
