<?php

namespace App\Services;

use App\Models\DatabaseConnection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use Throwable;

class DatabaseConnectionManager
{
    /**
     * Supported database drivers.
     */
    public const SUPPORTED_DRIVERS = ['mysql', 'pgsql'];

    /**
     * Build and test a runtime connection configuration.
     *
     * @param array{
     *     driver: string,
     *     host: string,
     *     port: int,
     *     database: string,
     *     username: string,
     *     password: string
     * } $config
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $config): array
    {
        $driver = strtolower($config['driver'] ?? '');

        if (!in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            return [
                'success' => false,
                'message' => "Unsupported database driver '{$driver}'. Only MySQL and PostgreSQL are supported.",
            ];
        }

        // Verify required PHP PDO extension is available
        $pdoExtension = "pdo_{$driver}";
        if (!extension_loaded($pdoExtension)) {
            return [
                'success' => false,
                'message' => "The PHP driver extension ({$pdoExtension}) is not installed or enabled on this server.",
            ];
        }

        $tempConnectionName = 'tenant_test_' . bin2hex(random_bytes(6));

        try {
            $this->registerRuntimeConnection($tempConnectionName, $config);

            // Probe connection with strict 5-second timeout
            $connection = DB::connection($tempConnectionName);
            $connection->getPdo();
            $connection->select('SELECT 1 AS ping');

            return [
                'success' => true,
                'message' => "Database connection successful.",
            ];
        } catch (Throwable $e) {
            // Log failure safely without exposing password
            Log::warning('Customer database test connection failed', [
                'driver' => $driver,
                'host' => $config['host'] ?? 'unknown',
                'port' => $config['port'] ?? 'unknown',
                'database' => $config['database'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => "Failed to connect to the database server. Please verify your host, port, credentials, and network firewall settings.",
            ];
        } finally {
            $this->purgeConnection($tempConnectionName);
        }
    }

    /**
     * Get or establish an active connection for an authorized DatabaseConnection model.
     */
    public function getConnection(DatabaseConnection $databaseConnection): ConnectionInterface
    {
        $connectionName = $this->getConnectionName($databaseConnection);

        if (!Config::has("database.connections.{$connectionName}")) {
            $config = [
                'driver' => $databaseConnection->driver,
                'host' => $databaseConnection->host,
                'port' => $databaseConnection->port,
                'database' => $databaseConnection->database,
                'username' => $databaseConnection->username,
                'password' => $databaseConnection->password, // automatically decrypted via model cast
            ];

            $this->registerRuntimeConnection($connectionName, $config);
        }

        return DB::connection($connectionName);
    }

    /**
     * Disconnect and clear runtime connection configuration from memory.
     */
    public function purgeConnection(string|DatabaseConnection $target): void
    {
        $connectionName = is_string($target) ? $target : $this->getConnectionName($target);

        try {
            DB::disconnect($connectionName);
        } catch (Throwable) {
            // Ignore disconnect errors on already closed sockets
        }

        Config::set("database.connections.{$connectionName}", null);
    }

    /**
     * Generate an isolated connection identifier for a tenant's database connection.
     */
    public function getConnectionName(DatabaseConnection $databaseConnection): string
    {
        return "tenant_c{$databaseConnection->company_id}_db{$databaseConnection->id}";
    }

    /**
     * Register a runtime database connection configuration into Laravel's config repository.
     */
    protected function registerRuntimeConnection(string $connectionName, array $config): void
    {
        $driver = strtolower($config['driver'] ?? 'mysql');

        if ($driver === 'mysql') {
            Config::set("database.connections.{$connectionName}", [
                'driver' => 'mysql',
                'host' => $config['host'],
                'port' => (int) ($config['port'] ?? 3306),
                'database' => $config['database'],
                'username' => $config['username'],
                'password' => $config['password'],
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => null,
                'options' => [
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ],
            ]);
        } elseif ($driver === 'pgsql') {
            Config::set("database.connections.{$connectionName}", [
                'driver' => 'pgsql',
                'host' => $config['host'],
                'port' => (int) ($config['port'] ?? 5432),
                'database' => $config['database'],
                'username' => $config['username'],
                'password' => $config['password'],
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
                'options' => [
                    PDO::ATTR_TIMEOUT => 5,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ],
            ]);
        }
    }
}
