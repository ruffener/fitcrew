<?php
declare(strict_types=1);
require __DIR__ . '/admin_test_support.php';
$p=admin_test_db();$f=admin_test_fixture($p);$root=dirname(__DIR__);
if (is_file($root.'/.env')) { throw new RuntimeException('Run HTTP proof in an isolated candidate copy without .env.'); }
$dir=sys_get_temp_dir().'/fc-admin-http-'.bin2hex(random_bytes(6));mkdir($dir,0700);
$csrf=str_repeat('c',64);
foreach ($f as $v) { file_put_contents($dir.'/sess_'.$v['raw'],'fitcrew_csrf_token|s:64:"'.$csrf.'";'); }
$port=(int)(getenv('FC_ADMIN_TEST_HTTP_PORT') ?: 18764);
preg_match('/port=([0-9]+)/',getenv('FC_ADMIN_TEST_DSN'),$match);
$env=array_merge(getenv(),['APP_ENV'=>'test','APP_DEBUG'=>'false','DB_HOST'=>'127.0.0.1','DB_PORT'=>$match[1],
    'DB_DATABASE'=>'fitcrew_admin1_test','DB_USERNAME'=>getenv('FC_ADMIN_TEST_USER') ?: 'root',
    'DB_PASSWORD'=>getenv('FC_ADMIN_TEST_PASSWORD') ?: '', 'SESSION_NAME'=>'fitcrew_session', 'SESSION_SECURE'=>'false']);
