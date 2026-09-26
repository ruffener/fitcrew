<?php

declare(strict_types=1);
require __DIR__ . '/auth_user_operations_support.php';
if (!function_exists('pcntl_fork')) throw new RuntimeException('Concurrency proof requires pcntl (Linux/WSL).');
ob_start();
$p=uop_db();$f=uop_fixture($p);uop_login($f['super']);$r=uop_revision($p,$f['user']);$p=null;
$dir=sys_get_temp_dir().'/fc-uop-race-'.uop_key();mkdir($dir,0700);
function uop_child(string $file, callable $fn): int
{
    $pid=pcntl_fork();if($pid===-1)throw new RuntimeException('Fork failed');
    if($pid===0){
        try{$fn();file_put_contents($file,'success');}
        catch(DomainException $e){file_put_contents($file,'denied:'.$e->getMessage());}
        catch(Throwable $e){file_put_contents($file,'error:'.$e->getMessage());}
        ob_clean(); exit;
    }
    return $pid;
}
try {
    $children=[];
    for($i=0;$i<2;$i++)$children[]=uop_child($dir.'/edit'.$i,fn()=>fc_auth_user_profile_edit(uop_db(),$f['user']['public_id'],['display_name'=>'Concurrent '.$i],$r,uop_key(),'Concurrent edit proof'));
    foreach($children as $pid)pcntl_waitpid($pid,$status);
    $results=[file_get_contents($dir.'/edit0'),file_get_contents($dir.'/edit1')];sort($results);
    uop_assert($results===['denied:stale_user_state','success'],'Two concurrent edits: exactly one succeeds');
    $p=uop_db();uop_assert((int)$p->query("SELECT COUNT(*) FROM audit_events WHERE event_type='USER_PROFILE_EDITED'")->fetchColumn()===1,'One concurrent success audit');
    $f=uop_fixture($p);uop_login($f['super']);$r=uop_revision($p,$f['user']);$p=null;
    $lock=uop_db();$lock->beginTransaction();$lock->prepare("UPDATE users SET platform_role_code='USER' WHERE id=?")->execute([$f['super']['id']]);
    $pid=uop_child($dir.'/demote',fn()=>fc_auth_user_profile_edit(uop_db(),$f['user']['public_id'],['display_name'=>'Denied'],$r,uop_key(),'Authority proof'));
    usleep(200000);uop_assert(!is_file($dir.'/demote'),'Operation waits for current actor authority');
    $lock->commit();pcntl_waitpid($pid,$status);$lock=null;
    uop_assert(file_get_contents($dir.'/demote')==='denied:user_operation_denied','Concurrent demotion defeats stale authority');
    $p=uop_db();$f=uop_fixture($p);uop_login($f['super']);$r=uop_revision($p,$f['user']);$p=null;
    $lock=uop_db();$lock->beginTransaction();$lock->prepare('UPDATE user_sessions SET idle_expires_at=UTC_TIMESTAMP(6)+INTERVAL 1 SECOND WHERE user_id=?')->execute([$f['super']['id']]);
    $pid=uop_child($dir.'/expiry',fn()=>fc_auth_user_sessions_end(uop_db(),$f['user']['public_id'],$r,uop_key(),'Expiry proof'));
    usleep(1200000);$lock->commit();pcntl_waitpid($pid,$status);$lock=null;
    uop_assert(file_get_contents($dir.'/expiry')==='denied:user_operation_denied','Session expiring during row-lock wait cannot mutate');
    $p=uop_db();$f=uop_fixture($p);$p=null;
    $lock=uop_db();$lock->beginTransaction();$lock->prepare("UPDATE users SET account_status='SUSPENDED' WHERE id=?")->execute([$f['user']['id']]);
    $pid=uop_child($dir.'/newsession',fn()=>fc_session_record_create(uop_db(),$f['user']['id'],$f['user']['identity_id'],'concurrent-session',new DateTimeImmutable('+1 hour'),new DateTimeImmutable('+1 day')));
    usleep(200000);uop_assert(!is_file($dir.'/newsession'),'New session waits for target account lock');
    $lock->commit();pcntl_waitpid($pid,$status);$lock=null;
    uop_assert(file_get_contents($dir.'/newsession')==='denied:fitcrew_account_access_denied','Concurrent suspension prevents late session issuance');
    echo "AUTH USER OPERATIONS CONCURRENCY: PASS\n";
} finally { foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir); }
