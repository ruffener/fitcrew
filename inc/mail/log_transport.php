<?php

declare(strict_types=1);

/** @param array<string,mixed> $message
 *  @return array{accepted:bool,driver:string,message_id:?string}
 */
function fc_mail_log_transport(array $message): array
{
    $safeRecipient = hash('sha256', strtolower(trim((string) $message['to'])));
    fc_log('info', 'Transactional email captured by log transport.', [
        'mail_driver' => 'log',
        'message_tag' => (string) ($message['tag'] ?? ''),
        'recipient_hash' => $safeRecipient,
    ]);

    return ['accepted' => true, 'driver' => 'log', 'message_id' => null];
}
