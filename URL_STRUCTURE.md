# URL Structure and Folder Layout

This document describes the recommended URL structure and folder layout when deploying this project (Autobot) to production, e.g. on Google Cloud Run.

## 1. High-Level Goals

- Use clean URLs without the `/autobot` prefix.
- Expose `public/` as the web document root so `/public` does not appear in URLs.
- Keep API endpoints under `/api/...`.
- Keep admin UI under `/admin/...`.

## 2. Recommended Production URL Structure

### Public UI (end users)

- `/` → `public/login.html` (or a landing page that redirects to login)
- `/login.html` → `public/login.html`
- `/dashboard.html` → `public/dashboard.html`
- `/payment.html` → `public/payment.html`
- `/billing.html` → `public/billing.html`
- `/services.html` → `public/services.html`
- `/usage.html` → `public/usage.html`
- `/profile.html` → `public/profile.html`
- `/api-docs.html` → `public/api-docs.html`

Static assets (served from `assets`):

- `/assets/css/style.css` → `assets/css/style.css`
- `/assets/css/responsive-fixes.css` → `assets/css/responsive-fixes.css`
- `/assets/css/modal-fixes.css` → `assets/css/modal-fixes.css`
- `/assets/js/*.js` → `assets/js/*.js`

### Admin UI

Admin pages should be served under `/admin` from a folder inside `public`:

- `/admin/login.html` → `public/admin/login.html`
- `/admin/dashboard.html` → `public/admin/dashboard.html`
- `/admin/customers.html` → `public/admin/customers.html`
- `/admin/invoices.html` → `public/admin/invoices.html`
- `/admin/packages.html` → `public/admin/packages.html`
- `/admin/reports.html` → `public/admin/reports.html`
- `/admin/services.html` → `public/admin/services.html`
- `/admin/settings.html` → `public/admin/settings.html`

### API Endpoints

The PHP API remains under `/api/...` as already structured:

- `/api/auth/login.php` → `api/auth/login.php`
- `/api/auth/logout.php` → `api/auth/logout.php`
- `/api/auth/me.php` → `api/auth/me.php`
- `/api/payment/methods.php` → `api/payment/methods.php`
- `/api/payment/add-card.php` → `api/payment/add-card.php`
- `/api/payment/remove-card.php` → `api/payment/remove-card.php`
- `/api/payment/set-default.php` → `api/payment/set-default.php`
- `/api/dashboard/stats.php` → `api/dashboard/stats.php`
- `/api/services/list.php` → `api/services/list.php`
- `/api/user/api-key.php` → `api/user/api-key.php`
- etc.

A front-controller like `api/index.php` or web server routing should map clean URLs (e.g. `/api/payment/methods`) to the corresponding PHP files.

## 3. Folder Layout on Disk

Recommended layout inside the container or VM:

```text
/autobot
  api/               # PHP API
  includes/          # PHP libraries
  public/            # Web document root (served as "/")
    assets/          # Static JS/CSS/images for both user + admin
      css/
      js/
    login.html
    dashboard.html
    payment.html
    billing.html
    services.html
    usage.html
    profile.html
    api-docs.html
    admin/           # Admin UI is here, not at /admin outside public
      login.html
      dashboard.html
      customers.html
      invoices.html
      packages.html
      reports.html
      services.html
      settings.html
  ...other project files...
```

In this layout, `public/` becomes the **document root** of the web server (Apache, Nginx, or PHP built-in), so users never see `/public` in the URL.

## 4. Google Cloud Run Notes

When deploying to Google Cloud Run:

- The container should run a web server whose document root is `/autobot/public` (or equivalent inside the image).
- All links in HTML/JS should use paths relative to `/` without the `/autobot` prefix.
  - E.g. `<a href="/dashboard.html">` instead of `<a href="/autobot/dashboard.html">`.
  - E.g. `<script src="/assets/js/payment.js"></script>` instead of `/autobot/assets/js/payment.js`.
- API calls from the frontend should hit `/api/...` (e.g. `/api/payment/methods`) and the web server should route those to `api/*.php`.
- Separate domains or paths can be used for admin if desired, but `/admin/...` is enough for a single Cloud Run service.

## 5. Migration from Local LAMP (/autobot)

On local LAMP you might currently use URLs like:

- `http://localhost/autobot/public/login.html`
- `http://localhost/autobot/login.html`
- `http://localhost/autobot/admin/login.html`

For production/Cloud Run, these should become:

- `https://<service>.<region>.run.app/login.html`
- `https://<service>.<region>.run.app/dashboard.html`
- `https://<service>.<region>.run.app/admin/login.html`

Therefore:

1. Update all hard-coded links in HTML/JS to remove the `/autobot` prefix.
2. Ensure static assets are served from `/assets/...` under `public/`.
3. Configure the web server in the container (see `docker/apache-config.conf`) to:
   - Use `public/` as document root.
   - Proxy or rewrite `/api/...` requests to the PHP handler in `api/`.

This structure is suitable for a single Cloud Run service and keeps URLs clean and consistent.
