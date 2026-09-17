<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require_once __DIR__ . '/../utilities/db.php';
require_once __DIR__ . '/../utilities/UUID.php';


class CollectionCode implements JsonSerializable {
    protected $id;
    protected $code;
    protected $projectId;

    public function __construct() { }

    public function create($code, $projectId) {
        $exists = self::withCodeAndProjectId($code, $projectId);
        if ($exists) {
            throw new Exception("Collection code '$code' already exists for project '$projectId'.");
        } else {
            $this->id = UUID::v4();
            $this->code = $code;
            $this->projectId = $projectId;
            $this->save();
            return $this;
        }
    }

    protected function save(): CollectionCode {
        $exists = CollectionCode::withCodeAndProjectId($this->code, $this->projectId);
        if (is_null($exists)){
            PostgresDB::run("INSERT INTO collection_codes VALUES (?, ?, ?);",
                [$this->id, $this->code, $this->projectId]);
            return CollectionCode::withId($this->id);
        } else {
            PostgresDB::run("UPDATE collection_codes SET code = ?,  project_id = ? WHERE id = ?",
                [$this->code, $this->projecId, $exists->id]);
            return CollectionCode::withId($exists->id);
        }
    }

    public function delete($code, $projectId) {
        $exists = self::withCodeAndProjectId($code, $projectId);
        if (!$exists) {
            throw new Exception("Collection code '$code' does not exist for project '$projectId'.");
        }

        $id = $exists->getId();

        $stmt = PostgresDB::run(
            "DELETE FROM collection_codes WHERE id = :id",
            ["id" => $id]
        );
    
        if ($stmt->rowCount() > 0) {
            return true;
        } else {
            throw new Exception("Collection code '$code' does not exist for project '$projectId'.");
        }
    }

    public static function withCodeAndProjectId(string $code, string $projectId): ?CollectionCode {
        try {
            $instance = new self();
            $instance->loadByCodeAndProjectId($code, $projectId);
            return $instance;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return null;
        }
    }

    public static function withId(string $id): ?CollectionCode {
        try {
            $instance = new self();
            $instance->loadById($id);
            return $instance;
        } catch (PDOException $e) {
            error_log($e->getMessage());
            return null;
        }
    }

    protected function loadById($id): void {
        if (!$id || empty($id)) {
            throw new PDOException('You must supply an id to load');
        }

        $stmt = PostgresDB::run(
            "SELECT * 
             FROM public.collection_codes
             WHERE id = :id",
            [
                "id" => $id
            ]
        );

        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row !== false) {
            $this->fill( $row );
            error_log("this: ".print_r($this,true));
        } else {
            throw new PDOException("Collection Code not found for id [{$id}]");
        }
    }

    protected function loadByCodeAndProjectId(string $code, string $projectId): void {
        if (!$code || empty($code)) {
            throw new PDOException('You must supply a code to load');
        }

        $stmt = PostgresDB::run(
            "SELECT * 
             FROM public.collection_codes
             WHERE code = :code
               AND project_id = :project_id",
            [
                "code" => $code,
                "project_id" => $projectId
            ]
        );
    
        $row = $stmt->fetch(PDO::FETCH_LAZY);
        if ($row !== false) {
            $this->fill( $row );
            error_log("this: ".print_r($this,true));
        } else {
            throw new PDOException("Collection Code [{$code}] not found for project [{$projectId}]");
        }

    }

    protected function fill( $row ): void {
        $this->id = $row['id'];
        $this->code = $row['code'];
        $this->projectId = $row['project_id'];
    }

    public static function listCollections(string $projectId) {
        $stmt = PostgresPostgresDB::run(
            "SELECT code FROM collection_codes WHERE project_id = :project_id",
            ["project_id" => $projectId]
        );
    
        $collectionCodesArray = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return $collectionCodesArray ?: [];
    }
    

    public function getId(): string {
        return $this->id;
    }

    public function setId(string $id): void {
        $this->id = $id;
    }

    public function getCode(): string {
        return $this->code;
    }

    public function setCode(string $filename): void {
        $this->code = $code;
    }

    public function getProjectId(?string $transcriptionFilename): string {
        return $this->projectId;
    }


    public function setProjectId(string $dateUploaded): void {
        $this->projectId = $projectId;
    }

    public function jsonSerialize(): array {
        return [
            'id'                      => $this->getId(),
            'code'                    => $this->getCode(),
            'projectId'               => $this->getProjectId()
        ];
    }
}
