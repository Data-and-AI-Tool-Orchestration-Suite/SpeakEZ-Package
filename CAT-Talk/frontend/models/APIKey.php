<?php

require_once __DIR__ . '/Plugin.php';

class APIKey implements JsonSerializable {
    protected $id;
    protected $name;
    protected $ownerId;

    public function __construct() {
        $this->id = UUID::v4();
    }

    /**
     * Create a new api key database entry 
     * @param string $name
     * @param string $ownerId
     * @return APIKey|null
     * @throws Exception
     */
    public static function create(string $name, string $ownerId): ?APIKey {
        if (empty($name))
            throw new Exception('You must provide a name to create');
        if (empty($ownerId))
            throw new Exception('You must provide an ownerId to create');
        $instance = new self();
        $instance->setName($name);
        $instance->setOwnerId($ownerId);

        $apiKey = $instance->save();
        // error_log(print_r($apiKey, true));
        // if ($apiKey) {
        //     $apiKey->setTenantId();
        // }

        return $apiKey; 
    }

    public static function update(string $id, string $name, string $ownerId): APIKey {
        if (empty($id))
            throw new Exception('You must provide an id to update an API Key');
        $instance = APIKey::withId($id);
        if (is_null($instance))
            throw new Exception("No API Key found with id [{$id}]");
        $instance->setName($name);
        $instance->setOwnerId($ownerId);
        $instance->setJobId($jobId);
        return $instance->save();
    }

    /**
     * @param string $id
     * @return void
     * @throws Exception
     */
    public static function delete(User $user, string $id) {
        if (empty($id))
            throw new Exception('You must provide an internal id to delete');
        $existing = APIKey::withId($id);
        if (is_null($existing))
            throw new Exception("No API Key exists with id [$id]");
        $stmt = PostgresDB::run(
            "DELETE FROM api_keys WHERE id = :id AND owner_id = :owner_id", 
            ["id" => $id, "owner_id" => $user->getId()]
        );
        $affectedRows = $stmt->rowCount();
        if ($affectedRows == 0){

        } else if ($affectedRows > 1){

        }
    }

    /**
     * Creates the DB tables for the API Key Plugin
     */
    public static function createPluginDBTables(){
        $stmt = PostgresDB::run("CREATE TABLE IF NOT EXISTS api_keys(
                                    id VARCHAR(72) NOT NULL PRIMARY KEY,
                                    name TEXT NOT NULL,
                                    owner_id VARCHAR(36) NOT NULL REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
                                    tenant_id VARCHAR(36) DEFAULT NULL REFERENCES tenants(id) ON DELETE CASCADE ON UPDATE CASCADE
                                );"
        );
        // Create index for tenant_id
        $stmt = PostgresDB::run("CREATE INDEX IF NOT EXISTS idx_api_keys_tenant_id
                                ON api_keys(tenant_id);"
                        );
        // Create api_keys_tenant_view
        $stmt = PostgresDB::run("CREATE OR REPLACE VIEW api_keys_tenant_view AS
                                SELECT * FROM api_keys
                                WHERE current_setting('site_session.tenant_id', true) = '' -- Session Tenant ID not set. Should only be used by backend calls
                                OR tenant_id = current_setting('site_session.tenant_id')::VARCHAR(36);"
                        );

                        
        // Create ON INSERT rule for api_keys
        $stmt = PostgresDB::run("CREATE OR REPLACE TRIGGER enforce_tenant_id
                                BEFORE INSERT ON api_keys
                                FOR EACH ROW
                                EXECUTE FUNCTION set_tenant_id();"
                        );


