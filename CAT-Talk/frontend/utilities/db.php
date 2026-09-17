<?php

class PostgresDB {
    protected static $instance = null;

    protected function __construct() {}
    protected function __clone() {}

    public static function instance() {
        global $config;
        if (self::$instance === null) {
            $dsn = "pgsql:host={$config['db']['host']};port={$config['db']['port']};dbname={$config['db']['name']}";
            try {
                self::$instance = new PDO($dsn, $config['db']['user'], $config['db']['pass']);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                throw new PDOException("{$e->getMessage()} (on line {$e->getLine()})", (int)$e->getCode());
            }
        }
        return self::$instance;
    }

    public static function __callStatic($method, $args) {
        return call_user_func_array(array(self::instance(), $method), $args);
    }

    public static function run($sql, $args = []) {
        if (!$args)
            return self::instance()->query($sql);
        $stmt = self::instance()->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }

    public static function prepare($sql) {
        return self::instance()->prepare($sql);
    }

    public static function close() {
        self::$instance = null;
    }


    /**
     * Retrieve the current session tenant_id if set. Null otherwise
     * Usually only called here for debug. Called within queries to get current value
     * @return string|null
     */
    public static function getTenant(): ?string {
        // Set the tenant ID for this session
        $sql = "SELECT current_setting('site_session.tenant_id') AS tenant_id;";
        $stmt = self::instance()->query($sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result["tenant_id"] ?? null;
    }

    /**
     * Set the current session tenant_id
     * @param string $tenantId
     * @return void
     */
    public static function setTenant(string $tenantId): void {
        $tenantId = self::instance()->quote($tenantId);
        // Set the tenant ID for this session
        $sql = "SET site_session.tenant_id = $tenantId";
        self::instance()->exec($sql);
    }

    /**
     * Reset current session tenant_id to default value. Default value not specified
     * as of writing.
     * @return void
     */
    public static function resetTenant(): void {
        // Reset the session tenant ID
        $sql = "RESET site_session.tenant_id";
        self::instance()->exec($sql);
    }

    /**
     * Sets if row_security should be used for this session
     * @param bool $userRowSecurity
     */
    public static function setRowSecurity(bool $useRowSecurity): void {
        $rowSecurity = $useRowSecurity ? "on" : "off";
        $sql = "SET row_security = '$useRowSecurity'";
        self::instance()->exec($sql);
    }
}

