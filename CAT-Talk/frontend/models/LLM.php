<?php

require_once __DIR__ . '/../utilities/db.php';
require_once __DIR__ . '/../utilities/UUID.php';

class LLMConversation implements JsonSerializable {
    protected $id;
    protected $fileId;
    protected $convoName;
    protected $model;
    protected $messages;
    protected $createdAt;

    public function __construct() { }

    public static function withId($id): ?LLMConversation {
        $stmt = PostgresDB::run(
            "SELECT * FROM llm_conversations WHERE id = :id",
            ['id' => $id]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $instance = new self();
            $instance->fill($row);
            return $instance;
        }
        return null;
    }

    public static function withFileIDandConvoID($fileId, $convoId): ?LLMConversation {
        $stmt = PostgresDB::run(
            "SELECT * FROM llm_conversations WHERE file_id = :fileId AND id = :convoId LIMIT 1",
            ['fileId' => $fileId, 'convoId' => $convoId]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $instance = new self();
            $instance->fill($row);
            return $instance;
        }
        return null;
    }

    protected function fill($row): void {
        $this->id = $row['id'];
        $this->fileId = $row['file_id'];
        $this->convoName = $row['convo_name'];
        $this->model = $row['model'];
        $this->messages = is_string($row['messages']) ? json_decode($row['messages'], true) : $row['messages'];
        $this->createdAt = $row['created_at'] ?? null;
    }

    public function save() {
        $exists = self::withId($this->id);
        if ($exists) {
            PostgresDB::run(
                "UPDATE llm_conversations SET file_id = ?, convo_name = ?, model = ?, messages = ? WHERE id = ?",
                [$this->fileId, $this->convoName, $this->model, json_encode($this->messages), $this->id]
            );
        } else {
            $stmt = PostgresDB::run(
                "INSERT INTO llm_conversations (file_id, convo_name, model, messages) VALUES (?, ?, ?, ?) RETURNING id",
                [$this->fileId, $this->convoName, $this->model, json_encode($this->messages)]
            );
            $this->id = $stmt->fetchColumn();
        }
        return $this;
    }

    public static function create($fileId, $convoName, $model): LLMConversation {
        $instance = new self();
        $instance->fileId = $fileId;
        $instance->convoName = $convoName;
        $instance->model = $model;
        $instance->messages = [];
        $instance->save();
        return $instance;
    }

    public function appendMessage($role, $content) {
        $this->messages[] = [
            'timestamp' => gmdate('c'),
            'role' => $role,
            'content' => $content
        ];
        $this->save();
    }

    public static function listByFile($fileId): array {
        $stmt = PostgresDB::run(
            "SELECT * FROM llm_conversations WHERE file_id = :fileId ORDER BY created_at DESC",
            ['fileId' => $fileId]
        );
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $instance = new self();
            $instance->fill($row);
            $results[] = $instance;
        }
        return $results;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'file_id' => $this->fileId,
            'convo_name' => $this->convoName,
            'model' => $this->model,
            'messages' => $this->messages,
            'created_at' => $this->createdAt
        ];
    }

    public function getId() { return $this->id; }
    public function setId($id) { $this->id = $id; }
    public function getFileId() { return $this->fileId; }
    public function setFileId($fileId) { $this->fileId = $fileId; }
    public function getConvoName() { return $this->convoName; }
    public function setConvoName($convoName) { $this->convoName = $convoName; }
    public function getModel() { return $this->model; }
    public function setModel($model) { $this->model = $model; }
    public function getMessages() { return $this->messages; }
    public function setMessages($messages) { $this->messages = $messages; }
    public function getCreatedAt() { return $this->createdAt; }
    public function setCreatedAt($createdAt) { $this->createdAt = $createdAt; }
}
