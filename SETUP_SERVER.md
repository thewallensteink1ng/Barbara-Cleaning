# Hostinger setup (no confusion)

## 1) GitHub (make folders appear correctly)
If you created `_private` as a **file**, delete it and upload this repo again.

`_private` and `_logs` are folders here because they include `.gitkeep`.

## 2) Hostinger deploy path
Deploy into:

- `/public_html/dashboard/`

Make sure the target folder is empty on first deploy.

## 3) Create server-only configs
On the server (File Manager):

- Copy `_private/db-config.example.php` to `_private/db-config.php` and fill credentials
- Copy `_private/admin-config.example.php` to `_private/admin-config.php` and set password
- Optional: copy `_private/pixel_config.example.json` to `_private/pixel_config.json`

## 4) Security
`_private/` and `_logs/` are blocked by `.htaccess`.

## 5) Common 404 cause
Make sure you don't end up with:

- `/public_html/dashboard/Barbara-Cleaning/index.php`

It must be:

- `/public_html/dashboard/index.php`
