<?php

declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit('Not Found');}
require_once dirname(__DIR__).'/inc/bootstrap.php';
require_once dirname(__DIR__).'/inc/product/bootstrap.php';
function p3db_assert(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function p3db_denied(callable $fn,string $message):void{try{$fn();}catch(DomainException|InvalidArgumentException|LogicException){return;}throw new RuntimeException($message.': denial expected');}
if(!in_array('mysql',PDO::getAvailableDrivers(),true)){fwrite(STDERR,"[BLOCKED] PDO MySQL driver is required for the PASS 3 DB proof.\n");exit(2);}
$pdo=fc_db();$pdo->beginTransaction();
try{
    $owner=fc_user_create($pdo,'PASS3 Owner');
    $crew=fc_crew_create($pdo,(int)$owner['id'],'PASS3 Crew');
    $challenge=fc_challenge_create($pdo,(int)$owner['id'],(int)$crew['id'],'PASS3 Challenge',['planned_start_date'=>'2100-01-01']);
    $draft=fc_challenge_rule_current_draft($pdo,(int)$challenge['id']);
    fc_challenge_rule_publish($pdo,(int)$owner['id'],(int)$challenge['id'],(int)$draft['id']);

    $invite=fc_crew_invitation_create($pdo,(int)$owner['id'],(int)$crew['id'],(int)$challenge['id'],'alpha@example.com');
    $state=$pdo->prepare('SELECT transport_status,transport_driver,transport_message_id,sent_at,resend_count FROM crew_invitations WHERE public_id=?');
    $state->execute([$invite['public_id']]);$row=$state->fetch(PDO::FETCH_ASSOC);
    p3db_assert($row && $row['transport_status']==='PENDING_SEND' && $row['sent_at']===null,'New invitation must start PENDING_SEND without sent_at.');
    fc_crew_invitation_deliver($pdo,$invite,static fn(array $m):array=>['accepted'=>true,'driver'=>'postmark','message_id'=>'pm-pass3']);
    $state->execute([$invite['public_id']]);$row=$state->fetch(PDO::FETCH_ASSOC);
    p3db_assert($row && $row['transport_status']==='TRANSPORT_ACCEPTED' && $row['transport_driver']==='postmark' && $row['transport_message_id']==='pm-pass3' && $row['sent_at']!==null,'Accepted transport truth missing.');
    $resend=fc_crew_invitation_resend($pdo,(int)$owner['id'],(int)$crew['id'],(string)$invite['public_id']);
    p3db_assert((int)$resend['generation']===1,'Resend must advance generation.');
    p3db_assert(fc_crew_invitation_auth_snapshot($pdo,(string)$invite['public_id'],0)===null,'Resend must invalidate prior generation.');
    p3db_assert(fc_crew_invitation_auth_snapshot($pdo,(string)$invite['public_id'],1)!==null,'New resend generation must be current.');
    p3db_denied(fn()=>fc_crew_invitation_deliver($pdo,$resend,static function(array $m):array{throw new RuntimeException('synthetic');}),'Synthetic transport failure');
    $state->execute([$invite['public_id']]);$row=$state->fetch(PDO::FETCH_ASSOC);
    p3db_assert($row && $row['transport_status']==='TRANSPORT_FAILED' && $row['sent_at']===null,'Failed transport must not look sent.');

    $cancel=fc_crew_invitation_create($pdo,(int)$owner['id'],(int)$crew['id'],(int)$challenge['id'],'cancel@example.com');
    fc_crew_invitation_cancel($pdo,(int)$owner['id'],(int)$crew['id'],(string)$cancel['public_id']);
    p3db_assert(fc_crew_invitation_auth_snapshot($pdo,(string)$cancel['public_id'],0)===null,'Cancelled invitation must fail snapshot.');
    $expired=fc_crew_invitation_create($pdo,(int)$owner['id'],(int)$crew['id'],(int)$challenge['id'],'expired@example.com');
    $pdo->prepare('UPDATE crew_invitations SET expires_at=DATE_SUB(CURRENT_TIMESTAMP(6),INTERVAL 1 SECOND) WHERE public_id=?')->execute([$expired['public_id']]);
    p3db_assert(fc_crew_invitation_auth_snapshot($pdo,(string)$expired['public_id'],0)===null,'Expired invitation must fail snapshot.');

    $rateOwner=(int)$owner['id']+900000;
    for($i=0;$i<10;$i++)fc_crew_invitation_rate_limit_issue($pdo,$rateOwner);
    p3db_denied(fn()=>fc_crew_invitation_rate_limit_issue($pdo,$rateOwner),'Issue limiter');
    $rateInvitation='01PASS3RATELIMITRESEND000';
    for($i=0;$i<5;$i++)fc_crew_invitation_rate_limit_resend($pdo,$rateOwner,$rateInvitation);
    p3db_denied(fn()=>fc_crew_invitation_rate_limit_resend($pdo,$rateOwner,$rateInvitation),'Resend limiter');
    $raw='network:198.51.100.77|agent:'.hash('sha256','PASS3 Agent').'|do-not-persist-raw';
    for($i=0;$i<20;$i++)fc_crew_invitation_rate_limit_invalid_raw($pdo,$raw);
    p3db_denied(fn()=>fc_crew_invitation_rate_limit_invalid_raw($pdo,$raw),'Invalid raw lookup limiter');
    $bucket=$pdo->query('SELECT bucket_key_hash FROM security_rate_limit_buckets ORDER BY updated_at DESC LIMIT 1')->fetchColumn();
    p3db_assert(is_string($bucket)&&strlen($bucket)===64&&!str_contains($bucket,'do-not-persist-raw'),'Rate limiter must persist only keyed evidence.');
    $pdo->rollBack();
    fwrite(STDOUT,"Crew invitation PASS 3 DB proof: PASS\n- accepted/failed/resend transport truth: PASS\n- stale generation / cancelled / expired snapshot rejection: PASS\n- issue/resend/invalid-token rate limits: PASS\n- test data rolled back: PASS\n");
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,'[FAIL] '.$e->getMessage().PHP_EOL);exit(1);}
