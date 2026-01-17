<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
$dbcfg = __DIR__ . '/_private/db-config.php';
if (!is_file($dbcfg)) $dbcfg = __DIR__ . '/db-config.php';
require_once $dbcfg;
require_once __DIR__ . '/lib/bc-capi.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

set_time_limit(0);
ignore_user_abort(true);

global $pdo;

$event = trim((string)($_POST['event'] ?? 'Lead'));
$stage = trim((string)($_POST['stage'] ?? 'all'));
$days  = (int)($_POST['days'] ?? 7);
$limit = (int)($_POST['limit'] ?? 25);
$onlyFailed = (int)($_POST['only_failed'] ?? 1) === 1;
$offset = (int)($_POST['offset'] ?? 0);

if ($days < 1) $days = 7;
if ($days > 30) $days = 30;
if ($limit < 1) $limit = 25;
if ($limit > 80) $limit = 80;

$allowedEvents = ['Lead','Contact','Schedule','Purchase'];
if (!in_array($event, $allowedEvents, true)) $event = 'Lead';

$allowedStages = ['all','lead','contact','schedule','purchase'];
if (!in_array($stage, $allowedStages, true)) $stage = 'all';

$since = date('Y-m-d H:i:s', time() - ($days * 86400));

$sentCol = [
  'Lead' => 'lead_event_sent',
  'Contact' => 'contact_event_sent',
  'Schedule' => 'scheduled_event_sent',
  'Purchase' => 'purchase_event_sent',
][$event] ?? 'lead_event_sent';

$respCol = [
  'Lead' => 'lead_event_response',
  'Contact' => 'contact_event_response',
  'Schedule' => 'scheduled_event_response',
  'Purchase' => 'purchase_event_response',
][$event] ?? 'lead_event_response';

$idCol = [
  'Lead' => 'lead_event_id',
  'Contact' => 'contact_event_id',
  'Schedule' => 'scheduled_event_id',
  'Purchase' => 'purchase_event_id',
][$event] ?? 'lead_event_id';

$stageWhere = '1=1';
if ($stage === 'lead') {
  $stageWhere = "(COALESCE(went_whatsapp,0)=0 AND (scheduled_for IS NULL OR scheduled_for='') AND COALESCE(paid_value,0)<=0)";
} elseif ($stage === 'contact') {
  $stageWhere = "(COALESCE(went_whatsapp,0)=1 AND (scheduled_for IS NULL OR scheduled_for='') AND COALESCE(paid_value,0)<=0)";
} elseif ($stage === 'schedule') {
  $stageWhere = "((scheduled_for IS NOT NULL AND scheduled_for<>'') AND COALESCE(paid_value,0)<=0)";
} elseif ($stage === 'purchase') {
  $stageWhere = "(COALESCE(paid_value,0)>0)";
}

$eventWhere = '1=1';
if ($event === 'Contact') $eventWhere = 'COALESCE(went_whatsapp,0)=1';
if ($event === 'Schedule') $eventWhere = "(scheduled_for IS NOT NULL AND scheduled_for<>'')";
if ($event === 'Purchase') $eventWhere = "(COALESCE(paid_value,0)>0)";

$failedWhere = '1=1';
if ($onlyFailed) $failedWhere = "COALESCE($sentCol,0)=0";

$sql = "SELECT id FROM bc_leads
        WHERE created_at >= :since
          AND $stageWhere
          AND $eventWhere
          AND $failedWhere
        ORDER BY id DESC
        LIMIT :lim OFFSET :off";

$st = $pdo->prepare($sql);
$st->bindValue(':since', $since);
$st->bindValue(':lim', $limit, PDO::PARAM_INT);
$st->bindValue(':off', $offset, PDO::PARAM_INT);
$st->execute();
$ids = $st->fetchAll(PDO::FETCH_COLUMN, 0);

if (!$ids) {
  echo json_encode(['ok'=>true,'processed'=>0,'success'=>0,'failed'=>0,'done'=>true,'next_offset'=>$offset]);
  exit;
}

$processed=0; $success=0; $failed=0;

$fetch = $pdo->prepare("SELECT * FROM bc_leads WHERE id=? LIMIT 1");

foreach ($ids as $id) {
  $id = (int)$id;
  $fetch->execute([$id]);
  $row = $fetch->fetch(PDO::FETCH_ASSOC);
  if (!$row) continue;

  $processed++;

  $eid = (string)($row[$idCol] ?? '');
  if ($eid === '') {
    $eid = strtolower($event) . '_resend_' . $id;
    try { $pdo->prepare("UPDATE bc_leads SET {$idCol}=? WHERE id=?")->execute([$eid, $id]); } catch (Throwable $e) {}
  }

  $eventTime = time();
  if ($event === 'Lead') $eventTime = strtotime((string)($row['created_at'] ?? '')) ?: time();
  if ($event === 'Contact') $eventTime = strtotime((string)($row['went_whatsapp_at'] ?? '')) ?: (strtotime((string)($row['created_at'] ?? '')) ?: time());
  if ($event === 'Schedule') $eventTime = strtotime((string)($row['scheduled_for'] ?? '') . ' 12:00:00') ?: time();
  if ($event === 'Purchase') $eventTime = strtotime((string)($row['paid_at'] ?? '')) ?: time();

  $user = [
    'name' => (string)($row['name'] ?? ''),
    'email'=> (string)($row['email'] ?? ''),
    'phone'=> (string)($row['phone'] ?? ''),
    'fbp'  => (string)($row['fbp'] ?? ''),
    'fbc'  => (string)($row['fbc'] ?? ''),
    'client_ip' => (string)($row['ip_address'] ?? ''),
    'client_ua' => (string)($row['user_agent'] ?? ''),
  ];

  $custom = ['currency'=>'EUR','lead_id'=>$id];

  if ($event === 'Schedule') {
    $v = (float)($row['scheduled_value'] ?? 0);
    if ($v > 0) $custom['value'] = $v;
  } elseif ($event === 'Purchase') {
    $v = (float)($row['paid_value'] ?? 0);
    if ($v > 0) $custom['value'] = $v;
  } else {
    // Lead/Contact: no auto value
    $custom['service_type'] = (string)($row['service_type'] ?? '') ?: null;
  }

  $res = bc_send_capi_event($pdo, [
    'event_name' => $event,
    'event_id' => $eid,
    'event_time' => $eventTime,
    'action_source' => ($event === 'Lead' || $event === 'Contact') ? 'website' : 'system_generated',
    'event_source_url' => (string)($row['page_url'] ?? ''),
    'user' => $user,
    'custom' => $custom
  ]);

  try {
    $pdo->prepare("UPDATE bc_leads SET {$sentCol}=:sent, {$respCol}=:resp WHERE id=:id")
        ->execute([
          ':sent' => $res['ok'] ? 1 : 0,
          ':resp' => json_encode($res, JSON_UNESCAPED_SLASHES),
          ':id'   => $id
        ]);
  } catch (Throwable $e) {}

  if ($res['ok']) $success++; else $failed++;
}

echo json_encode([
  'ok'=>true,
  'processed'=>$processed,
  'success'=>$success,
  'failed'=>$failed,
  'done'=>(count($ids) < $limit),
  'next_offset'=>$offset + $limit
]);
