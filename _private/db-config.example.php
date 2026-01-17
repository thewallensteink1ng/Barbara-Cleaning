<?php
// dashboard/_private/db-config.php (SERVER ONLY)
// 1) Copy this file to: dashboard/_private/db-config.php
// 2) Fill your database credentials
// 3) Keep this file OUT of GitHub

if (!defined('BC_IS_ADMIN')) define('BC_IS_ADMIN', false);

$dbHost = 'localhost';
$dbName = 'YOUR_DB_NAME';
$dbUser = 'YOUR_DB_USER';
$dbPass = 'YOUR_DB_PASSWORD';

try {
  $pdo = new PDO(
    "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );
} catch (PDOException $e) {
  error_log('BC Dashboard DB error: ' . $e->getMessage());

  $msg = 'Database connection error';
  if (BC_IS_ADMIN) {
    $msg .= ' DETAILS: ' . $e->getMessage();
  }

  http_response_code(500);
  exit($msg);
}
