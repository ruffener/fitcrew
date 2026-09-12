<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__) . '/inc/config/env.php';
$capturedLogs=[];
function fc_log(string $level,string $message,array $context=[]):void { global $capturedLogs; $capturedLogs[]=['level'=>$level,'message'=>$message,'context'=>$context]; }
require_once dirname(__DIR__) . '/inc/mail/mail.php';
require_once dirname(__DIR__) . '/inc/mail/templates/crew_invitation.php';
function mail_assert(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
$_ENV['MAIL_DRIVER']='postmark';
$_ENV['POSTMARK_SERVER_TOKEN']='test-secret-never-log';
$msg=fc_mail_validate(fc_mail_crew_invitation_message('invitee@example.com','Brad Ruffener','Ruffener Crew','https://fitcrewchallenge.com/crew-invite.php?token=secret-token'));
mail_assert($msg['from_name']==='Brad via FitCrew Challenge','Canonical From Name mismatch.');
mail_assert($msg['from_email']==='hello@fitcrewchallenge.com','Canonical From address mismatch.');
mail_assert($msg['reply_to']==='hello@fitcrewchallenge.com','Canonical Reply-To mismatch.');
mail_assert($msg['subject']==='Brad invited you to Ruffener Crew','Canonical invitation subject mismatch.');
mail_assert(str_contains($msg['text_body'],'secret-token') && str_contains($msg['html_body'],'secret-token'),'Both message representations must contain the invitation destination.');
$seen=[];
$result=fc_mail_postmark_transport($msg,static function(string $url,array $headers,string $body) use (&$seen):array {
    $seen=['url'=>$url,'headers'=>$headers,'body'=>$body];
    return ['status'=>200,'body'=>json_encode(['ErrorCode'=>0,'MessageID'=>'pm-test-id'])];
});
mail_assert($result['accepted'] && $result['message_id']==='pm-test-id','Postmark accepted response mismatch.');
mail_assert($seen['url']==='https://api.postmarkapp.com/email','Postmark endpoint mismatch.');
mail_assert(($seen['headers']['X-Postmark-Server-Token']??'')==='test-secret-never-log','Postmark token must be sent only in transport header.');
$payload=json_decode($seen['body'],true,512,JSON_THROW_ON_ERROR);
mail_assert($payload['TrackOpens']===false && $payload['TrackLinks']==='None','Invitation tracking must remain disabled.');
$logs=json_encode($capturedLogs,JSON_THROW_ON_ERROR);
mail_assert(!str_contains($logs,'test-secret-never-log') && !str_contains($logs,'secret-token'),'Tokens must never be logged.');
try {
    fc_mail_postmark_transport($msg,static fn():array=>['status'=>422,'body'=>json_encode(['ErrorCode'=>300,'Message'=>'bad'])]);
    throw new RuntimeException('Rejected transport response was accepted.');
} catch (RuntimeException $e) {
    mail_assert($e->getMessage()==='Transactional email could not be sent.','Transport must return sanitized failure.');
}
fwrite(STDOUT,"Mail transport unit proof: PASS\n- Canonical Crew invitation sender/subject: PASS\n- HTML + text bodies: PASS\n- Postmark token environment boundary / no logging: PASS\n- Open/link tracking disabled: PASS\n- Sanitized send failure: PASS\n");
