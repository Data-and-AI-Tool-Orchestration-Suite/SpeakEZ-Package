<?php

// Import all plugins here
require_once __DIR__ . '/APIKey.php';
require_once __DIR__ . '/Project.php';
require_once __DIR__ . '/SiteBanner.php';
// require_once __DIR__ . '/../utilities/S3Handler.php';

class Plugin implements JsonSerializable {
    protected string $id;
    protected string $name;
    protected string $displayName = "";
    protected string $description = "";
    protected bool $active = false;

    public function __construct() {
        $this->id = UUID::v4();
    }

    /**
     * Create a new plugin database entry 
     * @param string $name
     * @param string $displayName
     * @param string $projectId
     * @return Plugin|null
     * @throws Exception
     */
    public static function create(string $name, string $displayName, string $description="", bool $active=false): ?Plugin {
        if (empty($name))
            throw new Exception('You must provide a name to create');
        if (empty($displayName))
            throw new Exception('You must provide an displayName to create');
        $instance = new self();
        $instance->setName($name);
        $instance->setDisplayName($displayName);
        $instance->setDescription($description);
        $instance->setActive($active);
        return $instance->save();
    }

    public static function update(string $id, string $name, string $displayName, string $description, bool $active): Plugin {
        if (empty($id))
            throw new Exception('You must provide an id to update an Plugin');
        $instance = Plugin::withId($id);
        if (is_null($instance))
            throw new Exception("No Plugin found with id [{$id}]");
        $instance->setName($name);
        $instance->setDisplayName($displayName);
        $instance->setDescription($description);
        $instance->setActive($active);
        return $instance->save();
    }

    /**
     * @param string $id
     * @return void
     * @throws Exception
     */
    public static function delete(string $id) {
        if (empty($id))
            throw new Exception('You must provide an internal id to delete');
        $existing = Plugin::withId($id);
        if (is_null($existing))
            throw new Exception("No Plugin exists with id [$id]");
        $stmt = PostgresDB::run(
            "DELETE FROM plugins WHERE id = :id", 
            ["id" => $id]
        );
        $affectedRows = $stmt->rowCount();
        if ($affectedRows == 0){

        } else if ($affectedRows > 1){

        }
    }

    /**
     * Handle activating and deactivating plugins
     * Add to this as new plugins are added
     * @param bool $active
     * @return void
     */
    public function updateActivation(bool $active, bool $dropTables=false) {
        $this->setActive($active);
        if ($this->getName() == "api_keys"){
            if ($active){
                APIKey::createPluginDBTables();
            } else if ($dropTables) {
                APIKey::dropPluginDBTables();
            }
        } else if ($this->getName() == "projects"){
            if ($active){
                Project::createPluginDBTables();
            } else if ($dropTables) {
                Project::dropPluginDBTables();
            }
        } else if ($this->getName() == "user_agreement"){
            if (!$active && $dropTables){
                User::unacceptAgreementForAll();
            }
        } else if ($this->getName() == "site_banner"){
            if ($active){
                SiteBanner::createPluginDBTables();
            } else if ($dropTables) {
                SiteBanner::dropPluginDBTables();
            }
        }
        $this->save();
    }

    /**
     * @param string $id
     * @return Plugin|null
     */
    public static function withId(int $id): ?Plugin {
        try {
            $instance = new self();
            $instance->loadById($id);
            return $instance;
        } catch (PDOException $e) {
            error_log(print_r($e, true));
            return null;
        }
    }

