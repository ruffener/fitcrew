<?php
declare(strict_types=1);
require __DIR__.'/admin_2_support.php';
$p=a2_db();$f=a2_fixture($p);$h=a2_start_http($f);$target=$f['user']['public_id'];$path='/admin/operation.php?kind=user&target='.$target.'&action=profile';
try {
    a2_assert(a2_http($h,$path,$f['user']['raw'])['status']===403,'USER denied operation entry');
    a2_assert(a2_http($h,$path,$f['super']['raw'],'DELETE')['status']===405,'Non-POST mutation methods denied');
    $edit=a2_http($h,$path,$f['super']['raw']);$post=['ticket'=>a2_handle($edit),'stage'=>'review','display_name'=>'New','timezone'=>'UTC','locale'=>'en','reason'=>'HTTP validation'];
    a2_assert(a2_http($h,'/admin/operation.php',$f['super']['raw'],'POST',$post)['status']===403,'Review requires CSRF');
    $post['csrf_token']=str_repeat('c',64);
    foreach(['actor_id','platform_role_code','provider_subject','revision','request_key','target'] as $extra) a2_assert(a2_http($h,'/admin/operation.php',$f['super']['raw'],'POST',$post+[$extra=>'crafted'])['status']===400,'Crafted review field rejected: '.$extra);
    a2_assert(a2_http($h,'/admin/operation.php',$f['super']['raw'],'POST',array_replace($post,['display_name'=>['bad']]))['status']===400,'Array field rejected');
    $review=a2_http($h,'/admin/operation.php',$f['super']['raw'],'POST',$post);
    a2_assert(a2_confirm($h,$f,'super',$review,['confirm'=>'no'])['status']===403,'Explicit checkbox required');
    a2_assert(a2_confirm($h,$f,'super',$review,['csrf_token'=>'bad'])['status']===403,'Execution requires CSRF');
    a2_assert(a2_confirm($h,$f,'admin',$review)['status']===403,'Another principal cannot use review handle');
    a2_assert(a2_confirm($h,$f,'super',$review,['display_name'=>'Tampered'])['status']===403,'Execution payload cannot override reviewed fields');
    $p->exec("UPDATE users SET display_name='Concurrent' WHERE id=3");
    $stale=a2_confirm($h,$f,'super',$review);a2_assert($stale['status']===409 && str_contains($stale['body'],'changed since you opened'),'Stale state safely presented');
    $review=a2_review($h,$f,'admin','user',$target,'profile',['display_name'=>'Stale authority','timezone'=>'UTC','locale'=>'en']);
    $p->exec("UPDATE users SET platform_role_code='USER' WHERE id=2");a2_assert(a2_confirm($h,$f,'admin',$review)['status']===403,'Actor demotion after review denies execution');
    $p->exec("UPDATE users SET platform_role_code='PLATFORM_ADMIN' WHERE id=2");
    $review=a2_review($h,$f,'super','user',$target,'replacement_contact',['email'=>'other@example.test']);
    $conflict=a2_confirm($h,$f,'super',$review);a2_assert($conflict['status']===409 && str_contains($conflict['body'],'ACCOUNT RECONCILIATION REQUIRED'),'Contact conflict surfaces safe reconciliation message');
    $review=a2_review($h,$f,'super','user',$target,'replacement_contact',['email'=>'replacement@example.test']);
    a2_assert(a2_confirm($h,$f,'super',$review)['status']===303,'Verification initiation uses Auth delivery flow');
    a2_assert(a2_confirm($h,$f,'super',$review)['status']===303 && (int)$p->query('SELECT COUNT(*) FROM auth_contact_verifications WHERE target_user_id=3')->fetchColumn()===1,'Verification retry does not issue duplicate request');
    $review=a2_review($h,$f,'super','user',$target,'primary_contact',['contact_id'=>(string)$f['alternate']['id']]);a2_assert(a2_confirm($h,$f,'super',$review)['status']===303,'Verified primary contact selection via HTTP');
    foreach(['end_sessions','suspend','restore'] as $action) {
        $review=a2_review($h,$f,'super','user',$f['admin']['public_id'],$action);a2_assert(a2_confirm($h,$f,'super',$review)['status']===303,'Super Admin HTTP '.$action);
        a2_assert(a2_http($h,'/admin/',$f['admin']['raw'])['status']===303,'Revoked target session requires new authentication after '.$action);
    }
    $review=a2_review($h,$f,'super','user',$target,'profile',['display_name'=>'<script>stored</script>','timezone'=>'UTC','locale'=>'en']);
    a2_assert(!str_contains($review['body'],'<script>stored</script>') && str_contains($review['body'],'&lt;script&gt;'),'Review escapes requested markup');
    a2_confirm($h,$f,'super',$review);$detail=a2_http($h,'/admin/user.php?id='.$target,$f['super']['raw']);
    a2_assert(!str_contains($detail['body'],'<script>stored</script>') && !str_contains($detail['body'],'SECRET-SUBJECT'),'Detail escapes stored markup and excludes provider evidence');
    $p->exec("INSERT INTO challenge_rule_versions(public_id,challenge_id,version_number,challenge_timezone,created_by_user_id) VALUES('01ARZ3NDEKTSV4RRFFQ69G5FR1',1,1,'UTC',3)");
    $challenge='01ARZ3NDEKTSV4RRFFQ69G5FH1';
    $review=a2_review($h,$f,'super','challenge',$challenge,'edit_rule_draft',['planned_start_date'=>'2026-10-01','duration_days'=>'84','challenge_timezone'=>'UTC','weekly_checkin_day'=>'1','live_leaderboard_visible'=>'0']);
    a2_assert(a2_confirm($h,$f,'super',$review)['status']===303,'Draft Rule fields submit correct values through HTTP');
    a2_assert((int)$p->query('SELECT live_leaderboard_visible FROM challenge_rule_versions LIMIT 1')->fetchColumn()===0,'Hidden leaderboard value remains false');
    a2_assert(a2_http($h,'/admin/operation.php?kind=challenge&target='.$challenge.'&action=set_lifecycle',$f['super']['raw'])['status']===400,'Arbitrary lifecycle action denied');
    $edit=a2_http($h,$path,$f['super']['raw']);
    a2_assert(str_contains($edit['body'],'for="op-display_name"') && str_contains($edit['body'],'Skip to content') && str_contains($edit['body'],'>Cancel</a>'),'Editor has labels, skip link and reachable Cancel');
    a2_assert(!preg_match('/name="(?:revision|request_key|actor_id|session_id)"/',$edit['body']),'Owner revisions and authority stay server-side');
} finally { a2_stop_http($h); }
echo "ADMIN-2 COMMON HTTP PROOF: PASS\n";
