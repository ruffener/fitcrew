<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__) . '/inc/bootstrap.php';
function aeu_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
try {
    $base = ['issuer'=>'https://accounts.google.com', 'provider_email_verified'=>1, 'email_at_provider'=>' Person@GMAIL.COM '];
    foreach ([
        [$base, 'person@gmail.com'],
        [array_replace($base, ['email_at_provider'=>'A.Name+tag@gmail.com']), 'a.name+tag@gmail.com'],
        [array_replace($base, ['email_at_provider'=>'person@company.example', 'hosted_domain'=>'company.example']), 'person@company.example'],
        [array_replace($base, ['email_at_provider'=>'person@aol.com']), null],
        [array_replace($base, ['email_at_provider'=>'person@gmail.com.attacker.example']), null],
        [array_replace($base, ['email_at_provider'=>'person@company.example','hosted_domain'=>'']), null],
        [array_replace($base, ['email_at_provider'=>'person@company.example','hosted_domain'=>'https://company.example']), null],
        [array_replace($base, ['email_at_provider'=>'not-an-email']), null],
        [array_replace($base, ['provider_email_verified'=>0]), null],
        [array_replace($base, ['provider_email_verified'=>null]), null],
        [array_replace($base, ['issuer'=>'https://attacker.example']), null],
    ] as [$claims, $expected]) {
        aeu_assert(fc_google_authoritative_email($claims) === $expected, 'Google mailbox authority classification failed.');
    }
    $claims = fc_google_validate_verified_payload([
        'iss'=>'https://accounts.google.com', 'aud'=>'auth-unit-client', 'exp'=>time()+300,
        'sub'=>'stable-google-subject', 'nonce'=>'auth-unit-nonce', 'email'=>'person@company.example',
        'email_verified'=>true, 'hd'=>'company.example',
    ], 'auth-unit-client', fc_secret_evidence_hash('auth-unit-nonce'), time());
    aeu_assert($claims['provider_subject']==='stable-google-subject' && fc_google_authoritative_email($claims)==='person@company.example', 'Signed hd not retained after Google token validation.');

    fc_email_request_require_acknowledgement();
    ob_start(); require fc_path('views/auth/email_request_ack.php'); $html=ob_get_clean();
    $dom=new DOMDocument(); @$dom->loadHTML($html); $xpath=new DOMXPath($dom);
    $dialog=$xpath->query('//dialog')->item(0);
    aeu_assert($dialog!==null && $dialog->hasAttribute('open') && $dialog->getAttribute('aria-modal')==='true', 'No-JS modal missing.');
    aeu_assert(str_contains($html, fc_e(FC_EMAIL_MAGIC_LINK_REQUEST_MESSAGE)), 'Generic acknowledgement changed.');
    aeu_assert($xpath->query('//dialog/form[@method="post"][@action="/auth/email/acknowledge.php"]/input[@name="csrf_token"]')->length===1, 'Acknowledgement CSRF form missing.');
    aeu_assert($xpath->query('//dialog//button')->length===1, 'Modal has an alternate dismissal control.');
    foreach (explode(' ', $dialog->getAttribute('aria-labelledby').' '.$dialog->getAttribute('aria-describedby')) as $id) {
        aeu_assert($xpath->query('//*[@id="'.$id.'"]')->length===1, 'Modal accessible name/description missing.');
    }
    $title='Auth proof'; $contentView='views/auth/login.php';
    ob_start();require fc_path('views/layouts/auth.php');$html=ob_get_clean();
    aeu_assert(str_contains($html,'<div inert>') && str_contains($html,'id="fitcrew-email-request-ack"'), 'Background remains actionable before acknowledgement.');
    aeu_assert(!str_contains($html,'/auth/email/link.php') && !str_contains($html,'Add email sign-in'), 'Setup detour remains visible.');
    fc_email_request_require_acknowledgement('retry');
    ob_start();require fc_path('views/layouts/auth.php');$retryHtml=ob_get_clean();
    aeu_assert(str_contains($retryHtml,'<div inert>') && str_contains($retryHtml,FC_EMAIL_MAGIC_LINK_RETRY_MESSAGE), 'Stale form lacks required retry acknowledgement.');
    aeu_assert(!str_contains($retryHtml,FC_EMAIL_MAGIC_LINK_REQUEST_MESSAGE) && str_contains($retryHtml,'No sign-in email was requested.'), 'Rejected form falsely claims that email may arrive.');
    $retryDom=new DOMDocument();@$retryDom->loadHTML($retryHtml);$retryXpath=new DOMXPath($retryDom);
    aeu_assert($retryXpath->query('//dialog/form[@method="post"][@action="/auth/email/acknowledge.php"]/input[@name="csrf_token"]')->length===1, 'Retry acknowledgement bypasses protected POST.');
    fc_email_request_require_acknowledgement('untrusted-value');
    aeu_assert($_SESSION['fitcrew_email_request_ack']==='signin', 'Unexpected mode changed account-neutral feedback.');
    unset($_SESSION['fitcrew_email_request_ack']);
    ob_start();require fc_path('views/layouts/auth.php');$html=ob_get_clean();
    aeu_assert(!str_contains($html,'<div inert>') && !str_contains($html,'id="fitcrew-email-request-ack"'), 'Acknowledgement persists after clearing.');
    $read=static fn(string $path):string => str_replace("\r\n","\n",file_get_contents(fc_path($path)));
    $confirm=$read('auth/email/confirm.php');
    aeu_assert(str_contains($confirm,"header('Referrer-Policy: same-origin')") && str_contains($confirm,"default-src 'none'") && str_contains($confirm,"form-action 'self'") && str_contains($confirm,"fc_request_method() !== 'GET'"), 'Token confirmation privacy/origin policy changed.');
    $ack=$read('auth/email/acknowledge.php');
    aeu_assert(str_contains($ack,'fc_validate_csrf') && str_contains($ack,'fc_email_magic_link_completion_origin_valid') && str_contains($ack,'if (!fc_is_post())') && str_contains($ack,"fc_redirect('/login.php')"), 'Acknowledgement is not fixed and POST-protected.');
    $request=$read('auth/email/request.php');
    aeu_assert(substr_count($request,'fc_email_request_require_acknowledgement();')===1 && substr_count($request,"fc_email_request_require_acknowledgement('retry');")===2 && !str_contains($request,'fc_flash('), 'Request outcomes do not share the mandatory acknowledgement.');
    foreach (['link.php','link-confirm.php','link-complete.php'] as $route) {
        $source=$read('auth/email/'.$route);
        aeu_assert(str_contains($source,"header('Location: /login.php', true, 303)") && !str_contains($source,'$_POST') && !str_contains($source,'fc_db'), 'Retired route still accepts setup authority.');
    }
    fwrite(STDOUT,"Auth email account unit: PASS\n- trusted Google mailbox matrix; no provider-text or alias inference\n- mandatory generic modal, accessible labels, CSRF and no-JS inert background\n- rejected form retry modal; token privacy/origin rules and retired setup routes\n");
} catch (Throwable $error) { fwrite(STDERR,'[FAIL] '.$error->getMessage().PHP_EOL); exit(1); }
