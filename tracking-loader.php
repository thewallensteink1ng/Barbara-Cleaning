<?php
declare(strict_types=1);

/**
 * tracking-loader.php (WP-SAFE)
 * - Nunca pode retornar 500 por DB/PDO
 * - Usa WordPress $wpdb (wp-load.php) se existir
 * - Se não conseguir DB, retorna JS válido com console.error (sem quebrar a página)
 */

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/_logs/tracking-loader-errors.log');

function bc_emit_js_error(string $msg, array $extra = []): void {
  $m = json_encode($msg, JSON_UNESCAPED_SLASHES);
  $e = json_encode($extra, JSON_UNESCAPED_SLASHES);
  echo "console.error('[BC tracking-loader]', $m, $e);";
  echo "window.bcPixelDebug = {error:true, message:$m, extra:$e};";
  exit;
}

function bc_try_boot_wp(): bool {
  $candidates = [
    __DIR__ . '/../wp-load.php',
    __DIR__ . '/../../wp-load.php',
    __DIR__ . '/../public_html/wp-load.php',
  ];
  foreach ($candidates as $p) {
    if (file_exists($p)) {
      @include_once $p; // include (não require) pra não fatal
      if (function_exists('get_bloginfo') || class_exists('wpdb')) return true;
    }
  }
  return false;
}

function bc_wpdb(): ?object {
  if (!bc_try_boot_wp()) return null;
  if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) return $GLOBALS['wpdb'];
  return null;
}

function bc_table(object $wpdb, string $base): ?string {
  // tenta sem prefixo e com prefixo
  $plain = $base;
  $pref  = $wpdb->prefix . $base;

  $t1 = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $plain));
  if ($t1 === $plain) return $plain;

  $t2 = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pref));
  if ($t2 === $pref) return $pref;

  return null;
}

$configPath = __DIR__ . '/pixel_config.json';
$config = [
  'auto_recovery' => true,
  'enforce_single_active' => true,
  'guardian' => [
    'enabled' => true,
    'max_reload_attempts' => 2,
    'check_ms' => 1500,
    'timeout_ms' => 12000
  ]
];
if (file_exists($configPath)) {
  $raw = file_get_contents($configPath);
  $j = json_decode($raw ?: '', true);
  if (is_array($j)) $config = array_replace_recursive($config, $j);
}

$wpdb = bc_wpdb();
if (!$wpdb) {
  bc_emit_js_error('WordPress DB ($wpdb) not available. Check wp-load.php path.');
}

$tblPixels = bc_table($wpdb, 'bc_pixels');
$tblGoogle = bc_table($wpdb, 'bc_google_ads');

if (!$tblPixels) {
  bc_emit_js_error('Table bc_pixels not found (with or without WP prefix).');
}

