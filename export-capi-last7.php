<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
$dbcfg = __DIR__ . '/_private/db-config.php';
if (!is_file($dbcfg)) $dbcfg = __DIR__ . '/db-config.php';
require_once $dbcfg;
require_once __DIR__ . '/lib/bc-capi.php';

global $pdo;

$days = (int)($_GET['days'] ?? 7);
if ($days < 1) $days = 7;
if ($days > 7) $days = 7;

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="bc_capi_export_last'.$days.'d.jsonl"');

$rows = $pdo->query("SELECT * FROM bc_leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) ORDER BY id DESC LIMIT 8000")->fetchAll(PDO::FETCH_ASSOC);

$write = function(array $e) { echo json_encode($e, JSON_UNESCAPED_SLASHES) . "\n"; };

foreach ($rows as $r) {
  $id = (int)($r['id'] ?? 0);

  $ud = bc_build_user_data([
    'name' => (string)($r['name'] ?? ''),
    'email'=> (string)($r['email'] ?? ''),
    'phone'=> (string)($r['phone'] ?? ''),
    'fbp'  => (string)($r['fbp'] ?? ''),
    'fbc'  => (string)($r['fbc'] ?? ''),
    'client_ip' => (string)($r['ip_address'] ?? ''),
    'client_ua' => (string)($r['user_agent'] ?? ''),
  ]);

  $url = (string)($r['page_url'] ?? '');
  $created = strtotime((string)($r['created_at'] ?? '')) ?: time();

  // Lead (sem value)
  $write([
    'event_name' => 'Lead',
    'event_time' => $created,
    'event_id'   => (string)($r['lead_event_id'] ?? ('lead_export_'.$id)),
    'action_source' => 'website',
    'event_source_url' => $url,
    'user_data' => $ud,
    'custom_data' => [
      'currency' => 'EUR',
      'lead_id' => $id,
      'service_type' => (string)($r['service_type'] ?? ''),
    ],
  ]);

  // Contact (sem value) - só se clicou WhatsApp
  if (!empty($r['went_whatsapp'])) {
    $t = strtotime((string)($r['went_whatsapp_at'] ?? '')) ?: $created;
    $write([
      'event_name' => 'Contact',
      'event_time' => $t,
      'event_id'   => (string)($r['contact_event_id'] ?? ('contact_export_'.$id)),
      'action_source' => 'website',
      'event_source_url' => $url,
      'user_data' => $ud,
      'custom_data' => [
        'currency' => 'EUR',
        'lead_id' => $id,
        'service_type' => (string)($r['service_type'] ?? ''),
      ],
    ]);
  }

  // Schedule (value manual se existir)
  if (!empty($r['scheduled_for'])) {
    $t = time();
    $v = (float)($r['scheduled_value'] ?? 0);
    $cd = ['currency'=>'EUR','lead_id'=>$id,'scheduled_for'=>(string)$r['scheduled_for']];
    if ($v > 0) $cd['value'] = $v;

    $write([
      'event_name' => 'Schedule',
      'event_time' => $t,
      'event_id'   => (string)($r['scheduled_event_id'] ?? ('schedule_export_'.$id)),
      'action_source' => 'system_generated',
      'event_source_url' => '',
      'user_data' => $ud,
      'custom_data' => $cd,
    ]);
  }

  // Purchase (value manual se existir)
  if ((float)($r['paid_value'] ?? 0) > 0) {
    $t = time();
    $v = (float)$r['paid_value'];

    $write([
      'event_name' => 'Purchase',
      'event_time' => $t,
      'event_id'   => (string)($r['purchase_event_id'] ?? ('purchase_export_'.$id)),
      'action_source' => 'system_generated',
      'event_source_url' => '',
      'user_data' => $ud,
      'custom_data' => [
        'currency'=>'EUR',
        'value'=>$v,
        'lead_id'=>$id
      ],
    ]);
  }
}
