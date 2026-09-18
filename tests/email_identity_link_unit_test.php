<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('Not Found'); }
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once fc_path('inc/auth/email_identity_link.php');
function eilu_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
try {
    // Fixed return preference cannot change invitation routing or authorize a link.
    foreach (['/app.php'=>'/auth/email/link.php', '/crew-invite.php'=>'/crew-invite.php', 'https://example.test'=>'https://example.test'] as $input=>$expected) {
        $_SESSION['fitcrew_email_link_setup_until'] = time()+60;
        eilu_assert(fc_email_identity_link_after_login($input)===$expected, 'Auth destination changed incorrectly.');
        eilu_assert(!isset($_SESSION['fitcrew_email_link_setup_until']), 'Setup preference not consumed.');
    }
    $_SESSION['fitcrew_email_link_setup_until'] = time()-1;
    eilu_assert(fc_email_identity_link_after_login('/app.php')==='/app.php', 'Expired setup preference accepted.');
    foreach (['signin','link','invalid'] as $mode) {
        fc_email_request_require_acknowledgement($mode);
        $emailAckMode = $_SESSION['fitcrew_email_request_ack'];
        $expectedMessage = $mode==='link' ? FC_EMAIL_IDENTITY_LINK_REQUEST_MESSAGE : FC_EMAIL_MAGIC_LINK_REQUEST_MESSAGE;
        ob_start(); require fc_path('views/auth/email_request_ack.php'); $html = ob_get_clean();
        $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
        $dialog = $xpath->query('//dialog')->item(0);
        eilu_assert($dialog !== null && $dialog->hasAttribute('open') && $dialog->getAttribute('aria-modal')==='true', 'No-JavaScript acknowledgement absent.');
        eilu_assert(str_contains($html, fc_e($expectedMessage)), 'Acknowledgement exposed a different request outcome.');
        eilu_assert($xpath->query('//dialog/form[@method="post"][@action="/auth/email/acknowledge.php"]/input[@name="csrf_token"]')->length===1, 'Acknowledgement form not protected.');
        eilu_assert($xpath->query('//dialog//button')->length===1, 'Acknowledgement has an alternate dismissal control.');
        foreach (explode(' ', $dialog->getAttribute('aria-labelledby') . ' ' . $dialog->getAttribute('aria-describedby')) as $id) {
            eilu_assert($xpath->query('//*[@id="'.$id.'"]')->length===1, 'Dialog accessible name or description broken.');
        }
    }
    $title='Auth proof';$contentView='views/auth/email_link.php';$linkRecent=true;
    ob_start();require fc_path('views/layouts/auth.php');$html=ob_get_clean();
    eilu_assert(str_contains($html,'<div inert>') && str_contains($html,'id="fitcrew-email-request-ack"'), 'Background is actionable before acknowledgement.');
    unset($_SESSION['fitcrew_email_request_ack']);
    ob_start();require fc_path('views/layouts/auth.php');$html=ob_get_clean();
    eilu_assert(!str_contains($html,'<div inert>') && !str_contains($html,'id="fitcrew-email-request-ack"'), 'Acknowledgement persisted after clearing.');
    $read = static fn(string $path):string => str_replace("\r\n","\n",file_get_contents(fc_path($path)));
    foreach (['auth/email/link-confirm.php','auth/email/confirm.php'] as $path) {
        $source=$read($path);
        eilu_assert(str_contains($source,"header('Referrer-Policy: same-origin')") && str_contains($source,"default-src 'none'") && str_contains($source,"form-action 'self'") && str_contains($source,"fc_request_method() !== 'GET'"), 'Confirmation privacy/origin policy changed.');
    }
    $complete=$read('auth/email/link-complete.php');
    eilu_assert(strpos($complete,'fc_validate_csrf')<strpos($complete,'fc_email_identity_link_complete(') && strpos($complete,'fc_email_magic_link_completion_origin_valid')<strpos($complete,'fc_email_identity_link_complete('), 'Completion proof precedes request protection.');
    eilu_assert(str_contains($complete,'if (!fc_is_post())'), 'Link completion accepts GET.');
    $ack=$read('auth/email/acknowledge.php');
    eilu_assert(str_contains($ack,'fc_validate_csrf') && str_contains($ack,'fc_email_magic_link_completion_origin_valid') && str_contains($ack,'if (!fc_is_post())') && !str_contains($ack,"\$_POST['return"), 'Acknowledgement endpoint is not fixed and protected.');
    $request=$read('auth/email/request.php');
    eilu_assert(substr_count($request,'fc_email_request_require_acknowledgement();')===3 && !str_contains($request,'fc_flash('), 'Request outcomes do not use the same required acknowledgement.');
    fwrite(STDOUT,"EMAIL_IDENTITY_LINK/acknowledgement unit: PASS\n- fixed return routing and invitation priority\n- generic rendered modal, accessible labels, CSRF form and no-JS inert background\n- existing token-origin/privacy policy and protected POST boundaries\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL); exit(1);
}
