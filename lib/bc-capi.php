<?php
declare(strict_types=1);

/**
 * Barbara Cleaning – Meta Conversions API helper (CAPI)
 * File: /dashboard/lib/bc-capi.php
 *
 * Goals:
 * - Send server-side events (Lead / Contact / Schedule / Purchase)
 * - Strong matching (email/phone/name + fbp/fbc + ip/ua + address fields)
 * - Safe logging (no tokens in logs)
 * - Works with your Pixels Dashboard (table: bc_pixels)
 */

function bc_capi_log(string $level, string $msg, array $ctx = []): void {
  $line = '[' . date('c') . '] [' . strtoupper($level) . '] ' . $msg;
  if (!empty($ctx)) {
    // Avoid logging secrets
    if (isset($ctx['access_token'])) $ctx['access_token'] = '[redacted]';
    $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES);
  }
  $line .= PHP_EOL;

  $log = __DIR__ . '/../_private/pixel_actions.log';
  @file_put_contents($log, $line, FILE_APPEND);
}

function bc_is_sha256(string $v): bool {
  return (bool)preg_match('/^[a-f0-9]{64}$/i', $v);
}

function bc_norm_str(?string $v): string {
  $v = (string)($v ?? '');
  $v = trim($v);
  if ($v === '') return '';
  $v = mb_strtolower($v, 'UTF-8');

  // Remove accents (best effort)
  if (function_exists('iconv')) {
    $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
    if ($x !== false && $x !== '') $v = $x;
  }

  // Collapse whitespace
  $v = preg_replace('/\s+/', ' ', $v);
  return trim($v);
}

function bc_sha256(string $v): string {
  return hash('sha256', $v);
}

function bc_hash_field(?string $v, string $mode = 'str'): string {
  // mode: str | email | phone | country | zip
  $v = (string)($v ?? '');
  $v = trim($v);
  if ($v === '') return '';

  if (bc_is_sha256($v)) return strtolower($v);

  if ($mode === 'email') {
    $v = bc_norm_str($v);
  } elseif ($mode === 'country') {
    $v = strtolower(trim($v));
  } elseif ($mode === 'phone') {
    // phone expected digits only
    $v = preg_replace('/\D+/', '', $v);
  } elseif ($mode === 'zip') {
    // Eircode: remove spaces + uppercase to normalize then lowercase for hashing
    $v = strtoupper(preg_replace('/\s+/', '', $v));
    $v = strtolower($v);
  } else {
    $v = bc_norm_str($v);
  }

  if ($v === '') return '';
  return bc_sha256($v);
}

function bc_split_name(string $name): array {
  $name = trim($name);
  if ($name === '') return ['', ''];
  $parts = preg_split('/\s+/', $name);
  if (!$parts) return ['', ''];
  $fn = $parts[0] ?? '';
  $ln = count($parts) > 1 ? $parts[count($parts)-1] : '';
  return [$fn, $ln];
}

/**
 * Normalize Irish phone to digits with country code, ex: 353871234567
 * Returns null if invalid/unknown.
 */
function bc_normalize_ie_phone(string $raw): ?string {
  $raw = trim($raw);
  if ($raw === '') return null;

  $digits = preg_replace('/\D+/', '', $raw);
  if ($digits === '') return null;

  if (strpos($digits, '00') === 0) $digits = substr($digits, 2);

  $isMobileNo0 = fn(string $nsn) => (bool)preg_match('/^8[3-9]\d{7}$/', $nsn);
  $isMobileWith0 = fn(string $nsn) => (bool)preg_match('/^08[3-9]\d{7}$/', $nsn);
  $isDublinWith0 = fn(string $nsn) => (bool)preg_match('/^01\d{7}$/', $nsn);
  $isDublinNo0 = fn(string $nsn) => (bool)preg_match('/^1\d{7}$/', $nsn);
  $isOtherWith0 = fn(string $nsn) => (bool)preg_match('/^0[2-9]\d{7,8}$/', $nsn);
  $isOtherNo0 = fn(string $nsn) => (bool)preg_match('/^[2-9]\d{7,8}$/', $nsn);

  if (strpos($digits, '353') === 0) {
    $rest = substr($digits, 3);
    if ($isMobileNo0($rest) || $isDublinNo0($rest) || $isOtherNo0($rest)) return '353' . $rest;
    return null;
  }

  if ($digits[0] === '0') {
    if ($isMobileWith0($digits) || $isDublinWith0($digits) || $isOtherWith0($digits)) return '353' . substr($digits, 1);
    return null;
  }

  if ($isMobileNo0($digits) || $isDublinNo0($digits) || $isOtherNo0($digits)) return '353' . $digits;

  return null;
}

