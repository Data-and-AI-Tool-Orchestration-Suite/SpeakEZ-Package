<?php


class Tenant implements JsonSerializable {
    protected string $id;
    protected string $name;
    protected bool $canSelfManage;

    public function __construct() {
        $this->id = UUID::v4();
    }

    /**
     * Create a new tenant database entry 
     * @param string $name
     * @param string $userId
     * @return Tenant|null
     * @throws Exception
     */
    public static function create(string $name, bool $canSelfManage=false): ?Tenant {
        if (empty($name))
            throw new Exception('You must provide a name to create');

        $existing = self::withName($name);
        if (!is_null($existing))
            throw new Exception('A tenant with this name already exists');
        $instance = new self();
        $instance->setName($name);
        $instance->setCanSelfManage($canSelfManage);

        $newTenant = $instance->save();
        if ($newTenant) {
            $newTenant->addRole("Admin");
            $newTenant->addRole("User");
            $newTenant->initializeStyles();
        }
        return $newTenant;
    }

    public static function update(string $id, string $name, bool $canSelfManage=false): Tenant {
        if (empty($id))
            throw new Exception('You must provide an id to update a tenant');
        $instance = Tenant::withId($id);
        if (is_null($instance))
            throw new Exception("No tenant found with id [{$id}]");
        $instance->setName($name);
        $instance->setCanSelfManage($canSelfManage);

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
        $existing = Tenant::withId($id);
        if (is_null($existing))
            throw new Exception("No tenant exists with id [$id]");
        $stmt = PostgresDB::run(
            "DELETE FROM tenants WHERE id = :id", 
            ["id" => $id]
        );
        $affectedRows = $stmt->rowCount();
        if ($affectedRows === 0){

        } else if ($affectedRows > 1){

        }
    }

    

    /**
     * ======================
     *    General Tenants 
     * ======================
     */