try {
  // 1) pixels ativos
  $active = $wpdb->get_results("SELECT id, pixel_id, access_token, is_active, pixel_name, created_at
                                FROM {$tblPixels} WHERE is_active=1 ORDER BY id DESC", ARRAY_A) ?: [];

  $autoRecovered = false;
  $recoveredPixelId = null;

  // 2) auto-recovery real (respeita pixel_config.json)
  if (count($active) === 0 && !empty($config['auto_recovery'])) {
    $latest = $wpdb->get_row("SELECT id, pixel_id, access_token, pixel_name, created_at
                              FROM {$tblPixels} ORDER BY id DESC LIMIT 1", ARRAY_A);
    if ($latest && !empty($latest['pixel_id'])) {
      $wpdb->query("UPDATE {$tblPixels} SET is_active=0");
      $wpdb->query($wpdb->prepare("UPDATE {$tblPixels} SET is_active=1 WHERE id=%d", (int)$latest['id']));
      $autoRecovered = true;
      $recoveredPixelId = (string)$latest['pixel_id'];
      @file_put_contents(__DIR__ . '/_logs/pixel_actions.log', json_encode([
        'ts' => gmdate('c'), 'action' => 'AUTO_RECOVERY_ACTIVATED', 'pixel_id' => $recoveredPixelId
      ]) . PHP_EOL, FILE_APPEND);

      $active = [[
        'id' => $latest['id'],
        'pixel_id' => $latest['pixel_id'],
        'access_token' => $latest['access_token'] ?? '',
        'is_active' => 1,
        'pixel_name' => $latest['pixel_name'] ?? '',
        'created_at' => $latest['created_at'] ?? null
      ]];
    }
  }

  // 3) enforce single active
  if (!empty($config['enforce_single_active']) && count($active) > 1) {
    $keep = $active[0]; // mais novo
    $wpdb->query("UPDATE {$tblPixels} SET is_active=0");
    $wpdb->query($wpdb->prepare("UPDATE {$tblPixels} SET is_active=1 WHERE id=%d", (int)$keep['id']));
    @file_put_contents(__DIR__ . '/_logs/pixel_actions.log', json_encode([
      'ts' => gmdate('c'), 'action' => 'ENFORCE_SINGLE_ACTIVE', 'kept_pixel_id' => $keep['pixel_id']
    ]) . PHP_EOL, FILE_APPEND);
    $active = [$keep];
  }

  $pixelsClient = [];
  foreach ($active as $p) {
    if (!empty($p['pixel_id'])) {
      $pixelsClient[] = ['pixel_id' => (string)$p['pixel_id'], 'name' => (string)($p['pixel_name'] ?? '')];
    }
  }

  // Google Ads (opcional)
  $googleClient = ['conversionId'=>null,'leadLabel'=>null,'contactLabel'=>null,'scheduleLabel'=>null];
  if ($tblGoogle) {
    $g = $wpdb->get_row("SELECT conversion_id, lead_label, contact_label, schedule_label
                         FROM {$tblGoogle} WHERE is_active=1 ORDER BY id DESC LIMIT 1", ARRAY_A);
    if ($g) {
      $googleClient = [
        'conversionId' => $g['conversion_id'] ?? null,
        'leadLabel' => $g['lead_label'] ?? null,
        'contactLabel' => $g['contact_label'] ?? null,
        'scheduleLabel' => $g['schedule_label'] ?? null,
      ];
    }
  }

  $debug = [
    'version' => 'WP-SAFE',
    'pixels' => array_map(fn($x) => $x['pixel_id'], $pixelsClient),
    'pixels_count' => count($pixelsClient),
    'auto_recovery' => (bool)$config['auto_recovery'],
    'auto_recovered' => $autoRecovered,
    'recovered_pixel_id' => $recoveredPixelId,
    'enforce_single_active' => (bool)$config['enforce_single_active'],
    'google_enabled' => (bool)($googleClient['conversionId'] ?? null)
  ];

  $pixelsJson = json_encode($pixelsClient, JSON_UNESCAPED_SLASHES);
  $googleJson = json_encode($googleClient, JSON_UNESCAPED_SLASHES);
  $debugJson  = json_encode($debug, JSON_UNESCAPED_SLASHES);
  $guardianJson = json_encode($config['guardian'], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  @file_put_contents(__DIR__ . '/_logs/tracking-loader-errors.log', "[".date('c')."] ".$e->getMessage()."\n", FILE_APPEND);
  bc_emit_js_error('DB query failed in tracking-loader', ['msg' => $e->getMessage()]);
}

?>
(function(){
  try {
    if (window.__bcTrackingLoaded) return;
    window.__bcTrackingLoaded = true;
    window.__bcTrackingLoader = 'tracking-loader.php@WP-SAFE';

    window.bcGoogleAds = <?= $googleJson ?>;
    window.bcPixelDebug = <?= $debugJson ?>;

    var PIXELS = <?= $pixelsJson ?>;
    var GUARD = <?= $guardianJson ?>;

    function ensureFbqStub(){
      if (typeof window.fbq === 'function') return;
      var fbq = function(){
        fbq.callMethod ? fbq.callMethod.apply(fbq, arguments) : fbq.queue.push(arguments);
      };
      fbq.queue = fbq.queue || [];
      fbq.push = fbq;
      fbq.loaded = true;
      fbq.version = '2.0';
      window.fbq = fbq;
      window._fbq = fbq;
    }

    function loadScriptOnce(id, src, onerrorCb){
      if (document.getElementById(id)) return;
      var s = document.createElement('script');
      s.id = id;
      s.async = true;
      s.src = src;
      if (onerrorCb) s.onerror = onerrorCb;
      document.head.appendChild(s);
    }

    ensureFbqStub();

    function loadMeta(retry){
      retry = retry || 0;
      loadScriptOnce(
        'bc-fbevents',
        'https://connect.facebook.net/en_US/fbevents.js' + (retry ? ('?v=' + Date.now()) : ''),
        function(){
          console.warn('[BC] Meta script failed. retry=', retry);
          if (retry < 2) {
            var old = document.getElementById('bc-fbevents');
            if (old && old.parentNode) old.parentNode.removeChild(old);
            setTimeout(function(){ loadMeta(retry + 1); }, 800);
          }
        }
      );
    }
    loadMeta(0);

    if (!window.__bcFbqInited) {
      window.__bcFbqInited = true;
      for (var i=0; i<PIXELS.length; i++){
        try { window.fbq('init', PIXELS[i].pixel_id); } catch(e){}
      }
      try { window.fbq('track', 'PageView'); } catch(e){}
    }

    function flushArrayQueue(name){
      var q = window[name];
      if (!Array.isArray(q) || !q.length) return;
      for (var i=0; i<q.length; i++){
        try { window.fbq.apply(null, q[i]); } catch(e){}
      }
      window[name] = [];
    }
    flushArrayQueue('__bcFbqQ');
    flushArrayQueue('__bcFbqQueue');

    // Google Ads (1x)
    (function initGtag(){
      var cfg = window.bcGoogleAds || {};
      if (!cfg.conversionId) return;
      if (window.__bcGtagLoaded) return;
      window.__bcGtagLoaded = true;

      window.dataLayer = window.dataLayer || [];
      function gtag(){ dataLayer.push(arguments); }
      window.gtag = window.gtag || gtag;

      loadScriptOnce('bc-gtag', 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(cfg.conversionId));
      gtag('js', new Date());
      gtag('config', cfg.conversionId, { send_page_view: false });

      window.bcGtagEvent = function(label, params){
        try {
          if (!label) return;
          gtag('event', 'conversion', Object.assign({ send_to: cfg.conversionId + '/' + label }, (params||{})));
        } catch(e){}
      };
    })();

    // Pixel Guardian (evita “morrer do nada”)
    if (GUARD && GUARD.enabled) {
      var start = Date.now();
      var attempts = 0;
      var t = setInterval(function(){
        var ok = (typeof window.fbq === 'function');
        var healthy = ok && (typeof window.fbq.callMethod === 'function' || typeof window.fbq.queue !== 'undefined');
        var timedOut = (Date.now() - start) > (GUARD.timeout_ms || 12000);

        if (healthy) {
          flushArrayQueue('__bcFbqQ');
          flushArrayQueue('__bcFbqQueue');
          return;
        }
        if (!timedOut) return;

        if (attempts < (GUARD.max_reload_attempts || 2)) {
          attempts++;
          console.warn('[BC] Pixel Guardian: reload attempt', attempts);
          var old = document.getElementById('bc-fbevents');
          if (old && old.parentNode) old.parentNode.removeChild(old);
          ensureFbqStub();
          loadMeta(attempts);
          try {
            for (var i=0; i<PIXELS.length; i++){
              window.fbq('init', PIXELS[i].pixel_id);
            }
            window.fbq('track', 'PageView');
          } catch(e){}
          start = Date.now();
        } else {
          clearInterval(t);
          console.error('[BC] Pixel Guardian: failed to recover');
        }
      }, GUARD.check_ms || 1500);
    }

  } catch (e) {
    console.error('[BC] tracking-loader fatal', e);
  }
})();
