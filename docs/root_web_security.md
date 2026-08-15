# Root Web Security Contract

## Decision

FitCrew uses the repository/project root as the Apache document root:

```text
C:\laragon\www\fitcrew
```

A separate `public/` directory is not used.

This is an intentional architecture choice. Because private application files now live beneath the Apache document root, access denial is part of the application deployment contract rather than being provided by physical directory separation.

## Publicly addressable surface

Only intentional entry points and static assets should be reachable from the browser:

```text
/
/login.php
/logout.php
/register.php
/forgot-password.php
/reset-password.php
/app.php
/api/...
/health/google/...
/assets/...
```

Future routes must be added deliberately.

## Protected surface

The root `.htaccess` denies direct web access to:

```text
.env and all dotfiles/dot-directories
inc/
views/
database/
storage/
docs/
tests/
vendor/
composer.json
composer.lock
README.md
```

`Options -Indexes` disables directory listings.

`assets/.htaccess` denies executable/server-side script extensions inside the public asset tree.

## Deployment requirement

Apache must permit `.htaccess` overrides for the FitCrew document root. A production host that disables `.htaccess` must reproduce these rules in the virtual-host/server configuration before FitCrew is considered deployable.

## Required security smoke test

Expected public behavior:

```text
/                         200
/login.php                200
/assets/css/app.css       200
/assets/js/app.js         200
/app.php                  protected redirect while signed out
```

Expected protected behavior:

```text
/.env                     403 or 404
/.git/                    403 or 404
/inc/bootstrap.php        403 or 404
/views/                   403 or 404
/database/                403 or 404
/storage/                 403 or 404
/docs/                    403 or 404
/tests/                   403 or 404
/vendor/                  403 or 404
/composer.json            403 or 404
/composer.lock            403 or 404
/README.md                403 or 404
```

A 200 response for any protected target is a release blocker.

## Security posture

The previous `public/` model was stronger by physical isolation. The root model is accepted only with an explicit, tested Apache deny boundary.

Do not silently weaken or remove the deny rules for convenience.