/**
 * Best effort: real visitor IP behind proxies (Hostinger/Cloudflare/etc.)
 */
function bc_get_client_ip(): string {
  $candidates = [
    'HTTP_CF_CONNECTING_IP',
    'HTTP_X_REAL_IP',
    'HTTP_X_FORWARDED_FOR',
    'REMOTE_ADDR',
  ];
  foreach ($candidates as $k) {
    if (empty($_SERVER[$k])) continue;
    $v = (string)$_SERVER[$k];
    if ($k === 'HTTP_X_FORWARDED_FOR') {
      // first IP
      $v = explode(',', $v)[0] ?? '';
    }
    $v = trim($v);
    if ($v !== '') return $v;
  }
  return '';
}

function bc_build_user_data(array $user): array {
  $email = (string)($user['email'] ?? '');
  $phone = (string)($user['phone_digits'] ?? ($user['phone'] ?? ''));
  $name  = (string)($user['name'] ?? '');

  [$fn, $ln] = bc_split_name($name);
  if (!empty($user['fn'])) $fn = (string)$user['fn'];
  if (!empty($user['ln'])) $ln = (string)$user['ln'];

  $zip = (string)($user['zip'] ?? ($user['eircode'] ?? ''));
  $city = (string)($user['city'] ?? '');
  $state = (string)($user['state'] ?? ($user['county'] ?? ''));
  $country = (string)($user['country'] ?? 'ie');

  $externalId = (string)($user['external_id'] ?? '');

  $ud = [];

  $em = bc_hash_field($email, 'email');
  if ($em !== '') $ud['em'] = [$em];

  $ph = bc_hash_field($phone, 'phone');
  if ($ph !== '') $ud['ph'] = [$ph];

  $hfn = bc_hash_field($fn, 'str');
  if ($hfn !== '') $ud['fn'] = $hfn;

  $hln = bc_hash_field($ln, 'str');
  if ($hln !== '') $ud['ln'] = $hln;

  $hzp = bc_hash_field($zip, 'zip');
  if ($hzp !== '') $ud['zp'] = $hzp;

  $hct = bc_hash_field($city, 'str');
  if ($hct !== '') $ud['ct'] = $hct;

  $hst = bc_hash_field($state, 'str');
  if ($hst !== '') $ud['st'] = $hst;

  $hco = bc_hash_field($country, 'country');
  if ($hco !== '') $ud['country'] = $hco;

  $hex = bc_hash_field($externalId, 'str');
  if ($hex !== '') $ud['external_id'] = [$hex];

  $fbp = trim((string)($user['fbp'] ?? ''));
  $fbc = trim((string)($user['fbc'] ?? ''));
  if ($fbp !== '') $ud['fbp'] = $fbp;
  if ($fbc !== '') $ud['fbc'] = $fbc;

  $ip = trim((string)($user['client_ip'] ?? ($user['client_ip_address'] ?? '')));
  $ua = trim((string)($user['client_user_agent'] ?? ($user['user_agent'] ?? '')));
  if ($ip !== '') $ud['client_ip_address'] = $ip;
  if ($ua !== '') $ud['client_user_agent'] = $ua;

  return $ud;
}

function bc_capi_http_post(string $url, array $payload, int $timeout = 8): array {
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $json,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
      ],
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,
    ]);
    $body = (string)curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
      'ok' => ($err === '' && $code >= 200 && $code < 300),
      'status' => $code,
      'body' => $body,
      'error' => $err,
    ];
  }

  $ctx = stream_context_create([
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
      'content' => $json,
      'timeout' => $timeout,
    ]
  ]);
  $body = @file_get_contents($url, false, $ctx);
  $code = 0;

  if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $h) {
      if (preg_match('#HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int)$m[1]; break; }
    }
  }

  return [
    'ok' => ($body !== false && $code >= 200 && $code < 300),
    'status' => $code,
    'body' => (string)($body ?: ''),
    'error' => ($body === false ? 'file_get_contents_failed' : ''),
  ];
}

