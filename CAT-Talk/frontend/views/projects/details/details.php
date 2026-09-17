<?php
/** @var UserSession $userSession */
$page = "projects";
include_once __DIR__ . '/../../_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h4"><?= $project->getName() ?> Project Details</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <!-- <a type="button" class="btn btn-primary"  href="<?= $rootURL ?>/projects">
            <i class="fas fa-plus mr-1"></i>Project Button
        </a> -->
    </div>
</div>

<?php



include_once __DIR__ . '/members.php';
if (Plugin::isPluginActiveByName("api_keys")){
    include_once __DIR__ . '/api-keys.php';
}
?>

<?php
include_once __DIR__ . '/../../_footer.php';