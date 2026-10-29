# Hofros API (`hofros-ws`)

Laravel backend for Hofros. Deployment foundation mirrors LPay (`lpay-ws`).

## Quick start (XAMPP MySQL)

1. Start Apache + MySQL in XAMPP.
2. Create database `hofro` (phpMyAdmin or CLI).
3. Install and run:

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

- API root: http://127.0.0.1:8000/api
- Health: http://127.0.0.1:8000/api/health
- v1 health: http://127.0.0.1:8000/api/v1/health

## Deploy (Hostinger)

See [`hostinger/README.md`](hostinger/README.md) for the `public_html/api` bridge,
`.env` values for split deploy (Vercel frontend → Hostinger API), and verification steps.
