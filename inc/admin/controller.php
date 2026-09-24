<?php
declare(strict_types=1);

function fc_admin_dispatch(string $route): void
{
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');
    $titles = ['index' => 'Dashboard', 'users' => 'Users', 'user' => 'User detail', 'crews' => 'Crews',
        'crew' => 'Crew detail', 'invitations' => 'Invitations', 'invitation' => 'Invitation detail',
        'authentication' => 'Authentication summary', 'system' => 'System', 'admins' => 'Admins', 'role' => 'Confirm Admin role change'];
    $title = $titles[$route] ?? 'Admin';
    $actor = $pdo = $principal = null;
    $contentView = 'views/admin/error.php';
    try {
        if (!isset($titles[$route])) { throw new FcAdminDenied('not_found', 404); }
        $pdo = fc_db();
        $principal = fc_current_user(); // Auth is the sole source of the authenticated principal.
        if ($principal === null) {
            fc_admin_audit($pdo, null, 'ADMIN_ENTRY', 'DENIED', null, null, null, $route, 'sign_in_required');
            fc_flash('notice', 'Sign in, then open Admin again to continue.');
            header('Location: /login.php', true, 303);
            return;
        }
        $actor = fc_admin_enter($pdo, $principal, $route, in_array($route, ['admins', 'role'], true));
        $method = fc_request_method();
        if (!in_array($method, $route === 'role' ? ['GET', 'POST'] : ['GET'], true)) {
            header('Allow: ' . ($route === 'role' ? 'GET, POST' : 'GET'));
            fc_admin_audit($pdo, (int) $actor['id'], 'ADMIN_REQUEST_REJECTED', 'DENIED', null, null, null, $route, 'method_not_allowed');
            throw new FcAdminDenied('method_not_allowed', 405);
        }
        $contentView = 'views/admin/' . ($route === 'index' ? 'dashboard' : $route) . '.php';
        if (in_array($route, ['users', 'crews', 'invitations', 'admins'], true)) {
            $query = fc_admin_input($_GET, 'q');
            $pageText = fc_admin_input($_GET, 'page', 5);
            $page = $pageText === '' ? 1 : (ctype_digit($pageText) ? (int) $pageText : 0);
            if ($page < 1 || $page > 10000) { throw new FcAdminDenied('invalid_input', 400); }
            $rows = fc_admin_list($pdo, $route, $query, $page);
            $more = count($rows) > 50; $rows = array_slice($rows, 0, 50);
        } elseif ($route === 'index') {
            $stats = fc_admin_dashboard($pdo); $audit = fc_admin_audit_rows($pdo);
        } elseif ($route === 'user') {
            $user = fc_admin_user($pdo, fc_admin_id(fc_admin_input($_GET, 'id', 26)));
            if ($user === null) { throw new FcAdminDenied('not_found', 404); }
            $detail = fc_admin_user_details($pdo, $user);
        } elseif ($route === 'crew') {
            $crew = fc_admin_crew($pdo, fc_admin_id(fc_admin_input($_GET, 'id', 26)));
            if ($crew === null) { throw new FcAdminDenied('not_found', 404); }
            $detail = fc_admin_crew_details($pdo, $crew);
        } elseif ($route === 'invitation') {
            $invitation = fc_admin_invitation($pdo, fc_admin_id(fc_admin_input($_GET, 'id', 26)));
            if ($invitation === null) { throw new FcAdminDenied('not_found', 404); }
            $audit = fc_admin_audit_rows($pdo, 'invitation', (int) $invitation['id']);
        } elseif ($route === 'authentication') {
            $summary = fc_admin_authentication($pdo);
        } elseif ($route === 'system') {
            $system = fc_admin_system($pdo);
        } elseif ($route === 'role') {
            if ($method === 'POST') {
                $csrf = $_POST['csrf_token'] ?? null;
                if (!is_string($csrf) || !fc_validate_csrf($csrf)) {
                    fc_admin_audit($pdo, (int) $actor['id'], 'ADMIN_ROLE_REJECTED', 'DENIED', null, null, null, 'role', 'csrf_failed');
                    throw new FcAdminDenied('csrf_failed');
                }
                $targetId = fc_admin_id(fc_admin_input($_POST, 'target', 26));
                $action = fc_admin_input($_POST, 'action', 10);
                $oldRole = fc_admin_input($_POST, 'old_role', 32);
                $confirmation = fc_admin_input($_POST, 'confirmation', 64);
                $confirmed = fc_admin_input($_POST, 'confirm', 3) === 'yes'
                    && fc_admin_confirmation_consume($principal, $confirmation, $targetId, $action, $oldRole);
                fc_admin_change_role($pdo, $principal, $targetId, $action, $oldRole, $confirmed);
                fc_flash('success', $action === 'make' ? 'Admin access granted.' : 'Admin access removed.');
                header('Location: /admin/user.php?id=' . rawurlencode($targetId), true, 303);
                return;
            }
            $target = fc_admin_user($pdo, fc_admin_id(fc_admin_input($_GET, 'id', 26)));
            $action = fc_admin_input($_GET, 'action', 10);
            if ($target === null) { throw new FcAdminDenied('not_found', 404); }
            if ($target['platform_role_code'] === 'PLATFORM_SUPER_ADMIN') { throw new FcAdminDenied('super_admin_protected'); }
            if (!in_array($action, ['make', 'remove'], true)) { throw new FcAdminDenied('invalid_input', 400); }
            $oldRole = $action === 'make' ? 'USER' : 'PLATFORM_ADMIN';
            if ($target['platform_role_code'] !== $oldRole || ($action === 'make' && $target['account_status'] !== 'ACTIVE')) {
                throw new FcAdminDenied('role_changed', 409);
            }
            $confirmation = fc_admin_confirmation_issue($principal, $target, $action);
            $contentView = 'views/admin/role_confirmation.php';
        }
    } catch (FcAdminDenied $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        http_response_code($e->status);
        $errorMessage = match ($e->reason) {
            'role_changed', 'target_inactive' => 'This account changed or is not eligible. Open its details and review the current status.',
            'confirmation_required', 'csrf_failed' => 'The confirmation is missing or expired. Open the user and confirm the change again.',
            'not_found', 'target_missing' => 'That record was not found.',
            'invalid_input', 'invalid_action' => 'Please check the request and try again.',
            'method_not_allowed' => 'This page does not accept that request method.',
            'super_admin_protected' => 'Super Admin authority cannot be changed here.',
            default => 'Your current account does not have access to this Admin area.',
        };
        $contentView = 'views/admin/error.php';
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        http_response_code(503);
        $errorMessage = 'Admin is temporarily unavailable. Please try again later.';
        $contentView = 'views/admin/error.php';
        fc_log('error', 'Admin request failed.', ['reason' => 'admin_request_failed']);
    }
    require fc_path('views/admin/layout.php');
}
