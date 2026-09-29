<?php

namespace Tests\Feature;

use Tests\TestCase;

class WaitForDatabaseTest extends TestCase
{
    public function test_it_succeeds_when_the_database_is_reachable(): void
    {
        $this->artisan('orbit:wait-for-db', ['--timeout' => 1])
            ->expectsOutputToContain('Database is reachable')
            ->assertSuccessful();
    }

    public function test_it_fails_after_the_timeout(): void
    {
        config(['database.connections.sqlite.database' => '/nonexistent/dir/orbit.sqlite']);

        $this->artisan('orbit:wait-for-db', ['--timeout' => 0])
            ->expectsOutputToContain('Database still unreachable')
            ->assertFailed();
    }
}
