<?php
declare(strict_types=1);

/**
 * dashboard/admin-auth.php
 * Single password session gate used by all dashboard pages.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
  $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
  if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path' => '/',
      'secure' => $isHttps,
      'httponly' => true,
      'samesite' => 'Lax'
    ]);
  }
  session_start();
}

$cfg = __DIR__ . '/_private/admin-config.php';
if (!is_file($cfg)) $cfg = __DIR__ . '/admin-config.php';
$config = @include $cfg;
if (!is_array($config)) $config = [];

$ADMIN_PASSWORD = (string)($config['password'] ?? '');
$TZ = (string)($config['timezone'] ?? 'UTC');
if ($TZ) { @date_default_timezone_set($TZ); }

function bc_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$next = (string)($_GET['next'] ?? '');
if ($next === '') $next = (string)($_SERVER['REQUEST_URI'] ?? '/dashboard/leads-dashboard.php');
if ($next && !str_starts_with($next, '/')) $next = '/dashboard/leads-dashboard.php';

if (!empty($_GET['logout'])) {
  $_SESSION['bc_admin'] = false;
  session_regenerate_id(true);
  header('Location: ' . $next);
  exit;
}

if (!empty($_SESSION['bc_admin'])) {
  return; // already authed
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pass = (string)($_POST['password'] ?? '');
  if ($ADMIN_PASSWORD !== '' && hash_equals($ADMIN_PASSWORD, $pass)) {
    $_SESSION['bc_admin'] = true;
    session_regenerate_id(true);
    $go = (string)($_POST['next'] ?? $next);
    if (!$go || !str_starts_with($go, '/')) $go = '/dashboard/leads-dashboard.php';
    header('Location: ' . $go);
    exit;
  }
  $error = 'Wrong password.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Dashboard Login</title>
  <style>
    :root{
      --bg:#f6f9ff;
      --card:#ffffff;
      --line:#e5e7eb;
      --txt:#0f172a;
      --muted:#64748b;
      --brand:#002842;
      --pri:#CF0558;
    }
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(180deg, rgba(0,40,66,.06), transparent 55%), var(--bg);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Arial;color:var(--txt);padding:18px}
    .wrap{width:100%;max-width:420px}
    .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px 16px;box-shadow:0 10px 22px rgba(2,8,23,.06)}
    h1{margin:0 0 6px;font-size:18px;color:var(--brand)}
    p{margin:0 0 14px;color:var(--muted)}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    input{flex:1;min-width:210px;padding:12px 12px;border-radius:12px;border:1px solid var(--line);background:#fff;color:var(--txt);outline:none}
    input:focus{border-color:rgba(207,5,88,.6);box-shadow:0 0 0 4px rgba(207,5,88,.12)}
    .btn{padding:12px 14px;border-radius:12px;border:1px solid rgba(207,5,88,.55);background:var(--pri);color:#fff;font-weight:800;cursor:pointer}
    .btn:hover{filter:brightness(1.02)}
    .err{margin-top:10px;color:#991b1b;background:#fee2e2;border:1px solid #fecaca;padding:10px 12px;border-radius:12px}
    .meta{margin-top:10px;color:var(--muted);font-size:12px}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1>Barbara Cleaning — Dashboard</h1>
      <p>Enter your password to continue.</p>

      <form method="post" action="">
        <input type="hidden" name="next" value="<?= bc_h($next) ?>">
        <div class="row">
          <input type="password" name="password" placeholder="Password" autocomplete="current-password" required>
          <button class="btn" type="submit">Login</button>
        </div>
      </form>

      <?php if ($error): ?><div class="err"><?= bc_h($error) ?></div><?php endif; ?>

      <div class="meta">If you changed the password, update <code>admin-config.php</code>.</div>
    </div>
  </div>
</body>
</html>
<?php exit; ?>
