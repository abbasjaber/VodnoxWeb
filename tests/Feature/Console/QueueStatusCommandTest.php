<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueStatusCommandTest extends TestCase
{
    /**
     * @param  array<string, bool>  $options
     * @return array<string, mixed>
     */
    private function queueStatus(array $options = ['--json' => true]): array
    {
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.retry_after', 90);

        $exit = Artisan::call('vodnox:queue-status', $options);

        $this->assertSame(0, $exit);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertJob(array $attributes = []): void
    {
        $now = now()->getTimestamp();

        DB::table('jobs')->insert(array_merge([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $now - 1,
            'created_at' => $now - 1,
        ], $attributes));
    }

    public function test_it_reports_the_queue_connection_and_retry_after(): void
    {
        $status = $this->queueStatus();

        $this->assertSame('database', $status['connection']);
        $this->assertSame('database', $status['driver']);
        $this->assertSame('jobs', $status['table']);
        $this->assertSame(90, $status['retry_after']);
        $this->assertSame(0, $status['pending']);
    }

    public function test_it_counts_pending_reserved_delayed_and_failed_jobs(): void
    {
        $now = now()->getTimestamp();

        $this->insertJob(['available_at' => $now - 10, 'created_at' => $now - 10]);
        $this->insertJob(['available_at' => $now - 5, 'created_at' => $now - 5]);
        $this->insertJob(['available_at' => $now + 600, 'created_at' => $now]);          // delayed
        $this->insertJob(['reserved_at' => $now - 1, 'available_at' => $now - 1, 'created_at' => $now - 1]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $status = $this->queueStatus();

        $this->assertSame(2, $status['pending']);
        $this->assertSame(1, $status['delayed']);
        $this->assertSame(1, $status['reserved']);
        $this->assertSame(1, $status['failed']);
        $this->assertIsInt($status['oldest_pending_seconds']);
    }

    public function test_it_never_prints_job_payloads_or_exceptions(): void
    {
        $sentinel = 'SUPER-SECRET-PAYLOAD-SENTINEL';

        $this->insertJob(['payload' => $sentinel]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => $sentinel,
            'exception' => $sentinel,
            'failed_at' => now(),
        ]);

        config()->set('queue.default', 'database');
        Artisan::call('vodnox:queue-status');
        $plain = Artisan::output();

        Artisan::call('vodnox:queue-status', ['--json' => true]);
        $json = Artisan::output();

        $this->assertStringNotContainsString($sentinel, $plain);
        $this->assertStringNotContainsString($sentinel, $json);
    }

    public function test_it_handles_a_non_database_connection_without_counting(): void
    {
        config()->set('queue.default', 'sync');

        $exit = Artisan::call('vodnox:queue-status', ['--json' => true]);
        $this->assertSame(0, $exit);

        $status = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('sync', $status['connection']);
        $this->assertSame('sync', $status['driver']);
        $this->assertNull($status['table']);
        $this->assertNull($status['pending']);
    }
}
