<?php
// dashboard/pixels-config.php
// Used by pixel-loader.js on the public website.
// IMPORTANT: do NOT expose access_token here.

$dbcfg = __DIR__ . '/_private/db-config.php';
if (!is_file($dbcfg)) $dbcfg = __DIR__ . '/db-config.php';
require_once $dbcfg;

header('Content-Type: application/json; charset=utf-8');

try {
  $stmt = $pdo->query("SELECT pixel_id FROM bc_pixels WHERE is_active = 1 AND pixel_id <> ''");
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'pixels' => $rows,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'pixels' => [],
    'error' => 'db_error'
  ]);
}
