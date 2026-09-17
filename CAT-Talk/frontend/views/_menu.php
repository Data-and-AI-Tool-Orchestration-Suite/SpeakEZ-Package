<?php
/** @var User $user */
/** @var string $page */
global $rootURL;
?>
<div class="collapse navbar-collapse" id="navbarSupportedContent">
    <ul class="navbar-nav mb-2 dflex">
        <!-- Menu Items -->
        <li class="nav-item">
            <a class="nav-link <?= $page=='home'?'active':'' ?>" aria-current="dashboard" href="<?= $rootURL ?>/">Home</a>
        </li>

        <?php if (Plugin::isPluginActiveByName("projects")): ?>
        <li class="nav-item">
            <a class="nav-link <?= $page=='projects'?'active':'' ?>" aria-current="projects" href="<?= $rootURL ?>/projects">Projects</a>
        </li>
        <?php endif; ?>

        <li class="nav-item">
            <a class="nav-link <?= $page=='user-guide'?'active':'' ?>" aria-current="user-guide" href="<?= $rootURL ?>/user-guide">User Guide</a>
        </li>

        <!-- Dropdown -->
        <li class="nav-item dropdown">
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="page1Dropdown">
            <li><a class="dropdown-item <?= $page=='page1'?'active':'' ?>" href="<?= $rootURL ?>/page1">Page 1</a></li>
            <li><a class="dropdown-item <?= $page=='otherPage'?'active':'' ?>" href="<?= $rootURL ?>#">Another action</a></li>
            <li><a class="dropdown-item" href="<?= $rootURL ?>#">Something else here</a></li>
          </ul>
        </li>
        <!-- /Dropdown -->

        <?php if (Plugin::isPluginActiveByName("user_guide")): ?>
        <li class="nav-item">
            <a class="nav-link <?= $page=='user-guide'?'active':'' ?>" aria-current="user-guide" href="<?= $rootURL ?>/user-guide">User Guide</a>
        </li>
        <?php endif; ?>

        <?php if (isset($user) && $user->isAdmin()) : ?>
            <!-- Admin Dropdown -->
            <li class="nav-item dropdown">
              <a class="nav-link 
              <?= (
                    $page=='users' || 
                    $page=='plugins' || 
                    $page=='tenants' 
                )?'active':'' ?>" 
                href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                Administration
              </a>
              <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="adminDropdown">
                <li><a class="dropdown-item <?= $page=='users'?'active':'' ?>" href="<?= $rootURL ?>/users">Users</a></li>
                <li><a class="dropdown-item <?= $page=='plugins'?'active':'' ?>" href="<?= $rootURL ?>/plugins">Plugins</a></li>
                <li><a class="dropdown-item <?= $page=='tenants'?'active':'' ?>" href="<?= $rootURL ?>/tenants">Tenants</a></li>
                <li><a class="dropdown-item <?= $page=='metrics'?'active':'' ?>" href="<?= $rootURL ?>/metrics">Metrics</a></li>
                <?php if (Plugin::isPluginActiveByName("site_banner")): ?>
                <li><a class="dropdown-item <?= $page=='banner-settings'?'active':'' ?>" href="<?= $rootURL ?>/banner-settings">Banner Settings</a></li>
                <?php endif; ?>
              </ul>
            </li>
            <!-- /Dropdown -->
        <?php endif; ?>

        <li class="nav-item">
                    <a class="nav-link" aria-current="dashboard" href="https://data.ai.uky.edu">Data.AI</a>
        </li>


        <!-- My Acct Dropdown -->
        <?php if ($user->getId() != "") : ?>
            <li class="nav-item dropdown ">
                <a class="nav-link <?= (
                                        $page=='api-key'
                                    )?'active':'' ?>" href="#" id="myAcctDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-regular fa-circle-user"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end acct-menu" aria-labelledby="myAcctDropdown">
                    <?php if (!is_null($user)) : ?>
                        <?php if (is_null($user->getFullName()) || empty($user->getFullName())) : ?>
                            <li class="nav-item"><p class="fw-bold" style="margin-bottom: 0px;"><?php echo $user->getEPPN(); ?></p></li>
                        <?php else: ?>
                            <li class="nav-item"><p class="fw-bold" style="margin-bottom: 0px;"><?php echo $user->getFullName(); ?></p></li>
                        <?php endif; ?>
                        
                        <!-- Add API Key menu link if plugin active -->
                        <?php if (Plugin::isPluginActiveByName("api_keys")): ?>
                            <li>
                                <a class="api-key-button dropdown-item <?= $page=='api-key'?'active':'' ?>" href="<?= $rootURL ?>/api-keys"><i class="fas fa-key"></i> API Keys</a>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?= $rootURL ?>/logout">Logout</a></li>
                </ul>
            </li>
        <?php else: ?>
            <li class="nav-item dropdown ">
                <a class="nav-link" href="#" id="myAcctDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fa-regular fa-circle-user"></i>
                </a>
                <ul class="dropdown-menu dropdown-menu-end acct-menu" aria-labelledby="myAcctDropdown">
                    <li><a class="dropdown-item" href="<?= $rootURL ?>/login">Login</a></li>
                </ul>
            </li>
        <?php endif; ?>
        <!-- /My Acct Dropdown -->
    </ul> <!-- /Menu Items -->
</div>