/**
 * Send Conversions API event to all ACTIVE pixels in your bc_pixels table.
 *
 * $evt structure:
 * [
 *  'event_name' => 'Lead'|'Contact'|...,
 *  'event_time' => time(),
 *  'event_id' => 'lead_...',
 *  'event_source_url' => 'https://...',
 *  'action_source' => 'website',
 *  'test_event_code' => '',
 *  'user' => [email, phone_digits, name, fbp, fbc, client_ip, client_user_agent, zip, city, state, country, external_id],
 *  'custom' => [ ...custom_data ]
 * ]
 */
function bc_send_capi_event(PDO $pdo, array $evt): array {
  $eventName = (string)($evt['event_name'] ?? '');
  if ($eventName === '') return ['ok' => false, 'error' => 'missing_event_name'];

  $eventTime = (int)($evt['event_time'] ?? time());
  if ($eventTime <= 0) $eventTime = time();

  $eventId = (string)($evt['event_id'] ?? '');
  $eventSourceUrl = (string)($evt['event_source_url'] ?? '');
  $actionSource = (string)($evt['action_source'] ?? 'website');
  $testCode = (string)($evt['test_event_code'] ?? '');

  $custom = $evt['custom'] ?? [];
  if (!is_array($custom)) $custom = [];

  $user = $evt['user'] ?? [];
  if (!is_array($user)) $user = [];

  // Always ensure we have ip/ua on server side too
  if (empty($user['client_ip'])) $user['client_ip'] = bc_get_client_ip();
  if (empty($user['client_user_agent'])) $user['client_user_agent'] = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

  // Pull active pixels from DB
  try {
    $rows = $pdo->query("SELECT pixel_id, access_token, name FROM bc_pixels WHERE is_active = 1 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    bc_capi_log('error', 'CAPI: failed to query bc_pixels', ['err' => $e->getMessage()]);
    return ['ok' => false, 'error' => 'db_query_failed'];
  }

  if (empty($rows)) {
    bc_capi_log('warn', 'CAPI: no active pixel found');
    return ['ok' => false, 'error' => 'no_active_pixel'];
  }

  $graphVer = defined('BC_FB_GRAPH_VERSION') ? (string)BC_FB_GRAPH_VERSION : 'v20.0';

  $results = [];
  $allOk = true;

  foreach ($rows as $px) {
    $pixelId = trim((string)($px['pixel_id'] ?? ''));
    $token = trim((string)($px['access_token'] ?? ''));
    if ($pixelId === '' || $token === '') {
      $allOk = false;
      $results[] = ['pixel_id' => $pixelId, 'ok' => false, 'error' => 'missing_pixel_or_token'];
      continue;
    }

    $event = [
      'event_name' => $eventName,
      'event_time' => $eventTime,
      'action_source' => $actionSource,
      'event_source_url' => $eventSourceUrl,
      'user_data' => bc_build_user_data($user),
      'custom_data' => (object)$custom,
    ];
    if ($eventId !== '') $event['event_id'] = $eventId;

    $payload = [
      'data' => [$event],
      'partner_agent' => 'barbaracleaning_custom',
    ];
    if ($testCode !== '') $payload['test_event_code'] = $testCode;

    $url = "https://graph.facebook.com/{$graphVer}/" . rawurlencode($pixelId) . "/events?access_token=" . rawurlencode($token);

    $res = bc_capi_http_post($url, $payload, 8);
    $ok = (bool)$res['ok'];

    $results[] = [
      'pixel_id' => $pixelId,
      'ok' => $ok,
      'status' => $res['status'],
      'error' => $res['error'] ?? '',
      'body' => $ok ? '' : substr((string)$res['body'], 0, 800),
    ];

    if (!$ok) {
      $allOk = false;
      bc_capi_log('error', 'CAPI: send failed', [
        'pixel_id' => $pixelId,
        'status' => $res['status'],
        'error' => $res['error'] ?? '',
        'body' => substr((string)$res['body'], 0, 600),
        'event_name' => $eventName,
      ]);
    }
  }

  return ['ok' => $allOk, 'results' => $results];
}
