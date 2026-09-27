<?php
declare(strict_types=1);

/** Explicit presentation projection: never dump owner snapshot/result arrays. */
function fc_admin_operation_form(array $ticket): array
{
    $s=$ticket['state'];
    if($ticket['kind']==='user') {
        $p=$s['profile'];
        return match($ticket['action']) {
            'profile'=>[
                'display_name'=>['label'=>'Display name','value'=>$p['display_name'],'max'=>120],
                'timezone'=>['label'=>'Timezone','value'=>$p['timezone']??'UTC','options'=>array_combine(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))],
                'locale'=>['label'=>'Language','value'=>$p['locale']??'en','options'=>array_combine($s['supported_locales'],$s['supported_locales'])],
            ],
            'primary_contact'=>['contact_id'=>['label'=>'Verified contact','value'=>'','options'=>fc_admin_contact_options($s['contacts'])]],
            'replacement_contact'=>['email'=>['label'=>'Replacement email','value'=>'','type'=>'email','max'=>254]],
            default=>[],
        };
    }
    if($ticket['kind']==='crew') {
        $c=$s['crew'];
        return match($ticket['action']) {
            'edit'=>['display_name'=>['label'=>'Crew name','value'=>$c['display_name'],'max'=>120],
                'description'=>['label'=>'Description','value'=>$c['description']??'','max'=>500,'optional'=>true]],
            'transfer_owner'=>['new_owner_public_id'=>['label'=>'Proposed new owner','value'=>'','options'=>fc_admin_member_options($s['members'],(int)$c['owner_user_id'],true)]],
            'remove_member'=>['member_public_id'=>['label'=>'Member to remove','value'=>'','options'=>fc_admin_member_options($s['members'],(int)$c['owner_user_id'])]],
            default=>[],
        };
    }
    if($ticket['kind']==='challenge') {
        $c=$s['challenge']; $rule=$s['draft_rule']??$s['published_rule'];
        $participants=[];
        foreach($s['participants'] as $p) if($p['participation_status']==='ACTIVE') $participants[$p['user_public_id']]=$p['display_name'].' · '.$p['user_public_id'];
        return match($ticket['action']) {
            'edit_name'=>['display_name'=>['label'=>'Challenge name','value'=>$c['display_name'],'max'=>140]],
            'edit_rule_draft'=>[
                'planned_start_date'=>['label'=>'Planned start date','value'=>$rule['planned_start_date']??'','type'=>'date','optional'=>true],
                'duration_days'=>['label'=>'Duration in days (7–365)','value'=>$rule['duration_days']??84,'type'=>'number','max'=>3,'min_value'=>7,'max_value'=>365],
                'challenge_timezone'=>['label'=>'Challenge timezone','value'=>$rule['challenge_timezone']??'UTC','options'=>array_combine(DateTimeZone::listIdentifiers(),DateTimeZone::listIdentifiers())],
                'weekly_checkin_day'=>['label'=>'Weekly check-in day','value'=>$rule['weekly_checkin_day']??0,'options'=>['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday']],
                'live_leaderboard_visible'=>['label'=>'Live leaderboard visibility','value'=>$rule['live_leaderboard_visible']??1,'options'=>['0'=>'Hidden','1'=>'Visible']],
            ],
            'remove_participant'=>['participant_public_id'=>['label'=>'Participant to remove','value'=>'','options'=>$participants]],
            'end'=>['end_reason'=>['label'=>'Challenge end explanation (optional)','value'=>'','max'=>500,'optional'=>true]],
            default=>[],
        };
    }
    return [];
}
function fc_admin_member_options(array $members,int $ownerId,bool $newOwner=false): array
{
    $options=[];
    foreach($members as $m) if($m['membership_status']==='ACTIVE' && (int)$m['user_id']!==$ownerId && (!$newOwner || $m['account_status']==='ACTIVE'))
        $options[$m['user_public_id']]=$m['display_name'].' · '.$m['user_public_id'];
    return $options;
}
function fc_admin_contact_options(array $contacts): array
{
    $options=[];
    foreach($contacts as $c) if($c['verification_status']==='VERIFIED') $options[(string)$c['id']]=$c['email_canonical'];
    return $options;
}
function fc_admin_operation_current(array $ticket): array
{
    if($ticket['kind']==='user') {
        $p=$ticket['state']['profile'];
        $facts=['Name'=>$p['display_name'],'Public ID'=>$p['public_id'],'Account status'=>$p['account_status'],'Platform role'=>fc_admin_role_label($p['platform_role_code']),'Timezone'=>$p['timezone'],'Language'=>$p['locale']];
        foreach($ticket['state']['contacts']??[] as $contact) if((int)$contact['is_primary_for_contact']===1) $facts['Current primary contact']=$contact['email_canonical'];
        return $facts;
    }
    if($ticket['kind']==='crew') {
        $s=$ticket['state'];$c=$s['crew'];$owner='Unavailable';
        foreach($s['members'] as $m) if((int)$m['user_id']===(int)$c['owner_user_id']) $owner=$m['display_name'].' · '.$m['user_public_id'];
        return ['Crew'=>$c['display_name'],'Public ID'=>$c['public_id'],'Status'=>$c['crew_status'],'Current owner'=>$owner,'Current Challenge'=>$s['current_challenge']['display_name']??'None','Description'=>$c['description']];
    }
    if($ticket['kind']==='challenge') {
        $s=$ticket['state'];$c=$s['challenge'];$control=$s['owner_controls'];
        $facts=['Challenge'=>$c['display_name'],'Public ID'=>$c['public_id'],'Lifecycle'=>$c['lifecycle_status'],'Operational state'=>$c['operational_state'],'Effective end'=>$control['effective_end_at'],'Archived'=>$control['archived_at']];
        if($ticket['action']==='edit_rule_draft') $facts+=fc_admin_rule_facts($s['draft_rule']??$s['published_rule']);
        return $facts;
    }
    return [];
}
function fc_admin_rule_facts(?array $rule): array
{
    if($rule===null) return ['Rules'=>'Not established'];
    return ['Rule version'=>(string)$rule['version_number'],'Rule status'=>$rule['version_status'],'Planned start'=>$rule['planned_start_date'],'Duration (days)'=>$rule['duration_days'],'Challenge timezone'=>$rule['challenge_timezone'],'Weekly check-in day'=>['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][(int)$rule['weekly_checkin_day']],'Live leaderboard'=>(int)$rule['live_leaderboard_visible']===1?'Visible':'Hidden'];
}
function fc_admin_operation_receipt(array $result): array
{
    $rows=['Audit event'=>(string)(int)($result['audit_id']??0),'Request'=>$result['replayed']??false?'Previously applied; original receipt returned':'Applied'];
    foreach(['revoked_sessions'=>'Sessions revoked','verification_public_id'=>'Verification reference','delivery'=>'Verification delivery',
        'removed_active_challenge_participations'=>'Challenge participations removed','rule_version_number'=>'Draft Rule version'] as $key=>$label) {
        if(isset($result[$key]) && is_scalar($result[$key])) $rows[$label]=(string)$result[$key];
    }
    return $rows;
}
