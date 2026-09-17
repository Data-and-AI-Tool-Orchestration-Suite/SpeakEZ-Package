<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require_once __DIR__ . '/../utilities/db.php';
require_once __DIR__ . '/../utilities/UUID.php';



// Model for the site_banner table

class SiteBanner implements JsonSerializable {
    protected $is_on;
    protected $message;

    public static function createPluginDBTables(): void {
        PostgresDB::run(
            "CREATE TABLE IF NOT EXISTS site_banner (
                is_on BOOLEAN NOT NULL DEFAULT FALSE,
                message TEXT NOT NULL DEFAULT ''
            )"
        );
    }

    public static function dropPluginDBTables(): void {
        PostgresDB::run("DROP TABLE IF EXISTS site_banner");
    }

    public function __construct($is_on = false, $message = '') {
        $this->is_on = $is_on;
        $this->message = $message;
    }

    public static function load(): ?SiteBanner {
        $stmt = PostgresDB::run("SELECT * FROM site_banner LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return new SiteBanner($row['is_on'], $row['message']);
        }
        return null;
    }

    public function save(): void {
        // Upsert: update if exists, else insert
        $stmt = PostgresDB::run("SELECT COUNT(*) FROM site_banner");
        $count = $stmt->fetchColumn();
        if ($count > 0) {
            PostgresDB::run(
                "UPDATE site_banner SET is_on = ?, message = ?",
                [json_encode($this->getIsOn()), $this->getMessage()]
            );
        } else {
            PostgresDB::run(
                "INSERT INTO site_banner (is_on, message) VALUES (?, ?)",
                [json_encode($this->getIsOn()), $this->getMessage()]
            );
        }
    }

    public function getIsOn(): bool {
        return (bool)$this->is_on;
    }

    public function setIsOn(bool $is_on=false): void {
        $this->is_on = $is_on;
    }

    public function getMessage(): string {
        return $this->message;
    }

    public function setMessage(string $message): void {
        $this->message = $message;
    }

    public function jsonSerialize(): array {
        return [
            'is_on' => $this->is_on,
            'message' => $this->message,
        ];
    }
}
