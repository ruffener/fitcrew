<?php
declare(strict_types=1);

/** Admin orchestrates; Auth/Product own authority, revisions, writes and audit. */
function fc_admin_operation_catalog(string $kind): array
{
    return match ($kind) {
        'user' => [
            'profile'=>['Edit profile','Save the displayed profile fields. Sign-in identities and platform role are unchanged.'],
            'end_sessions'=>['End sessions','Revoke every current FitCrew session. The user must authenticate again.'],
            'suspend'=>['Suspend account','Suspend authenticated access and revoke existing sessions.'],
            'restore'=>['Restore account','Allow authentication again. Previously revoked sessions remain revoked; the user must sign in again.'],
            'primary_contact'=>['Select primary verified contact','Choose an existing verified contact owned by this same canonical user.'],
            'replacement_contact'=>['Initiate contact verification','Send a verification request. Mailbox control must be proven through the verification link. This does not change the primary contact or create a sign-in identity.'],
        ],
        'crew'=>[
            'edit'=>['Edit Crew profile','Save the Crew name and description.'],
            'transfer_owner'=>['Transfer Crew ownership','The new owner gains Crew management and ownership of its current Challenge, if any. The previous owner remains an active member. Historical Challenge ownership stays unchanged.'],
            'remove_member'=>['Remove Crew member','Remove this Crew membership AND all active Challenge participations in this Crew. Active participation intervals close, pending participant offers are cancelled, and selected Crew context is cleared. History remains; returning requires the governed invitation and acceptance flow.'],
            'archive'=>['Archive Crew','Archive this Crew without deleting its history. A Crew with a current Challenge cannot be archived.'],
            'restore'=>['Restore Crew','Restore an archived Crew to active status while preserving its history.'],
        ],
        'challenge'=>[
            'edit_name'=>['Edit Challenge name','Correct the Challenge name without changing competitive results or Rules.'],
            'edit_rule_draft'=>['Edit draft Rules','Change a DRAFT Rule version. If only published Rules exist, create a correction draft that supersedes them. Published Rules and participant acceptance history remain unchanged. Publishing and re-acceptance are separate governed steps.'],
            'remove_participant'=>['Remove participant','End this active Challenge participation and close its participation interval. Crew membership and history remain. Returning requires the governed invitation and explicit acceptance flow.'],
            'end'=>['End Challenge','Record an effective end and release the Crew’s current Challenge slot. This does not assign results or rewrite the lifecycle. The competitive consequence is not determined by this operation.'],
            'archive'=>['Archive Challenge','Archive presentation and release the Crew’s current Challenge slot. History and canonical lifecycle remain unchanged.'],
            'unarchive'=>['Unarchive Challenge','Restore the Challenge to its Crew’s current slot when eligible. Ended, deleted or completed Challenges cannot become current again, and another current Challenge blocks this operation.'],
        ],
        default => [],
    };
}

function fc_admin_operation_snapshot(PDO $pdo, string $kind, string $target): array
{
    return match ($kind) {
        'user'=>fc_auth_user_operations_snapshot($pdo,$target),
        'crew'=>fc_product_admin_crew_snapshot($pdo,$target),
        'challenge'=>fc_product_admin_challenge_snapshot($pdo,$target),
        default=>throw new FcAdminDenied('invalid_action',400),
    };
}

function fc_admin_operation_available(string $kind, array $state): array
{
    $allowed=$state['allowed_operations'];
    if ($kind==='user') {
        $status=$state['profile']['account_status'];
        $allowed=array_values(array_filter($allowed,static fn($op)=>
            !($op==='suspend' && $status!=='ACTIVE') && !($op==='restore' && $status!=='SUSPENDED')
            && !($op==='replacement_contact' && $status!=='ACTIVE')));
    }
    if($kind==='challenge') {
        $c=$state['owner_controls'];
        $allowed=array_values(array_filter($allowed,static fn($op)=>
            !($op==='archive' && $c['archived_at']!==null) && !($op==='unarchive' && $c['archived_at']===null)
            && !($op==='end' && $c['effective_end_at']!==null)));
    }
    return $allowed;
}

