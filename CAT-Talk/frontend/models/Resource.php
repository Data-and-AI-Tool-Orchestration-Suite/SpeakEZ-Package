<?php
/**
 * This is an extendable class that represents user owned resources that 
 * may be shared between users. It implements the basis for access rights
 * management.
 * 
 * See `/frontend/examples/models/Document.php` for an example extension class.
 * See `/frontend/examples/constrollers/DocumentsController.php` for an example controller implementation.
 */
abstract class Resource implements JsonSerializable {
    public const DATABASE_TABLE_NAME = "resources";
    protected string $id;
    protected string $type;
    protected DateTime $createdAt;
    protected DateTime $updatedAt;

    public function __construct() {
        $this->id = UUID::v4();
    }

    /**
     * Subclasses must define their fields for DB operations.
     * Return as ['field_name' => value, ...]
     */
    abstract protected function getFields(): array;

    /**
     * Subclasses must populate their fields from a DB row.
     */
    abstract protected function setFieldsFromRow(array $row): void;

    /**
     * Save resource: INSERT if new, UPDATE if exists
     * @return static|null
     */
    public function save(): ?static {
        if (!$this->id) throw new Exception("Resource ID missing.");

        $fields = $this->getFields();
        $fields['resource_id'] = $this->id;
        $columns = implode(", ", array_keys($fields));
        $placeholders = ":" . implode(", :", array_keys($fields));
        $databaseTableName = static::DATABASE_TABLE_NAME;


        PostgresDB::run("BEGIN");
        try {
            // Check if row exists
            $exists = PostgresDB::run(
                "SELECT 1 FROM $databaseTableName WHERE resource_id = :resource_id",
                ['resource_id' => $this->id]
            )->fetchColumn();

            if ($exists) {
                // UPDATE
                $setStr = implode(", ", array_map(fn($col) => "$col = :$col", array_keys($fields)));
                PostgresDB::run(
                    "UPDATE $databaseTableName SET $setStr WHERE resource_id = :resource_id",
                    $fields
                );
                PostgresDB::run(
                    "UPDATE resources SET updated_at = now() WHERE id = :id",
                    ["id" => $this->getId()]
                );
            } else {
                // INSERT
                PostgresDB::run(
                    "INSERT INTO $databaseTableName ($columns) VALUES ($placeholders)",
                    $fields
                );
            }

            PostgresDB::run("COMMIT");
            // Return the static object returned by withId()
            // Using static here allows children objects
            return static::withId($this->getId());
        } catch (Exception $e) {
            PostgresDB::run("ROLLBACK");
            throw new Exception("Failed to save resource: " . $e->getMessage());
        }
        return null;
    }

    /**
     * Create resource: inserts into resources table + grants creator manage access
     * Implement a child class create() function which calls this
     * See /frontend/examples/models/Document create() for an example
     */
    protected static function _create(string $type, string $creatorUserId): static {
        $instance = new static();
        $instance->type = $type;

        PostgresDB::run("BEGIN");
        try {
            $stmt = PostgresDB::run(
                "INSERT INTO resources (id, type) VALUES (:id, :type) RETURNING id, created_at, updated_at",
                ['id' => $instance->getId(), 'type' => $instance->getType()]
            );
            $result = $stmt->fetch();
            $instance->id = $result["id"];
            $instance->createdAt = new DateTime($result["created_at"]);
            $instance->updatedAt = new DateTime($result["updated_at"]);

            // Grant creator manage permission
            $instance->grantPermission($creatorUserId, "manage");

            PostgresDB::run("COMMIT");
            return $instance;
        } catch (Exception $e) {
            PostgresDB::run("ROLLBACK");
            throw new Exception("Failed to create resource: " . $e->getMessage());
        }

        return null;
    }

    /**
     * @param string $id
     * @return static|null
     */
    public static function withId(string $id): ?static {
        try {
            // Create and load a static instance
            // Using static() instead of self() allows child classes
            // to be returned.
            $instance = new static();
            $instance->loadById($id);
            return $instance;
        } catch (PDOException $e) {
            error_log(print_r($e, true));
            return null;
        }
    }

    /**
     * Load resource from DB into object
     */
    public function loadById(string $id): void {
        $stmt = PostgresDB::run("SELECT * FROM resources_tenant_view WHERE id = :id", ['id' => $id]);
        $resource = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$resource) throw new PDOException("Resource not found.");

