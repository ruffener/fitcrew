<?php /** @var string $csrfToken */ /** @var string $scriptNonce */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Add email sign-in · FitCrew Challenge</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; color: #0b1d43; background: #f3f6fb; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        main { width: min(100%, 520px); background: #fff; border: 1px solid #d4ddec; border-radius: 18px; padding: 32px; box-shadow: 0 18px 50px rgba(11,29,67,.10); }
        .eyebrow { color: #1744c7; font-size: .78rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 8px 0 12px; font-size: clamp(1.8rem, 6vw, 2.6rem); line-height: 1.05; }
        p { color: #536078; line-height: 1.55; }
        form { margin-top: 24px; }
        button, a { display: inline-block; border-radius: 9px; padding: 12px 18px; font: inherit; font-weight: 800; }
        button { width: 100%; border: 0; color: #fff; background: #1646d8; cursor: pointer; }
        a { color: #1646d8; }
        .security { border-left: 3px solid #ff6b00; padding-left: 14px; font-size: .9rem; }
    </style>
</head>
<body>
<main>
    <p class="eyebrow">FitCrew Challenge</p>
    <section id="email-link-confirm" hidden>
        <h1>Add email sign-in?</h1>
        <p>Continue only if you asked to add email sign-in to your existing FitCrew account. Use the same browser where you requested this link, while still signed in.</p>
        <form method="post" action="/auth/email/link-complete.php">
            <input type="hidden" name="csrf_token" value="<?= fc_e($csrfToken) ?>">
            <input id="email-link-token" type="hidden" name="token" value="">
            <button type="submit">Add email sign-in</button>
        </form>
        <p class="security">This adds a sign-in method to your currently signed-in account. Opening this page alone changes nothing.</p>
    </section>
    <section id="email-link-invalid" hidden>
        <h1>This link cannot be used.</h1>
        <p>It may be invalid, expired, replaced, or already used.</p>
        <p><a href="/auth/email/link.php">Return to email sign-in setup</a></p>
    </section>
<noscript><p>JavaScript is required to read this secure link. Enable it and reopen the link from your email.</p></noscript>
</main>
<script nonce="<?= fc_e($scriptNonce) ?>">
(() => {
    const params = new URLSearchParams(window.location.hash.slice(1));
    const token = params.get('token') || '';
    window.history.replaceState(null, '', window.location.pathname);
    const validShape = /^[A-Za-z0-9_-]{43}$/.test(token);
    document.getElementById(validShape ? 'email-link-confirm' : 'email-link-invalid').hidden = false;
    if (validShape) {
        document.getElementById('email-link-token').value = token;
    }
})();
</script>
</body>
</html>