function fc_admin_operation_links(string $kind, string $target, array $state): void
{
    $catalog=fc_admin_operation_catalog($kind);
    echo '<div class="admin-actions" aria-label="Available operations">';
    foreach(fc_admin_operation_available($kind,$state) as $action) {
        if (!isset($catalog[$action])) continue;
        echo '<a class="button button-secondary" href="/admin/operation.php?kind='.fc_e($kind).'&amp;target='.fc_e($target).'&amp;action='.fc_e($action).'">'.fc_e($catalog[$action][0]).'</a>';
    }
    echo '</div>';
}

/** Opaque handles bind server-side payloads to the actual durable principal. */
function fc_admin_operation_ticket(array $principal, array $data): string
{
    $tickets=$_SESSION['admin2_operations'] ?? [];
    foreach($tickets as $key=>$ticket) if (($ticket['expires']??0)<time()) unset($tickets[$key]);
    while(count($tickets)>=20) array_shift($tickets);
    $key=bin2hex(random_bytes(24));
    $tickets[$key]=$data+['actor'=>(int)$principal['user_id'],'session'=>(int)$principal['session_record_id'],'expires'=>time()+1800];
    $_SESSION['admin2_operations']=$tickets;
    return $key;
}
function fc_admin_operation_load(array $principal, string $key): array
{
    $ticket=$_SESSION['admin2_operations'][$key]??null;
    if(!is_array($ticket) || $ticket['expires']<time() || $ticket['actor']!==(int)$principal['user_id']
        || $ticket['session']!==(int)$principal['session_record_id']) throw new FcAdminDenied('confirmation_required');
    return $ticket;
}
function fc_admin_operation_fields(array $post, array $spec): array
{
    $fields=[];
    if(array_diff(array_keys($post),array_merge(['csrf_token','ticket','stage','reason'],array_keys($spec)))!==[]) throw new FcAdminDenied('invalid_input',400);
    foreach($spec as $name=>$field) {
        $value=fc_admin_input($post,$name,($field['max']??500)*4);
        if ($value==='' && !($field['optional']??false)) throw new FcAdminDenied('invalid_input',400);
        if(isset($field['options']) && !array_key_exists($value,$field['options'])) throw new FcAdminDenied('invalid_input',400);
        if(($field['type']??'')==='number' && (!ctype_digit($value) || (int)$value<($field['min_value']??0) || (int)$value>($field['max_value']??PHP_INT_MAX))) throw new FcAdminDenied('invalid_input',400);
        $fields[$name]=$value;
    }
    return $fields;
}
function fc_admin_operation_execute(PDO $pdo, array $ticket): array
{
    $target=$ticket['target']; $f=$ticket['fields']; $args=[$ticket['state']['revision'],$ticket['request_key'],$ticket['reason']];
    return match($ticket['kind'].':'.$ticket['action']) {
        'challenge:edit_name'=>fc_product_admin_challenge_edit_name($pdo,$target,$f['display_name'],...$args),
        'challenge:edit_rule_draft'=>fc_product_admin_challenge_rule_draft_edit($pdo,$target,$f,...$args),
        'challenge:remove_participant'=>fc_product_admin_challenge_participant_remove($pdo,$target,$f['participant_public_id'],...$args),
        'challenge:end'=>fc_product_admin_challenge_end($pdo,$target,$f['end_reason'],...$args),
        'challenge:archive'=>fc_product_admin_challenge_archive($pdo,$target,...$args),
        'challenge:unarchive'=>fc_product_admin_challenge_unarchive($pdo,$target,...$args),
        'crew:edit'=>fc_product_admin_crew_edit($pdo,$target,$f,...$args),
        'crew:transfer_owner'=>fc_product_admin_crew_transfer_owner($pdo,$target,$f['new_owner_public_id'],...$args),
        'crew:remove_member'=>fc_product_admin_crew_member_remove($pdo,$target,$f['member_public_id'],...$args),
        'crew:archive'=>fc_product_admin_crew_archive($pdo,$target,...$args),
        'crew:restore'=>fc_product_admin_crew_restore($pdo,$target,...$args),
        'user:profile'=>fc_auth_user_profile_edit($pdo,$target,$f,...$args),
        'user:end_sessions'=>fc_auth_user_sessions_end($pdo,$target,...$args),
        'user:suspend'=>fc_auth_user_suspend($pdo,$target,...$args),
        'user:restore'=>fc_auth_user_restore($pdo,$target,...$args),
        'user:primary_contact'=>fc_auth_user_primary_contact_select($pdo,$target,(int)$f['contact_id'],...$args),
        'user:replacement_contact'=>fc_auth_user_replacement_contact_initiate($pdo,$target,$f['email'],...$args),
        default=>throw new FcAdminDenied('invalid_action',400),
    };
}
function fc_admin_operation_request(PDO $pdo, array $principal, string $method): array
{
    if($method==='GET') {
        if(isset($_GET['ticket'])) {
            $key=fc_admin_input($_GET,'ticket',48); $ticket=fc_admin_operation_load($principal,$key);
            if(!isset($ticket['result'])) throw new FcAdminDenied('confirmation_required');
            return $ticket+['ticket'=>$key,'stage'=>'receipt'];
        }
        $kind=fc_admin_input($_GET,'kind',12); $target=fc_admin_id(fc_admin_input($_GET,'target',26));
        $action=fc_admin_input($_GET,'action',32); $catalog=fc_admin_operation_catalog($kind);
        if(!isset($catalog[$action])) throw new FcAdminDenied('invalid_action',400);
        $state=fc_admin_operation_snapshot($pdo,$kind,$target);
        if(!in_array($action,fc_admin_operation_available($kind,$state),true)) throw new FcAdminDenied('not_authorized');
        $data=['kind'=>$kind,'target'=>$target,'action'=>$action,'state'=>$state,'phase'=>'edit'];
        $key=fc_admin_operation_ticket($principal,$data);
        return $data+['ticket'=>$key,'stage'=>'edit'];
    }
    $csrf=$_POST['csrf_token']??null;
    if(!is_string($csrf) || !fc_validate_csrf($csrf)) throw new FcAdminDenied('csrf_failed');
    $key=fc_admin_input($_POST,'ticket',48); $ticket=fc_admin_operation_load($principal,$key);
    $stage=fc_admin_input($_POST,'stage',12);
    if($stage==='review' && $ticket['phase']==='edit') {
        $spec=fc_admin_operation_form($ticket);
        $fields=fc_admin_operation_fields($_POST,$spec);
        $reason=fc_admin_input($_POST,'reason',2000);
        if($reason==='' || preg_match('//u',$reason)!==1 || preg_match('/[\x00-\x1f\x7f]/u',$reason) || mb_strlen($reason)>500) throw new FcAdminDenied('invalid_input',400);
        $data=array_intersect_key($ticket,array_flip(['kind','target','action','state']));
        $data+=['fields'=>$fields,'reason'=>$reason,'request_key'=>bin2hex(random_bytes(24)),'phase'=>'review'];
        $key=fc_admin_operation_ticket($principal,$data);
        return $data+['ticket'=>$key,'stage'=>'review'];
    }
    if($stage!=='execute' || $ticket['phase']!=='review' || fc_admin_input($_POST,'confirm',3)!=='yes'
        || array_diff(array_keys($_POST),['csrf_token','ticket','stage','confirm'])!==[]) throw new FcAdminDenied('confirmation_required');
    // Do not consume the review handle: exact retries reach owner durable idempotency.
    $result=fc_admin_operation_execute($pdo,$ticket);
    $_SESSION['admin2_operations'][$key]['result']=$result;
    header('Location: /admin/operation.php?ticket='.rawurlencode($key),true,303);
    return ['redirect'=>true];
}