        $this->id = $resource['id'];
        $this->type = $resource['type'];
        $this->createdAt = new DateTime($resource['created_at']);
        $this->updatedAt = new DateTime($resource['updated_at']);

        $databaseTableName = static::DATABASE_TABLE_NAME;

        $stmt = PostgresDB::run(
            "SELECT d.*, r.created_at, r.updated_at 
            FROM $databaseTableName d 
            JOIN resources_tenant_view r
            ON d.resource_id = r.id
            WHERE d.resource_id = :id",
            ['id' => $this->id]
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->setFieldsFromRow($row ?: []);
    }

    /**
     * @return int
     */
    public static function countForDatatable(string $userId): int {
        $databaseTableName = static::DATABASE_TABLE_NAME;
        $stmt = PostgresDB::run(
            "SELECT COUNT(t.*)
            FROM {$databaseTableName} t
            JOIN resources_tenant_view rtv
                ON rtv.id = t.resource_id
            INNER JOIN resource_permissions rp
                ON rp.resource_id = t.resource_id
            WHERE rp.user_id = :user_id;",
            ["user_id" => $userId]
        );
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }

    /**
     * @param string $filter
     * @return int
     */
    public static function countFilteredForDatatable(string $userId, string $filter): int {
        $databaseTableName = static::DATABASE_TABLE_NAME;
        $stmt = PostgresDB::run(
            "SELECT COUNT(t.*)
            FROM {$databaseTableName} t
            JOIN resources_tenant_view rtv
                ON rtv.id = t.resource_id
            INNER JOIN resource_permissions rp
                ON rp.resource_id = t.resource_id
            WHERE rp.user_id = :user_id
            AND t.name ~* :filter;",
            ["user_id" => $userId, "filter" => $filter]
        );
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }


    /**
     * Default function to list the document for viewing in a datatable.
     * Override in class implementation if necessary.
     * @param int $start
     * @param int $length
     * @param string $order_by
     * @param string $order_dir
     * @param string $filter
     * @return array
     */
    public static function listForDatatable(string $userId, int $start, int $length, string $order_by, string $order_dir, string $filter): array {
        $matches = [];
        // NOTE: Offset order_by by 2 due to SELECT statement structure.
        // It is likely for order_by to work properly for every case, the child class
        // would need to overwrite this method.
        if (is_null($order_by) || $order_by == '' || $order_by == '1'){
            $order_by = '3';
        } elseif (is_numeric($order_by) && filter_var($order_by, FILTER_VALIDATE_INT) !== false){
            $order_by = (int)$order_by + 2;
        }
        if (is_null($order_dir) || $order_dir == '' || !(strtolower($order_dir) == "asc" || strtolower($order_dir) == "desc")){
            $order_dir = 'desc';
        }

        $databaseTableName = static::DATABASE_TABLE_NAME;
        $stmt = PostgresDB::run(
            "SELECT t.resource_id AS id, t.*, rp.permission, rtv.created_at, rtv.updated_at
            FROM {$databaseTableName} t
            JOIN resources_tenant_view rtv
                ON rtv.id = t.resource_id
            INNER JOIN resource_permissions rp
                ON rp.resource_id = t.resource_id
            WHERE rp.user_id = :user_id
            AND t.name ~* :filter
            ORDER BY {$order_by} {$order_dir}
            LIMIT :length
            OFFSET :start;", 
            ["user_id" => $userId, "filter" => $filter, 
            "length" => $length, "start" => $start]
        );
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($results){
            foreach($results as $row)
                array_push($matches, $row);
        }
        return $matches;
    }


    /**
     * @return int
     */
    public static function countUsersWithAccessForDatatable(string $resourceId): int {
        $databaseTableName = static::DATABASE_TABLE_NAME;
        $stmt = PostgresDB::run(
            "SELECT COUNT(u.id)
            FROM users u
            INNER JOIN resource_permissions rp
            ON u.id = rp.user_id
            WHERE rp.resource_id = :resource_id;",
            ["resource_id" => $resourceId]
        );
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }

    /**
     * @param string $filter
     * @return int
     */
    public static function countUsersWithAccessFilteredForDatatable(string $resourceId, string $filter): int {
        $databaseTableName = static::DATABASE_TABLE_NAME;
        $stmt = PostgresDB::run(
            "SELECT COUNT(u.id)
            FROM users u
            INNER JOIN resource_permissions rp
            ON u.id = rp.user_id
            WHERE rp.resource_id = :resource_id
            AND (u.full_name ~* :filter OR u.email ~* :filter);",
            ["resource_id" => $resourceId, "filter" => $filter]
        );
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }


