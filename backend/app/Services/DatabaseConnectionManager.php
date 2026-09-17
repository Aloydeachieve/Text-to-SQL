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
     * Probe customer database connection health and return latency & status.
     *
     * @param DatabaseConnection $databaseConnection
     * @return array{status: string, latency_ms: float, message: string, last_checked_at: string}
     */
    public function checkHealth(DatabaseConnection $databaseConnection): array
    {
        $tempConnectionName = 'tenant_health_' . bin2hex(random_bytes(6));
        $probeTimeout = (int) config('reliability.health_probe_timeout_seconds', 3);

        $startTime = microtime(true);

        try {
            $config = [
                'driver' => $databaseConnection->driver,
                'host' => $databaseConnection->host,
                'port' => $databaseConnection->port,
                'database' => $databaseConnection->database,
                'username' => $databaseConnection->username,
                'password' => $databaseConnection->password,
            ];

            $this->registerRuntimeConnection($tempConnectionName, $config, $probeTimeout);

            $connection = DB::connection($tempConnectionName);
            $connection->getPdo();
            $connection->select('SELECT 1 AS ping');

            $latencyMs = round((microtime(true) - $startTime) * 1000, 2);

            $databaseConnection->update([
                'status' => 'connected',
                'last_tested_at' => now(),
            ]);

            return [
                'status' => 'healthy',
                'latency_ms' => $latencyMs,
                'message' => 'Connection operational.',
                'last_checked_at' => now()->toIso8601String(),
            ];
        } catch (Throwable $e) {
            $latencyMs = round((microtime(true) - $startTime) * 1000, 2);
            $errorMsg = strtolower($e->getMessage());

            $status = 'unhealthy';
            $safeMessage = 'Unable to connect to customer database.';

            if (str_contains($errorMsg, 'timed out') || str_contains($errorMsg, 'timeout') || $e->getCode() == 2002) {
                $status = 'timeout';
                $safeMessage = 'Database connection timed out during health probe.';
            } elseif (str_contains($errorMsg, 'access denied') || str_contains($errorMsg, 'authentication failed') || $e->getCode() == 1045) {
                $status = 'authentication_failed';
                $safeMessage = 'Database authentication failed with configured credentials.';
            } elseif (str_contains($errorMsg, 'unknown database') || str_contains($errorMsg, 'does not exist')) {
                $status = 'unavailable';
                $safeMessage = 'Target database schema is unavailable or does not exist.';
            }

            Log::warning('Customer database health probe failed', [
                'connection_id' => $databaseConnection->id,
                'company_id' => $databaseConnection->company_id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            $databaseConnection->update([
                'status' => 'failed',
                'last_tested_at' => now(),
            ]);

            return [
                'status' => $status,
                'latency_ms' => $latencyMs,
                'message' => $safeMessage,
                'last_checked_at' => now()->toIso8601String(),
            ];
        } finally {
            $this->purgeConnection($tempConnectionName);
        }
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
    protected function registerRuntimeConnection(string $connectionName, array $config, ?int $timeout = null): void
    {
        $driver = strtolower($config['driver'] ?? 'mysql');
        $connTimeout = $timeout ?? (int) config('reliability.query_timeout_seconds', 10);

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
                    PDO::ATTR_TIMEOUT => $connTimeout,
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
                    PDO::ATTR_TIMEOUT => $connTimeout,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ],
            ]);
        }
    }
}
