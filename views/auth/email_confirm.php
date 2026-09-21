<?php /** @var string $csrfToken */ /** @var string $scriptNonce */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>Confirm email sign-in · FitCrew Challenge</title>
    <style>
        /* Security-isolated Foundation roles: no shared stylesheet or remote fonts. */
        :root { color-scheme: light; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; font-size: 16px; color: #0d1b3d; background: #f2f4f7; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; line-height: 1.65; }
        main { width: min(100%, 520px); min-width: 0; background: #fff; border: 1px solid #d8dee9; border-radius: 18px; padding: 32px; box-shadow: 0 18px 50px rgba(13,27,61,.10); overflow-wrap: anywhere; }
        .eyebrow { margin: 0; color: #1e40af; font-size: .8125rem; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
        h1 { margin: 8px 0 16px; font-size: 2rem; line-height: 1.2; }
        p { margin: 16px 0; color: #536174; }
        form { margin-top: 24px; }
        button, a { display: inline-flex; align-items: center; justify-content: center; min-height: 46px; max-width: 100%; border-radius: 10px; padding: 12px 16px; font: inherit; font-size: 1rem; font-weight: 700; text-align: center; }
        button { width: 100%; border: 1px solid #1e40af; color: #fff; background: #1e40af; cursor: pointer; }
        button:hover { background: #17378f; }
        a { color: #1e40af; text-underline-offset: 3px; }
        button:focus-visible, a:focus-visible { outline: 3px solid #1e40af; outline-offset: 3px; }
        .security, .error { padding: 16px; border: 1px solid #bfd0ff; border-radius: 14px; font-size: .875rem; line-height: 1.55; background: #eef3ff; color: #0d1b3d; }
        .error { border-color: #fda29b; background: #fff1f0; color: #b42318; }
        @media (max-width: 520px) { body { padding: 16px; } main { padding: 24px; } }
    </style>
</head>
<body>
<main>
    <p class="eyebrow">FitCrew Challenge</p>
    <section id="email-link-confirm" hidden>
        <h1>Confirm your sign-in.</h1>
        <p>Continue only if you requested this email link.</p>
        <form method="post" action="/auth/email/complete.php">
            <input type="hidden" name="csrf_token" value="<?= fc_e($csrfToken) ?>">
            <input id="email-link-token" type="hidden" name="token" value="">
            <button type="submit">Continue to FitCrew Challenge</button>
        </form>
        <p class="security">Opening this page did not sign you in and did not consume the link.</p>
    </section>
    <section id="email-link-invalid" hidden>
        <h1>This link cannot be used.</h1>
        <p class="error">It may be invalid, expired, replaced, or already used.</p>
        <p><a href="/login.php">Request a new sign-in link</a></p>
    </section>
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
