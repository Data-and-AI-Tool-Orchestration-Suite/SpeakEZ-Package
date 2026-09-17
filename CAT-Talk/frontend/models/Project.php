<?php


// REPLACE COLLECTION WITH WHAT YOU ADD TO YOUR PROJECTS
// require_once MODELS_DIR . 'Collection.php';

class Project implements JsonSerializable {
    protected string $id;
    protected string $name;

    public function __construct() {
        $this->id = UUID::v4();
    }

    /**
     * Create a new project database entry 
     * @param string $name
     * @param string $memberId
     * @return Project|null
     * @throws Exception
     */
    public static function create(string $name, string $memberId): ?Project {
        if (empty($name))
            throw new Exception('You must provide a name to create');

        $existing = self::withNameAndMember($name, $memberId);
        if (!is_null($existing))
            throw new Exception('You are already a member of a project with this name');
        $instance = new self();
        $instance->setName($name);

        $newProject = $instance->save();
        $newProject->saveMember($memberId, "Admin");

        return $newProject;
    }

    public static function update(string $id, string $name): Project {
        if (empty($id))
            throw new Exception('You must provide an id to update a project');
        $instance = Project::withId($id);
        if (is_null($instance))
            throw new Exception("No project found with id [{$id}]");
        $instance->setName($name);

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
        $existing = Project::withId($id);
        if (is_null($existing))
            throw new Exception("No project exists with id [$id]");
        $stmt = PostgresDB::run(
            "DELETE FROM projects WHERE id = :id", 
            ["id" => $id]
        );
        $affectedRows = $stmt->rowCount();
        if ($affectedRows === 0){

        } else if ($affectedRows > 1){

        }
    }

    public static function listCollections(string $projectId) {
        $stmt = PostgresDB::run(
            "SELECT code FROM collection_codes WHERE project_id = :id",
            ["id" => $projectId]
        );
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        return $result ?: null;
    }


