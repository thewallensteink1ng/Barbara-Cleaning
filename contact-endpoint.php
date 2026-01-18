<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  exit;
}

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

function read_payload(): array {
  $raw = (string)file_get_contents('php://input');
  $raw = trim($raw);

  if ($raw !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) return $j;

    parse_str($raw, $out);
    if (isset($out['payload'])) {
      $j2 = json_decode((string)$out['payload'], true);
      if (is_array($j2)) return $j2;
    }
  }

  if (!empty($_POST) && is_array($_POST)) {
    if (isset($_POST['payload'])) {
      $j = json_decode((string)$_POST['payload'], true);
      if (is_array($j)) return $j;
    }
    return $_POST;
  }

  return [];
}

function col_exists(PDO $pdo, string $table, string $col): bool {
  static $cache = [];
  $k = $table . '.' . $col;
  if (array_key_exists($k, $cache)) return $cache[$k];
  try {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1");
    $st->execute([':t'=>$table, ':c'=>$col]);
    $cache[$k] = (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $cache[$k] = false; }
  return $cache[$k];
}

function update_flexible(PDO $pdo, string $table, array $data, string $whereSql, array $whereParams): int {
  $set = [];
  $params = [];
  foreach ($data as $k=>$v) {
    if (!col_exists($pdo, $table, $k)) continue;
    $set[] = "`$k` = :$k";
    $params[":$k"] = $v;
  }
  if (empty($set)) return 0;
  $sql = "UPDATE `$table` SET " . implode(',', $set) . " WHERE " . $whereSql;
  $st = $pdo->prepare($sql);
  $st->execute(array_merge($params, $whereParams));
  return $st->rowCount();
}

function safe_str($v): string {
  return trim((string)($v ?? ''));
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'method_not_allowed'], 405);

  $payload = read_payload();
  if (empty($payload)) json_out(['ok'=>false,'error'=>'empty_payload'], 400);

  $leadId = (int)($payload['lead_id'] ?? 0);

  $name = safe_str($payload['name'] ?? '');
  $email = safe_str($payload['email'] ?? '');
  $phoneRaw = safe_str($payload['phone_digits'] ?? ($payload['phone'] ?? ''));
  $serviceType = safe_str($payload['service_type'] ?? '');

  // Address (optional)
  $eircode = safe_str($payload['eircode'] ?? '');
  $city = safe_str($payload['city'] ?? '');
  $county = safe_str($payload['county'] ?? '');

  $eventId = safe_str($payload['event_id'] ?? '');
  $fbp = safe_str($payload['fbp'] ?? '');
  $fbc = safe_str($payload['fbc'] ?? '');
  $pageUrl = safe_str($payload['page_url'] ?? '');
  $testCode = safe_str($payload['test_event_code'] ?? '');

  if ($name === '' || $email === '' || $phoneRaw === '') {
    json_out(['ok'=>false,'error'=>'missing_required_fields'], 422);
  }

  $phoneDigits = bc_normalize_ie_phone($phoneRaw) ?? preg_replace('/\D+/', '', $phoneRaw);
  if ($phoneDigits === '' || strlen($phoneDigits) < 8) {
    json_out(['ok'=>false,'error'=>'invalid_phone'], 422);
  }

  // Update lead row if we have an ID
  if ($leadId > 0) {
    $update = [
      'stage' => 'contact',
      'went_whatsapp' => 1,
      'contact_at' => date('Y-m-d H:i:s'),
      'contact_event_id' => $eventId,

      // keep lead info updated
      'name' => $name,
      'email' => $email,
      'phone' => '+' . $phoneDigits,
      'phone_digits' => $phoneDigits,
      'service_type' => $serviceType,

      'fbp' => $fbp,
      'fbc' => $fbc,

      // address
      'eircode' => $eircode,
      'city' => $city,
      'county' => $county,
    ];
    update_flexible($pdo, 'bc_leads', $update, "id = :id", [':id'=>$leadId]);
  }

  // CAPI: Contact
  $evt = [
    'event_name' => 'Contact',
    'event_time' => time(),
    'event_id' => $eventId,
    'event_source_url' => $pageUrl,
    'action_source' => 'website',
    'test_event_code' => $testCode,
    'user' => [
      'email' => $email,
      'phone_digits' => $phoneDigits,
      'name' => $name,
      'fbp' => $fbp,
      'fbc' => $fbc,
      'external_id' => ($leadId > 0 ? ('lead:' . $leadId) : ''),
      'zip' => $eircode,
      'city' => $city,
      'county' => $county,
      'country' => 'ie',
    ],
    'custom' => [
      'currency' => 'EUR',
      'value' => 1.0,
      'content_name' => 'WhatsApp Click',
      'content_category' => 'cleaning',
      'service_type' => $serviceType,
      'lead_id' => $leadId,
    ],
  ];

  $capi = bc_send_capi_event($pdo, $evt);

  json_out(['ok'=>true, 'lead_id'=>$leadId, 'capi'=>$capi]);

} catch (Throwable $e) {
  bc_capi_log('error', 'contact-endpoint fatal', ['err'=>$e->getMessage()]);
  json_out(['ok'=>false,'error'=>'server_error'], 500);
}
