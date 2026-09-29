<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('orbit:wait-for-db {--timeout=120 : Seconds to wait before giving up} {--interval=2 : Seconds between attempts}')]
#[Description('Wait until the database accepts connections (used before migrations in Kubernetes)')]
class WaitForDatabase extends Command
{
    public function handle(): int
    {
        $deadline = microtime(true) + (int) $this->option('timeout');
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                DB::purge();
                DB::connection()->getPdo();
                $this->info("Database is reachable (attempt {$attempt}).");

                return self::SUCCESS;
            } catch (Throwable $e) {
                if (microtime(true) >= $deadline) {
                    $this->error("Database still unreachable after {$attempt} attempts: {$e->getMessage()}");

                    return self::FAILURE;
                }

                $this->line("Waiting for database (attempt {$attempt}): ".class_basename($e));
                sleep(max(1, (int) $this->option('interval')));
            }
        }
    }
}
