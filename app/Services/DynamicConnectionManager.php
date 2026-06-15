<?php

namespace App\Services;

use App\DataTransfer\RemoteDatabaseConfig;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class DynamicConnectionManager
{
    public function connectionName(string $role): string
    {
        return 'utility_'.$role;
    }

    public function register(RemoteDatabaseConfig $config, string $role): string
    {
        $name = $this->connectionName($role);

        config([
            "database.connections.{$name}" => $config->connectionConfig(),
        ]);

        DB::purge($name);

        return $name;
    }

    public function connect(RemoteDatabaseConfig $config, string $role): Connection
    {
        return DB::connection($this->register($config, $role));
    }

    public function test(RemoteDatabaseConfig $config, string $role): void
    {
        $this->connect($config, $role)->getPdo();
    }

    /**
     * @return list<string>
     */
    public function tables(RemoteDatabaseConfig $config, string $role): array
    {
        $this->register($config, $role);

        return collect(Schema::connection($this->connectionName($role))->getTableListing())
            ->sort()
            ->values()
            ->all();
    }

    public function assertReachable(RemoteDatabaseConfig $config, string $role, string $label): void
    {
        try {
            $this->test($config, $role);
        } catch (Throwable $e) {
            throw new RuntimeException("Could not connect to {$label} ({$config->database}): {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * @return list<string>
     */
    public function databases(RemoteDatabaseConfig $config): array
    {
        $connection = DB::connection($this->register($config, 'source'));

        if (in_array($config->driver, ['mysql', 'mariadb'], true)) {
            $skip = ['information_schema', 'mysql', 'performance_schema', 'sys'];

            return collect($connection->select('SHOW DATABASES'))
                ->map(fn ($row) => array_values((array) $row)[0])
                ->reject(fn (string $db) => in_array($db, $skip, true))
                ->sort()
                ->values()
                ->all();
        }

        if ($config->driver === 'pgsql') {
            return collect($connection->select(
                'SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname'
            ))
                ->pluck('datname')
                ->map(fn ($db) => (string) $db)
                ->reject(fn (string $db) => in_array($db, ['template0', 'template1', 'postgres'], true))
                ->values()
                ->all();
        }

        return $this->tables($config, 'source');
    }
}
