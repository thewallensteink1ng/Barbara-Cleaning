<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
$dbcfg = __DIR__ . '/_private/db-config.php';
if (!is_file($dbcfg)) $dbcfg = __DIR__ . '/db-config.php';
require_once $dbcfg;
require_once __DIR__ . '/lib/bc-capi.php';

global $pdo;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function col_exists(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $key = $table . '.' . $col;
  if (isset($cache[$key])) return $cache[$key];
  try {
    // Prefer INFORMATION_SCHEMA (reliable on Hostinger/MariaDB)
    $st = $pdo->prepare("\n      SELECT 1\n      FROM INFORMATION_SCHEMA.COLUMNS\n      WHERE TABLE_SCHEMA = DATABASE()\n        AND TABLE_NAME = :t\n        AND COLUMN_NAME = :c\n      LIMIT 1\n    ");
    $st->execute([':t' => $table, ':c' => $col]);
    $cache[$key] = (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    // Fallback (avoid prepared statements for SHOW COLUMNS)
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) && preg_match('/^[A-Za-z0-9_]+$/', $col)) {
      try {
        $sql = "SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($col);
        $r = $pdo->query($sql);
        $cache[$key] = (bool)$r->fetch(PDO::FETCH_ASSOC);
      } catch (Throwable $e2) {
        $cache[$key] = false;
      }
    } else {
      $cache[$key] = false;
    }
  }
  return $cache[$key];
}

function stage_of(array $r): string {
  $paid = (float)($r['paid_value'] ?? 0);
  $scheduledFor = trim((string)($r['scheduled_for'] ?? ''));
  $went = (int)($r['went_whatsapp'] ?? 0);

  if ($paid > 0) return 'purchase';
  if ($scheduledFor !== '') return 'schedule';
  if ($went === 1) return 'contact';
  return 'lead';
}

function normalize_search_digits(string $q): array {
  $d = (string)preg_replace('/\D+/', '', $q);
  $d353 = $d;
  if (str_starts_with($d, '00')) $d353 = substr($d, 2);
  if (str_starts_with($d353, '0')) $d353 = '353' . substr($d353, 1);
  if (!str_starts_with($d353, '353') && strlen($d353) >= 8 && strlen($d353) <= 10) $d353 = '353' . $d353;

  $d0 = $d;
  if (str_starts_with($d0, '353')) $d0 = '0' . substr($d0, 3);

  return [$d, $d353, $d0];
}

function flash_set(string $k, string $v): void { $_SESSION['bc_flash'][$k] = $v; }
function flash_get(string $k): string {
  $v = (string)($_SESSION['bc_flash'][$k] ?? '');
  unset($_SESSION['bc_flash'][$k]);
  return $v;
}

/**
 * Envia evento Meta CAPI
 * Regras:
 * - Lead/Contact: SEM value
 * - Schedule/Purchase: value SOMENTE manual (você preenche)
 */
