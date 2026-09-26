<?php

declare(strict_types=1);
require __DIR__ . '/auth_user_operations_support.php';
ob_start();
function uop_http(string $base,string $path,?string &$cookie,?array $post=null,?string $origin=null): array
{
    $headers="Content-Type: application/x-www-form-urlencoded\r\n";
    if($cookie!==null)$headers.="Cookie: $cookie\r\n";
    if($origin!==null)$headers.="Origin: $origin\r\n";
    $context=stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>$headers,'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]]);
    $body=file_get_contents($base.$path,false,$context);$response=$http_response_header??[];
    preg_match('/\s(\d{3})\s/',$response[0]??'',$m);
    foreach($response as $h)if(preg_match('/^Set-Cookie: ([^;]+)/i',$h,$c))$cookie=$c[1];
    return ['status'=>(int)($m[1]??0),'body'=>(string)$body,'headers'=>implode("\n",$response)];
}
$p=uop_db();$f=uop_fixture($p);uop_login($f['super']);$v=uop_verification($p,$f,'http-proof@example.test');
$socket=stream_socket_server('tcp://0.0.0.0:0',$errno,$error);if($socket===false)throw new RuntimeException($error);
$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);$base='http://127.0.0.1:'.$port;
$log=tempnam(sys_get_temp_dir(),'fc-uop-http-');
$server=proc_open([PHP_BINARY,'-S','0.0.0.0:'.$port,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);
if(!is_resource($server))throw new RuntimeException('HTTP proof server failed.');
try {
    for($n=0;$n<50;$n++){ $s=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$error,.1);if($s!==false){fclose($s);break;}usleep(20000); }
    $cookie=null;$get=uop_http($base,'/auth/contact/confirm.php',$cookie);
    uop_assert($get['status']===200 && str_contains($get['headers'],'no-store') && str_contains($get['headers'],"default-src 'none'"),'Confirmation GET has no-store and isolated CSP');
    uop_assert(fc_contact_email_find_verified_owner($p,'http-proof@example.test')===null,'GET never consumes proof or verifies a contact');
    uop_assert(!str_contains($get['body'],$v['raw_token']) && str_contains($get['body'],'history.replaceState') && str_contains($get['body'],'required'),'Fragment credential, history scrubbing and explicit checkbox');
    preg_match('/name="csrf_token" value="([^"]+)"/',$get['body'],$match);$csrf=html_entity_decode($match[1]??'');
    uop_assert($csrf!=='','Real confirmation session produces CSRF token');
    $post=['csrf_token'=>$csrf,'token'=>$v['raw_token'],'confirm'=>'yes'];
    uop_assert(uop_http($base,'/auth/contact/complete.php',$cookie)['status']===405,'GET completion rejected');
    $bad=$post;$bad['csrf_token']='bad';uop_assert(uop_http($base,'/auth/contact/complete.php',$cookie,$bad)['status']===403,'Bad CSRF rejected before verification');
    $bad=$post;unset($bad['confirm']);uop_assert(uop_http($base,'/auth/contact/complete.php',$cookie,$bad)['status']===403,'Missing confirmation rejected');
    uop_assert(uop_http($base,'/auth/contact/complete.php',$cookie,$post,'https://foreign.example')['status']===403,'Foreign Origin rejected');
    uop_assert(uop_http($base,'/auth/contact/complete.php',$cookie,$post+['platform_role_code'=>'PLATFORM_SUPER_ADMIN'])['status']===400,'Unexpected form fields rejected');
    uop_assert(fc_contact_email_find_verified_owner($p,'http-proof@example.test')===null,'Rejected requests preserve unverified state');
    $done=uop_http($base,'/auth/contact/complete.php',$cookie,$post);
    uop_assert($done['status']===200 && str_contains($done['body'],'verified'),'Real protected POST completes mailbox verification');
    uop_assert((int)fc_contact_email_find_verified_owner($p,'http-proof@example.test')['user_id']===$f['user']['id'],'HTTP completion preserves canonical target');
    uop_assert(uop_http($base,'/auth/contact/complete.php',$cookie,$post)['status']===200,'HTTP replay is safe');
    uop_assert((int)$p->query('SELECT COUNT(*) FROM user_sessions')->fetchColumn()===4,'Confirmation browser receives no authenticated session');
    echo "AUTH USER OPERATIONS HTTP: PASS\n";
} finally {proc_terminate($server);fclose($pipes[0]);proc_close($server);unlink($log);}
