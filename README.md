# Barbara Cleaning — Dashboard (/dashboard)

This folder is meant to be deployed to:

- Hostinger path: `public_html/dashboard/`
- Public URL: `https://YOURDOMAIN.com/dashboard/`

## 1) Deploy

### Option A) Deploy from Git (recommended)
Deploy this repository to:

- `public_html/dashboard/`

### Option B) Upload ZIP
Upload the contents of this repo into:

- `public_html/dashboard/`

## 2) Create SERVER-ONLY configs

Create these files on the server (do **not** commit them to GitHub):

- `dashboard/_private/db-config.php` (copy from `db-config.example.php`)
- `dashboard/_private/admin-config.php` (copy from `admin-config.example.php`)
- optional: `dashboard/_private/pixel_config.json` (copy from `pixel_config.example.json`)

## 3) Quick tests

1) Folder reachable:
- `https://YOURDOMAIN.com/dashboard/ping.txt` should show `OK`

2) Endpoint reachable:
- `https://YOURDOMAIN.com/dashboard/lead-endpoint.php` should return JSON (e.g. `invalid_json`)

3) Lead insert test (run in browser console):

```js
fetch('/dashboard/lead-endpoint.php', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    event_id: 'test_lead_' + Date.now(),
    data: {
      name: 'TEST LEAD',
      phone: '+353871234567',
      email: 'test@example.com',
      service_type: 'deep_cleaning',
      bedrooms: '2',
      bathrooms: '1',
      country: 'IE'
    },
    meta: {
      page_url: location.href,
      referrer: document.referrer,
      user_agent: navigator.userAgent
    }
  })
}).then(r => r.text()).then(console.log).catch(console.error);
```

## 4) IMPORTANT

- Do not commit anything inside `_private/` (real configs) or `_logs/`.
- If you deploy from Git and it overwrites the folder, you may need to re-copy `_private/*.php` back onto the server.
