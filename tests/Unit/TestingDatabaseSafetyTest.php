<?php

namespace Tests\Unit;

use Tests\TestCase;

class TestingDatabaseSafetyTest extends TestCase
{
    public function test_automated_tests_are_hard_pinned_to_in_memory_sqlite(): void
    {
        $connection = (string) config('database.default');

        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', $connection);
        $this->assertSame('sqlite', config("database.connections.{$connection}.driver"));
        $this->assertSame(':memory:', config("database.connections.{$connection}.database"));
    }
}