function send_event(PDO $pdo, string $event, array $row): array {
  $map = [
    'Lead' => ['id'=>'lead_event_id','sent'=>'lead_event_sent','resp'=>'lead_event_response','src'=>'website'],
    'Contact' => ['id'=>'contact_event_id','sent'=>'contact_event_sent','resp'=>'contact_event_response','src'=>'website'],
    'Schedule' => ['id'=>'scheduled_event_id','sent'=>'scheduled_event_sent','resp'=>'scheduled_event_response','src'=>'system_generated'],
    'Purchase' => ['id'=>'purchase_event_id','sent'=>'purchase_event_sent','resp'=>'purchase_event_response','src'=>'system_generated'],
  ];
  $m = $map[$event] ?? $map['Lead'];

  $leadId = (int)($row['id'] ?? 0);

  $eid = trim((string)($row[$m['id']] ?? ''));
  if ($eid === '') {
    $eid = strtolower($event) . '_dash_' . $leadId;
    try {
      if (col_exists($pdo,'bc_leads',$m['id'])) {
        $pdo->prepare("UPDATE bc_leads SET {$m['id']}=:eid WHERE id=:id")->execute([':eid'=>$eid, ':id'=>$leadId]);
      }
    } catch (Throwable $e) {}
  }

  // Para dashboard, event_time = AGORA (momento que você marca o evento)
  $eventTime = time();

  $user = [
    'name' => (string)($row['name'] ?? ''),
    'email'=> (string)($row['email'] ?? ''),
    'phone'=> (string)($row['phone'] ?? ''),
    'fbp'  => (string)($row['fbp'] ?? ''),
    'fbc'  => (string)($row['fbc'] ?? ''),
    'client_ip' => (string)($row['ip_address'] ?? ''),
    'client_ua' => (string)($row['user_agent'] ?? ''),
  ];

  $custom = [
    'currency' => 'EUR',
    'lead_id'  => $leadId,
  ];

  if ($event === 'Schedule') {
    $v = (float)($row['scheduled_value'] ?? 0);
    if ($v > 0) $custom['value'] = $v; // manual
    $custom['scheduled_for'] = (string)($row['scheduled_for'] ?? '');
  } elseif ($event === 'Purchase') {
    $v = (float)($row['paid_value'] ?? 0);
    if ($v > 0) $custom['value'] = $v; // manual
  } else {
    // Lead/Contact: sem value (por pedido)
    $custom['service_type'] = (string)($row['service_type'] ?? '') ?: null;
  }

  $res = bc_send_capi_event($pdo, [
    'event_name' => $event,
    'event_id' => $eid,
    'event_time' => $eventTime,
    'action_source' => $m['src'],
    'event_source_url' => (string)($row['page_url'] ?? ''),
    'user' => $user,
    'custom' => $custom
  ]);

  // Persist status se as colunas existirem
  try {
    $upd = [];
    $params = [
      ':id'=>$leadId,
      ':sent'=>($res['ok']?1:0),
      ':resp'=>json_encode($res, JSON_UNESCAPED_SLASHES),
    ];
    if (col_exists($pdo,'bc_leads',$m['sent'])) $upd[] = "{$m['sent']}=:sent";
    if (col_exists($pdo,'bc_leads',$m['resp'])) $upd[] = "{$m['resp']}=:resp";
    if ($upd) {
      $pdo->prepare("UPDATE bc_leads SET ".implode(',', $upd)." WHERE id=:id")->execute($params);
    }
  } catch (Throwable $e) {}

  return $res;
}

/* =========================
   ACTIONS (POST)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);

  $q = (string)($_POST['q'] ?? '');
  $stageFilter = (string)($_POST['stage'] ?? 'all');
  $sort = (string)($_POST['sort'] ?? 'priority');
  $page = max(1, (int)($_POST['page'] ?? 1));

  $redirect = 'leads-dashboard.php';
  $qs = http_build_query(['q'=>$q,'stage'=>$stageFilter,'sort'=>$sort,'page'=>$page]);
  if ($qs) $redirect .= '?' . $qs;

  $row = null;
  if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM bc_leads WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  if ($action === 'resend' && $row) {
    $event = (string)($_POST['event'] ?? 'Lead');
    if (!in_array($event, ['Lead','Contact','Schedule','Purchase'], true)) $event = 'Lead';

    $res = send_event($pdo, $event, $row);
    flash_set('msg', $res['ok'] ? "✅ $event sent" : "⚠️ $event failed (check _logs/capi_events.log)");
    header('Location: ' . $redirect); exit;
  }

  if ($action === 'save_schedule' && $row) {
    $date = trim((string)($_POST['scheduled_for'] ?? ''));
    $val  = (float)($_POST['scheduled_value'] ?? 0);

    // Salva schedule
    try {
      $pdo->prepare("UPDATE bc_leads SET scheduled_for=:d, scheduled_value=:v WHERE id=:id")
          ->execute([':d'=>$date, ':v'=>$val, ':id'=>(int)$row['id']]);
      // opcional: scheduled_at se existir
      if (col_exists($pdo,'bc_leads','scheduled_at')) {
        $pdo->prepare("UPDATE bc_leads SET scheduled_at=COALESCE(scheduled_at, NOW()) WHERE id=:id")
            ->execute([':id'=>(int)$row['id']]);
      }
    } catch (Throwable $e) {}

    $st = $pdo->prepare("SELECT * FROM bc_leads WHERE id=? LIMIT 1");
    $st->execute([(int)$row['id']]);
    $row2 = $st->fetch(PDO::FETCH_ASSOC) ?: $row;

    $res = send_event($pdo, 'Schedule', $row2);
    flash_set('msg', $res['ok'] ? "✅ Schedule sent" : "⚠️ Schedule failed");
    header('Location: ' . $redirect); exit;
  }

  if ($action === 'save_purchase' && $row) {
    $val = (float)($_POST['paid_value'] ?? 0);
    $paidAt = trim((string)($_POST['paid_at'] ?? ''));
    if ($paidAt === '') $paidAt = date('Y-m-d H:i:s');

    try {
      $pdo->prepare("UPDATE bc_leads SET paid_value=:v, paid_at=:t WHERE id=:id")
          ->execute([':v'=>$val, ':t'=>$paidAt, ':id'=>(int)$row['id']]);
    } catch (Throwable $e) {}

    $st = $pdo->prepare("SELECT * FROM bc_leads WHERE id=? LIMIT 1");
    $st->execute([(int)$row['id']]);
    $row2 = $st->fetch(PDO::FETCH_ASSOC) ?: $row;

    $res = send_event($pdo, 'Purchase', $row2);
    flash_set('msg', $res['ok'] ? "✅ Purchase sent" : "⚠️ Purchase failed");
    header('Location: ' . $redirect); exit;
  }

  header('Location: ' . $redirect); exit;
}

/* =========================
   LIST / SEARCH / SORT
========================= */
$search = trim((string)($_GET['q'] ?? ''));
$stageFilter = trim((string)($_GET['stage'] ?? 'all'));
$sort = trim((string)($_GET['sort'] ?? 'priority'));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = [];
$params = [];

