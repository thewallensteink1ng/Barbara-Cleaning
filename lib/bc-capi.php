<?php
declare(strict_types=1);

/**
 * dashboard/lib/bc-capi.php
 * Normalize + hash user data and send Meta CAPI events to active pixels.
 */

function bc_client_ip(): string {
  foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) {
      $ip = explode(',', (string)$_SERVER[$k])[0];
      return trim($ip);
    }
  }
  return '';
}

/** Normalize Irish phone into digits E164 without "+" (353XXXXXXXXX). */
function bc_normalize_ie_phone(string $raw): string {
  $raw = trim((string)$raw);
  if ($raw === '') return '';
  $d = preg_replace('/\D+/', '', $raw);
  if (!$d) return '';

  if (str_starts_with($d, '00')) $d = substr($d, 2);

  if (str_starts_with($d, '353')) {
    $rest = substr($d, 3);
    return $rest ? ('353' . $rest) : $d;
  }
  if (str_starts_with($d, '0')) {
    $rest = substr($d, 1);
    return $rest ? ('353' . $rest) : ('353' . $rest);
  }
  if (strlen($d) >= 8 && strlen($d) <= 10) return '353' . $d;

  return $d;
}

function bc_norm(string $v): string { return strtolower(trim($v)); }
function bc_hash(string $v): string {
  $v = bc_norm($v);
  return $v === '' ? '' : hash('sha256', $v);
}

function bc_split_name(string $name): array {
  $name = trim($name);
  if ($name === '') return ['fn'=>'','ln'=>''];
  $parts = preg_split('/\s+/', $name) ?: [];
  $fn = $parts[0] ?? '';
  $ln = count($parts) > 1 ? (string)end($parts) : '';
  return ['fn'=>$fn,'ln'=>$ln];
}

/** Build Meta user_data with hashed fields. */
function bc_build_user_data(array $u): array {
  $name = (string)($u['name'] ?? '');
  $split = bc_split_name($name);

  $em = bc_hash((string)($u['email'] ?? ''));

  $phoneDigits = bc_normalize_ie_phone((string)($u['phone'] ?? ''));
  $ph = bc_hash($phoneDigits); // hashed digits E164 without +

  $fn = bc_hash($split['fn']);
  $ln = bc_hash($split['ln']);

  $out = [];
  if ($em) $out['em'] = [$em];
  if ($ph) $out['ph'] = [$ph];
  if ($fn) $out['fn'] = [$fn];
  if ($ln) $out['ln'] = [$ln];

  $fbp = (string)($u['fbp'] ?? '');
  $fbc = (string)($u['fbc'] ?? '');
  if ($fbp) $out['fbp'] = $fbp;
  if ($fbc) $out['fbc'] = $fbc;

  $ip = (string)($u['client_ip'] ?? '');
  $ua = (string)($u['client_ua'] ?? '');
  if ($ip) $out['client_ip_address'] = $ip;
  if ($ua) $out['client_user_agent'] = $ua;

  return $out;
}

/**
 * Send one event to all active pixels.
 * ok=true only if at least one pixel succeeded.
 */
function bc_send_capi_event(PDO $pdo, array $evt): array {
  $eventName = (string)($evt['event_name'] ?? '');
  if ($eventName === '') return ['ok'=>false,'error'=>'missing event_name','results'=>[]];

  $stmt = $pdo->query("SELECT pixel_id, access_token FROM bc_pixels WHERE is_active=1 AND access_token IS NOT NULL AND access_token<>'' ORDER BY id DESC");
  $pixels = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
  if (!$pixels) return ['ok'=>false,'error'=>'no_active_pixels','results'=>[],'success'=>0,'failed'=>0];

  $results = [];
  $success = 0; $failed = 0;

  foreach ($pixels as $p) {
    $pixelId = (string)($p['pixel_id'] ?? '');
    $token   = (string)($p['access_token'] ?? '');
    if ($pixelId === '' || $token === '') continue;

    $userData = bc_build_user_data((array)($evt['user'] ?? []));
    $custom   = (array)($evt['custom'] ?? []);

    // Remove null custom fields (Meta doesn't like nulls sometimes)
    foreach ($custom as $k=>$v) if ($v === null || $v === '') unset($custom[$k]);

    $body = [
      'data' => [[
        'event_name' => $eventName,
        'event_time' => (int)($evt['event_time'] ?? time()),
        'event_id'   => (string)($evt['event_id'] ?? ''),
        'action_source' => (string)($evt['action_source'] ?? 'website'),
        'event_source_url' => (string)($evt['event_source_url'] ?? ''),
        'user_data' => $userData,
        'custom_data' => $custom,
      ]],
      'partner_agent' => 'barbara_cleaning_custom'
    ];

    $url = "https://graph.facebook.com/v24.0/" . rawurlencode($pixelId) . "/events?access_token=" . rawurlencode($token);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS => json_encode($body),
      CURLOPT_TIMEOUT => 12,
    ]);

    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $respJson = null;
    if (is_string($resp) && $resp !== '') {
      $tmp = json_decode($resp, true);
      if (is_array($tmp)) $respJson = $tmp;
    }

    $ok = ($err === '' && $code >= 200 && $code < 300 && (!isset($respJson['error'])));
    if ($ok) $success++; else $failed++;

    $results[] = [
      'pixel_id' => $pixelId,
      'ok' => $ok,
      'http' => $code,
      'error' => $err ?: ($respJson['error']['message'] ?? null),
    ];

    @file_put_contents(__DIR__ . '/../_logs/capi_events.log', json_encode([
      'ts' => gmdate('c'),
      'event' => $eventName,
      'event_id' => $body['data'][0]['event_id'],
      'pixel_id' => $pixelId,
      'http' => $code,
      'ok' => $ok,
      'error' => $err ?: ($respJson['error']['message'] ?? null)
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
  }

  return [
    'ok' => ($success > 0),
    'success' => $success,
    'failed' => $failed,
    'results' => $results,
    'error' => ($success > 0) ? null : 'all_failed'
  ];
}
