<!doctype html>
<html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Confirm contact email — FitCrew</title>
<style>body{font:1rem/1.6 system-ui,sans-serif;color:#13233a;margin:0;padding:1.5rem}main{max-width:36rem;margin:2rem auto}button,a{display:inline-block;margin:1rem 1rem 0 0;padding:.7rem}button{font:inherit}button:focus-visible,a:focus-visible{outline:3px solid #244acf;outline-offset:3px}label{display:block;margin-top:1rem}input[type=checkbox]{width:1.2rem;height:1.2rem}h1{line-height:1.2}</style>
<main><h1>Confirm your contact email</h1>
<p>A FitCrew administrator requested this email for an existing account. Continue only if this is your account and you expected the request.</p>
<p>This verifies your contact email. It does not sign you in or change your primary contact.</p>
<form method="post" action="/auth/contact/complete.php">
<input type="hidden" name="csrf_token" value="<?= fc_e($csrf) ?>">
<input type="hidden" name="token" id="contact-token" value="">
<label><input type="checkbox" name="confirm" value="yes" required> I control this mailbox and requested this contact change.</label>
<button id="verify" disabled>Verify contact email</button><a href="/">Cancel</a>
</form><p id="message" role="status">Open the verification link from your email to continue.</p>
<noscript>JavaScript is required to read the private link from your email.</noscript>
</main>
<script nonce="<?= fc_e($nonce) ?>">
const params = new URLSearchParams(location.hash.slice(1));
const token = params.get('token') || '';
history.replaceState(null, '', location.pathname);
if (/^[A-Za-z0-9_-]{43}$/.test(token)) {
    document.getElementById('contact-token').value = token;
    document.getElementById('verify').disabled = false;
    document.getElementById('message').textContent = '';
}
</script></html>