/** Known outcome allowlist; exception messages are never rendered verbatim. */
function fc_admin_operation_error(Throwable $e): array
{
    $code=$e->getMessage();
    if(in_array($code,['stale_user_state','stale_crew_state','stale_challenge_state','idempotency_conflict'],true)) return [409,'This record changed since you opened it. Review the current values and try again.'];
    if($code==='account_reconciliation_required') return [409,'ACCOUNT RECONCILIATION REQUIRED. This email is associated with conflicting account evidence. Separate account reconciliation is required; no contact ownership was transferred.'];
    if(in_array($code,['user_operation_denied','product_admin_operation_denied','crew_operation_denied','challenge_operation_denied'],true)) return [403,'Your current account is not authorized to perform this operation on this target.'];
    $messages=[
        'completed_challenge_rules_locked'=>'Completed Challenge competitive Rules are protected.',
        'challenge_rule_draft_unavailable'=>'This Challenge has no Rule draft or published Rule version to correct. The Product owner must establish its Rules first.',
        'participant_unavailable'=>'The selected participant is unavailable.',
        'participant_not_active'=>'The selected user is not an active participant in this Challenge.',
        'challenge_already_ended'=>'This Challenge has already ended.',
        'challenge_already_archived'=>'This Challenge is already archived.',
        'challenge_not_archived'=>'This Challenge is not archived.',
        'challenge_cannot_be_restored'=>'An ended, deleted or completed Challenge cannot become current again.',
        'This Crew already has a current Challenge. Finish or archive it before restoring another Challenge.'=>'This Crew already has a current Challenge. Resolve it through the governed controls before restoring another.',
        'invalid_challenge_timezone'=>'Choose a valid Challenge timezone.',
        'invalid_weekly_checkin_day'=>'Choose a check-in day from the supported list.',
        'Challenge start date must be a valid date.'=>'Enter a valid planned start date.',
        'Challenge duration must be between 7 and 365 days.'=>'Enter a Challenge duration between 7 and 365 days.',
        'crew_has_current_challenge'=>'This Crew has a current Challenge. Resolve that Challenge through its governed controls before archiving the Crew.',
        'new_owner_unavailable'=>'The proposed owner must be an active user.',
        'owner_unchanged'=>'This user already owns the Crew.',
        'new_owner_must_be_active_crew_member'=>'The proposed owner must already be an active member of this Crew.',
        'crew_owner_membership_invalid'=>'The current ownership and membership need Product-owner review.',
        'invalid_crew_transition'=>'The Crew is no longer in the required state. Reload its details.',
        'crew_owner_cannot_be_removed'=>'The Crew Owner cannot be removed. Ownership must first be transferred through the governed operation.',
        'crew_member_not_active'=>'This user is no longer an active Crew member.',
        'crew_member_unavailable'=>'The selected member is unavailable.',
        'invalid_description'=>'Enter a single-line description of at most 500 characters.',
        'invalid_account_transition'=>'The account is no longer in the required state.',
        'verified_owned_contact_required'=>'Select a verified contact already owned by this user.',
        'active_contact_target_required'=>'Contact verification requires an active target account.',
        'contact_verification_rate_limited'=>'A verification was recently requested. Wait before initiating another.',
        'invalid_display_name'=>'Enter a valid name within the displayed length limit.',
        'invalid_timezone'=>'Choose a valid IANA timezone.',
        'unsupported_locale'=>'Choose one of the supported languages.',
        'invalid_email'=>'Enter a valid email address.',
        'invalid_reason'=>'Enter a single-line reason of at most 500 characters.',
    ];
    if(isset($messages[$code])) return [$e instanceof InvalidArgumentException?400:409,$messages[$code]];
    if($e instanceof InvalidArgumentException) return [400,'Please review the submitted fields and try again.'];
    return [503,'This operation is unavailable. Return to the record and try again later.'];
}
