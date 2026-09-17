<?php
/**
 * Example implementation of an extension of Resource
 */
class Document extends Resource {
    public const DATABASE_TABLE_NAME = "documents";
    protected ?string $name = null;
    protected ?string $content = null;

    public function __construct() {
        parent::__construct();
    }

    // -------------------------
    // Subclass field management
    // -------------------------

    /**
     * Overwrite of the Resource::create() class
     * Specific implementation for the child
     */
     public static function create(string $creatorUserId, string $name, string $content): static {
        // Create the base resource (in resources table)
        $instance = parent::_create("document", $creatorUserId);

        // Set document-specific fields
        $instance->name = $name;
        $instance->content = $content;

        // Save into documents table
        $instance->save();

        return $instance;
    }

    /**
     * This function permits the Resource parent class
     * to access the fields of its child.
     */
    protected function getFields(): array {
        return [
            'name' => $this->name,
            'content' => $this->content
        ];
    }

    protected function setFieldsFromRow(array $row): void {
        $this->name = $row['name'] ?? null;
        $this->content = $row['content'] ?? null;
    }

    // -------------------------
    // Convenience getters/setters
    // -------------------------

    public function getName(): ?string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = trim($name);
    }

    public function getContent(): ?string {
        return $this->content;
    }

    public function setContent(string $content): void {
        $this->content = trim($content);
    }
}