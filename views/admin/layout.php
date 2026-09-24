<?php $adminAuthorized = fc_admin_is_admin($actor); ?>
<!doctype html>
<html lang="en">
<head>
    <?php require fc_path('views/partials/head.php'); ?>
    <link rel="stylesheet" href="/admin/assets/admin.css?v=<?= (int) filemtime(fc_path('admin/assets/admin.css')) ?>">
</head>
<body class="admin-body">
<a class="admin-skip" href="#admin-content">Skip to content</a>
<header class="site-header site-header-app">
    <div class="site-header-inner">
        <a class="brand-lockup" href="/app.php" aria-label="FitCrew Challenge application">
            <img class="brand-lockup-mark" src="/assets/img/brand/fitcrew-app-icon-navy-reference.png" alt="">
            <span class="brand-lockup-type" aria-hidden="true"><strong><span>FIT</span><em>CREW</em></strong><small>CHALLENGE</small></span>
        </a>
        <div class="admin-account"><span>Platform administration</span>
            <?php if ($adminAuthorized): ?><strong><?= fc_admin_text($actor['display_name']) ?> · <?= fc_e(fc_admin_role_label($actor['platform_role_code'])) ?></strong><?php endif; ?>
        </div>
        <a class="admin-back" href="/app.php">Back to FitCrew</a>
    </div>
</header>
<div class="admin-shell">
<?php if ($adminAuthorized): ?>
    <aside class="admin-sidebar">
        <p class="sidebar-label">Admin</p>
        <nav class="sidebar-nav" aria-label="Admin navigation">
        <?php foreach (['index'=>'Dashboard','users'=>'Users','crews'=>'Crews','invitations'=>'Invitations','authentication'=>'Authentication','system'=>'System'] as $key=>$label): ?>
            <?php $selected = $route === $key || ($key === 'users' && $route === 'user') || ($key === 'crews' && $route === 'crew') || ($key === 'invitations' && $route === 'invitation'); ?>
            <a href="/admin/<?= $key === 'index' ? '' : $key . '.php' ?>" <?= $selected ? 'class="is-current" aria-current="page"' : '' ?>><?= fc_e($label) ?></a>
        <?php endforeach; ?>
        <?php if (fc_admin_is_super($actor)): ?><a href="/admin/admins.php" <?= in_array($route,['admins','role'],true) ? 'class="is-current" aria-current="page"' : '' ?>>Admins</a><?php endif; ?>
        </nav>
        <form method="post" action="/logout.php"><?= fc_csrf_input() ?><button class="admin-signout" type="submit">Sign out</button></form>
    </aside>
<?php endif; ?>
    <main id="admin-content" class="admin-content">
        <?php require fc_path('views/partials/flash.php'); ?>
        <div class="admin-page-heading"><p class="eyebrow">FitCrew Admin</p><h1><?= fc_e($title) ?></h1></div>
        <?php require fc_path($contentView); ?>
        <p class="admin-time-note">All operational timestamps are shown in UTC.</p>
    </main>
</div>
</body>
</html>
