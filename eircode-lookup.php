<?php
declare(strict_types=1);

/**
 * Eircode Lookup – (Optional) address autofill helper.
 * File: /dashboard/eircode-lookup.php
 *
 * IMPORTANT:
 * - There is no official free public Eircode -> full address API.
 * - This file is built to support a provider (ex: Postcoder / getAddress.io).
 * - If no API key is configured, it returns ok=false so the form can show manual address fields.
 */

header('Content-Type: application/json; charset=utf-8');

function json_out(array $a, int $code=200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_SLASHES);
  exit;
}

function get_cfg(): array {
  // Optional: keep secrets in /dashboard/_private/admin-config.php
  $cfg = [];
  $path = __DIR__ . '/_private/admin-config.php';
  if (is_file($path)) {
    $x = require $path;
    if (is_array($x)) $cfg = $x;
  }
  return $cfg;
}

function eircode_normalize(string $v): string {
  $v = strtoupper(trim($v));
  $v = preg_replace('/\s+/', '', $v);
  return $v;
}

// Eircode format: 7 chars (Routing Key + Unique Identifier) e.g. D02X285 or A65F4E2
function is_valid_eircode(string $v): bool {
  $v = eircode_normalize($v);
  return (bool)preg_match('/^(?:[AC-FHKNPRTV-Y]\d{2}|D6W)[0-9AC-FHKNPRTV-Y]{4}$/i', $v);
}

function http_get_json(string $url, int $timeout=7): array {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = (string)curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err !== '' || $code < 200 || $code >= 300) {
      return ['ok'=>false,'status'=>$code,'error'=>$err,'body'=>$body];
    }

    $j = json_decode($body, true);
    return ['ok'=>is_array($j), 'status'=>$code, 'json'=>$j, 'body'=>$body];
  }

  $ctx = stream_context_create([
    'http' => [
      'method' => 'GET',
      'header' => "Accept: application/json\r\n",
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
  if ($body === false || $code < 200 || $code >= 300) {
    return ['ok'=>false,'status'=>$code,'error'=>($body===false?'file_get_contents_failed':''),'body'=>(string)($body?:'')];
  }
  $j = json_decode((string)$body, true);
  return ['ok'=>is_array($j), 'status'=>$code, 'json'=>$j, 'body'=>(string)$body];
}

try {
  $eircode = (string)($_GET['eircode'] ?? '');
  $eircode = eircode_normalize($eircode);

  if ($eircode === '') json_out(['ok'=>false,'error'=>'missing_eircode'], 400);
  if (!is_valid_eircode($eircode)) json_out(['ok'=>false,'error'=>'invalid_eircode'], 422);

  $cfg = get_cfg();

  /**
   * Provider: Postcoder (Capita) – supports Irish Eircode lookup with API key.
   * Configure:
   * - create /dashboard/_private/admin-config.php and return ['postcoder_api_key' => 'YOUR_KEY']
   *
   * Docs mention:
   * https://ws.postcoder.com/pcw/{apikey}/address/ie/{eircode}?format=json
   */
  $key = (string)($cfg['postcoder_api_key'] ?? '');
  $key = trim($key);

  if ($key === '') {
    json_out([
      'ok'=>false,
      'error'=>'no_provider_configured',
      'message'=>'No address lookup API key configured. Form should show manual address fields.'
    ], 200);
  }

  $url = 'https://ws.postcoder.com/pcw/' . rawurlencode($key) . '/address/ie/' . rawurlencode($eircode) . '?format=json';
  $res = http_get_json($url, 8);

  if (!$res['ok'] || empty($res['json']) || !is_array($res['json'])) {
    json_out(['ok'=>false,'error'=>'lookup_failed','status'=>$res['status'] ?? 0], 200);
  }

  // Postcoder returns an array of matches
  $first = $res['json'][0] ?? null;
  if (!is_array($first)) json_out(['ok'=>false,'error'=>'no_results'], 200);

  // Best-effort mapping (provider-dependent)
  $line1 = (string)($first['addressline1'] ?? ($first['summaryline'] ?? ''));
  $line2 = (string)($first['addressline2'] ?? '');
  $city  = (string)($first['posttown'] ?? ($first['city'] ?? ''));
  $county = (string)($first['county'] ?? '');

  json_out([
    'ok'=>true,
    'eircode'=>$eircode,
    'address_line1'=>trim($line1),
    'address_line2'=>trim($line2),
    'city'=>trim($city),
    'county'=>trim($county),
    'raw'=>$first, // useful for debugging; remove if you prefer
  ], 200);

} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>'server_error'], 500);
}
