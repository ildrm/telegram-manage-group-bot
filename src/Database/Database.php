<?php
namespace App\Database;

use PDO;
use PDOException;

class Database {
    private PDO $pdo;
    private string $driver;

    public function __construct(array $config) {
        $this->driver = $config['driver'] ?? 'mysql';
        
        try {
            if ($this->driver === 'sqlite') {
                $path = $config['path'] ?? 'storage/database.sqlite';
                $this->pdo = new PDO("sqlite:$path");
                $this->pdo->exec('PRAGMA journal_mode = WAL');
            } else {
                $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
                $this->pdo = new PDO($dsn, $config['username'], $config['password']);
            }
            
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            die("Database Connection Error: " . $e->getMessage());
        }
    }

    public function exec(string $sql): bool {
        return (bool)$this->pdo->exec($sql);
    }

    public function query(string $sql) {
        return $this->pdo->query($sql);
    }

    public function prepare(string $sql) {
        return $this->pdo->prepare($sql);
    }
    
    public function lastInsertId(): string {
        return $this->pdo->lastInsertId();
    }
    
    public function getDriver(): string {
        return $this->driver;
    }

    // --- Query Helper Methods for Abstraction ---

    /*
     * Handles "INSERT IGNORE" (MySQL) vs "INSERT OR IGNORE" (SQLite)
     */
    public function insertIgnore(string $table, array $data): void {
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        
        if ($this->driver === 'sqlite') {
            $sql = "INSERT OR IGNORE INTO $table ($fields) VALUES ($placeholders)";
        } else {
            $sql = "INSERT IGNORE INTO $table ($fields) VALUES ($placeholders)";
        }
        
        $stmt = $this->prepare($sql);
        $stmt->execute(array_values($data));
    }

    /*
     * Handles "REPLACE INTO" (Standard)
     */
    public function replace(string $table, array $data): void {
        // REPLACE INTO works on both MySQL and SQLite
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        
        $sql = "REPLACE INTO $table ($fields) VALUES ($placeholders)";
        
        $stmt = $this->prepare($sql);
        $stmt->execute(array_values($data));
    }

    /*
     * Handles Upsert (On Duplicate Key Update)
     * $updateCols: columns to update if conflict occurs
     */
    public function upsert(string $table, array $data, array $updateCols, string $uniqueKey = 'id'): void {
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = implode(', ', array_fill(0, count($keys), '?'));
        $values = array_values($data);

        if ($this->driver === 'sqlite') {
            // SQLite: INSERT INTO ... ON CONFLICT(key) DO UPDATE SET ...
            $sets = [];
            foreach ($updateCols as $col) {
                $sets[] = "$col = excluded.$col";
            }
            $setString = implode(', ', $sets);
            
            // Note: SQLite ON CONFLICT requires knowing the conflict target (unique key)
            // Simpler replacement might be "REPLACE INTO" if we don't care about preserving ID
            // But strict upsert:
            $sql = "INSERT INTO $table ($fields) VALUES ($placeholders) 
                    ON CONFLICT($uniqueKey) DO UPDATE SET $setString";
            
        } else {
            // MySQL: INSERT INTO ... ON DUPLICATE KEY UPDATE ...
            $sets = [];
            foreach ($updateCols as $col) {
                $sets[] = "$col = VALUES($col)";
            }
            $setString = implode(', ', $sets);
            
            $sql = "INSERT INTO $table ($fields) VALUES ($placeholders) 
                    ON DUPLICATE KEY UPDATE $setString";
        }

        $stmt = $this->prepare($sql);
        $stmt->execute($values);
    }
}
