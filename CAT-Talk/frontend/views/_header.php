<?php
/** @var UserSession $userSession */
/** @var string $page */
global $rootURL;
global $PROJECT_NAME;
global $CONFIG;

/**
 * Initiate tenant config and load styling
 */
$_headerCurrentTenantId = Tenant::getCurrentTenant($user->getId());
$_defaultTenantStyles = $CONFIG["tenants"]["styling"]["defaults"];
$_headerTenantStyles =  $_defaultTenantStyles;
$_headerCurrentTenant = null;
if ($_headerCurrentTenantId){
    $_headerCurrentTenant = Tenant::withId($_headerCurrentTenantId);
    $_tenantStyles = $_headerCurrentTenant->getStyles();
    if ($_tenantStyles){
        foreach ($_tenantStyles as $_tenantStyle => $_tenantStyleValue) {
            $_headerTenantStyles[$_tenantStyle] = $_tenantStyleValue;
        }
    } else { // Tenant styles have not been properly initialized. Initialize them.
        $_headerCurrentTenant->initializeStyles();
    }
    
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= $rootURL?>/img/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= $rootURL?>/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="<?= $rootURL?>/img/favicon-16x16.png">
    <link rel="manifest" href="<?= $rootURL?>/img/site.webmanifest">
    <title><?= $_headerTenantStyles["title_text"] ?></title>
    <link href="https://cdn.datatables.net/v/bs5/jszip-3.10.1/dt-2.1.4/af-2.7.0/b-3.1.1/b-colvis-3.1.1/b-html5-3.1.1/b-print-3.1.1/cr-2.0.4/date-1.5.3/fc-5.0.1/fh-4.0.1/kt-2.12.1/r-3.0.2/rg-1.5.0/rr-1.5.0/sc-2.4.3/sb-1.8.0/sp-2.3.2/sl-2.0.5/sr-1.4.1/datatables.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-daterangepicker/3.0.5/daterangepicker.css" integrity="sha512-gp+RQIipEa1X7Sq1vYXnuOW96C4704yI1n0YB9T/KqdvqaEgL6nAuTSrKufUX3VBONq/TPuKiXGLVgBKicZ0KA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastify-js/1.12.0/toastify.min.css" integrity="sha512-k+xZuzf4IaGQK9sSDjaNyrfwgxBfoF++7u6Q0ZVUs2rDczx9doNZkYXyyQbnJQcMR4o+IjvAcIj69hHxiOZEig==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-select/1.14.0-beta2/css/bootstrap-select.min.css" integrity="sha512-mR/b5Y7FRsKqrYZou7uysnOdCIJib/7r5QeJMFvLNHNhtye3xJp1TdJVPLtetkukFn227nKpXD9OjUc09lx97Q==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@simonwep/pickr/dist/themes/classic.min.css" integrity="sha512-ZNRpTltJDDKaVyCtHAalU6WRUzU9FANDrYL2y/3/6917fUzAhbUuSpj2QhyC4gNMxapPpmW6lFkIbskW2aLK5g==" crossorigin="anonymous">

    <?php 
    include_once(__DIR__ . "/..$rootURL/css/variables.css.php")
    ?>
    <!-- <link type="text/css" rel="stylesheet" href="<?= $rootURL?>/css/variables.css.php"> -->
    <link type="text/css" rel="stylesheet" href="<?= $rootURL?>/css/navbar.css">
    <link type="text/css" rel="stylesheet" href="<?= $rootURL?>/css/global.css">
    <link type="text/css" rel="stylesheet" href="<?= $rootURL?>/css/site-banner.css">
    <!--    jQuery script must be in the header -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css">
    <script src="<?= $rootURL?>/js/prism.min.js"></script>
    <script src="<?= $rootURL?>/js/prism-line-numbers.min.js"></script>
    <!-- <script src="<?= $rootURL?>/js/modals.js"></script> showSuccess, showError, etc -->
     <script>
        const currentTenantId = "<?= $_headerCurrentTenantId ?>";
    </script>




</head>
<body>
<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container-fluid">
        <div style="display: flex;">
            <a class="navbar-brand" href="<?= $rootURL ?>/">
                <span class="brand-text"><?= $_headerTenantStyles["title_text"] ?></span>
            </a>

        
            <?php 
            
            if ($_headerCurrentTenant){
            $_headerUserTenants = Tenant::getTenantsByUser($user->getId());
            if (sizeof($_headerUserTenants) > 1 || $_headerCurrentTenant->hasRole($user->getId(), "Admin")):?>
            <div class="nav-item dropdown">
                <button class="btn btn-primary btn-sm" id="tenant-dropdown" style="margin-top:auto; margin-bottom:auto;" data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-layer-group"></i></button>
                <ul class="dropdown-menu dropdown-menu-start" aria-labelledby="tenant-dropdown">
                    <?php foreach ($_headerUserTenants as $_headerTenantId => $_headerTenantData){
                        $_headerTenantName = $_headerTenantData["name"];
                    ?>
                    <li>
                        <button class="dropdown-item <?= $_headerTenantId == $_headerCurrentTenant->getId() ?'active':'' ?>"  style="display:flex;" <?= $_headerTenantId == $_headerCurrentTenant->getId() ? "":"onclick='switchTenant(\"$_headerTenantId\")'" ?>>
                            <span class="me-3"><?php echo $_headerTenantName; ?></span>
                            <?php if (Tenant::withId($_headerTenantId)->hasRole($user->getId(), "Admin")) : ?>
                            <a class="btn btn-xs dropdown-item-button" href="<?= $rootURL ?>/tenants/<?= $_headerTenantId ?>/manage" onclick="event.stopPropagation(); event.preventDefault(); window.location.href=this.href;"><i class="fas fa-gear"></i></a>
                            <?php endif; ?>
                        </button>
                        
                        
                    </li>
                    <?php } ?>
                </ul>
            </div>            
            
            <?php endif; } ?>
        </div>



       

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fa fa-bars" aria-hidden="true"></i>
        </button>
        <?php include_once __DIR__ . '/_menu.php'; ?>
    </div>
</nav>
<?php include_once __DIR__ . '/_modals.php'; ?>

<script type="text/javascript">
    // AJAX handling for 
    $(document).ajaxError(function(event, jqXHR, ajaxSettings, thrownError) {
        if (jqXHR.status === 0) {
            showError("Failed to communicate. Your session<br> may have expired. Try reloading.");
        } 
    });

    // Copy the passed text into the clipboard
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text);
    }

    // Escape HTML for safe echoing into HTML
    function escapeHTML(text) {
        return text.replace(/[&<>"'/]/g, function(match) {
            switch (match) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#039;';
                case '/': return '&#47;';
                default: return match;
            }
        });
    }

    // Switch tenant to specified ID if possible
    function switchTenant(tenantId){
        $.ajax({
            url: '<?= $rootURL ?>/tenants/switch-tenant',
            method: 'POST',
            data: {
                "tenant_id": tenantId,
            },
            success: function(data) {
                if (data.tenant_id && data.tenant_id == tenantId){
                    console.log(window.location.pathname);
                    window.location.reload();
                } else {
                    showError("Failed to switch tenants. Try reloading...")
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                let data = jqXHR.responseJSON;
                if (data.error){
                    showError(data.error);
                } else {
                    showError("Failed to switch tenants. Try again...")
                }
            }
        });
    }

</script>

<?php
// Display the site banner if enabled

if (Plugin::isPluginActiveByName("site_banner") && $user->getId() != ""):
    require_once __DIR__ . '/../models/SiteBanner.php';
    $siteBanner = SiteBanner::load();
    if ($siteBanner && $siteBanner->getIsOn() && trim($siteBanner->getMessage()) !== ''): ?>
        <div class="site-banner-display mx-auto mt-0 mb-3 px-4 py-2 text-center">
            <span class="site-banner-icon me-2" style="vertical-align: middle;"><i class="fa fa-bullhorn"></i></span>
            <span style="vertical-align: middle; font-size: 1.1em; font-weight: 500;">
                <?= nl2br(htmlspecialchars($siteBanner->getMessage())) ?>
            </span>
        </div>
    <?php endif; ?>
<?php endif; ?>


<div class="container-fluid" style="height:100%;">
    <div class="row" style="height:100%;">
        <main role="main" class="col-md-12 bg-faded py-3">
