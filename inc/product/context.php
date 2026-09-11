<?php

declare(strict_types=1);

/** @return array<string,mixed> */
function fc_product_context(PDO $pdo, int $userId): array
{
    $crews = fc_crews_for_user($pdo, $userId);
    $storedStatement = $pdo->prepare('SELECT * FROM user_product_contexts WHERE user_id = :user_id LIMIT 1');
    $storedStatement->execute([':user_id' => $userId]);
    $stored = $storedStatement->fetch(PDO::FETCH_ASSOC) ?: null;

    $crew = null;
    $storedCrewId = $stored !== null && $stored['selected_crew_id'] !== null ? (int) $stored['selected_crew_id'] : null;
    foreach ($crews as $candidate) {
        if ($storedCrewId !== null && (int) $candidate['id'] === $storedCrewId) {
            $crew = $candidate;
            break;
        }
    }
    if ($crew === null && $crews !== []) {
        $crew = $crews[0];
    }

    $challenges = $crew !== null ? fc_challenges_for_user($pdo, $userId, (int) $crew['id']) : [];
    $challenge = null;
    $storedChallengeId = $stored !== null && $stored['selected_challenge_id'] !== null ? (int) $stored['selected_challenge_id'] : null;
    foreach ($challenges as $candidate) {
        if ($storedChallengeId !== null && (int) $candidate['id'] === $storedChallengeId) {
            $challenge = $candidate;
            break;
        }
    }

    $resolvedCrewId = $crew !== null ? (int) $crew['id'] : null;
    $resolvedChallengeId = $challenge !== null ? (int) $challenge['id'] : null;
    if ($stored === null || $storedCrewId !== $resolvedCrewId || $storedChallengeId !== $resolvedChallengeId) {
        fc_product_context_persist($pdo, $userId, $resolvedCrewId, $resolvedChallengeId);
    }

    return [
        'crews' => $crews,
        'crew' => $crew,
        'challenges' => $challenges,
        'challenge' => $challenge,
    ];
}

function fc_product_context_persist(PDO $pdo, int $userId, ?int $crewId, ?int $challengeId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO user_product_contexts (user_id, selected_crew_id, selected_challenge_id) ' .
        'VALUES (:user_id, :crew_id, :challenge_id) ' .
        'ON DUPLICATE KEY UPDATE selected_crew_id = VALUES(selected_crew_id), selected_challenge_id = VALUES(selected_challenge_id)'
    );
    $statement->execute([':user_id' => $userId, ':crew_id' => $crewId, ':challenge_id' => $challengeId]);
}

function fc_product_context_select_crew(PDO $pdo, int $userId, int $crewId): void
{
    fc_crew_require_member($pdo, $userId, $crewId);
    // Selecting a Crew chooses the Crew only. A Challenge remains unselected until
    // the user explicitly opens one from Overview, Crew, or the Challenge list.
    fc_product_context_persist($pdo, $userId, $crewId, null);
}

function fc_product_context_select_challenge(PDO $pdo, int $userId, int $challengeId): void
{
    $challenge = fc_challenge_require_access($pdo, $userId, $challengeId);
    fc_crew_require_member($pdo, $userId, (int) $challenge['crew_id']);
    fc_product_context_persist($pdo, $userId, (int) $challenge['crew_id'], $challengeId);
}

function fc_product_context_handle_selection(PDO $pdo, int $userId, array $source): bool
{
    $crewPublicId = trim((string) ($source['select_crew'] ?? ''));
    if ($crewPublicId !== '') {
        $crew = fc_crew_require_public($pdo, $userId, $crewPublicId);
        fc_product_context_select_crew($pdo, $userId, (int) $crew['id']);
        return true;
    }

    $challengePublicId = trim((string) ($source['select_challenge'] ?? ''));
    if ($challengePublicId !== '') {
        $challenge = fc_challenge_require_public($pdo, $userId, $challengePublicId);
        fc_product_context_select_challenge($pdo, $userId, (int) $challenge['id']);
        return true;
    }

    return false;
}
