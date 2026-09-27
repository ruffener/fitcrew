<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
ob_start();
require_once __DIR__.'/admin_test_support.php';
require_once __DIR__.'/auth_user_operations_support.php';
require_once dirname(__DIR__).'/inc/product/bootstrap.php';
require_once dirname(__DIR__).'/inc/admin/operations.php';
require_once dirname(__DIR__).'/inc/admin/operation_forms.php';
function a2_db(): PDO {
    $dsn=getenv('FC_ADMIN2_TEST_DSN')?:'';
    if(!preg_match('/\Amysql:host=127\.0\.0\.1;port=[0-9]+;dbname=fitcrew_admin2_test;charset=utf8mb4\z/',$dsn)) throw new RuntimeException('Dedicated loopback fitcrew_admin2_test required.');
    $p=new PDO($dsn,getenv('FC_ADMIN2_TEST_USER')?:'root',getenv('FC_ADMIN2_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    if($p->query('SELECT DATABASE()')->fetchColumn()!=='fitcrew_admin2_test') throw new RuntimeException('Unsafe test target');
    $p->exec("SET time_zone='+00:00'"); return $p;
}
function a2_fixture(PDO $p): array {
    $p->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) if($t!=='schema_migrations') $p->exec('DELETE FROM `'.str_replace('`','``',$t).'`');
    $p->exec('SET FOREIGN_KEY_CHECKS=1');
    $f=admin_test_fixture($p);
    $f['verified']=fc_contact_email_create($p,3,'verified@example.test','GOOGLE',true,'VERIFIED','2026-01-01 00:00:00');
    $f['alternate']=fc_contact_email_create($p,3,'alternate@example.test','GOOGLE',false,'VERIFIED','2026-01-01 00:00:00');
    $f['unverified']=fc_contact_email_create($p,3,'unverified@example.test','USER');
    $f['foreign']=fc_contact_email_create($p,2,'other@example.test','GOOGLE',true,'VERIFIED','2026-01-01 00:00:00');
    return $f;
}
function a2_login(array $f,string $who): void { session_id($f[$who]['raw']); }
function a2_ticket(PDO $p,string $kind,string $target,string $action,array $fields=[]): array {
    return ['kind'=>$kind,'target'=>$target,'action'=>$action,'state'=>fc_admin_operation_snapshot($p,$kind,$target),'fields'=>$fields,'reason'=>'Isolated Admin integration proof','request_key'=>bin2hex(random_bytes(24))];
}
function a2_apply(PDO $p,string $kind,string $target,string $action,array $fields=[]): array { return fc_admin_operation_execute($p,a2_ticket($p,$kind,$target,$action,$fields)); }
function a2_assert(bool $ok,string $message): void { admin_test_assert($ok,$message); }
function a2_denied(callable $f,string $code): void { uop_denied($f,$code); }
function a2_start_http(array $f): array {
    $root=dirname(__DIR__);
    if(is_file($root.'/.env')) throw new RuntimeException('HTTP proof requires isolated source without .env');
    $dir=sys_get_temp_dir().'/fc-admin2-http-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
    foreach(['super','admin','user','inactive'] as $name) file_put_contents($dir.'/sess_'.$f[$name]['raw'],'fitcrew_csrf_token|s:64:"'.str_repeat('c',64).'";');
    $port=random_int(19000,24000); preg_match('/port=(\d+)/',getenv('FC_ADMIN2_TEST_DSN'),$m);
    $env=array_merge(getenv(),['APP_ENV'=>'test','APP_DEBUG'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>$m[1],'DB_DATABASE'=>'fitcrew_admin2_test','DB_USERNAME'=>getenv('FC_ADMIN2_TEST_USER')?:'root','DB_PASSWORD'=>getenv('FC_ADMIN2_TEST_PASSWORD')?:'','SESSION_NAME'=>'fitcrew_session','SESSION_SECURE'=>'false','MAIL_DRIVER'=>'log']);
    $proc=proc_open([PHP_BINARY,'-d','session.save_path='.$dir,'-S','0.0.0.0:'.$port,'-t',$root],[0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$root,$env);
    if(!is_resource($proc)) throw new RuntimeException('HTTP server unavailable');
    for($i=0;$i<50;$i++){ $socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1); if($socket){fclose($socket);return compact('proc','port','dir');} usleep(100000); }
    throw new RuntimeException('HTTP server not ready');
}
function a2_http(array $h,string $path,string $session,string $method='GET',array $post=[]): array {
    $ctx=stream_context_create(['http'=>['method'=>$method,'header'=>"Content-Type: application/x-www-form-urlencoded\r\nCookie: fitcrew_session=".$session."\r\n",'content'=>http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]);
    $body=file_get_contents('http://127.0.0.1:'.$h['port'].$path,false,$ctx); $headers=$http_response_header??[]; preg_match('/\s(\d{3})\s/',$headers[0]??'',$m);
    return ['status'=>(int)($m[1]??0),'body'=>(string)$body,'headers'=>implode("\n",$headers)];
}
function a2_handle(array $response): string { preg_match('/name="ticket" value="([a-f0-9]{48})"/',$response['body'],$m); return $m[1]??''; }
function a2_review(array $h,array $f,string $actor,string $kind,string $target,string $op,array $fields=[]): array {
    $r=a2_http($h,'/admin/operation.php?kind='.$kind.'&target='.$target.'&action='.$op,$f[$actor]['raw']);
    a2_assert($r['status']===200 && a2_handle($r)!=='','Editor renders: '.$kind.'/'.$op);
    return a2_http($h,'/admin/operation.php',$f[$actor]['raw'],'POST',$fields+['csrf_token'=>str_repeat('c',64),'stage'=>'review','ticket'=>a2_handle($r),'reason'=>'HTTP Admin proof']);
}
function a2_confirm(array $h,array $f,string $actor,array $review,array $extra=[]): array {
    return a2_http($h,'/admin/operation.php',$f[$actor]['raw'],'POST',$extra+['csrf_token'=>str_repeat('c',64),'stage'=>'execute','ticket'=>a2_handle($review),'confirm'=>'yes']);
}
function a2_stop_http(array $h): void {
    proc_terminate($h['proc']);proc_close($h['proc']);
    foreach(glob($h['dir'].'/*') as $file) unlink($file);rmdir($h['dir']);
}
