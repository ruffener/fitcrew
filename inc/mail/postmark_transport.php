<?php

declare(strict_types=1);

/**
 * @param array<string,mixed> $message
 * @param null|callable(string,array<string,string>,string):array{status:int,body:string} $http
 * @return array{accepted:bool,driver:string,message_id:?string}
 */
function fc_mail_postmark_transport(array $message, ?callable $http = null): array
{
    $config = fc_mail_config();
    $token = $config['postmark_server_token'];
    if ($token === '') {
        throw new RuntimeException('Postmark mail transport is not configured.');
    }

    $payload = [
        'From' => sprintf('%s <%s>', (string) $message['from_name'], (string) $message['from_email']),
        'To' => (string) $message['to'],
        'ReplyTo' => (string) $message['reply_to'],
        'Subject' => (string) $message['subject'],
        'TextBody' => (string) $message['text_body'],
        'HtmlBody' => (string) $message['html_body'],
        'TrackOpens' => false,
        'TrackLinks' => 'None',
        'MessageStream' => 'outbound',
    ];
    if ((string) ($message['tag'] ?? '') !== '') {
        $payload['Tag'] = (string) $message['tag'];
    }
    $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $headers = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'X-Postmark-Server-Token' => $token,
    ];

    if ($http === null) {
        $http = static function (string $url, array $headers, string $body): array {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('PHP cURL is required for Postmark transport.');
            }
            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }
            $curl = curl_init($url);
            if ($curl === false) {
                throw new RuntimeException('Unable to initialize Postmark transport.');
            }
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_POSTFIELDS => $body,
            ]);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $error = curl_error($curl);
            curl_close($curl);
            if ($response === false) {
                throw new RuntimeException('Postmark transport request failed.' . ($error !== '' ? ' Network error.' : ''));
            }
            return ['status' => $status, 'body' => (string) $response];
        };
    }

    $response = $http('https://api.postmarkapp.com/email', $headers, $body);
    $decoded = json_decode((string) $response['body'], true);
    if (!is_array($decoded)) {
        $decoded = [];
    }
    $errorCode = isset($decoded['ErrorCode']) ? (int) $decoded['ErrorCode'] : null;
    if ((int) $response['status'] < 200 || (int) $response['status'] >= 300 || $errorCode !== 0) {
        fc_log('warning', 'Transactional email transport rejected a message.', [
            'mail_driver' => 'postmark',
            'http_status' => (int) $response['status'],
            'postmark_error_code' => $errorCode,
            'message_tag' => (string) ($message['tag'] ?? ''),
        ]);
        throw new RuntimeException('Transactional email could not be sent.');
    }

    $messageId = isset($decoded['MessageID']) ? trim((string) $decoded['MessageID']) : '';
    fc_log('info', 'Transactional email accepted by transport.', [
        'mail_driver' => 'postmark',
        'message_tag' => (string) ($message['tag'] ?? ''),
        'message_id' => $messageId,
    ]);
    return ['accepted' => true, 'driver' => 'postmark', 'message_id' => $messageId !== '' ? $messageId : null];
}