    /**
     * @param string $id
     * @return Tenant|null
     */
    public static function withId(string $id): ?Tenant {
        try {
            $instance = new self();
            $instance->loadById($id);
            return $instance;
        } catch (PDOException $e) {
            // error_log(print_r($e, true));
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
        
        $stmt = PostgresDB::run("SELECT t.id, t.name, t.can_self_manage
                                FROM tenants t
                                LEFT JOIN tenant_users tu ON tu.tenant_id = t.id
                                WHERE t.id = :id
                                GROUP BY t.id, t.name
                                LIMIT 1;",
                                ["id" => $id]
        );
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cnt = 0;
        foreach($result as $tenant) {
            $this->fill( $tenant );
            $cnt++;
        }
        if ($cnt !== 1)
            throw new PDOException("Tenant with id [{$id}] not found");
    }
    

    /**
     * @param string $name
     * @param string $userId
     * @return Tenant|null
     */
    public static function withName(string $name): ?Tenant {
        try {
            $instance = new self();
            $instance->loadByName($name);
            return $instance;
        } catch (PDOException $e) {
            return null;
        }
    }
    
    /**
     * @param string $name
     * @return void
     */
    protected function loadByName(string $name): void {
        if (empty($name))
            throw new PDOException('You must supply an name to load');
        
        $stmt = PostgresDB::run("SELECT t.id, t.name, t.can_self_manage                        
                                FROM tenants t
                                WHERE t.name = :name 
                                LIMIT 1;",
                                ["name" => $name]
        );
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cnt = 0;
        foreach($result as $tenant) {
            $this->fill( $tenant );
            $cnt++;
        }
        if ($cnt !== 1)
            throw new PDOException("Tenant with name [$name] not found");
    }


    /**
     * @return int
     */
    public static function countForDatatable(): int {
        $stmt = PostgresDB::run("SELECT COUNT(*) as count FROM tenants;");
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
                                FROM tenants 
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
        if (is_null($order_by) || $order_by == '' || $order_by == '0')
            $order_by = 'name';
        if (is_null($order_dir) || $order_dir == '' || !(strtolower($order_dir) == "asc" || strtolower($order_dir) == "desc"))
            $order_dir = 'desc';

        // Ensure $order_by is a column name
        $stmt = PostgresDB::run("SELECT column_name
                                FROM information_schema.columns
                                WHERE table_name = 'tenants';");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (in_array($order_by, $columns))
            $order_by = "name";
        
        $stmt = PostgresDB::run("SELECT t.id, t.name, t.can_self_manage
                                FROM tenants t
                                WHERE name ~* :filter
                                GROUP BY t.id
                                ORDER BY $order_by $order_dir
                                LIMIT :length
                                OFFSET :start;", 
                                ["filter" => $filter, 
                                "length" => $length, "start" => $start]
                            );
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($results){
            foreach($results as $row)
                array_push($matches, Tenant::withRow($row));
        }
        return $matches;
        
    }

    
    /**
     * @param $row
     * @return Tenant
     */
    public static function withRow( $row ): Tenant {
        $instance = new self();
        $instance->fill( $row );
        return $instance;
    }


    

    protected function fill( $row ): void {
        $this->id = $row['id'];
        $this->name = $row['name']; 
        $this->canSelfManage = $row['can_self_manage'] ?? false;     
    }


    protected function save(): ?Tenant {
        $exists = Tenant::withId($this->getId());    
        if (is_null($exists)) {
            $stmt = PostgresDB::run("INSERT INTO tenants (id, name, can_self_manage)
                                    VALUES (:id, :name, :can_self_manage);",
                                    ["id" => $this->getId(), "name" => $this->getName(), 
                                    "can_self_manage" => json_encode($this->getCanSelfManage(), true)]
            );
        } else {
            $stmt = PostgresDB::run("UPDATE tenants
                                    SET name = :name,
                                    can_self_manage = :can_self_manage
                                    WHERE id = :id;",
                                    ["name" => $this->getName(), 
                                    "can_self_manage" => json_encode($this->getCanSelfManage(), true),
                                    "id" => $this->getId()]
            );
        }
        return self::withId($this->getId());
    }

    /**
     * Retrieve tenant ID, name and roles. If specified user is site admin, 
     * return all tenants. If not site admin, return only tenants 
     * where user is tenant admin.
     * @param User $user
     */
    public static function getTenants(User $user){
        $tenants = [];
        $results = [];
        if ($user->isAdmin()){
            $stmt = PostgresDB::run("SELECT id, name, (
                                        SELECT jsonb_object_agg(role_id, role_name) 
                                        FROM tenant_roles
                                        WHERE tenant_id = id
                                    ) as roles
                                    FROM tenants
                                    LEFT JOIN tenant_roles tr
                                    ON tr.tenant_id = id;");
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } else {
            $stmt = PostgresDB::run("SELECT t.id, t.name, (
                                        SELECT jsonb_object_agg(role_id, role_name) 
                                        FROM tenant_roles
                                        WHERE tenant_id = t.id
                                    ) as roles
                                    FROM tenants t
                                    LEFT JOIN tenant_roles tr
                                    ON tr.tenant_id = t.id
                                    LEFT JOIN tenant_users tu
                                    ON t.id = tu.id
                                    WHERE tu.user_id = :user_id
                                    AND tu.tenant_role = (
                                        SELECT role_id
                                        FROM tenant_roles
                                        WHERE tenant_id = t.id
                                        AND role_name = 'Admin'
                                    );",
                                    ["user_id" => $userId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($results as $row){
            $tenants[$row["id"]] = array(
                "name" => $row["name"],
                "roles" => json_decode($row["roles"], true),
            );
        }
        return $tenants;
    }

    /**
     * ======================
     *    Tenant Users
     * ======================
     */
    

    /**
     * @return int
     */
    public static function countUsersForDatatable(string $tenantId): int {
        $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                FROM tenant_users 
                                WHERE tenant_id = :tenant_id;", ["tenant_id" => $tenantId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }

    /**
     * @param string $filter
     * @return int
     */
    public static function countFilteredUsersForDatatable(string $tenantId, string $filter): int {
        $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                FROM tenant_users tu JOIN users ON users.id = tu.user_id
                                WHERE tenant_id = :tenant_id 
                                AND (users.full_name ~* :filter OR users.eppn ~* :filter OR users.email ~* :filter);", 
                                ["tenant_id" => $tenantId, "filter" => $filter]
                            );
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"];
        return -1;
    }

    /**
     * Returns the list of roles associated with this tenant
     * @return array
     */
    public function getRoles(): array {
        $roles = [];
        $stmt = PostgresDB::run("SELECT role_id, role_name
                                FROM tenant_roles 
                                WHERE tenant_id = :tenant_id;", 
                                ["tenant_id" => $this->getId()]
                            );
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($results) {
            foreach ($results as $result){
                $roles[$result["role_id"]] = $result["role_name"];
            }
        }
        return $roles;
    }

    /**
     * Add a new role to the tenant
     * @param string $roleName
     * @return void
     */
    public function addRole(string $roleName): void {
        error_log($this->getId());
        $stmt = PostgresDB::run("INSERT INTO tenant_roles (tenant_id, role_id, role_name)
                                VALUES (:tenant_id::VARCHAR(36),
                                    (SELECT COALESCE(MAX(role_id), -1)+1 as new_role_id
                                    FROM tenant_roles
                                    WHERE tenant_id = :tenant_id::VARCHAR(36)),
                                    :role_name)
                                 ;", 
                                ["tenant_id" => $this->getId(),
                                "role_name" => $roleName]
                            );
    }


    public static function listUsers(string $tenantId, int $start, int $length, string $order_by, string $order_dir, string $filter): array {
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

        $tenant = self::withId($tenantId);
        if ($tenant){
            $stmt = PostgresDB::run("SELECT users.*, (
                                        SELECT jsonb_object_agg(role_id, role_name) 
                                        FROM tenant_roles tr
                                        LEFT JOIN tenant_users tui
                                        ON tui.tenant_id = tr.tenant_id
                                        AND tui.user_id = users.id
                                        WHERE tr.tenant_id = :tenant_id 
                                        AND tui.tenant_role = tr.role_id
                                    ) as tenant_roles
                                FROM users
                                JOIN tenant_users tu ON users.id = tu.user_id
                                WHERE tu.tenant_id = :tenant_id 
                                AND (users.full_name ~* :filter OR users.eppn ~* :filter OR users.email ~* :filter)
                                GROUP BY tu.user_id, users.id
                                ORDER BY $order_by $order_dir
                                
                                LIMIT :length
                                OFFSET :start;", 
                                ["tenant_id" => $tenantId, "filter" => $filter, 
                                "length" => $length, "start" => $start]
                            );
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($results as $row){
                $user = User::withRow((array)$row);
                if ($user) {
                    $userData = $user->jsonSerialize();
                    $userData["roles"] = json_decode($row["tenant_roles"], false);
                    array_push($matches, $userData);
                }
                
                
            }
        }
        return $matches; 
        
    }


    /**
     * @return array
     */
    public function getUsers(): array {
        $users = [];
        $stmt = PostgresDB::run("SELECT id FROM users u JOIN tenant_users tu
                                ON tu.user_id = u.id
                                WHERE tu.tenant_id = ?",
                                [$this->getId()]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($results){
            foreach($results as $row){
                array_push($users, $row['id']);
            }
        }
        return $users;
    }


    /**
     * Returns a list of tenant IDs for a given user
     * @return array
     */
    public static function getTenantsByUser(string $userId): array {
        $tenants = [];
        $stmt = PostgresDB::run("SELECT t.id, t.name, (
                                    SELECT jsonb_object_agg(role_id, role_name) 
                                    FROM tenant_roles tr
                                    LEFT JOIN tenant_users tu 
                                    ON tu.tenant_id = tr.tenant_id
                                    AND tu.user_id = :user_id
                                    WHERE tr.tenant_id = t.id 
                                    AND tu.tenant_role = tr.role_id
                                ) as roles
                                FROM tenant_users tu
                                LEFT JOIN tenant_roles tr ON tu.tenant_id = tr.tenant_id
                                LEFT JOIN tenants t ON tu.tenant_id = t.id
                                WHERE tu.user_id = :user_id
                                GROUP BY tu.tenant_id, t.id, t.name
                                ORDER BY (t.id = (
                                    SELECT tenant_id 
                                    FROM users_current_tenants
                                    WHERE user_id = :user_id
                                )) desc, t.name desc;",
                                ["user_id" => $userId]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($results){
            foreach($results as $row){
                $tenants[$row["id"]] = array(
                    "name" => $row["name"],
                    "roles" => json_decode($row["roles"], true),
                );
            }
        }
        return $tenants;
    }

    /**
     * Takes as a parameter 
     * @param string $userId
     * @param string $role
     * @return void
     */
    public function setUserRoles(string $userId, array $roleNames): void {
        if (is_null(User::withId($userId))){
            throw new Exception("User with provided ID does not exist");
        }
        foreach ($roleNames as $roleName){
            $stmt = PostgresDB::run("SELECT COUNT(*) as count FROM tenant_roles WHERE role_name = ?", [$roleName]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$result || ($result["count"] ?? 0) < 1 ){
                throw new Exception("Not a valid role: $roleName");
            }
        }

        $stmt = PostgresDB::run("BEGIN;");
        try {
            // Remove current roles for this tenant and user
            $stmt = PostgresDB::run("DELETE FROM tenant_users 
                                    WHERE tenant_id = :tenant_id
                                    AND user_id = :user_id;",
                                    ["tenant_id" => $this->getId(), "user_id" => $userId],
                );
            // Insert all set roles for this tenant and user
            foreach ($roleNames as $roleName){
                $stmt = PostgresDB::run("INSERT INTO tenant_users (tenant_id, user_id, tenant_role)
                                        VALUES (:tenant_id::VARCHAR(36), :user_id, (
                                            SELECT tr.role_id
                                            FROM tenant_roles tr
                                            WHERE tr.role_name = :role_name
                                            AND tr.tenant_id = :tenant_id
                                            )
                                        );",
                                        ["tenant_id" => $this->getId(), "user_id" => $userId, "role_name" => $roleName],
                    );
            }
            // COMMIT the state if no errors occurred
            $stmt = PostgresDB::run("COMMIT;");
        } catch (Exception $e) {
            // ROLLBACK the state if an error occurred
            error_log(print_r($e, true));
            $stmt = PostgresDB::run("ROLLBACK;");
            throw new Exception($e->getMessage());
        }         
    }

    /**
     * @param string $userId
     * @return void
     */
    public function removeUser(string $userId): void {
        try{
            $stmt = PostgresDB::run("DELETE FROM tenant_users 
                                WHERE tenant_id = ? AND user_id = ?",
                                [$this->getId(), $userId]);  
        } catch (Exception $e) {
            throw new Exception("Cannot remove this user. They are not a part of any other tenant. If you are an admin, you can fully delete their user account.");
        }
    }


    /**
     * Returns if the given user is a user of the tenant
     * @param $userId
     * @return bool
     */
    public function isMember($userId): bool {
        $stmt = PostgresDB::run("SELECT COUNT(*) as count
                                FROM tenant_users 
                                WHERE tenant_id = :tenant_id AND user_id = :user_id;", 
                                ["tenant_id" => $this->getId(), "user_id" => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result)
            return $result["count"] > 0;
        return False;
    }

    /**
     * Returns the role of a user on a tenant, if they are a user
     * @param $userId
     * @return ?array
     */
    public function getUserRoles(string $userId): ?array {
        $roles = [];
        $stmt = PostgresDB::run("SELECT role_name
                                FROM tenant_users tu JOIN tenant_roles tr
                                ON tu.tenant_role = tr.role_id AND tu.tenant_id = tr.tenant_id
                                WHERE tu.tenant_id = :tenant_id AND user_id = :user_id;", 
                                ["tenant_id" => $this->getId(), "user_id" => $userId]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($result as $role){
            $roles[] = $role["role_name"];
        }
        return $roles;
    }

    /**
     * Returns if the 
     * @param $userId
     * @return bool
     */
    public function hasRole(string $userId, string $role): bool {
        return in_array($role, $this->getUserRoles($userId));
    }

    /**
     * Returns the ID of the current tenant set for the given user,
     * null if not set.
     * @param string $userId
     * @return string|null
     */
    public static function getCurrentTenant(string $userId): ?string {
        $stmt = PostgresDB::run("SELECT tenant_id
                                FROM users_current_tenants 
                                WHERE user_id = :user_id;", 
                                ["user_id" => $userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result){
            return $result["tenant_id"] ?? null;
        }
        return null;
    }

    /**
     * Switch the current tenant of the given user to this.
     * @param string $userId
     * @return void
     */
    public function setCurrentTenant(string $userId): void {
        // Remove previous entry and add new. This handles for when no entry 
        // is set initially for the user.
        // Unset current tenant
        $stmt = PostgresDB::run("BEGIN;");
        $stmt = PostgresDB::run("DELETE FROM users_current_tenants 
                                WHERE user_id = :user_id;", 
                                ["user_id" => $userId]);
        // Set current tenant to this
        $stmt = PostgresDB::run("INSERT INTO users_current_tenants 
                                (tenant_id, user_id)
                                VALUES (:tenant_id, :user_id);", 
                                ["tenant_id" => $this->getId(),"user_id" => $userId]);
        $stmt = PostgresDB::run("COMMIT;");
    }


    /**
     * ==================================
     *          Tenant Styles
     * ==================================
     */

    /**
     * Get valid styles for tenants. Used to verify that a style
     * field is valid before allowing insert
     * @return array
     */
    public static function getValidStyles(): array{
        return [
            'title_text', 
            'title_text_color', 
            'navbar_color',
            'navbar_menu_color', 
            'menu_active_dropdown_text_color',
            'menu_active_dropdown_bg_color',
            'button_primary',
            'button_secondary',
            'button_success',
            'button_danger',
            'button_warning',
            'button_info',
            'title_text_color',
            'button_light',
            'button_dark',
        ];
    }

    /**
     * Retrieve the styles associated with the tenant. If not set, 
     * use default values from config.php
     * Add new entries to this as new tenant styles are added
     * @return array|null
     */
    public function getStyles(): ?array{
        global $CONFIG;
        // Defaults from config.php
        $defaults = $CONFIG["tenants"]["styling"]["defaults"];
        $default_title_text                      = $defaults["title_text"] ?? "Default Title";
        $default_title_text_color                = $defaults["title_text_color"] ?? "#FFFFFF";
        $default_navbar_color                    = $defaults["navbar_color"] ?? "#1a48aa";
        $default_navbar_menu_color               = $defaults["navbar_menu_color"] ?? "#FFFFFF";
        $default_menu_active_dropdown_text_color = $defaults["menu_active_dropdown_text_color"] ?? "#FFFFFF";
        $default_menu_active_dropdown_bg_color   = $defaults["menu_active_dropdown_bg_color"] ?? "#0d6efd";
        $default_button_primary                  = $defaults["button_primary"] ?? "#0d6efd";
        $default_button_secondary                = $defaults["button_secondary"] ?? "#6c757d";
        $default_button_success                  = $defaults["button_success"] ?? "#198754";
        $default_button_danger                   = $defaults["button_danger"] ?? "#dc3545";
        $default_button_warning                  = $defaults["button_warning"] ?? "#ffc107";
        $default_button_info                     = $defaults["button_info"] ?? "#0dcaf0";
        $default_button_light                    = $defaults["button_light"] ?? "#f8f9fa";
        $default_button_dark                     = $defaults["button_dark"] ?? "#343a40";


        $stmt = PostgresDB::run("SELECT tenant_id, 
                                COALESCE(styles->>'title_text', '$default_title_text') AS title_text,
                                COALESCE(styles->>'title_text_color', '$default_title_text_color') AS title_text_color,
                                COALESCE(styles->>'navbar_color', '$default_navbar_color') AS navbar_color,
                                COALESCE(styles->>'navbar_menu_color', '$default_navbar_menu_color') AS navbar_menu_color,
                                COALESCE(styles->>'menu_active_dropdown_text_color', '$default_menu_active_dropdown_text_color') AS menu_active_dropdown_text_color,
                                COALESCE(styles->>'menu_active_dropdown_bg_color', '$default_menu_active_dropdown_bg_color') AS menu_active_dropdown_bg_color,
                                COALESCE(styles->>'button_primary', '$default_button_primary') AS button_primary,
                                COALESCE(styles->>'button_secondary', '$default_button_secondary') AS button_secondary,
                                COALESCE(styles->>'button_success', '$default_button_success') AS button_success,
                                COALESCE(styles->>'button_danger', '$default_button_danger') AS button_danger,
                                COALESCE(styles->>'button_warning', '$default_button_warning') AS button_warning,
                                COALESCE(styles->>'button_info', '$default_button_info')   AS button_info,
                                COALESCE(styles->>'button_light', '$default_button_light') AS button_light,
                                COALESCE(styles->>'button_dark', '$default_button_dark') AS button_dark


                                FROM tenant_styles
                                WHERE tenant_id = :tenant_id;", 
                                ["tenant_id" => $this->getId()]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result){
            return $result;
        }
        return null;
    }

    /**
     * Set the styles associated with the tenant. 
     */
    public function setStyle(string $styleName, string $styleValue){
        if (!in_array($styleName, Tenant::getValidStyles())){
            throw new Exception("Invalid style name");
        }

        $stmt = PostgresDB::run("UPDATE tenant_styles 
                                SET styles = jsonb_set(styles, :style_path, :style_value::jsonb, true)
                                WHERE tenant_id = :tenant_id;", 
                                [
                                    "style_path" => '{"' . $styleName . '"}', 
                                    "style_value" => json_encode($styleValue),
                                    "tenant_id" => $this->getId()
                                ]);
    }

    public function initializeStyles() {
        global $CONFIG;
        // Defaults from config.php
        $defaults = $CONFIG["tenants"]["styling"]["defaults"];
        $default_title_text                      = $defaults["title_text"];
        $default_title_text_color                = $defaults["title_text_color"];
        $default_navbar_color                    = $defaults["navbar_color"];
        $default_navbar_menu_color               = $defaults["navbar_menu_color"];
        $default_menu_active_dropdown_text_color = $defaults["menu_active_dropdown_text_color"];
        $default_menu_active_dropdown_bg_color   = $defaults["menu_active_dropdown_bg_color"];
        $default_button_primary                  = $defaults["button_primary"];
        $default_button_secondary                = $defaults["button_secondary"];
        $default_button_success                  = $defaults["button_success"];
        $default_button_danger                   = $defaults["button_danger"];
        $default_button_warning                  = $defaults["button_warning"];
        $default_button_info                     = $defaults["button_info"];
        $default_button_light                    = $defaults["button_light"];
        $default_button_dark                     = $defaults["button_dark"];

        $stmt = PostgresDB::run("INSERT INTO tenant_styles (tenant_id, styles)
                                    VALUES (
                                        :tenant_id,
                                        jsonb_build_object(
                                            'title_text',                      :title_text::TEXT,
                                            'title_text_color',                :title_text_color::TEXT,
                                            'navbar_color',                    :navbar_color::TEXT,
                                            'navbar_menu_color',               :navbar_menu_color::TEXT,
                                            'menu_active_dropdown_text_color', :menu_active_dropdown_text_color::TEXT,
                                            'menu_active_dropdown_bg_color',   :menu_active_dropdown_bg_color::TEXT,
                                            'button_primary',                  :button_primary::TEXT,
                                            'button_secondary',                :button_secondary::TEXT,
                                            'button_success',                  :button_success::TEXT,
                                            'button_danger',                   :button_danger::TEXT,
                                            'button_warning',                  :button_warning::TEXT,
                                            'button_info',                     :button_info::TEXT,
                                            'button_light',                    :button_light::TEXT,
                                            'button_dark',                     :button_dark::TEXT
                                        )
                                    )
                                    ON CONFLICT (tenant_id) 
                                    DO UPDATE SET styles = EXCLUDED.styles;", 
                                    [
                                        "tenant_id" => $this->getId(),
                                        "title_text" => $default_title_text,
                                        "title_text_color" => $default_title_text_color,
                                        "navbar_color" => $default_navbar_color,
                                        "navbar_menu_color" => $default_navbar_menu_color,
                                        "menu_active_dropdown_text_color" => $default_menu_active_dropdown_text_color,
                                        "menu_active_dropdown_bg_color" => $default_menu_active_dropdown_bg_color,
                                        "button_primary" => $default_button_primary,
                                        "button_secondary" => $default_button_secondary,
                                        "button_success" => $default_button_success,
                                        "button_danger" => $default_button_danger,
                                        "button_warning" => $default_button_warning,
                                        "button_info" => $default_button_info,
                                        "button_light" => $default_button_light,
                                        "button_dark" => $default_button_dark
                                    ]);
    }

    /**
     * 
     * Other Helper Functions
     * 
     */



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
     * @return bool
     */
    public function getCanSelfManage(): bool {
        return $this->canSelfManage;
    }
    /**
     * Alias for getCanSelfManage()
     * @return bool
     */
    public function canSelfManage(): bool {
        return $this->canSelfManage;
    }
    /**
     * @param bool $canSelfManage
     */
    public function setCanSelfManage(bool $canSelfManage): void {
        $this->canSelfManage = $canSelfManage;
    }
    

    /**
     * @return array|mixed
     */
    public function jsonSerialize(): array {
        return [
            'id'            => $this->getId(),
            'name'          => $this->getName(),
            'can_self_manage' => $this->getCanSelfManage()
        ];
    }
}