<?php
declare(strict_types=1);
require __DIR__ . '/admin_test_support.php';
if (!function_exists('pcntl_fork')) { throw new RuntimeException('Concurrency proof requires CLI pcntl (Linux/WSL).'); }
$p=admin_test_db();$f=admin_test_fixture($p);$p=null;
$dir=sys_get_temp_dir().'/fc-admin-race-'.bin2hex(random_bytes(6));mkdir($dir,0700);
function admin_race_child(string $file, callable $call): int
{
    $pid=pcntl_fork();
    if($pid===-1){throw new RuntimeException('Unable to fork');}
    if($pid===0){
        try { $call();file_put_contents($file,'success'); }
        catch(FcAdminDenied $e){file_put_contents($file,'denied:'.$e->reason);}
        catch(PDOException $e){file_put_contents($file,'sql:'.$e->getCode());}
        catch(Throwable $e){file_put_contents($file,'error:'.$e->getMessage());}
        exit;
    }
    return $pid;
}
try {
    // Independent connections compete to promote the same target from the same expected role.
    $children=[];
    for($i=0;$i<2;$i++){$children[]=admin_race_child($dir.'/same'.$i,fn()=>fc_admin_change_role(admin_test_db(),$f['super'],$f['user']['public_id'],'make','USER',true));}
    foreach($children as $pid){pcntl_waitpid($pid,$status);}
    $results=[file_get_contents($dir.'/same0'),file_get_contents($dir.'/same1')];sort($results);
    admin_test_assert($results===['denied:role_changed','success'],'Concurrent promotion: exactly one success');
    $p=admin_test_db();
    admin_test_assert((int)$p->query("SELECT COUNT(*) FROM audit_events WHERE event_type='ADMIN_ROLE_GRANTED' AND outcome='SUCCESS'")->fetchColumn()===1,'Exactly one concurrent success audit');
    $f=admin_test_fixture($p);$p=null;
    // Parent holds a current role update uncommitted; child must wait and reject the stale Auth principal.
    $lock=admin_test_db();$lock->beginTransaction();$lock->exec("UPDATE users SET platform_role_code='USER' WHERE id=1");
    $pid=admin_race_child($dir.'/stale',fn()=>fc_admin_change_role(admin_test_db(),$f['super'],$f['user']['public_id'],'make','USER',true));
    usleep(250000);admin_test_assert(!is_file($dir.'/stale'),'Mutation waits for actor authority lock');
    $lock->commit();pcntl_waitpid($pid,$status);$lock=null;
    admin_test_assert(file_get_contents($dir.'/stale')==='denied:role_required','Current actor demotion defeats stale principal');
    $p=admin_test_db();$f=admin_test_fixture($p);$p->exec("UPDATE users SET platform_role_code='USER' WHERE id=1");$p=null;
    // A session expiring during a lock wait must not retain authority via a pre-wait clock.
    $p=admin_test_db();$f=admin_test_fixture($p);$p=null;
    $lock=admin_test_db();$lock->beginTransaction();
    $lock->exec('UPDATE user_sessions SET idle_expires_at=CURRENT_TIMESTAMP(6)+INTERVAL 1 SECOND WHERE id=1');
    $pid=admin_race_child($dir.'/expiry',fn()=>fc_admin_change_role(admin_test_db(),$f['super'],$f['user']['public_id'],'make','USER',true));
    usleep(1200000);$lock->commit();pcntl_waitpid($pid,$status);$lock=null;
    admin_test_assert(file_get_contents($dir.'/expiry')==='denied:session_inactive','Session expiry during lock wait denies mutation');
    $p=admin_test_db();$f=admin_test_fixture($p);$p->exec("UPDATE users SET platform_role_code='USER' WHERE id=1");$p=null;
    // Competing bootstrap-style updates, bypassing application policy, still face the unique index.
    $children=[];
    foreach([1,3] as $id){$children[]=admin_race_child($dir.'/singleton'.$id,fn()=>admin_test_db()->exec("UPDATE users SET platform_role_code='PLATFORM_SUPER_ADMIN' WHERE id=$id"));}
    foreach($children as $pid){pcntl_waitpid($pid,$status);}
    $results=[file_get_contents($dir.'/singleton1'),file_get_contents($dir.'/singleton3')];sort($results);
    admin_test_assert($results===['sql:23000','success'],'Database singleton survives concurrent direct promotions');
    $p=admin_test_db();admin_test_assert((int)$p->query("SELECT COUNT(*) FROM users WHERE platform_role_code='PLATFORM_SUPER_ADMIN'")->fetchColumn()===1,'At most one Super Admin remains');
    echo "ADMIN CONCURRENCY: PASS\n";
} finally { foreach(glob($dir.'/*') as $file){unlink($file);}rmdir($dir); }
