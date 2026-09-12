<?php

declare(strict_types=1);

require_once __DIR__ . '/contracts.php';
require_once __DIR__ . '/log_transport.php';
require_once __DIR__ . '/postmark_transport.php';

/** @param array<string,mixed> $message
 *  @return array{accepted:bool,driver:string,message_id:?string}
 */
function fc_mail_send(array $message): array
{
    $message = fc_mail_validate($message);
    $config = fc_mail_config();
    return match ($config['driver']) {
        'log' => fc_mail_log_transport($message),
        'postmark' => fc_mail_postmark_transport($message),
        default => throw new RuntimeException('Unsupported FitCrew mail driver.'),
    };
}
