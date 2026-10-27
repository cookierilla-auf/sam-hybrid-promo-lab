<?php
declare(strict_types=1);
namespace Lab;
use PDO;

final class Database
{
    public function connect(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'marketing_lab';
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
            throw new \InvalidArgumentException('Invalid database name');
        }
        $db = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
            Settings::required('DB_USER'), getenv('DB_PASSWORD') ?: '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        $db->exec("SET time_zone = '+00:00'");
        $db->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $db;
    }
}
