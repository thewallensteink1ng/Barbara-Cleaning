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

function insert_flexible(PDO $pdo, string $table, array $data): int {
  $cols = [];
  $vals = [];
  $params = [];
  foreach ($data as $k=>$v) {
    if (!col_exists($pdo, $table, $k)) continue;
    $cols[] = "`$k`";
    $vals[] = ":$k";
    $params[":$k"] = $v;
  }
  if (!$cols) return 0;
  $sql = "INSERT INTO `$table` (".implode(',',$cols).") VALUES (".implode(',',$vals).")";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (int)$pdo->lastInsertId();
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) json_out(['ok'=>false,'error'=>'invalid_json'], 400);

$data = $payload['data'] ?? [];
$meta = $payload['meta'] ?? [];
$eventId = trim((string)($payload['event_id'] ?? ''));

$name  = trim((string)($data['name'] ?? ''));
$email = trim((string)($data['email'] ?? ''));
$phone = trim((string)($data['phone'] ?? ''));

if ($name === '' || $email === '' || $phone === '') {
  json_out(['ok'=>false,'error'=>'missing_required_fields'], 400);
}

$digits = bc_normalize_ie_phone($phone);
if ($digits !== '') $phone = '+' . $digits;

$row = [
  'name' => $name,
  'email' => $email,
  'phone' => $phone,
  'service_type' => (string)($data['service_type'] ?? ''),
  'bedrooms' => (string)($data['bedrooms'] ?? ''),
  'bathrooms' => (string)($data['bathrooms'] ?? ''),
  'country' => (string)($data['country'] ?? 'IE'),
  'created_at' => date('Y-m-d H:i:s'),

  'page_url' => (string)($meta['page_url'] ?? ''),
  'referrer' => (string)($meta['referrer'] ?? ''),
  'user_agent' => (string)($meta['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')),
  'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
  'language' => (string)($meta['language'] ?? ''),
  'timezone_offset' => (string)($meta['timezone_offset'] ?? ''),
  'screen_width' => (string)($meta['screen_width'] ?? ''),
  'screen_height' => (string)($meta['screen_height'] ?? ''),

  'fbclid' => (string)($meta['fbclid'] ?? ''),
  'fbp' => (string)($meta['fbp'] ?? ''),
  'fbc' => (string)($meta['fbc'] ?? ''),

  'utm_source' => (string)($meta['utm_source'] ?? ''),
  'utm_medium' => (string)($meta['utm_medium'] ?? ''),
  'utm_campaign' => (string)($meta['utm_campaign'] ?? ''),
  'utm_content' => (string)($meta['utm_content'] ?? ''),
  'utm_term' => (string)($meta['utm_term'] ?? ''),

  'lead_event_id' => $eventId,
];

$leadId = insert_flexible($pdo, 'bc_leads', $row);
if ($leadId <= 0) json_out(['ok'=>false,'error'=>'db_insert_failed'], 500);

/**
 * CAPI Lead — SEM value
 */
$res = bc_send_capi_event($pdo, [
  'event_name' => 'Lead',
  'event_id' => $eventId ?: ('lead_'.$leadId),
  'event_time' => time(),
  'action_source' => 'website',
  'event_source_url' => (string)($meta['page_url'] ?? ''),
  'user' => [
    'name'=>$name,
    'email'=>$email,
    'phone'=>$phone,
    'fbp'=>(string)($meta['fbp'] ?? ''),
    'fbc'=>(string)($meta['fbc'] ?? ''),
    'client_ip'=>(string)($_SERVER['REMOTE_ADDR'] ?? ''),
    'client_ua'=>(string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
  ],
  'custom' => [
    'currency' => 'EUR',
    'lead_id' => $leadId,
    'service_type' => (string)($data['service_type'] ?? ''),
    'bedrooms' => (string)($data['bedrooms'] ?? ''),
    'bathrooms' => (string)($data['bathrooms'] ?? ''),
    // 🚫 sem value
  ],
]);

json_out(['ok'=>true,'id'=>$leadId,'capi_ok'=> (bool)($res['ok'] ?? false)]);
