<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MySqlConnectionSafetyTest extends TestCase
{
    public function test_concurrency_database_is_isolated(): void
    {
        if (config('database.default') === 'sqlite') {
            $this->markTestSkipped(
                'This safety test requires the isolated MySQL environment.'
            );
        }

        $this->assertSame('mysql', config('database.default'));

        $database = DB::connection()->getDatabaseName();

        $this->assertSame(
            'smartstock_concurrency_test',
            $database,
            'Unsafe database connection detected.'
        );

        $this->assertNotSame('smartstock', $database);
    }
}