<?php
/** 
 * @var User $user
 * @var string $userId
 * @var Document $document
 * @var string $resourceId
 */
$page = "documents";
include_once VIEWS_DIR . '/_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h4"><?= $document->getName() ?> Document Details</h1>
    <div class="btn-toolbar mb-2 mb-md-0">
        <!-- <a type="button" class="btn btn-primary"  href="<?= $rootURL ?>/projects">
            <i class="fas fa-plus mr-1"></i>Project Button
        </a> -->
    </div>
</div>

<?php

/**
 * Create new view files to be subviews of your class and put 
 * them in order here
 */

if ($document->canManage($userId)){
    include_once VIEWS_DIR . '/resources/access.php';
}


?>

<?php
include_once VIEWS_DIR . '/_footer.php';