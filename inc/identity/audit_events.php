<?php

declare(strict_types=1);

/** @return array{id:int} */
function fc_audit_event_write(PDO $pdo, array $event): array
{
    $outcome = fc_contract_value((string) ($event['outcome'] ?? ''), FC_AUDIT_OUTCOMES, 'audit outcome');
    $eventType = trim((string) ($event['event_type'] ?? ''));
    if ($eventType === '') {
        throw new InvalidArgumentException('Audit event_type is required.');
    }

    $metadata = fc_redact_sensitive_context((array) ($event['metadata'] ?? []));
    $metadataJson = $metadata === []
        ? null
        : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $statement = $pdo->prepare(
        'INSERT INTO audit_events ( ' .
        ' actor_user_id, event_type, target_type, target_id, outcome, request_id, metadata_json, group_id, client_evidence_hash ' .
        ') VALUES ( ' .
        ' :actor_user_id, :event_type, :target_type, :target_id, :outcome, :request_id, :metadata_json, :group_id, :client_hash ' .
        ')'
    );
    $statement->execute([
        ':actor_user_id' => $event['actor_user_id'] ?? null,
        ':event_type' => $eventType,
        ':target_type' => fc_nullable_trimmed($event['target_type'] ?? null),
        ':target_id' => fc_nullable_trimmed($event['target_id'] ?? null),
        ':outcome' => $outcome,
        ':request_id' => fc_nullable_trimmed($event['request_id'] ?? null),
        ':metadata_json' => $metadataJson,
        ':group_id' => $event['group_id'] ?? null,
        ':client_hash' => isset($event['raw_client_evidence']) && $event['raw_client_evidence'] !== null
            ? fc_secret_evidence_hash((string) $event['raw_client_evidence'])
            : null,
    ]);

    return ['id' => (int) $pdo->lastInsertId()];
}
