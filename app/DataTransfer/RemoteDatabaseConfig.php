<?php

namespace App\DataTransfer;

class RemoteDatabaseConfig
{
    public function __construct(
        public string $driver = 'mysql',
        public string $host = '127.0.0.1',
        public int $port = 3306,
        public string $database = '',
        public string $username = 'root',
        public string $password = '',
        public ?string $charset = null,
    ) {}

    public static function systemDatabaseFor(string $driver): string
    {
        return match ($driver) {
            'pgsql' => 'postgres',
            'sqlsrv' => 'master',
            default => 'mysql',
        };
    }

    public static function fromConnectionAndDatabase(array $connection, string $database): self
    {
        return new self(
            driver: $connection['driver'] ?? 'mysql',
            host: $connection['host'] ?? '127.0.0.1',
            port: (int) ($connection['port'] ?? 3306),
            database: $database,
            username: $connection['username'] ?? 'root',
            password: $connection['password'] ?? '',
            charset: $connection['charset'] ?? null,
        );
    }

    public function connectionConfig(): array
    {
        $config = [
            'driver' => $this->driver,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
            'prefix' => '',
            'prefix_indexes' => true,
        ];

        if ($this->charset) {
            $config['charset'] = $this->charset;
        }

        if (in_array($this->driver, ['mysql', 'mariadb'], true)) {
            $config['collation'] = 'utf8mb4_unicode_ci';
            $config['strict'] = true;
        }

        if ($this->driver === 'pgsql') {
            $config['charset'] = $this->charset ?? 'utf8';
            $config['search_path'] = 'public';
            $config['sslmode'] = 'prefer';
        }

        return $config;
    }
}
