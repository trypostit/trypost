<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\PassportSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use RuntimeException;
use Throwable;

class BootstrapPassport extends Command
{
    private const MYSQL_LOCK_NAME = 'trypost.passport.personal-access-client';

    private const PGSQL_LOCK_KEY = 684527671;

    protected $signature = 'trypost:bootstrap-passport';

    protected $description = 'Ensure the Passport personal access client exists.';

    public function handle(PassportSeeder $seeder): int
    {
        try {
            $this->withBootstrapLock(fn () => $seeder->run(app(ClientRepository::class)));
        } catch (Throwable $exception) {
            $this->error('Passport bootstrap failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Passport personal access client is ready.');

        return self::SUCCESS;
    }

    private function withBootstrapLock(callable $callback): void
    {
        $connection = DB::connection();

        match ($connection->getDriverName()) {
            'pgsql' => $this->withPostgresLock($connection, $callback),
            'mysql', 'mariadb' => $this->withMysqlLock($connection, $callback),
            default => $callback(),
        };
    }

    private function withPostgresLock(ConnectionInterface $connection, callable $callback): void
    {
        $connection->select('SELECT pg_advisory_lock(?)', [self::PGSQL_LOCK_KEY]);

        try {
            $callback();
        } finally {
            $connection->select('SELECT pg_advisory_unlock(?)', [self::PGSQL_LOCK_KEY]);
        }
    }

    private function withMysqlLock(ConnectionInterface $connection, callable $callback): void
    {
        $result = $connection->selectOne('SELECT GET_LOCK(?, 30) AS lock_acquired', [self::MYSQL_LOCK_NAME]);

        if ((int) ($result->lock_acquired ?? 0) !== 1) {
            throw new RuntimeException('Could not acquire Passport bootstrap lock.');
        }

        try {
            $callback();
        } finally {
            $connection->select('SELECT RELEASE_LOCK(?)', [self::MYSQL_LOCK_NAME]);
        }
    }
}
