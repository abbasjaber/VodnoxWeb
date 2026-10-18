<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueueStatusCommand extends Command
{
    protected $signature = 'vodnox:queue-status
                            {--json : Print the status as JSON instead of a table}';

    protected $description = 'Report the queue connection, backlog and failed count without exposing payloads or secrets';

    public function handle(): int
    {
        $connection = (string) config('queue.default');
        $config = config("queue.connections.{$connection}", []);
        $driver = (string) ($config['driver'] ?? $connection);
        $table = $driver === 'database' ? (string) ($config['table'] ?? 'jobs') : null;
        $retryAfter = isset($config['retry_after']) ? (int) $config['retry_after'] : null;

        $status = [
            'connection' => $connection,
            'driver' => $driver,
            'table' => $table,
            'retry_after' => $retryAfter,
            'pending' => null,
            'reserved' => null,
            'delayed' => null,
            'failed' => null,
            'oldest_pending_seconds' => null,
        ];

        if ($driver === 'database' && $table !== null) {
            try {
                $status = array_merge($status, $this->readDatabaseCounts($config, $table));
            } catch (Throwable $e) {
                $this->error('queue unreachable: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $status,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ));

            return self::SUCCESS;
        }

        $this->line('VODNOX queue status');
        $this->line(sprintf(
            '  connection : %s (driver: %s%s)',
            $connection,
            $driver,
            $table !== null ? ", table: {$table}" : ''
        ));
        $this->line(sprintf('  retry_after: %s', $retryAfter !== null ? $retryAfter.'s' : 'n/a'));
        $this->line(sprintf('  pending    : %s', $this->render($status['pending'])));
        $this->line(sprintf('  reserved   : %s', $this->render($status['reserved'])));
        $this->line(sprintf('  delayed    : %s', $this->render($status['delayed'])));
        $this->line(sprintf('  failed     : %s', $this->render($status['failed'])));
        $this->line(sprintf(
            '  oldest     : %s',
            $status['oldest_pending_seconds'] !== null ? $status['oldest_pending_seconds'].'s ago' : '-'
        ));

        return self::SUCCESS;
    }

    /**
     * Count the jobs backlog. Only aggregates are read: the payload column is
     * never selected, so no job data or credential can leak through this report.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, int|null>
     */
    private function readDatabaseCounts(array $config, string $table): array
    {
        $connection = $config['connection'] ?? null;
        $now = now()->getTimestamp();
        $jobs = fn () => DB::connection($connection)->table($table);

        $oldest = $jobs()->whereNull('reserved_at')->min('available_at');

        return [
            'pending' => $jobs()->whereNull('reserved_at')->where('available_at', '<=', $now)->count(),
            'delayed' => $jobs()->whereNull('reserved_at')->where('available_at', '>', $now)->count(),
            'reserved' => $jobs()->whereNotNull('reserved_at')->count(),
            'failed' => $this->failedCount(),
            'oldest_pending_seconds' => $oldest !== null ? max(0, $now - (int) $oldest) : null,
        ];
    }

    private function failedCount(): int
    {
        $failed = (array) config('queue.failed', []);

        if (($failed['driver'] ?? null) !== 'database-uuids') {
            return 0;
        }

        $table = (string) ($failed['table'] ?? 'failed_jobs');

        return DB::connection($failed['database'] ?? null)->table($table)->count();
    }

    private function render(?int $value): string
    {
        return $value === null ? 'n/a' : (string) $value;
    }
}
