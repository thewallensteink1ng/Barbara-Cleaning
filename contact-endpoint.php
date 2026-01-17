<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$dbcfg = __DIR__ . '/_private/db-config.php';
if (!is_file($dbcfg)) $dbcfg = __DIR__ . '/db-config.php';
require_once $dbcfg;
require_once __DIR__ . '/lib/bc-capi.php';

global $pdo;

function json_out(array $a, int $code=200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_SLASHES);
  exit;
}

function col_exists(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k = $table.'.'.$col;
  if (isset($cache[$k])) return $cache[$k];
  try {
    // Prefer INFORMATION_SCHEMA (reliable on Hostinger/MariaDB)
    $st = $pdo->prepare("\n      SELECT 1\n      FROM INFORMATION_SCHEMA.COLUMNS\n      WHERE TABLE_SCHEMA = DATABASE()\n        AND TABLE_NAME = :t\n        AND COLUMN_NAME = :c\n      LIMIT 1\n    ");
    $st->execute([':t' => $table, ':c' => $col]);
    $cache[$k] = (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    // Fallback (avoid prepared statements for SHOW COLUMNS)
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) && preg_match('/^[A-Za-z0-9_]+$/', $col)) {
      try {
        $sql = "SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($col);
        $r = $pdo->query($sql);
        $cache[$k] = (bool)$r->fetch(PDO::FETCH_ASSOC);
      } catch (Throwable $e2) {
        $cache[$k] = false;
      }
    } else {
      $cache[$k] = false;
    }
  }
  return $cache[$k];
}

function update_flexible(PDO $pdo, string $table, int $id, array $data): void {
  $sets = [];
  $params = [':id'=>$id];
  foreach ($data as $k=>$v) {
    if (!col_exists($pdo, $table, $k)) continue;
    $sets[] = "`$k`=:$k";
    $params[":$k"] = $v;
  }
  if (!$sets) return;
  $sql = "UPDATE `$table` SET ".implode(',',$sets)." WHERE id=:id";
  $st = $pdo->prepare($sql);
  $st->execute($params);
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) json_out(['ok'=>false,'error'=>'invalid_json'], 400);

$leadId = (int)($payload['lead_id'] ?? 0);
$eventId = trim((string)($payload['event_id'] ?? ''));

$name  = trim((string)($payload['name'] ?? ''));
$email = trim((string)($payload['email'] ?? ''));
$phone = trim((string)($payload['phone'] ?? ''));
$serviceType = trim((string)($payload['service_type'] ?? ''));

$meta = [
  'fbp' => (string)($payload['fbp'] ?? ''),
  'fbc' => (string)($payload['fbc'] ?? ''),
  'fbclid' => (string)($payload['fbclid'] ?? ''),
  'page_url' => (string)($payload['page_url'] ?? ''),
];

$digits = bc_normalize_ie_phone($phone);
if ($digits !== '') $phone = '+' . $digits;

if ($leadId > 0) {
  update_flexible($pdo, 'bc_leads', $leadId, [
    'went_whatsapp' => 1,
    'went_whatsapp_at' => date('Y-m-d H:i:s'),
    'contact_event_id' => $eventId,
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'service_type' => $serviceType,
    'fbp' => $meta['fbp'],
    'fbc' => $meta['fbc'],
    'fbclid' => $meta['fbclid'],
    'page_url' => $meta['page_url'],
    'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
  ]);
}

/**
 * CAPI Contact — SEM value
 */
$res = bc_send_capi_event($pdo, [
  'event_name' => 'Contact',
  'event_id' => $eventId ?: ('contact_'.$leadId),
  'event_time' => time(),
  'action_source' => 'website',
  'event_source_url' => $meta['page_url'],
  'user' => [
    'name'=>$name,
    'email'=>$email,
    'phone'=>$phone,
    'fbp'=>$meta['fbp'],
    'fbc'=>$meta['fbc'],
    'client_ip'=>(string)($_SERVER['REMOTE_ADDR'] ?? ''),
    'client_ua'=>(string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
  ],
  'custom' => [
    'currency' => 'EUR',
    'lead_id' => $leadId,
    'service_type' => $serviceType,
    // 🚫 sem value
  ],
]);

json_out(['ok'=>true,'capi_ok'=> (bool)($res['ok'] ?? false)]);
