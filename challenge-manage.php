<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/product/bootstrap.php';
fc_require_login();
header('Cache-Control: private, no-store');
$currentUser = fc_current_user();
$userId = (int) $currentUser['user_id'];
$pdo = fc_db();
$publicId = trim((string) (fc_is_post() ? ($_POST['challenge_public_id'] ?? '') : ($_GET['challenge'] ?? '')));
try {
    $challengeId = fc_family_challenge_id($pdo, $publicId);
    $challenge = fc_challenge_require_owner($pdo, $userId, $challengeId);
} catch (DomainException) {
    fc_response_code(404);
    exit('Challenge is unavailable.');
}
if (fc_is_post()) {
    if (!fc_validate_csrf($_POST['csrf_token'] ?? null)) { fc_response_code(403); exit('Forbidden'); }
    try {
        $action = (string) ($_POST['action'] ?? '');
        if (in_array($action, ['end', 'archive', 'delete', 'unarchive'], true) && ($_POST['confirm_action'] ?? '') !== $action) {
            throw new DomainException('Confirm the selected Challenge action first.');
        }
        fc_challenge_manage($pdo, $userId, $challengeId, $action, [
            'display_name' => $_POST['display_name'] ?? '', 'reason' => $_POST['reason'] ?? '',
            'expected_revision' => (int) ($_POST['management_revision'] ?? -1),
        ]);
        $messages = [
            'rename' => 'Challenge name saved.',
            'end' => 'Challenge end recorded. This does not create a final competitive result.',
            'archive' => 'Challenge archived. Participation and history have not been deleted.',
            'unarchive' => 'Challenge removed from the archive. Its end and deletion records, if any, are unchanged.',
            'delete' => 'Challenge removed from active use. Required history and participant rights are preserved.',
        ];
        fc_flash('success', $messages[$action] ?? 'Challenge updated.');
    } catch (Throwable $error) {
        fc_flash('error', $error instanceof DomainException || $error instanceof InvalidArgumentException
            ? $error->getMessage() : 'FitCrew could not complete that management action.');
    }
    fc_redirect('/challenge-manage.php?challenge=' . rawurlencode($publicId));
}
$challenge = fc_challenge_require_owner($pdo, $userId, $challengeId);
$management = fc_challenge_management_state($pdo, $challengeId);
$events = $pdo->prepare('SELECT e.event_code,e.occurred_at,u.display_name AS actor_name FROM challenge_product_events e JOIN users u ON u.id=e.actor_user_id WHERE e.challenge_id=:c AND e.subject_user_id IS NULL ORDER BY e.id DESC LIMIT 30');
$events->execute([':c' => $challengeId]);
$ownerHistory = $events->fetchAll(PDO::FETCH_ASSOC);
$appContext = fc_product_context($pdo, $userId);
$appSection = 'challenge';
$title = 'Manage Challenge';
$contentView = 'views/app/challenge/manage.php';
require fc_path('views/layouts/app.php');
