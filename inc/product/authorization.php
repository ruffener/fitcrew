<?php

declare(strict_types=1);

function fc_product_access_denied(string $message = 'You do not have access to this FitCrew area.'): never
{
    throw new DomainException($message);
}

/** @return array<string,mixed> */
function fc_crew_require_member(PDO $pdo, int $userId, int $crewId): array
{
    $statement = $pdo->prepare(
        'SELECT c.*, m.role_code AS membership_role, m.membership_status ' .
        'FROM crews c ' .
        'JOIN crew_memberships m ON m.crew_id = c.id AND m.user_id = :user_id ' .
        'WHERE c.id = :crew_id AND c.crew_status = \'ACTIVE\' AND m.membership_status = \'ACTIVE\' ' .
        'LIMIT 1'
    );
    $statement->execute([':user_id' => $userId, ':crew_id' => $crewId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        fc_product_access_denied('Crew access denied.');
    }

    return $row;
}

/** @return array<string,mixed> */
function fc_crew_require_owner(PDO $pdo, int $userId, int $crewId): array
{
    $crew = fc_crew_require_member($pdo, $userId, $crewId);
    if ((int) $crew['owner_user_id'] !== $userId || (string) $crew['membership_role'] !== 'OWNER') {
        fc_product_access_denied('Crew Owner authority required.');
    }

    return $crew;
}

function fc_challenge_user_has_access(PDO $pdo, int $userId, int $challengeId): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 ' .
        'FROM challenges c ' .
        'LEFT JOIN challenge_participations p ' .
        '  ON p.challenge_id = c.id AND p.user_id = :participant_user_id AND p.participation_status IN (\'ACTIVE\', \'WITHDRAWN\') ' .
        'WHERE c.id = :challenge_id ' .
        '  AND (c.owner_user_id = :owner_user_id OR p.id IS NOT NULL) ' .
        'LIMIT 1'
    );
    $statement->execute([
        ':participant_user_id' => $userId,
        ':challenge_id' => $challengeId,
        ':owner_user_id' => $userId,
    ]);

    return $statement->fetchColumn() !== false;
}

/** @return array<string,mixed> */
function fc_challenge_require_access(PDO $pdo, int $userId, int $challengeId): array
{
    $statement = $pdo->prepare(
        'SELECT c.*, cr.public_id AS crew_public_id, cr.display_name AS crew_name ' .
        'FROM challenges c JOIN crews cr ON cr.id = c.crew_id ' .
        'WHERE c.id = :challenge_id LIMIT 1'
    );
    $statement->execute([':challenge_id' => $challengeId]);
    $challenge = $statement->fetch(PDO::FETCH_ASSOC);

    if ($challenge === false || !fc_challenge_user_has_access($pdo, $userId, $challengeId)) {
        fc_product_access_denied('Challenge access denied.');
    }

    return $challenge;
}

/** @return array<string,mixed> */
function fc_challenge_require_owner(PDO $pdo, int $userId, int $challengeId): array
{
    $statement = $pdo->prepare(
        'SELECT c.*, cr.public_id AS crew_public_id, cr.display_name AS crew_name ' .
        'FROM challenges c JOIN crews cr ON cr.id = c.crew_id ' .
        'WHERE c.id = :challenge_id AND c.owner_user_id = :user_id LIMIT 1'
    );
    $statement->execute([':challenge_id' => $challengeId, ':user_id' => $userId]);
    $challenge = $statement->fetch(PDO::FETCH_ASSOC);
    if ($challenge === false) {
        fc_product_access_denied('Challenge Owner authority required.');
    }

    return $challenge;
}
