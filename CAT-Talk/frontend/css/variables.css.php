<?php 
/**
 * Define all css variables here. Pull them in from the tenant styles settings
 * if applicable.
 */

?>
<style>
:root {
    --navbar-bg-color: <?= $_headerTenantStyles["navbar_color"] ?>;
    --navbar-brand-text-color: <?= $_headerTenantStyles["title_text_color"] ?>;
    --navbar-menu-text-color: <?= $_headerTenantStyles["navbar_menu_color"] ?>;


    --dropdown-active-color: <?= $_headerTenantStyles["menu_active_dropdown_text_color"] ?>;
    --dropdown-active-bg-color: <?= $_headerTenantStyles["menu_active_dropdown_bg_color"] ?>;

    --bs-primary:   <?= $_headerTenantStyles["button_primary"] ?> !important;
    --bs-secondary: <?= $_headerTenantStyles["button_secondary"] ?>;
    --bs-success:   <?= $_headerTenantStyles["button_success"] ?>;
    --bs-danger:    <?= $_headerTenantStyles["button_danger"] ?>;
    --bs-warning:   <?= $_headerTenantStyles["button_warning"] ?>;
    --bs-info:      <?= $_headerTenantStyles["button_info"] ?>;
    --bs-light:     <?= $_headerTenantStyles["button_light"] ?>;
    --bs-dark:      <?= $_headerTenantStyles["button_dark"] ?>;
}

.btn-primary {
    background-color: var(--bs-primary) !important;
    border-color: var(--bs-primary) !important;
}

.btn-secondary {
    background-color: var(--bs-secondary) !important;
    border-color: var(--bs-secondary) !important;
}

.btn-success {
    background-color: var(--bs-success) !important;
    border-color: var(--bs-success) !important;
}

.btn-danger {
    background-color: var(--bs-danger) !important;
    border-color: var(--bs-danger) !important;
}

.btn-warning {
    background-color: var(--bs-warning) !important;
    border-color: var(--bs-warning) !important;
}

.btn-info {
    background-color: var(--bs-info) !important;
    border-color: var(--bs-info) !important;
}

.btn-light {
    background-color: var(--bs-light) !important;
    border-color: var(--bs-light) !important;
}

.btn-dark {
    background-color: var(--bs-dark) !important;
    border-color: var(--bs-dark) !important;
}
</style>