    /**
     * Creates the DB tables for the API Key Plugin
     */
    public static function createPluginDBTables(){
        /** 
         * General Projects tables
         */
        $stmt = PostgresDB::run("CREATE TABLE IF NOT EXISTS projects(
                                    id VARCHAR(36) NOT NULL PRIMARY KEY,
                                    name TEXT NOT NULL,
                                    tenant_id VARCHAR(36) DEFAULT NULL REFERENCES tenants(id) ON DELETE CASCADE ON UPDATE CASCADE
                                );");
                                
        $stmt = PostgresDB::run("CREATE TABLE IF NOT EXISTS project_members(
                                    project_id VARCHAR(36) REFERENCES projects(id) ON UPDATE CASCADE ON DELETE CASCADE,
                                    member_id VARCHAR(36) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
                                    role INT DEFAULT 0,
                                    PRIMARY KEY (project_id, member_id)
                                );");

        $stmt = PostgresDB::run("CREATE TABLE IF NOT EXISTS project_roles(
                                    role_id INT NOT NULL PRIMARY KEY,
                                    role_name VARCHAR(255) NOT NULL
                                );");
                                
                                // Add more roles here if necessary
        $stmt = PostgresDB::run("INSERT INTO project_roles (role_id, role_name) VALUES
                                    (0, 'Member'),
                                    (1, 'Admin')
                                ON CONFLICT (role_id) DO NOTHING;");

                                // Constraint for ensuring roles are valid
        $stmt = PostgresDB::run("CREATE OR REPLACE FUNCTION check_project_roles_validity() 
                                RETURNS TRIGGER AS $$
                                BEGIN
                                    IF NEW.role NOT IN (SELECT role_id FROM project_roles)
                                    THEN RAISE EXCEPTION 'Invalid role_id in roles array';
                                    END IF;
                                    RETURN NEW;
                                END;
                                $$ LANGUAGE plpgsql;");
        
        $stmt = PostgresDB::run("DO $$
                                    BEGIN
                                        IF NOT EXISTS (
                                            SELECT 1
                                            FROM pg_trigger
                                            WHERE tgname = 'validate_project_roles'
                                        ) THEN
                                            CREATE TRIGGER validate_project_roles
                                            BEFORE INSERT OR UPDATE ON project_members
                                            FOR EACH ROW EXECUTE FUNCTION check_project_roles_validity();
                                        END IF;
                                    END $$;");


        $stmt = PostgresDB::run("CREATE OR REPLACE FUNCTION delete_project_if_no_members()
                                RETURNS TRIGGER AS $$
                                BEGIN
                                    -- Check if the project still has members
                                    IF NOT EXISTS (
                                        SELECT 1
                                        FROM project_members
                                        WHERE project_id = OLD.project_id
                                    ) THEN
                                        -- Delete the project if no members exist
                                        DELETE FROM projects
                                        WHERE id = OLD.project_id;
                                    END IF;
                                
                                    RETURN NULL; -- Triggers for AFTER actions return NULL
                                END;
                                $$ LANGUAGE plpgsql;");
                                        
        $stmt = PostgresDB::run("DO $$
                                    BEGIN
                                        IF NOT EXISTS (
                                            SELECT 1
                                            FROM pg_trigger
                                            WHERE tgname = 'check_and_delete_project'
                                        ) THEN
                                            CREATE TRIGGER check_and_delete_project
                                            AFTER DELETE ON project_members
                                            FOR EACH ROW
                                            EXECUTE FUNCTION delete_project_if_no_members();
                                        END IF;
                                    END $$;");
        /**
         * Tenant Management
         */
        // Create index for tenant_id
        $stmt = PostgresDB::run("CREATE INDEX IF NOT EXISTS idx_projects_tenant_id
                                ON projects(tenant_id);"
                        );

        // Create projects_tenant_view
        $stmt = PostgresDB::run("CREATE OR REPLACE VIEW projects_tenant_view AS
                                SELECT * FROM projects
                                WHERE current_setting('site_session.tenant_id', true) = '' -- Session Tenant ID not set. Should only be used by backend calls
                                OR tenant_id = current_setting('site_session.tenant_id')::VARCHAR(36);"
                        );

                        
        // Create ON INSERT rule for api_keys
        $stmt = PostgresDB::run("CREATE OR REPLACE TRIGGER enforce_tenant_id
                                BEFORE INSERT ON projects
                                FOR EACH ROW
                                EXECUTE FUNCTION set_tenant_id();"
                        );

       

        // $stmt = PostgresDB::run("CREATE TABLE IF NOT EXISTS project_collections(
        //                                 project_id VARCHAR(36) REFERENCES projects(id) ON UPDATE CASCADE ON DELETE CASCADE,
        //                                 collection_code VARCHAR(255) REFERENCES files(collection_code) ON UPDATE CASCADE ON DELETE CASCADE,
        //                                 PRIMARY KEY (project_id, collection_code)
        //                             );");

        // Handle adding api_keys table if necessary
        $apiKeysPlugin = Plugin::withName("api_keys");
        if ($apiKeysPlugin && $apiKeysPlugin->isActive()){
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
        $stmt = PostgresDB::run("DROP TABLE IF EXISTS project_roles;");
        $stmt = PostgresDB::run("DROP TABLE IF EXISTS project_members;");
        $stmt = PostgresDB::run("DROP INDEX IF EXISTS idx_projects_tenant_id;");
        $stmt = PostgresDB::run("DROP VIEW IF EXISTS projects_tenant_view;");
        $stmt = PostgresDB::run("DROP TABLE IF EXISTS projects;");
    }

    /**
     * Returns all projects that a user belongs to
     * @param string $userId
     * @return array
     */
    public static function apiListProjects($userId) {
        $stmt = PostgresDB::run(
            "SELECT p.id, p.name, p.tenant_id
             FROM projects p
             JOIN project_members pm ON p.id = pm.project_id
             WHERE pm.member_id = :user_id",
            ["user_id" => $userId]
        );
        $projects = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $projects[] = $row;
        }
        return $projects;
    }

    /**
     * Returns all collections for a given project
     * @param string $projectId
     * @return array
     */
    public static function apiListProjectCollections($projectId) {
        $stmt = PostgresDB::run(
            "SELECT id, code FROM collection_codes WHERE project_id = :project_id",
            ["project_id" => $projectId]
        );
        $collections = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $collections[] = $row;
        }
        return $collections;
    }

    /**
     * Returns all files for a given project
     * @param string $projectId
     * @return array
     */
    public static function apiListProjectFiles($projectId) {
        $stmt = PostgresDB::run(
            "SELECT f.* FROM files f
             JOIN collection_codes cc ON f.collection_code_id = cc.id
             WHERE cc.project_id = :project_id",
            ["project_id" => $projectId]
        );
        $files = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $files[] = $row;
        }
        return $files;
    }
    

    /**
     * ======================
     *    General Projects 
     * ======================
     */


    /**
     * @param string $id
     * @return Project|null
     */
    public static function withId(string $id): ?Project {
        try {
            $instance = new self();
            $instance->loadById($id);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * @param string $id
     * @return void
     */
    protected function loadById(string $id): void {
        if (empty($id))
            throw new PDOException('You must supply an id to search');
        
        $stmt = PostgresDB::run("SELECT p.id, p.name
                                FROM projects_tenant_view p
                                LEFT JOIN project_members pm ON pm.project_id = p.id
                                WHERE p.id = :id
                                GROUP BY p.id, p.name
                                LIMIT 1;",
                                ["id" => $id]
        );
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cnt = 0;
        foreach($result as $project) {
            $this->fill( $project );
            $cnt++;
        }
        if ($cnt !== 1)
            throw new PDOException("Project with id [{$id}] not found");
    }


    /**
     * @return int
     */
    public static function countForDatatable(string $userId): int {
        $stmt = PostgresDB::run("SELECT COUNT(*) as count FROM project_members WHERE member_id = :member_id;", ["member_id" => $userId]);
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
        $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                FROM projects_tenant_view p JOIN project_members pm
                                ON p.id = pm.project_id
                                WHERE pm.member_id = :member_id AND p.name ~* :filter;", 
                                ["member_id" => $userId, "filter" => $filter]
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
    public static function listForDatatable(string $userId, int $start, int $length, string $order_by, string $order_dir, string $filter): array {
        $matches = [];
        if (is_null($order_by) || $order_by == '' || $order_by == '0')
            $order_by = 'name';
        if (is_null($order_dir) || $order_dir == '' || !(strtolower($order_dir) == "asc" || strtolower($order_dir) == "desc"))
            $order_dir = 'desc';

        // Ensure $order_by is a column name
        $stmt = PostgresDB::run("SELECT column_name
                                FROM information_schema.columns
                                WHERE table_name = 'projects';");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (in_array($order_by, $columns))
            $order_by = "name";
        
        $stmt = PostgresDB::run("SELECT p.id, p.name
                                FROM projects_tenant_view p
                                JOIN project_members pm ON p.id = pm.project_id
                                WHERE pm.member_id = :user_id AND name ~* :filter
                                GROUP BY p.id, p.name
                                ORDER BY $order_by $order_dir
                                LIMIT :length
                                OFFSET :start;", 
                                ["user_id" => $userId, "filter" => $filter, 
                                "length" => $length, "start" => $start]
                            );
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($results){
            foreach($results as $row)
                array_push($matches, Project::withRow($row));
        }
        return $matches;
        
    }

    /**
     * ======================
     *    Project Members
     * ======================
     */

    
    /**
     * @param string $name
     * @param string $memberId
     * @return Project|null
     */
    public static function withNameAndMember(string $name, string $memberId): ?Project {
        try {
            $instance = new self();
            $instance->loadByNameAndMember($name, $memberId);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    

    /**
     * @return int
     */
    public static function countMembersForDatatable(string $projectId, bool $unaffiliated): int {
        $stmt = null;
        if (!$unaffiliated) {
            $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                FROM project_members 
                                WHERE project_id = :project_id;", ["project_id" => $projectId]);
        } else {
            $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                FROM users_tenant_view u LEFT JOIN project_members pm
                                ON u.id = pm.member_id AND pm.project_id = :project_id
                                WHERE pm.project_id IS NULL;", ["project_id" => $projectId]);
        }
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }

    /**
     * @param string $projectId
     * @param bool $unaffiliated
     * @param string $filter
     * @return int
     */
    public static function countFilteredMembersForDatatable(string $projectId, bool $unaffiliated, string $filter): int {
        $stmt = null;
        if (!$unaffiliated) {
            $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                FROM project_members pm JOIN users_tenant_view u ON u.id = pm.member_id
                                WHERE project_id = :project_id 
                                AND (u.full_name ~* :filter OR u.eppn ~* :filter OR u.email ~* :filter);", 
                                ["project_id" => $projectId, "filter" => $filter]
                            );
        } else {
            $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                FROM users_tenant_view u LEFT JOIN project_members pm
                                ON u.id = pm.member_id AND pm.project_id = :project_id
                                WHERE pm.project_id IS NULL
                                AND (u.full_name ~* :filter OR u.eppn ~* :filter OR u.email ~* :filter);", 
                                ["project_id" => $projectId, "filter" => $filter]);
        }
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }

    public static function getRunningJobs(string $id): array {
        $stmt = PostgresDB::run("
            SELECT
                f.filename,
                cc.code AS collection_code,
                ARRAY_AGG(e.key || ': ' || e.value) AS jobs
            FROM files f
            JOIN collection_codes cc ON f.collection_code_id = cc.id
            CROSS JOIN LATERAL (
                SELECT key, value
                FROM jsonb_each_text(f.status::jsonb->'status')
                WHERE value = 'Started'
            ) e
            WHERE cc.project_id = :project_id
            GROUP BY f.filename, cc.code;
        ", ["project_id" => $id]);
    
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $fileJobsMap = [];
    
        foreach ($result as $row) {
            $filename = $row['filename'];
            $collectionCode = $row['collection_code'];
            // Decode 'jobs' string into an array (same as in the first version)
            $jobs = $row['jobs'] !== null 
                ? array_map('trim', explode(',', trim($row['jobs'], '{}')))
                : [];
    
            $fileJobsMap[$filename] = [
                'collection_code' => $collectionCode,
                'jobs' => $jobs,
            ];
        }
    
        return $fileJobsMap;
    }
    

    public static function listMembers(string $projectId, bool $unaffiliated, int $start, int $length, string $order_by, string $order_dir, string $filter): array {
        $matches = [];
        if (is_null($order_by) || $order_by == '' || $order_by == '0')
            $order_by = 'full_name';
        if (is_null($order_dir) || $order_dir == '' || !(strtolower($order_dir) == "asc" || strtolower($order_dir) == "desc"))
            $order_dir = 'desc';

        // Ensure $order_by is a column name
        $stmt = PostgresDB::run("SELECT column_name
                                FROM information_schema.columns
                                WHERE table_name = 'users';");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array($order_by, $columns))
            $order_by = "full_name";

        $searchQuery = "JOIN project_members pm ON u.id = pm.member_id
                        WHERE pm.project_id = :project_id";
        if ($unaffiliated){
            $searchQuery = "LEFT JOIN project_members pm 
                                ON u.id = pm.member_id AND pm.project_id = :project_id
                            WHERE pm.project_id IS NULL";
        }
        error_log($searchQuery);

        $project = self::withId($projectId);
        if ($project){
            $stmt = PostgresDB::run("SELECT u.* 
                                FROM users_tenant_view u
                                $searchQuery 
                                AND (u.full_name ~* :filter OR u.eppn ~* :filter OR u.email ~* :filter)
                                ORDER BY $order_by $order_dir
                                LIMIT :length
                                OFFSET :start;", 
                                ["project_id" => $projectId, "filter" => $filter, 
                                "length" => $length, "start" => $start]
                            );
            $stmt->execute();
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                array_push($matches, User::withRow((array)$row));
                
            }
        }
        return $matches; 
        
    }


    /**
     * @return array
     */
    public function getMembers(): array {
        $members = [];
        $stmt = PostgresDB::run("SELECT id FROM users_tenant_view u JOIN project_members pm
                                ON pm.member_id = u.id
                                WHERE pm.project_id = ?",
                                [$this->getId()]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($results){
            foreach($results as $row){
                array_push($members, $row['id']);
            }
        }
        return $members;
    }

    /**
     * @param string $userId
     * @param string $role
     * @return void
     */
    public function saveMember(string $userId, string $role): void {
        if (is_null(User::withId($userId))){
            throw new Exception("User with provided ID does not exist");
        }
        $stmt = PostgresDB::run("SELECT COUNT(*) as count FROM project_roles WHERE role_name = ?", [$role]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$result || ($result["count"] ?? 0) < 1 ){
            throw new Exception("Not a valid role: $role");
        }
        
        if (is_null(Project::withNameAndMember($this->getName(), $userId))){
            $stmt = PostgresDB::run("INSERT INTO project_members (project_id, member_id, role)
                                    VALUES (?, ?, (SELECT pr.role_id
                                                    FROM project_roles pr
                                                    WHERE pr.role_name = ?)
                                    );",
                                    [$this->getId(), $userId, $role],
                );
        } else {
            $stmt = PostgresDB::run("UPDATE project_members 
                                    SET project_id=:project_id, member_id=:member_id, 
                                    role=(SELECT pr.role_id
                                         FROM project_roles pr
                                         WHERE pr.role_name = :role)
                                    WHERE project_id=:project_id AND member_id=:member_id;",
                                    ["project_id" => $this->getId(), "member_id" => $userId, "role" => $role],
                );
        }
        
    }

    /**
     * @param string $userId
     * @return void
     */
    public function removeMember(string $userId): void {
        $stmt = PostgresDB::run("DELETE FROM project_members 
                                WHERE project_id = ? AND member_id = ?",
                                [$this->getId(), $userId]);  
    }


    /**
     * Returns if the given user is a member of the project
     * @param $userId
     * @return bool
     */
    public function isMember($userId): bool {
        $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                FROM project_members 
                                WHERE project_id = :project_id AND member_id = :user_id;", 
                                ["project_id" => $this->getId(), "user_id" => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"] > 0;
        return False;
    }

    /**
     * Returns the role of a user on a project, if they are a member
     * @param $userId
     * @return ?string
     */
    public function getMemberRole(string $userId): ?string {
        $stmt = PostgresDB::run("SELECT role_name
                                FROM project_members pm JOIN project_roles pr
                                ON pm.role = pr.role_id
                                WHERE project_id = :project_id AND member_id = :user_id;", 
                                ["project_id" => $this->getId(), "user_id" => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result){
            return $result["role_name"] ?? null;
        }
        return null;
    }

    /**
     * Returns if the 
     * @param $userId
     * @return bool
     */
    public function isRole(string $userId, string $role): bool {
        return $this->getMemberRole($userId) === $role;
    }


    /**
     * =================================
     *    Abstracted Project Relation
     * =================================
     */


    /**
     * @param string $name
     * @param string $memberId
     * @return Project|null
     */
    public static function withRelation(string $name, string $memberId): ?Project {
        try {
            $instance = new self();
            $instance->loadByNameAndMember($name, $memberId);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * @return int
     */
    public static function countRelationForDatatable(string $projectId, string $linkTableName): int {
        try {
            $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                    FROM $linkTableName 
                                    WHERE project_id = :project_id;", ["project_id" => $projectId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result)
                return $result["count"];
            return -1;
        } catch (PDOException $e) {
            error_log(print_r($e, true));
            return -1;
        }
        
    }

    /**
     * @param string $projectId: The ID of the project in question
     * @param string $filter: The filter to search for
     * @return int
     */
    public static function countFilteredRelationForDatatable(
        string $projectId, 
        string $linkTableName, 
        string $linkTableRelatedField, 
        string $filter, 
        array $filterFields, 
        string $relatedTableName, 
        string $relatedTableReferenceField="id"
    ): int {
        try {
            $filterClause = "";
            if (sizeof($filterFields) > 0){
                $filterClause = "AND (" . implode(
                    ' OR ',
                    array_map(function ($field) {
                        return "$field ~* :filter";
                    }, $filterFields)
                ) . ")";
            }
            

            $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                    FROM $linkTableName JOIN $relatedTableName ON $relatedTableName.$relatedTableReferenceField = $linkTableName.$linkTableRelatedField
                                    WHERE project_id = :project_id $filterClause;", 
                                    ["project_id" => $projectId, "filter" => $filter]
                                );
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result)
                return $result["count"];
            return -1;
        } catch (PDOException $e) {
            error_log(print_r($e, true));
            return -1;
        }
        
    }


    public static function listRelation(
        string $projectId,
        string $linkTableName, 
        string $linkTableRelatedField, 
        array $filterFields, 
        string $relatedTableName, 
        string $relatedTableReferenceField="id", 
        int $start=0, 
        int $length=10, 
        string $order_by="name", 
        string $order_dir="desc", 
        string $filter=""
    ): array {
        try {
            $matches = [];
            if (is_null($order_by) || $order_by == '' || $order_by == '0')
                $order_by = 'name';
            if (is_null($order_dir) || $order_dir == '' || !(strtolower($order_dir) == "asc" || strtolower($order_dir) == "desc"))
                $order_dir = 'desc';


            $filterClause = "";
            if (sizeof($filterFields) > 0){
                $filterClause = "AND (" . implode(
                    ' OR ',
                    array_map(function ($field) {
                        return "$field ~* :filter";
                    }, $filterFields)
                ) . ")";
            }

            // Ensure $order_by is a column name
            $stmt = PostgresDB::run("SELECT column_name
                                    FROM information_schema.columns
                                    WHERE table_name = '$relatedTableName';");
            $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array($order_by, $columns))
                $order_by = "name";

            $project = self::withId($projectId);
            if ($project){
                $stmt = PostgresDB::run("SELECT $relatedTableName.* 
                                    FROM $relatedTableName
                                    JOIN $linkTableName ON $relatedTableName.$relatedTableReferenceField = $linkTableName.$linkTableRelatedField
                                    WHERE $linkTableName.project_id = :project_id $filterClause
                                    ORDER BY $order_by $order_dir
                                    LIMIT :length
                                    OFFSET :start;", 
                                    ["project_id" => $projectId, "filter" => $filter, 
                                    "length" => $length, "start" => $start]
                                );
                $stmt->execute();
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)){
                    array_push($matches, $row);
                    
                }
            }
            
            return $matches; 
        } catch (PDOException $e) {
            error_log(print_r($e, true));
            return [];
        }
        
    }


    /**
     * Check that a related element exists within the project
     * @param string $linkTableRelatedValue
     * @param string $linkTableName
     * @param string $linkTableRelatedField
     * @return bool
     */
    public function checkRelationExists(string $linkTableRelatedValue, string $linkTableName, string $linkTableRelatedField="id"): bool {
        $stmt = PostgresDB::run("SELECT COUNT(*) AS count
                                FROM $linkTableName 
                                WHERE $linkTableRelatedField = :related_id;",
                                ["related_id" => $linkTableRelatedValue]
            );
        $result = $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result){
            return ($result["count"] ?? 0) > 0;
        }
        return false;
    }



    /**
     * Add a related element through a Link Table
     * @param string $linkTableName
     * @param string $linkTableRelatedField
     * @param string $linkTableRelatedValue
     * @return void
     */
    public function addRelation(string $linkTableRelatedValue, string $linkTableName, string $linkTableRelatedField) {
        $stmt = PostgresDB::run("INSERT INTO $linkTableName (project_id, $linkTableRelatedField)
                                VALUES (:project_id, :link_table_related_field);",
                                ["project_id" => $this->getId(), "link_table_related_field" => $linkTableRelatedValue]
            );
    }

    /**
     * Remove a related element through a Link Table
     * @param string $linkTableName
     * @param string $linkTableRelatedField
     * @param string $linkTableRelatedValue
     * @return void
     */
    public function removeRelation( string $linkTableRelatedValue, string $linkTableName, string $linkTableRelatedField) {
        $stmt = PostgresDB::run("DELETE FROM $linkTableName 
                                WHERE project_id = :project_id 
                                AND $linkTableRelatedField = :link_table_related_field;",
                                ["project_id" => $this->getId(), "link_table_related_field" => $linkTableRelatedValue]
            );
    }


    /**
     * @param string $userId
     * @param string $role
     * @return void
     */
    public function saveRelation(
        string $linkTableName, 
        string $linkTableRelatedField, 
        $linkTableRelatedValue,
        array $additionalFields
    ): void {
        if (is_null(User::withId($userId))){
            throw new Exception("User with provided ID does not exist");
        }

        if (is_null(Project::withNameAndMember($this->getName(), $userId))){
            $fields = "";
            $values = "";
            if (sizeof($additionalFields) > 0) {
                $fields = ', ' . implode(', ', array_keys($additionalFields));
                $values = ', :' . implode(', :', array_keys($additionalFields));
            }
            
            $stmt = PostgresDB::run("INSERT INTO $linkTableName (project_id, $linkTableRelatedField $fields)
                                        VALUES (:project_id, :link_table_field $values);",
                                        array_merge(
                                            ["project_id" => $this->getId(), "link_table_field" => $linkTableRelatedValue],
                                            $additionalFields
                                        )
                );
        } else {
            $fields = "";
            if (sizeof($additionalFields) > 0) {
                $fields = ", " . implode(', ', array_map(function ($key) {
                    return "$key=:$key";
                }, array_keys($additionalFields)));
            }
            $stmt = PostgresDB::run("UPDATE $linkTableName 
                                    SET project_id=:project_id, $linkTableRelatedField=:link_table_field $fields
                                    WHERE project_id=:project_id AND $linkTableRelatedField=:link_table_field;",
                                    array_merge(
                                        ["project_id" => $this->getId(), "link_table_field" => $memberId, "role" => $role],
                                        $additionalFields
                                    )
                );
        }
    }



    /**
     * 
     * Other Helper Functions
     * 
     */



    

    

    /**
     * @param $row
     * @return Project
     */
    public static function withRow( $row ): Project {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }


    /**
     * @param string $name
     * @return void
     */
    protected function loadByNameAndMember(string $name, string $memberId): void {
        if (empty($name))
            throw new PDOException('You must supply an name to load');
        
        $stmt = PostgresDB::run("SELECT p.id, p.name                                
                                FROM projects_tenant_view p
                                LEFT JOIN project_members pm ON pm.project_id = p.id
                                WHERE p.name = :name 
                                AND pm.member_id = :member_id
                                GROUP BY p.id, p.name
                                LIMIT 1;",
                                ["name" => $name, "member_id" => $memberId]
        );
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cnt = 0;
        foreach($result as $project) {
            $this->fill( $project );
            $cnt++;
        }
        if ($cnt !== 1)
            throw new PDOException("Project with name [$name] not found");
    }

    protected function fill( $row ): void {
        $this->id = $row['id'];
        $this->name = $row['name'];      
    }


    protected function save(): ?Project {
        $exists = Project::withId($this->getId());    
        
        if (is_null($exists)) {
            
            $stmt = PostgresDB::run("INSERT INTO projects (id, name)
                                    VALUES (:id, :name);",
                                    ["id" => $this->getId(), "name" => $this->getName()]
            );
        } else {
            $stmt = PostgresDB::run("UPDATE projects
                                    SET name = :name
                                    WHERE id = :id;",
                                    ["name" => $this->getName(), "id" => $this->getId()]
            );
        }
        return self::withId($this->getId());
    }

    public function getCollectionCodes(): array {
        $projectId = $this->getId();
    
        $stmt = PostgresDB::run(
            "SELECT code FROM public.collection_codes WHERE project_id = :project_id;",
            ["project_id" => $projectId]
        );
    
        $collectionCodes = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $collectionCodes[] = $row['code'];
        }
    
        return $collectionCodes;
    }
    
    /**
     * Return the Tenant ID associated with this Project
     * @return string|null
     */
    public function getTenantId(): ?string {
        $stmt = PostgresDB::run("SELECT tenant_id
                                FROM projects 
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
     * Set the Tenant ID associated with this Project
     * @return void
     */
    private function setTenantId(): void {
        $stmt = PostgresDB::run("UPDATE projects
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
     * @return array|mixed
     */
    public function jsonSerialize(): array {
        return [
            'id'          => $this->getId(),
            'name'        => $this->getName()
        ];
    }
}