        if (Plugin::isPluginActiveByName("projects")){
            $stmt = PostgresDB::run("CREATE TABLE IF NOT EXISTS project_api_keys(
                                        project_id VARCHAR(36) REFERENCES projects(id) ON UPDATE CASCADE ON DELETE CASCADE,
                                        api_key VARCHAR(36) REFERENCES api_keys(id) ON UPDATE CASCADE ON DELETE CASCADE,
                                        PRIMARY KEY (project_id, api_key)
                                    );"
            );
        }
    }

    /**
     * Drops the DB tables for the API Key Plugin
     */
    public static function dropPluginDBTables(){
        $stmt = PostgresDB::run("DROP TABLE IF EXISTS project_api_keys;");
        $stmt = PostgresDB::run("DROP INDEX IF EXISTS idx_api_keys_tenant_id;");
        $stmt = PostgresDB::run("DROP VIEW IF EXISTS api_keys_tenant_view;");
        $stmt = PostgresDB::run("DROP TABLE IF EXISTS api_keys;");
    }

    /**
     * @param string $id
     * @return APIKey|null
     */
    public static function withId(string $id): ?APIKey {
        try {
            $instance = new self();
            $instance->loadById($id);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * @param User $user
     * @param string $name
     * @return APIKey|null
     */
    public static function withUserAndName(string $userId, string $name): ?APIKey {
        try {
            $instance = new self();
            $instance->loadByUserAndName($userId, $name);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * @param User $user
     * @param string $name
     * @param ?string $projectId
     * @return APIKey|null
     */
    public static function withUserAndNameAndProject(string $userId, string $name, ?string $projectId=null): ?APIKey {
        try {
            $instance = new self();
            if ($projectId){
                $instance->loadByUserAndNameAndProject($userId, $name, $projectId);
            } else {
                $instance->loadByUserAndName($userId, $name);
            }
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * @return int
     */
    public static function countForDatatable(): int {
        $stmt = PostgresDB::run("SELECT COUNT(*) as count FROM api_keys_tenant_view;");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }

    /**
     * Count the number of API Keys wherre the name matches the filter
     * @param string $filter
     * @return int
     */
    public static function countFilteredForDatatable(string $filter): int {
        $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                FROM api_keys_tenant_view
                                WHERE name ~* :filter;", 
                                ["filter" => $filter]
                            );
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }

    /**
     * @param int $start
     * @param int $length
     * @param string $order_by
     * @param string $order_dir
     * @param string $filter
     * @return array
     */
    public static function listForDatatable(string $ownerId, int $start, int $length, string $order_by, string $order_dir, string $filter): array {
        $matches = [];
        if (is_null($order_by) || $order_by == '' || $order_by == '0')
            $order_by = 'name';
        if (is_null($order_dir) || $order_dir == '' || !(strtolower($order_dir) == "asc" || strtolower($order_dir) == "desc"))
            $order_dir = 'desc';

        // Ensure $order_by is a column name
        $stmt = PostgresDB::run("SELECT column_name
                                FROM information_schema.columns
                                WHERE table_name = 'api_keys_tenant_view';");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (in_array($order_by, $columns))
            $order_by = "name";

        // Add to query if projects plugin is active
        $projectsFields = "";
        $projectsJoin = "";
        if (Plugin::isPluginActiveByName("projects")){
            $projectsFields = ", projects.name AS project_name";
            $projectsJoin = "LEFT JOIN project_api_keys ON project_api_keys.api_key = a.id
                                LEFT JOIN projects ON projects.id = project_api_keys.project_id";
        }      
        $stmt = PostgresDB::run("SELECT a.id, a.name, a.owner_id $projectsFields
                                FROM api_keys_tenant_view a
                                $projectsJoin
                                WHERE a.owner_id = :owner_id AND a.name ~* :filter
                                ORDER BY $order_by $order_dir
                                LIMIT :length
                                OFFSET :start;", 
                                ["owner_id" => $ownerId, "filter" => $filter, 
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
     * @return array
     */
    public static function list(): array {
        $ret = [];
        $stmt = PostgresDB::run("SELECT id, name, owner_id
                                FROM api_keys_tenant_view
                                ORDER BY name ASC;"
                                );
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($results){
            foreach($results as $row)
                array_push(
                    $ret, 
                    ['id' => $row['id'], 'name' => $row['name'], 'owner_id' => $row['owner_id']]
                );
        }
        return $ret;
    }


    /**
     * @param $row
     * @return APIKey
     */
    public static function withRow( $row ): APIKey {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }

    /**
     * @param string $id
     * @return void
     */
    protected function loadById(string $id): void {
        if (empty($id))
            throw new PDOException('You must supply an id to search');
        
        $stmt = PostgresDB::run("SELECT id, name, owner_id
                                FROM api_keys_tenant_view
                                WHERE id = :id
                                LIMIT 1;",
                                ["id" => $id]
        );
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cnt = 0;
        foreach($result as $apiKey) {
            $this->fill( $apiKey );
            $cnt++;
        }
        if ($cnt <> 1)
            throw new PDOException("Chat Session with id [{$id}] not found");
    }

    /**
     * @param string $name
     * @return void
     */
    protected function loadByUserAndName(string $userId, string $name): void {
        if (empty($name))
            throw new PDOException('You must supply an name to load');
        
        $stmt = PostgresDB::run("SELECT id, name, owner_id
                                FROM api_keys_tenant_view
                                WHERE name = :name AND owner_id = :owner_id
                                LIMIT 1;",
                                ["name" => $name, "owner_id" => $userId]
        );
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cnt = 0;
        foreach($result as $apiKey) {
            $this->fill( $apiKey );
            $cnt++;
        }
        if ($cnt <> 1)
            throw new PDOException("API Key with name [{$name}] not found");
    }

    /**
     * @param string $name
     * @return void
     */
    protected function loadByUserAndNameAndProject(string $userId, string $name, string $projectId): void {
        if (empty($name))
            throw new PDOException('You must supply an name to load');
        
        $stmt = PostgresDB::run("SELECT a.id, a.name, a.owner_id
                                FROM api_keys_tenant_view a
                                JOIN project_api_keys p ON p.api_key = a.id
                                WHERE a.name = :name AND a.owner_id = :owner_id AND p.project_id = :project_id
                                LIMIT 1;",
                                ["name" => $name, "owner_id" => $userId, "project_id" => $projectId]
        );
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cnt = 0;
        foreach($result as $apiKey) {
            $this->fill( $apiKey );
            $cnt++;
        }
        if ($cnt <> 1)
            throw new PDOException("API Key with name [{$name}] not found");
    }

    protected function fill( $row ): void {
        $this->id = $row['id'];
        $this->name = $row['name'];
        $this->ownerId = $row['owner_id'];
    }


    protected function save(): ?APIKey {
        $exists = APIKey::withId($this->getId());
        
        if (is_null($exists)) {
            
            $stmt = PostgresDB::run("INSERT INTO api_keys (id, name, owner_id)
                                    VALUES (:id, :name, :owner_id);",
                                    ["id" => $this->getId(), "name" => $this->getName(), 
                                     "owner_id" => $this->getOwnerId()]
            );
        } else {
            $stmt = PostgresDB::run("UPDATE api_keys
                                    SET name = :name, owner_id = :owner_id
                                    WHERE id = :id;",
                                    ["name" => $this->getName(), "owner_id" => $this->getOwnerId(),
                                    "id" => $this->getId()]
            );
        }
        return APIKey::withId($this->getId());
    }

    /**
     * Return the Tenant ID associated with this API Key
     * @return string|null
     */
    public function getTenantId(): ?string {
        $stmt = PostgresDB::run("SELECT tenant_id
                                FROM api_keys 
                                WHERE id = :id;",
                                ["id" => $this->getId()]
                        );
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            return $result["tenant_id"] ?? null;
        }
        return null;
    }

    /**
     * Set the Tenant ID associated with this API Key
     * @return void
     */
    private function setTenantId(): void {
        $stmt = PostgresDB::run("UPDATE api_keys
                                SET tenant_id = current_setting('site_session.tenant_id')
                                WHERE id = :id;",
                                ["id" => $this->getId()]
                        );
    }

    /**
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }
    /**
     * @param string $id
     */
    public function setId(string $id): void {
        $this->id = $id;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }
    /**
     * @param string $name
     */
    public function setName(string $name): void {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getOwnerId(): string {
        return $this->ownerId;
    }
    /**
     * @param string $ownerId
     */
    public function setOwnerId(string $ownerId): void {
        $this->ownerId = $ownerId;
    }

    /**
     * @return User
     */
    public function getUser(): User {
        return User::withId($this->ownerId);
    }




    /**
     * @return array|mixed
     */
    public function jsonSerialize(): array {
        return [
            'id'         => $this->getId(),
            'name'       => $this->getName(),
            'owner_id'   => $this->getOwnerId()
        ];
    }
}