    /**
     * Get users with access to this resource
     * @param int $start
     * @param int $length
     * @param string $order_by
     * @param string $order_dir
     * @param string $filter
     * @return array
     */
    public static function listUsersWithAccessForDatatable(string $resourceId, int $start, int $length, ?string $order_by, ?string $order_dir, string $filter=""): array {
        $matches = [];
        if (is_null($order_by) || $order_by == '' || $order_by == '1'){
            $order_by = 'full_name';
        }
        if (is_null($order_dir) || $order_dir == '' || !(strtolower($order_dir) == "asc" || strtolower($order_dir) == "desc")){
            $order_dir = 'desc';
        }

        $stmt = PostgresDB::run(
            "SELECT u.*, rp.permission
            FROM users u
            INNER JOIN resource_permissions rp
            ON u.id = rp.user_id
            WHERE rp.resource_id = :resource_id
            AND (u.full_name ~* :filter OR u.email ~* :filter)
            ORDER BY $order_by $order_dir
            LIMIT :length
            OFFSET :start;",
            ['resource_id' => $resourceId, "filter" => $filter, 
            "length" => $length, "start" => $start]
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /**
     * @return int
     */
    public static function countUsersWithoutAccessForDatatable(string $resourceId): int {
        $databaseTableName = static::DATABASE_TABLE_NAME;
        $stmt = PostgresDB::run(
            "SELECT COUNT(u.id)
            FROM users u
            WHERE NOT EXISTS (
                SELECT 1
                FROM resource_permissions rp
                WHERE rp.user_id = u.id
                AND rp.resource_id = :resource_id
            );",
            ["resource_id" => $resourceId]
        );
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }

    /**
     * @param string $filter
     * @return int
     */
    public static function countUsersWithoutAccessFilteredForDatatable(string $resourceId, string $filter): int {
        $databaseTableName = static::DATABASE_TABLE_NAME;
        $stmt = PostgresDB::run(
            "SELECT COUNT(u.id)
            FROM users u
            WHERE NOT EXISTS (
                SELECT 1
                FROM resource_permissions rp
                WHERE rp.user_id = u.id
                AND rp.resource_id = :resource_id
            )
            AND (u.full_name ~* :filter OR u.email ~* :filter);",
            ["resource_id" => $resourceId, "filter" => $filter]
        );
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }

    /**
     * Get users without access to this resource
     * @param int $start
     * @param int $length
     * @param string $order_by
     * @param string $order_dir
     * @param string $filter
     * @return array
     */
    public static function listUsersWithoutAccessForDatatable(string $resourceId, int $start, int $length, ?string $order_by, ?string $order_dir, string $filter=""): array {
        $matches = [];
        if (is_null($order_by) || $order_by == '' || $order_by == '0'){
            $order_by = 'u.full_name';
        }
        if (is_null($order_dir) || $order_dir == '' || !(strtolower($order_dir) == "asc" || strtolower($order_dir) == "desc")){
            $order_dir = 'desc';
        }

        $stmt = PostgresDB::run(
            "SELECT u.*, u.email
            FROM users u
            WHERE NOT EXISTS (
                SELECT 1
                FROM resource_permissions rp
                WHERE rp.user_id = u.id
                AND rp.resource_id = :resource_id
            )
            AND (u.full_name ~* :filter OR u.email ~* :filter)
            ORDER BY $order_by $order_dir
            LIMIT :length
            OFFSET :start;",
            ['resource_id' => $resourceId, "filter" => $filter, 
            "length" => $length, "start" => $start]
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete the specified resource
     * @param string $id: The ID of the resource to delete
     */
    public static function delete(string $id): void {
        try {
            $stmt = PostgresDB::run(
                "DELETE FROM resources WHERE id = :id;",
                ['id' => $id]
            );
        } catch (Exception $e) {
            error_log(print_r($e, true));
            throw new Exception("Failed to delete resource from database");
        }
    }

    /**
     * Check user permission
     */
    public function hasPermission(string $userId, string $permission): bool {
        if (!in_array($permission, ['read', 'write', 'manage'])) {
            throw new Exception("Invalid permission type: $permission");
        }

        $stmt = PostgresDB::run(
            "SELECT 1 FROM resource_permissions WHERE resource_id = :resource_id AND user_id = :user_id AND permission = :permission",
            ['resource_id' => $this->id, 'user_id' => $userId, 'permission' => $permission]
        );

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Returns whether the specified user can read the resource
     * @param string $userId
     * @return bool
     */
    public function canRead(string $userId): bool {
        return (
            $this->hasPermission($userId, "read")  ||
            $this->hasPermission($userId, "write") ||
            $this->hasPermission($userId, "manage")
        );
    }

    /**
     * Returns whether the specified user can write to the resource
     * @param string $userId
     * @return bool
     */
    public function canWrite(string $userId): bool {
        return (
            $this->hasPermission($userId, "write") ||
            $this->hasPermission($userId, "manage")
        );
    }

    /**
     * Returns whether the specified user can manage the resource
     * @param string $userId
     * @return bool
     */
    public function canManage(string $userId): bool {
        return $this->hasPermission($userId, "manage");
    }



    /**
     * Grant permission to user
     * This is used to set or change user's permissions
     */
    public function grantPermission(string $userId, string $permission): void {
        if (!in_array($permission, ['read','write','manage'])) {
            throw new Exception("Invalid permission type: $permission");
        }

        try {
            PostgresDB::run(
                "INSERT INTO resource_permissions (resource_id, user_id, permission)
                VALUES (:resource_id, :user_id, :permission)
                ON CONFLICT (resource_id, user_id)
                DO UPDATE SET permission = EXCLUDED.permission",
                ['resource_id' => $this->id, 'user_id' => $userId, 'permission' => $permission]
            );
        }catch (\PDOException $e) {
            // Detect our specific trigger error
            if (str_contains($e->getMessage(), 'Cannot remove last manager')) {
                throw new Exception("Failed to set permission. A " . $this->getType() . " must always have at least one manager.", 0, $e);
            }
            throw $e; // Re-throw anything else
        }
        
    }

    /**
     * Revoke permission to user
     */
    public function revokePermission(string $userId): void {
        try {
            PostgresDB::run(
                "DELETE FROM resource_permissions 
                WHERE resource_id = :resource_id AND user_id = :user_id",
                ['resource_id' => $this->id, 'user_id' => $userId]
            );
        } catch (\PDOException $e) {
            // Detect our specific trigger error
            if (str_contains($e->getMessage(), 'Cannot remove last manager')) {
                throw new Exception("Failed to revoke access. A " . $this->getType() . " must always have at least one manager.", 0, $e);
            }
            throw $e; // Re-throw anything else
        }
        
    }

    public function getDatabaseName(): string {
        return static::DATABASE_TABLE_NAME;
    }

    /**
     * Return the ID of this resource
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Set the ID of this resource
     * @param string $id
     * @return void
     */
    public function setId(string $id): void {
        $this->id = $id;
    }

    /**
     * Return the type of this resource
     * @return string
     */
    public function getType(): string {
        return $this->type;
    }

    /**
     * Set the type of this resource
     * @param string $type
     * @return void
     */
    public function setType(string $type): void {
        $this->type = $type;
    }

    /**
     * Return when this resource was created
     * @return DateTime
     */
    public function getCreatedAt(): DateTime {
        return $this->createdAt;
    }

    /**
     * Set the time this resource was created
     * @param DateTime $createdAt
     * @return void
     */
    public function setCreatedAt(DateTime $createdAt): void {
        $this->createdAt = $createdAt;
    }

    /**
     * Return when this resource was updated
     * @return DateTime
     */
    public function getUpdatedAt(): DateTime {
        return $this->updatedAt;
    }

    /**
     * Set the time this resource was updated
     * @param DateTime $updatedAt
     * @return void
     */
    public function setUpdatedAt(DateTime $updatedAt): void {
        $this->updatedAt = $updatedAt;
    }

    /**
     * @return array|mixed
     */
    public function jsonSerialize(): array {
        $serialized = [
            "_id" => $this->getId(),
            "_type" => $this->getType(),
            "created_at" => $this->getCreatedAt(),
            "updated_at" => $this->getUpdatedAt(),
        ];
        $childFields = $this->getFields();
        $serialized = array_merge($serialized, $childFields);
        return $serialized;
    }

}