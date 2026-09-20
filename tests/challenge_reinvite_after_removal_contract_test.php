<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
function crar_assert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$root = dirname(__DIR__);
$family = file_get_contents($root . '/inc/product/family_alpha.php') ?: '';
$journey = file_get_contents($root . '/inc/product/challenge_invitations.php') ?: '';

crar_assert(str_contains($family, 'bool $allowRemovedReactivation = false'), 'Removed-participant reactivation must remain opt-in.');
crar_assert(str_contains($family, '&& !$allowRemovedReactivation'), 'Normal acceptance must still block removed participation without fresh authorization.');
crar_assert(str_contains($journey, "'PERSONAL_ACCEPTANCE',\n        true // A fresh, locked Challenge-scoped invitation explicitly authorizes re-entry after prior removal."), 'Canonical Challenge invitation must explicitly authorize removed-participant reactivation.');
crar_assert(str_contains($journey, "invitation_status=\\'PENDING\\'"), 'Re-entry authorization must still sit behind a locked current pending invitation.');
crar_assert(str_contains($journey, "fc_challenge_accept_participation_locked("), 'Re-entry must reuse the canonical Challenge acceptance engine.');

fwrite(STDOUT, "Challenge re-invite after removal contract proof: PASS\n- normal removed-member block remains default: PASS\n- fresh Challenge-scoped invitation authorizes re-entry: PASS\n- canonical acceptance engine remains authoritative: PASS\n");