$proc=proc_open([PHP_BINARY,'-d','session.save_path='.$dir,'-S','127.0.0.1:'.$port,'-t',$root],
    [0=>['pipe','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,$root,$env);
if (!is_resource($proc)) { throw new RuntimeException('Unable to start local HTTP proof.'); }
function admin_http(string $path, ?string $session, string $method='GET', array $data=[]): array
{
    global $port;
    $headers="Content-Type: application/x-www-form-urlencoded\r\n";
    if ($session!==null) { $headers.='Cookie: fitcrew_session='.$session."\r\n"; }
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>http_build_query($data),
        'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]]);
    $body=file_get_contents('http://127.0.0.1:'.$port.$path,false,$context);
    $headersOut=$http_response_header ?? [];preg_match('/\s(\d{3})\s/',$headersOut[0] ?? '',$m);
    return ['status'=>(int)($m[1] ?? 0),'body'=>(string)$body,'headers'=>implode("\n",$headersOut)];
}
try {
    $ready=false;
    for($i=0;$i<50;$i++) { $socket=@fsockopen('127.0.0.1',$port,$errno,$error,.1); if($socket){fclose($socket);$ready=true;break;}usleep(100000); }
    admin_test_assert($ready,'Local test server started');
    admin_test_assert(admin_http('/admin/',null)['status']===303,'Anonymous entry redirects to sign-in');
    foreach (['','users.php','crews.php','invitations.php','authentication.php','system.php','admins.php'] as $route) {
        $r=admin_http('/admin/'.$route,$f['super']['raw']);
        admin_test_assert($r['status']===200,"Super Admin reads /admin/$route");
        admin_test_assert(str_contains($r['headers'],'no-store'),'Admin responses are not cached');
        admin_test_assert(admin_http('/admin/'.$route,$f['user']['raw'])['status']===403,'Ordinary user denied');
    }
    $r=admin_http('/admin/',$f['admin']['raw']);
    admin_test_assert($r['status']===200 && !str_contains($r['body'],'href="/admin/admins.php"'),'Admin navigation omits Admin management');
    admin_test_assert(admin_http('/admin/admins.php',$f['admin']['raw'])['status']===403,'Direct Admin management denied to Admin');
    foreach (['/admin/user.php?id='.$f['user']['public_id'],'/admin/crew.php?id=01ARZ3NDEKTSV4RRFFQ69G5FC1','/admin/invitation.php?id=01ARZ3NDEKTSV4RRFFQ69G5FJ1'] as $path) {
        $r=admin_http($path,$f['admin']['raw']);admin_test_assert($r['status']===200,'Admin reads detail route');
        admin_test_assert(!str_contains($r['body'],'SECRET-SUBJECT') && !str_contains($r['body'],str_repeat('a',64)), 'Details exclude sensitive stored evidence');
    }
    admin_test_assert(admin_http('/admin/users.php',$f['super']['raw'],'POST',['action'=>'delete'])['status']===405,'Read-only route rejects POST');
    admin_test_assert(admin_http('/admin/role.php',$f['super']['raw'],'DELETE')['status']===405,'Role endpoint rejects DELETE');
    admin_test_assert(admin_http('/admin/user.php?id[]=bad',$f['super']['raw'])['status']===400,'Malformed input fails safely');
    $path='/admin/role.php?id='.$f['user']['public_id'].'&action=make';
    $preview=admin_http($path,$f['super']['raw']);
    admin_test_assert($preview['status']===200 && $p->query('SELECT platform_role_code FROM users WHERE id=3')->fetchColumn()==='USER','GET shows confirmation without role mutation');
    preg_match('/name="confirmation" value="([a-f0-9]{64})"/',$preview['body'],$m);
    $post=['target'=>$f['user']['public_id'],'action'=>'make','old_role'=>'USER','confirmation'=>$m[1] ?? '', 'confirm'=>'yes'];
    admin_test_assert(admin_http('/admin/role.php',$f['super']['raw'],'POST',$post)['status']===403,'Role POST requires CSRF');
    $post['csrf_token']=$csrf;
    admin_test_assert(admin_http('/admin/role.php',$f['admin']['raw'],'POST',$post)['status']===403,'Admin cannot directly post role change');
    admin_test_assert(admin_http('/admin/role.php',$f['super']['raw'],'POST',$post)['status']===303,'Confirmed role POST succeeds and redirects');
    admin_test_assert($p->query('SELECT platform_role_code FROM users WHERE id=3')->fetchColumn()==='PLATFORM_ADMIN','HTTP mutation persisted');
    admin_test_assert(admin_http('/admin/role.php',$f['super']['raw'],'POST',$post)['status']===403,'HTTP confirmation replay denied');
    $path='/admin/role.php?id='.$f['user']['public_id'].'&action=remove';
    $preview=admin_http($path,$f['super']['raw']);preg_match('/name="confirmation" value="([a-f0-9]{64})"/',$preview['body'],$m);
    $post['action']='remove';$post['old_role']='PLATFORM_ADMIN';$post['confirmation']=$m[1];
    admin_test_assert(admin_http('/admin/role.php',$f['super']['raw'],'POST',$post)['status']===303,'HTTP removal succeeds');
    admin_test_assert(admin_http('/admin/',$f['user']['raw'])['status']===403,'Existing session loses Admin access after removal');
    admin_test_assert(admin_http('/admin/role.php?id='.$f['super']['public_id'].'&action=remove',$f['super']['raw'])['status']===403,'Super Admin target protected over HTTP');
    $p->exec("UPDATE users SET display_name='<script>alert(1)</script>' WHERE id=3");
    $r=admin_http('/admin/user.php?id='.$f['user']['public_id'],$f['super']['raw']);
    admin_test_assert(!str_contains($r['body'],'<script>alert(1)</script>') && str_contains($r['body'],'&lt;script&gt;'),'HTTP output escapes stored markup');
    $p->exec('UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP(6) WHERE id=2');
    admin_test_assert(admin_http('/admin/',$f['admin']['raw'])['status']===303,'Revoked session loses entry');
    echo "ADMIN HTTP: PASS\n";
} finally {
    proc_terminate($proc);proc_close($proc);
    foreach(glob($dir.'/*') as $file){unlink($file);}rmdir($dir);
}
