<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0');

function bc_try_boot_wp(): bool {
  $candidates = [__DIR__ . '/../wp-load.php', __DIR__ . '/../../wp-load.php'];
  foreach ($candidates as $p) {
    if (file_exists($p)) { @include_once $p; if (isset($GLOBALS['wpdb'])) return true; }
  }
  return false;
}

if (!bc_try_boot_wp() || !isset($GLOBALS['wpdb'])) {
  echo json_encode(['ok'=>false,'error'=>'wpdb not available']);
  exit;
}
$wpdb = $GLOBALS['wpdb'];

function tbl($wpdb, $base){
  $plain = $base;
  $pref = $wpdb->prefix.$base;
  $t1 = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $plain));
  if ($t1 === $plain) return $plain;
  $t2 = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $pref));
  if ($t2 === $pref) return $pref;
  return null;
}

$tp = tbl($wpdb,'bc_pixels');
$tg = tbl($wpdb,'bc_google_ads');

$out = ['ok'=>true,'tables'=>['bc_pixels'=>$tp,'bc_google_ads'=>$tg]];
if ($tp) {
  $out['active_pixels'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tp} WHERE is_active=1");
  $out['total_pixels']  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tp}");
}
if ($tg) {
  $out['google_active'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$tg} WHERE is_active=1");
}
echo json_encode($out);