if ($search !== '') {
  [$digits, $d353, $d0] = normalize_search_digits($search);
  $where[] = "(name LIKE :s OR email LIKE :s OR phone LIKE :s
    OR REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-','') LIKE :sd
    OR REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-','') LIKE :sd353
    OR REPLACE(REPLACE(REPLACE(phone,'+',''),' ',''),'-','') LIKE :sd0)";
  $params[':s'] = '%' . $search . '%';
  $params[':sd'] = '%' . $digits . '%';
  $params[':sd353'] = '%' . $d353 . '%';
  $params[':sd0'] = '%' . $d0 . '%';
}

if ($stageFilter !== '' && $stageFilter !== 'all') {
  if ($stageFilter === 'lead') {
    $where[] = "(COALESCE(went_whatsapp,0)=0 AND (scheduled_for IS NULL OR scheduled_for='') AND COALESCE(paid_value,0)<=0)";
  } elseif ($stageFilter === 'contact') {
    $where[] = "(COALESCE(went_whatsapp,0)=1 AND (scheduled_for IS NULL OR scheduled_for='') AND COALESCE(paid_value,0)<=0)";
  } elseif ($stageFilter === 'schedule') {
    $where[] = "((scheduled_for IS NOT NULL AND scheduled_for<>'') AND COALESCE(paid_value,0)<=0)";
  } elseif ($stageFilter === 'purchase') {
    $where[] = "(COALESCE(paid_value,0)>0)";
  }
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ORDER BY seguro (sem injection)
$orderSql = "ORDER BY id DESC";
if ($sort === 'newest') $orderSql = "ORDER BY id DESC";
if ($sort === 'oldest') $orderSql = "ORDER BY id ASC";
if ($sort === 'stage') {
  // Purchase -> Schedule -> Contact -> Lead
  $orderSql = "ORDER BY
    CASE
      WHEN COALESCE(paid_value,0)>0 THEN 0
      WHEN (scheduled_for IS NOT NULL AND scheduled_for<>'') THEN 1
      WHEN COALESCE(went_whatsapp,0)=1 THEN 2
      ELSE 3
    END ASC, id DESC";
}
if ($sort === 'priority') {
  // prioridade: Lead sem WhatsApp primeiro -> Contact -> Schedule -> Purchase
  $orderSql = "ORDER BY
    CASE
      WHEN (COALESCE(went_whatsapp,0)=0 AND (scheduled_for IS NULL OR scheduled_for='') AND COALESCE(paid_value,0)<=0) THEN 0
      WHEN (COALESCE(went_whatsapp,0)=1 AND (scheduled_for IS NULL OR scheduled_for='') AND COALESCE(paid_value,0)<=0) THEN 1
      WHEN ((scheduled_for IS NOT NULL AND scheduled_for<>'') AND COALESCE(paid_value,0)<=0) THEN 2
      ELSE 3
    END ASC, id DESC";
}
if ($sort === 'whatsapp_missing') {
  $orderSql = "ORDER BY CASE WHEN COALESCE(went_whatsapp,0)=0 THEN 0 ELSE 1 END ASC, id DESC";
}
if ($sort === 'whatsapp_done') {
  $orderSql = "ORDER BY CASE WHEN COALESCE(went_whatsapp,0)=1 THEN 0 ELSE 1 END ASC, id DESC";
}

$countSt = $pdo->prepare("SELECT COUNT(*) FROM bc_leads $whereSql");
$countSt->execute($params);
$total = (int)$countSt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT * FROM bc_leads $whereSql $orderSql LIMIT :lim OFFSET :off";
$listSt = $pdo->prepare($sql);
foreach ($params as $k=>$v) $listSt->bindValue($k, $v);
$listSt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$listSt->bindValue(':off', $offset, PDO::PARAM_INT);
$listSt->execute();
$rows = $listSt->fetchAll(PDO::FETCH_ASSOC);

$msg = flash_get('msg');

// sent flags (existem ou não)
$hasLeadSent = col_exists($pdo,'bc_leads','lead_event_sent');
$hasConSent  = col_exists($pdo,'bc_leads','contact_event_sent');
$hasSchSent  = col_exists($pdo,'bc_leads','scheduled_event_sent');
$hasPurSent  = col_exists($pdo,'bc_leads','purchase_event_sent');

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Leads Dashboard — Barbara Cleaning</title>

  <style>
    :root{
      --bg:#f7faff;
      --surface:#ffffff;
      --line:rgba(15,23,42,.10);
      --txt:#0f172a;
      --muted:#64748b;
      --brand:#002842;
      --pri:#CF0558;
      --wa:#25d366;
      --ok:#16a34a;
      --warn:#f59e0b;

      --radius:16px;
      --radius-sm:12px;
      --shadow:0 14px 30px rgba(2,8,23,.08);
      --shadow-sm:0 8px 18px rgba(2,8,23,.06);
    }

    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
      color:var(--txt);
      background:
        radial-gradient(900px 500px at 15% -10%, rgba(207,5,88,.10), transparent 60%),
        radial-gradient(900px 500px at 110% 15%, rgba(0,40,66,.10), transparent 55%),
        var(--bg);
    }

    /* Prevent iOS zoom on inputs */
    @media(max-width: 480px){
      input, select, textarea, button{ font-size:16px !important; }
    }

    .wrap{max-width:1200px;margin:0 auto;padding:18px 14px 72px}

    .top{
      position:sticky;top:0;z-index:50;
      background:rgba(247,250,255,.80);
      backdrop-filter: blur(14px);
      border-bottom:1px solid rgba(15,23,42,.10);
      margin:-18px -14px 14px;
      padding:14px 14px;
    }
    .top-inner{
      max-width:1200px;margin:0 auto;
      display:flex;gap:14px;flex-wrap:wrap;
      align-items:center;justify-content:space-between;
    }

    .brand{display:flex;gap:12px;align-items:center;min-width:240px}
    .logo{
      width:44px;height:44px;border-radius:14px;
      background:linear-gradient(180deg,#fff,#f4f7ff);
      border:1px solid rgba(0,40,66,.16);
      display:grid;place-items:center;
      font-weight:900;color:var(--brand);
      box-shadow:0 10px 22px rgba(2,8,23,.08);
    }
    h1{margin:0;font-size:18px;color:var(--brand);letter-spacing:.2px}
    .sub{margin-top:2px;color:var(--muted);font-size:12px}

    .pill{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

    .btn{
      display:inline-flex;align-items:center;justify-content:center;gap:8px;
      padding:10px 12px;
      border-radius:var(--radius-sm);
      border:1px solid rgba(0,40,66,.18);
      background:rgba(255,255,255,.92);
      color:var(--brand);
      cursor:pointer;text-decoration:none;font-weight:900;white-space:nowrap;
      transition:transform .12s ease, box-shadow .12s ease, background-color .12s ease, border-color .12s ease, filter .12s ease;
    }
    .btn:hover{transform:translateY(-1px);box-shadow:var(--shadow-sm);border-color:rgba(0,40,66,.26)}
    .btn:active{transform:translateY(0);box-shadow:none}
    .btn.primary{
      background:var(--pri);
      border-color:rgba(207,5,88,.65);
      color:#fff;
      box-shadow:0 10px 18px rgba(207,5,88,.18);
    }
    .btn.primary:hover{filter:brightness(1.03)}
    .btn.soft{
      background:rgba(0,40,66,.04);
      border-color:rgba(0,40,66,.12);
      box-shadow:none;
    }
    .btn.soft:hover{background:rgba(0,40,66,.07);border-color:rgba(0,40,66,.18);transform:translateY(0)}
    .btn.wa{
      background:rgba(37,211,102,.10);
      border-color:rgba(37,211,102,.30);
      color:#0f5132;
      box-shadow:none;
    }
    .btn.wa:hover{background:rgba(37,211,102,.14);border-color:rgba(37,211,102,.40);transform:translateY(0)}
    .btn.ok{
      background:rgba(22,163,74,.12);
      border-color:rgba(22,163,74,.32);
      color:#166534;
      box-shadow:none;
      transform:none;
    }

    .btn[disabled], button[disabled]{opacity:.55;cursor:not-allowed;box-shadow:none;transform:none}
    .mini{height:40px;padding:8px 10px;border-radius:12px;font-size:13px}

    .in, select{
      height:44px;border-radius:var(--radius-sm);
      border:1px solid var(--line);
      background:rgba(255,255,255,.92);
      color:var(--txt);
      padding:0 12px;
      outline:none;
      transition:box-shadow .12s ease, border-color .12s ease, background-color .12s ease;
    }
    .in:focus, select:focus{
      border-color:rgba(207,5,88,.60);
      box-shadow:0 0 0 4px rgba(207,5,88,.12);
      background:#fff;
    }

    .msg{
      margin:10px 0 14px;padding:12px 14px;border-radius:14px;
      border:1px solid rgba(22,163,74,.22);
      background:rgba(22,163,74,.08);
      color:#166534;
      font-weight:800;
    }

    .grid{display:grid;grid-template-columns:1fr;gap:14px;align-items:start}
    @media(min-width:980px){ .grid{grid-template-columns:1.6fr .4fr;} }

    .card{
      background:rgba(255,255,255,.86);
      border:1px solid var(--line);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      overflow:hidden;
    }
    .card .hd{
      padding:14px 16px 12px;
      border-bottom:1px solid rgba(15,23,42,.08);
      background:linear-gradient(180deg, rgba(0,40,66,.035), rgba(255,255,255,0));
    }
    .card .bd{padding:16px}

    /* Filters: more organized */
    .filters{
      display:grid;
      grid-template-columns: 1fr 200px 280px auto auto;
      gap:10px;
      align-items:center;
    }
    .filters .in{min-width:220px}
    .filters select{min-width:170px}
    .filters .note{justify-self:end;white-space:nowrap}

    @media(max-width:980px){
      .filters{grid-template-columns:1fr 1fr;align-items:stretch}
      .filters .in{grid-column:1 / -1}
      .filters select{min-width:0}
      .filters .note{grid-column:1 / -1;justify-self:start}
    }
    @media(max-width:520px){
      .filters{grid-template-columns:1fr}
      .filters .btn, .filters a.btn{width:100%}
      .filters .note{margin-top:-2px}
    }

    .leadList{display:flex;flex-direction:column;gap:12px}
    .lead{
      border:1px solid var(--line);
      border-radius:var(--radius);
      padding:16px;
      background:rgba(255,255,255,.95);
      box-shadow:var(--shadow-sm);
      transition:box-shadow .12s ease, border-color .12s ease, transform .12s ease;
    }
    .lead:hover{border-color:rgba(207,5,88,.20);box-shadow:0 14px 28px rgba(2,8,23,.08)}
    .leadTop{
      display:grid;
      grid-template-columns: 1fr auto;
      gap:14px;
      align-items:start;
    }
    .leadTop .row{justify-content:flex-end}
    @media(max-width:820px){
      .leadTop{grid-template-columns:1fr}
      .leadTop .row{justify-content:flex-start}
    }

    .name{font-weight:900;color:var(--brand);font-size:16px;letter-spacing:.1px}
    .meta{color:var(--muted);font-size:12px;margin-top:3px}

    .tags{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}
    .tag{
      font-size:12px;
      border:1px solid rgba(0,40,66,.14);
      background:rgba(0,40,66,.03);
      padding:6px 10px;
      border-radius:999px;
      color:var(--muted);
      font-weight:900;
      letter-spacing:.1px;
    }
    .tag.stage{color:var(--brand);background:rgba(0,40,66,.06)}
    .tag.wa{border-color:rgba(37,211,102,.22);background:rgba(37,211,102,.08);color:#0f5132}
    .tag.no{border-color:rgba(245,158,11,.25);background:rgba(245,158,11,.08);color:#7c2d12}
    .tag.ok{border-color:rgba(22,163,74,.22);background:rgba(22,163,74,.08);color:#166534}

    .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    .mini{height:40px}

    /* Details (Schedule/Purchase) */
    details{
      border-top:1px dashed rgba(100,116,139,.25);
      margin-top:12px;
      padding-top:12px;
    }
    summary{
      cursor:pointer;
      color:var(--muted);
      font-weight:900;
      list-style:none;
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:10px 12px;
      border-radius:12px;
      background:rgba(0,40,66,.03);
      transition:background-color .12s ease;
    }
    summary::-webkit-details-marker{display:none}
    summary:hover{background:rgba(0,40,66,.05)}
    summary::after{
      content:"›";
      font-size:18px;
      line-height:1;
      transform:rotate(90deg);
      transition:transform .12s ease;
      color:rgba(100,116,139,.9);
    }
    details[open] summary::after{transform:rotate(-90deg)}

    .two{display:grid;grid-template-columns:1fr;gap:10px}
    @media(min-width:560px){ .two{grid-template-columns:1fr 1fr;} }

    .note{color:var(--muted);font-size:12px;font-weight:800}
</style>
</head>

<body>
  <div class="top">
    <div class="top-inner">
      <div class="brand">
        <div class="logo">BC</div>
        <div>
          <h1>Leads Dashboard</h1>
          <div class="sub">Lead → Contact → Schedule → Purchase</div>
        </div>
      </div>

      <div class="pill">
        <a class="btn soft" href="pixels-dashboard.php">Pixels</a>
        <a class="btn soft" href="google-ads-dashboard.php">Google Ads</a>
        <a class="btn soft" href="export-capi-last7.php?days=7">Export JSONL (last 7 days)</a>
        <a class="btn" href="?logout=1">Logout</a>
      </div>
    </div>
  </div>

  <div class="wrap">
    <?php if ($msg): ?><div class="msg"><?=h($msg)?></div><?php endif; ?>

    <div class="grid">
      <div class="card">
        <div class="hd">
          <form class="filters" method="get" action="">
            <input class="in" type="text" name="q" value="<?=h($search)?>" placeholder="Search by name, email or phone (any IE format)">

            <select name="stage">
              <option value="all" <?= $stageFilter==='all'?'selected':'' ?>>All stages</option>
              <option value="lead" <?= $stageFilter==='lead'?'selected':'' ?>>Lead only (no WhatsApp)</option>
              <option value="contact" <?= $stageFilter==='contact'?'selected':'' ?>>Contact (WhatsApp)</option>
              <option value="schedule" <?= $stageFilter==='schedule'?'selected':'' ?>>Scheduled</option>
              <option value="purchase" <?= $stageFilter==='purchase'?'selected':'' ?>>Purchase</option>
            </select>

            <select name="sort">
              <option value="priority" <?= $sort==='priority'?'selected':'' ?>>Sort: Priority (action first)</option>
              <option value="stage" <?= $sort==='stage'?'selected':'' ?>>Sort: Stage (Purchase→Lead)</option>
              <option value="whatsapp_missing" <?= $sort==='whatsapp_missing'?'selected':'' ?>>Sort: No WhatsApp first</option>
              <option value="whatsapp_done" <?= $sort==='whatsapp_done'?'selected':'' ?>>Sort: WhatsApp first</option>
              <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Sort: Newest</option>
              <option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>Sort: Oldest</option>
            </select>

            <button class="btn primary" type="submit">Apply</button>
            <a class="btn soft" href="leads-dashboard.php">Reset</a>

            <span class="note"><?= (int)$total ?> leads</span>
          </form>
        </div>

        <div class="bd">
          <div class="leadList">
            <?php foreach ($rows as $r):
              $id = (int)($r['id'] ?? 0);
              $stage = stage_of($r);
              $wa = (int)($r['went_whatsapp'] ?? 0) === 1;

              $leadSent = $hasLeadSent ? ((int)($r['lead_event_sent'] ?? 0) === 1) : false;
              $conSent  = $hasConSent  ? ((int)($r['contact_event_sent'] ?? 0) === 1) : false;
              $schSent  = $hasSchSent  ? ((int)($r['scheduled_event_sent'] ?? 0) === 1) : false;
              $purSent  = $hasPurSent  ? ((int)($r['purchase_event_sent'] ?? 0) === 1) : false;

              $phone = (string)($r['phone'] ?? '');
              $digits = preg_replace('/\D+/', '', $phone);
              if (str_starts_with($digits, '00')) $digits = substr($digits, 2);
              if (str_starts_with($digits, '0')) $digits = '353' . substr($digits, 1);
              if (!str_starts_with($digits, '353') && strlen($digits) >= 8 && strlen($digits) <= 10) $digits = '353' . $digits;
              $waLink = $digits ? ('https://wa.me/' . $digits . '?text=' . rawurlencode("Hi! Barbara Cleaning here 😊")) : '#';
            ?>
            <div class="lead">
              <div class="leadTop">
                <div style="min-width:240px;flex:1">
                  <div class="name"><?=h((string)($r['name'] ?? ''))?></div>
                  <div class="meta"><?=h((string)($r['email'] ?? ''))?> · <?=h($phone)?></div>
                  <div class="meta"><?=h((string)($r['created_at'] ?? ''))?></div>

                  <div class="tags">
                    <span class="tag stage"><?= strtoupper($stage) ?></span>
                    <span class="tag <?= $wa ? 'wa' : 'no' ?>"><?= $wa ? 'WhatsApp ✅' : 'No WhatsApp' ?></span>

                    <?php if (!empty($r['service_type'])): ?><span class="tag"><?=h((string)$r['service_type'])?></span><?php endif; ?>
                    <?php if (!empty($r['bedrooms']) || !empty($r['bathrooms'])): ?>
                      <span class="tag"><?=h((string)($r['bedrooms'] ?? '?'))?> beds · <?=h((string)($r['bathrooms'] ?? '?'))?> baths</span>
                    <?php endif; ?>

                    <?php if (!empty($r['scheduled_for'])): ?>
                      <span class="tag ok">Scheduled <?=h((string)$r['scheduled_for'])?><?php if (!empty($r['scheduled_value'])) echo ' · €'.h((string)$r['scheduled_value']); ?></span>
                    <?php endif; ?>

                    <?php if (!empty($r['paid_value'])): ?>
                      <span class="tag ok">Paid €<?=h((string)$r['paid_value'])?></span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="row">
                  <a class="btn wa mini" href="<?=h($waLink)?>" target="_blank" rel="noopener">Open WhatsApp</a>

                  <!-- SEND LEAD -->
                  <form method="post" class="row" style="margin:0">
                    <input type="hidden" name="action" value="resend">
                    <input type="hidden" name="event" value="Lead">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="q" value="<?=h($search)?>">
                    <input type="hidden" name="stage" value="<?=h($stageFilter)?>">
                    <input type="hidden" name="sort" value="<?=h($sort)?>">
                    <input type="hidden" name="page" value="<?= (int)$page ?>">
                    <button class="btn primary mini <?= $leadSent?'ok':'' ?>" type="submit">Send Lead</button>
                  </form>

                  <!-- SEND CONTACT (only makes sense if WhatsApp clicked) -->
                  <form method="post" class="row" style="margin:0">
                    <input type="hidden" name="action" value="resend">
                    <input type="hidden" name="event" value="Contact">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="q" value="<?=h($search)?>">
                    <input type="hidden" name="stage" value="<?=h($stageFilter)?>">
                    <input type="hidden" name="sort" value="<?=h($sort)?>">
                    <input type="hidden" name="page" value="<?= (int)$page ?>">
                    <button class="btn primary mini <?= $conSent?'ok':'' ?>" type="submit" <?= $wa? '' : 'disabled title="No WhatsApp click yet"' ?>>Send Contact</button>
                  </form>
                </div>
              </div>

              <details>
                <summary>Schedule / Purchase (manual €)</summary>

                <div class="two" style="margin-top:10px">
                  <!-- SCHEDULE -->
                  <form method="post" class="row" style="margin:0">
                    <input type="hidden" name="action" value="save_schedule">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="q" value="<?=h($search)?>">
                    <input type="hidden" name="stage" value="<?=h($stageFilter)?>">
                    <input type="hidden" name="sort" value="<?=h($sort)?>">
                    <input type="hidden" name="page" value="<?= (int)$page ?>">

                    <input class="in mini" type="date" name="scheduled_for" value="<?=h((string)($r['scheduled_for'] ?? ''))?>">
                    <input class="in mini" style="width:130px" type="number" step="0.01" name="scheduled_value" value="<?=h((string)($r['scheduled_value'] ?? ''))?>" placeholder="Value €">
                    <button class="btn primary mini <?= $schSent?'ok':'' ?>" type="submit">Send Schedule</button>
                  </form>

                  <!-- PURCHASE -->
                  <form method="post" class="row" style="margin:0">
                    <input type="hidden" name="action" value="save_purchase">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="q" value="<?=h($search)?>">
                    <input type="hidden" name="stage" value="<?=h($stageFilter)?>">
                    <input type="hidden" name="sort" value="<?=h($sort)?>">
                    <input type="hidden" name="page" value="<?= (int)$page ?>">

                    <input class="in mini" style="width:130px" type="number" step="0.01" name="paid_value" value="<?=h((string)($r['paid_value'] ?? ''))?>" placeholder="Value €">
                    <input class="in mini" type="text" name="paid_at" value="<?=h((string)($r['paid_at'] ?? ''))?>" placeholder="YYYY-MM-DD HH:MM:SS">
                    <button class="btn primary mini <?= $purSent?'ok':'' ?>" type="submit">Send Purchase</button>
                  </form>
                </div>

                <div class="note" style="margin-top:8px">
                  Lead/Contact are sent <b>without value</b>. Only Schedule/Purchase send your <b>manual €</b>.
                </div>
              </details>
            </div>
            <?php endforeach; ?>

            <?php if (!$rows): ?>
              <div class="note">No leads found.</div>
            <?php endif; ?>
          </div>

          <div class="pill" style="justify-content:space-between;margin-top:14px">
            <div class="note">Page <?= (int)$page ?> / <?= (int)$pages ?></div>
            <div class="pill">
              <?php
                $baseQ = ['q'=>$search,'stage'=>$stageFilter,'sort'=>$sort];
                if ($page > 1) {
                  $prev = $baseQ; $prev['page']=$page-1;
                  echo '<a class="btn soft" href="?' . h(http_build_query($prev)) . '">← Prev</a>';
                }
                if ($page < $pages) {
                  $next = $baseQ; $next['page']=$page+1;
                  echo '<a class="btn soft" href="?' . h(http_build_query($next)) . '">Next →</a>';
                }
              ?>
            </div>
          </div>

        </div>
      </div>

      <!-- Right panel: Quick filters -->
      <div class="card">
        <div class="hd">
          <div style="font-weight:900;color:var(--brand)">Quick filters</div>
          <div class="note">Fast links to organize your work</div>
        </div>
        <div class="bd">
          <div class="pill">
            <a class="btn soft" href="leads-dashboard.php?stage=lead&sort=priority">Lead only</a>
            <a class="btn soft" href="leads-dashboard.php?stage=contact&sort=newest">Contact</a>
            <a class="btn soft" href="leads-dashboard.php?stage=schedule&sort=newest">Schedule</a>
            <a class="btn soft" href="leads-dashboard.php?stage=purchase&sort=newest">Purchase</a>
          </div>

          <div style="height:12px"></div>
          <div class="note">Export / backup</div>
          <div class="pill" style="margin-top:10px">
            <a class="btn soft" href="export-capi-last7.php?days=7">Export JSONL CAPI</a>
          </div>
        </div>
      </div>

    </div>
  </div>
</body>
</html>
