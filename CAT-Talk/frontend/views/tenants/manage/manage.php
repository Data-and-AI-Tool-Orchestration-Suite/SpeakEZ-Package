<?php
/**
 * @var UserSession $userSession 
 * @var string $tenantId 
 * @var Tenant $tenant 
 * 
 */
$page = "tenant-management";
include_once __DIR__ . '/../../_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h4"><?= $tenant->getName() ?> - Tenant Management</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <!-- <a type="button" class="btn btn-primary"  href="<?= $rootURL ?>/projects">
            <i class="fas fa-plus mr-1"></i>Project Button
        </a> -->
    </div>
</div>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h4">Tenant Metrics</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <a type="button" class="btn btn-primary" href="<?= $rootURL ?>/tenants/<?= $tenant->getId() ?>/metrics">
            <i class="fas fa-list mr-1"></i> View Metrics
        </a>
    </div>
</div>
<?php


include_once __DIR__ . '/members.php';
include_once __DIR__ . '/styling.php';

?>

<?php
include_once __DIR__ . '/../../_footer.php';