    /**
     * @param User $user
     * @param string $name
     * @return Plugin|null
     */
    public static function withName(string $name): ?Plugin {
        try {
            $instance = new self();
            $instance->loadByName($name);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * @return int
     */
    public static function countForDatatable(): int {
        $stmt = PostgresDB::run("SELECT COUNT(*) as count FROM plugins;");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }

    /**
     * @param string $filter
     * @return int
     */
    public static function countFilteredForDatatable(string $filter): int {
        $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                FROM plugins
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
    public static function listForDatatable(int $start, int $length, string $order_by, string $order_dir, string $filter): array {
        $matches = [];
        if (is_null($order_by) || $order_by == '' || $order_by == '0'){
            $order_by = 'display_name';
        }
        if (is_null($order_dir) || $order_dir == '' || !(strtolower($order_dir) == "asc" || strtolower($order_dir) == "desc")){
            $order_dir = 'desc';
        }

        // Ensure $order_by is a column name
        $stmt = PostgresDB::run("SELECT column_name
                                FROM information_schema.columns
                                WHERE table_name = 'plugins';");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (in_array($order_by, $columns))
            $order_by = "display_name";
        $stmt = PostgresDB::run("SELECT id, name, display_name, description, active
                                FROM plugins
                                WHERE name ~* :filter OR display_name ~* :filter
                                ORDER BY $order_by $order_dir
                                LIMIT :length
                                OFFSET :start;", 
                                ["filter" => $filter, "length" => $length, "start" => $start]
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
        $stmt = PostgresDB::run("SELECT id, name, display_name, description, active
                                FROM plugins
                                ORDER BY name ASC;"
                                );
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($results){
            foreach($results as $row)
                array_push(
                    $ret, 
                    ['id' => $row['id'], 'name' => $row['name'], 'display_name' => $row['display_name']]
                );
        }
        return $ret;
    }


    /**
     * @param $row
     * @return Plugin
     */
    public static function withRow( $row ): Plugin {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }

    /**
     * @param string $id
     * @return void
     */
    protected function loadById(int $id): void {
        $stmt = PostgresDB::run("SELECT id, name, display_name, description, active
                                FROM plugins
                                WHERE id = :id
                                LIMIT 1;",
                                ["id" => $id]
        );
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cnt = 0;
        foreach($result as $projectId) {
            $this->fill( $projectId );
            $cnt++;
        }
        if ($cnt !== 1)
            throw new PDOException("Plugin with id [{$id}] not found");
    }

    /**
     * @param string $name
     * @return void
     */
    protected function loadByName(string $name): void {
        if (empty($name))
            throw new PDOException('You must supply an name to load');
        
        $stmt = PostgresDB::run("SELECT id, name, display_name, description, active
                                FROM plugins
                                WHERE name = :name
                                LIMIT 1;",
                                ["name" => $name]
        );
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cnt = 0;
        foreach($result as $projectId) {
            $this->fill( $projectId );
            $cnt++;
        }
        if ($cnt <> 1)
            throw new PDOException("Plugin with name [{$name}] not found");
    }

    protected function fill( $row ): void {
        $this->id = $row['id'];
        $this->name = $row['name'];
        $this->displayName = $row['display_name'];
        $this->description = $row['description'];
        $this->active = $row['active'];
    }


    protected function save(): ?Plugin {
        $exists = Plugin::withId($this->getId());
        
        if (is_null($exists)) {
            
            $stmt = PostgresDB::run("INSERT INTO plugins (id, name, display_name, description, active)
                                    VALUES (:id, :name, :display_name, :description, :active);",
                                    ["id" => $this->getId(), "name" => $this->getName(), 
                                     "display_name" => $this->getDisplayName(), "description" => $this->getDescription(), 
                                     "active" => json_encode($this->getActive(), true)]
            );
        } else {
            $stmt = PostgresDB::run("UPDATE plugins
                                    SET name = :name, display_name = :display_name, 
                                        description = :description, active = :active
                                    WHERE id = :id;",
                                    ["name" => $this->getName(), "display_name" => $this->getDisplayName(),
                                     "description" => $this->getDescription(), "active" => json_encode($this->getActive(), true),
                                     "id" => $this->getId()]
            );
        }
        return Plugin::withId($this->getId());
    }

    /**
     * Determines if a plugin with the given name exists and is active
     * @param string $name
     * @return bool
     */
    public static function isPluginActiveByName(string $name): bool {
        $plugin = self::withName($name);
        return !is_null($plugin) && $plugin->isActive();       
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
    public function getDisplayName(): string {
        return $this->displayName;
    }
    /**
     * @param string $displayName
     */
    public function setDisplayName(string $displayName): void {
        $this->displayName = $displayName;
    }

    /**
     * @return string
     */
    public function getDescription(): string {
        return $this->description;
    }
    /**
     * @param string $description
     */
    public function setDescription(string $description): void {
        $this->description = $description;
    }

    /**
     * @return bool
     */
    public function getActive(): bool {
        return $this->active;
    }
    /**
     * @param bool $active
     */
    public function setActive(bool $active): void {
        $this->active = $active;
    }

    /**
     * Alias for $this->getActive()
     * 
     * @return bool
     */
    public function isActive(): bool {
        return $this->active;
    }

    /**
     * @return array|mixed
     */
    public function jsonSerialize(): array {
        return [
            'id'           => $this->getId(),
            'name'         => $this->getName(),
            'display_name' => $this->getDisplayName(),
        ];
    }
}