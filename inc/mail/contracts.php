<?php

declare(strict_types=1);

const FC_MAIL_FROM_ADDRESS = 'hello@fitcrewchallenge.com';
const FC_MAIL_REPLY_TO = 'hello@fitcrewchallenge.com';

/** @return array{driver:string,postmark_server_token:string} */
function fc_mail_config(): array
{
    $driver = strtolower(trim((string) fc_env('MAIL_DRIVER', 'log')));
    if (!in_array($driver, ['log', 'postmark'], true)) {
        throw new RuntimeException('Unsupported FitCrew mail driver.');
    }

    return [
        'driver' => $driver,
        'postmark_server_token' => trim((string) fc_env('POSTMARK_SERVER_TOKEN', '')),
    ];
}

/** @param array<string,mixed> $message */
function fc_mail_validate(array $message): array
{
    foreach (['to','from_name','subject','text_body','html_body'] as $field) {
        if (!isset($message[$field]) || trim((string) $message[$field]) === '') {
            throw new InvalidArgumentException('Mail message is missing ' . $field . '.');
        }
    }
    if (filter_var((string) $message['to'], FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Mail recipient is not a valid email address.');
    }

    $message['from_email'] = FC_MAIL_FROM_ADDRESS;
    $message['reply_to'] = FC_MAIL_REPLY_TO;
    $message['tag'] = isset($message['tag']) ? trim((string) $message['tag']) : '';

    return $message;
}
