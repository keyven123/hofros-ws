# Hostinger: SPA in public_html + Laravel in backend

If the live site serves only the frontend from `public_html`, Laravel
lives in `backend`, which is **not** the document root, so `/api` never
runs PHP — Hostinger returns its HTML 404. The bridge below fixes that.

## Required layout

```text
domains/your-hofros-site.hostingersite.com/
  public_html/                 ← document root (SPA from hofros-core/dist, optional)
    index.html
    assets/
    .htaccess                  ← from hostinger/public_html/.htaccess
    api/
      index.php                ← from hostinger/public_html/api/index.php
      .htaccess                ← from hostinger/public_html/api/.htaccess
  backend/                     ← full hofros-ws project
    app/
    bootstrap/
    config/
    public/
    routes/
    vendor/
    .env
    ...
```

## Upload these 3 files

From this repo, copy into Hostinger:

| Local file | Upload to |
|---|---|
| `hofros-ws/hostinger/public_html/.htaccess` | `public_html/.htaccess` |
| `hofros-ws/hostinger/public_html/api/index.php` | `public_html/api/index.php` |
| `hofros-ws/hostinger/public_html/api/.htaccess` | `public_html/api/.htaccess` |

Create the `public_html/api` folder if it does not exist.

If the SPA is also hosted on Hostinger (same-origin), upload the build from
`hofros-core/dist/` into `public_html/` (`index.html`, `assets/`, logos, favicon, etc.).
If the SPA is on Vercel, `public_html` only needs the 3 files above.

## Storage (uploads)

Uploaded files are served from Laravel `storage/app/public`.
After deploy, on the server:

```bash
cd backend
php artisan storage:link
```

Then either:

- Symlink / copy `backend/public/storage` → `public_html/storage`, **or**
- Keep document root as `backend/public` (see alternative below).

## backend/.env checks

### Same-origin (SPA + API on Hostinger)

```env
APP_URL=https://your-hofros-site.hostingersite.com
FRONTEND_URL=https://your-hofros-site.hostingersite.com
CORS_ALLOWED_ORIGINS=
```

### Split deploy (SPA on Vercel → API on Hostinger)

```env
APP_URL=https://your-hofros-site.hostingersite.com
FRONTEND_URL=https://hofros.vercel.app
CORS_ALLOWED_ORIGINS=https://hofros.vercel.app
```

On Vercel (hofros-core), set a **build** env var to the Hostinger API:

```env
VITE_API_BASE_URL=https://your-hofros-site.hostingersite.com/api/v1
VITE_FRONTEND_URL=https://hofros.vercel.app
```

Then redeploy Vercel. Relative `/api/v1` will not work on Vercel (no Laravel there).
Hofros pages call `/v1/...`, and the client strips a duplicate `/v1` when the base already ends in `/api/v1`.

Then on the server:

```bash
cd backend
php artisan key:generate   # only if APP_KEY is empty
php artisan config:clear
php artisan migrate --force
php artisan storage:link
```

## Verify

These must return **JSON**:

1. https://your-hofros-site.hostingersite.com/api
2. https://your-hofros-site.hostingersite.com/api/health
3. https://your-hofros-site.hostingersite.com/api/v1/health

If you still get Hostinger’s HTML 404, the `api` folder was not created under `public_html`, or the bridge path to `backend` is wrong.

If you get a JSON error `"Laravel backend folder not found"`, rename/move the Laravel project so it sits next to `public_html` and is named exactly `backend`.

## Alternative (cleaner long-term)

In hPanel → Websites → your site → **Document root** → set to `backend/public`, then (optionally) copy the SPA build into `backend/public/`. Then you can remove the `public_html/api` bridge.
