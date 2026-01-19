<?php
declare(strict_types=1);

/**
 * Lead Endpoint – Receives form data and saves to database.
 * File: /dashboard/lead-endpoint.php
 *
 * This endpoint:
 * 1. Receives JSON payload from the frontend form
 * 2. Validates required fields
 * 3. Inserts a new lead into bc_leads table
 * 4. Sends Lead event to Meta CAPI
 * 5. Returns the new lead ID to the frontend
 */

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

function insert_flexible(PDO $pdo, string $table, array $data): int {
  $cols = [];
  $placeholders = [];
  $params = [];
  
  foreach ($data as $k => $v) {
    if (!col_exists($pdo, $table, $k)) continue;
    $cols[] = "`$k`";
    $placeholders[] = ":$k";
    $params[":$k"] = $v;
  }
  
  if (empty($cols)) return 0;
  
  $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  return (int)$pdo->lastInsertId();
}

function safe_str($v): string {
  return trim((string)($v ?? ''));
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok'=>false,'error'=>'method_not_allowed'], 405);
  }

  $payload = read_payload();
  if (empty($payload)) {
    json_out(['ok'=>false,'error'=>'empty_payload'], 400);
  }

  // Extract data from payload
  $data = $payload['data'] ?? $payload;
  $meta = $payload['meta'] ?? [];
  $eventId = safe_str($payload['event_id'] ?? '');
  $testCode = safe_str($payload['test_event_code'] ?? ($meta['test_event_code'] ?? ''));

  // Required fields
  $name = safe_str($data['name'] ?? '');
  $email = safe_str($data['email'] ?? '');
  $phoneRaw = safe_str($data['phone_digits'] ?? ($data['phone'] ?? ''));
  
  // Optional fields
  $firstName = safe_str($data['first_name'] ?? '');
  $lastName = safe_str($data['last_name'] ?? '');
  $serviceType = safe_str($data['service_type'] ?? '');
  $bedrooms = safe_str($data['bedrooms'] ?? '');
  $bathrooms = safe_str($data['bathrooms'] ?? '');
  
  // Address fields
  $eircode = safe_str($data['eircode'] ?? '');
  $addressLine1 = safe_str($data['address_line1'] ?? '');
  $addressLine2 = safe_str($data['address_line2'] ?? '');
  $city = safe_str($data['city'] ?? '');
  $county = safe_str($data['county'] ?? '');
  $country = safe_str($data['country'] ?? 'IE');

  // Meta/tracking fields
  $pageUrl = safe_str($meta['page_url'] ?? '');
  $referrer = safe_str($meta['referrer'] ?? '');
  $userAgent = safe_str($meta['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
  $fbclid = safe_str($meta['fbclid'] ?? '');
  $fbp = safe_str($meta['fbp'] ?? '');
  $fbc = safe_str($meta['fbc'] ?? '');
  $gclid = safe_str($meta['gclid'] ?? '');
  $gbraid = safe_str($meta['gbraid'] ?? '');
  $wbraid = safe_str($meta['wbraid'] ?? '');
  $utmSource = safe_str($meta['utm_source'] ?? '');
  $utmMedium = safe_str($meta['utm_medium'] ?? '');
  $utmCampaign = safe_str($meta['utm_campaign'] ?? '');
  $utmContent = safe_str($meta['utm_content'] ?? '');
  $utmTerm = safe_str($meta['utm_term'] ?? '');

  // Validate required fields
  if ($name === '' && $firstName === '' && $lastName === '') {
    json_out(['ok'=>false,'error'=>'missing_name'], 422);
  }
  if ($email === '') {
    json_out(['ok'=>false,'error'=>'missing_email'], 422);
  }
  if ($phoneRaw === '') {
    json_out(['ok'=>false,'error'=>'missing_phone'], 422);
  }

  // Normalize phone
  $phoneDigits = bc_normalize_ie_phone($phoneRaw) ?? preg_replace('/\D+/', '', $phoneRaw);
  if ($phoneDigits === '' || strlen($phoneDigits) < 8) {
    json_out(['ok'=>false,'error'=>'invalid_phone'], 422);
  }

  // Build name if only first/last provided
  if ($name === '' && ($firstName !== '' || $lastName !== '')) {
    $name = trim($firstName . ' ' . $lastName);
  }

  // Get client IP
  $ipAddress = bc_get_client_ip();

  // Generate event ID if not provided
  if ($eventId === '') {
    $eventId = 'lead_' . time() . '_' . bin2hex(random_bytes(6));
  }

  // Prepare data for insertion
  $insertData = [
    'name' => $name,
    'email' => $email,
    'phone' => '+' . $phoneDigits,
    'phone_digits' => $phoneDigits,
    'service_type' => $serviceType,
    'bedrooms' => $bedrooms,
    'bathrooms' => $bathrooms,
    
    'eircode' => $eircode,
    'address_line1' => $addressLine1,
    'address_line2' => $addressLine2,
    'city' => $city,
    'county' => $county,
    
    'page_url' => $pageUrl,
    'referrer' => $referrer,
    'user_agent' => $userAgent,
    'ip_address' => $ipAddress,
    
    'fbclid' => $fbclid,
    'fbp' => $fbp,
    'fbc' => $fbc,
    'gclid' => $gclid,
    'gbraid' => $gbraid,
    'wbraid' => $wbraid,
    
    'utm_source' => $utmSource,
    'utm_medium' => $utmMedium,
    'utm_campaign' => $utmCampaign,
    'utm_content' => $utmContent,
    'utm_term' => $utmTerm,
    
    'lead_event_id' => $eventId,
    'went_whatsapp' => 0,
  ];

  // Insert lead into database
  $leadId = insert_flexible($pdo, 'bc_leads', $insertData);
  
  if ($leadId <= 0) {
    json_out(['ok'=>false,'error'=>'insert_failed'], 500);
  }

  // Send Lead event to Meta CAPI
  $evt = [
    'event_name' => 'Lead',
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
      'external_id' => 'lead:' . $leadId,
      'zip' => $eircode,
      'city' => $city,
      'county' => $county,
      'country' => $country,
      'client_ip' => $ipAddress,
      'client_user_agent' => $userAgent,
    ],
    'custom' => [
      'currency' => 'EUR',
      'content_name' => 'Cleaning Quote Form',
      'content_category' => 'cleaning',
      'service_type' => $serviceType,
      'lead_id' => $leadId,
    ],
  ];

  $capi = bc_send_capi_event($pdo, $evt);

  // Update lead with CAPI response
  try {
    $upd = [];
    $params = [':id' => $leadId];
    
    if (col_exists($pdo, 'bc_leads', 'lead_event_sent')) {
      $upd[] = "lead_event_sent = :sent";
      $params[':sent'] = $capi['ok'] ? 1 : 0;
    }
    if (col_exists($pdo, 'bc_leads', 'lead_event_response')) {
      $upd[] = "lead_event_response = :resp";
      $params[':resp'] = json_encode($capi, JSON_UNESCAPED_SLASHES);
    }
    
    if ($upd) {
      $pdo->prepare("UPDATE bc_leads SET " . implode(',', $upd) . " WHERE id = :id")->execute($params);
    }
  } catch (Throwable $e) {
    // Log but don't fail the request
    bc_capi_log('warn', 'lead-endpoint: failed to update CAPI status', ['err' => $e->getMessage()]);
  }

  json_out(['ok' => true, 'id' => $leadId, 'capi' => $capi]);

} catch (Throwable $e) {
  bc_capi_log('error', 'lead-endpoint fatal', ['err' => $e->getMessage()]);
  json_out(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()], 500);
}
