<?php
// dashboard/pixels-dashboard.php
// VERSÃO V3 - Com controle de auto-recovery e logs detalhados

session_start();
$dbcfg = __DIR__ . '/_private/db-config.php';
if (!is_file($dbcfg)) $dbcfg = __DIR__ . '/db-config.php';
require_once $dbcfg;

$AUTH_SESSION_KEY = 'logged_in_pixels';
$AUTH_REDIRECT = 'pixels-dashboard.php';
require __DIR__ . '/admin-auth.php';

$msg = null;
$error = null;

// Arquivo de configuração para auto-recovery
$configFile = __DIR__ . '/_private/pixel_config.json';
if (!file_exists($configFile)) $configFile = __DIR__ . '/pixel_config.json';

function get_pixel_config() {
  global $configFile;
  if (file_exists($configFile)) {
    $content = @file_get_contents($configFile);
    $config = json_decode($content, true);
    if (is_array($config)) return $config;
  }
  return ['auto_recovery' => true];
}

function save_pixel_config($config) {
  global $configFile;
  @file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX);
}

function log_pixel_action($action, $details) {
  $logFile = __DIR__ . '/_logs/pixel_actions.log';
  $entry = date('Y-m-d H:i:s') . " | {$action} | " . json_encode($details) . "\n";
  @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

$pixelConfig = get_pixel_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  try {
    // Toggle auto-recovery
    if ($action === 'toggle_auto_recovery') {
      $pixelConfig['auto_recovery'] = !($pixelConfig['auto_recovery'] ?? true);
      save_pixel_config($pixelConfig);
      log_pixel_action('TOGGLE_AUTO_RECOVERY', ['enabled' => $pixelConfig['auto_recovery']]);
      $msg = $pixelConfig['auto_recovery'] ? 'Auto-recovery ativado!' : 'Auto-recovery desativado!';
    }

    if ($action === 'create') {
      $pixel_id = trim($_POST['pixel_id'] ?? '');
      $pixel_name = trim($_POST['pixel_name'] ?? '');
      $access_token = trim($_POST['access_token'] ?? '');

      if ($pixel_id === '' || $access_token === '') {
        $error = 'Pixel ID e Access Token são obrigatórios.';
      } else {
        $checkStmt = $pdo->prepare("SELECT id FROM bc_pixels WHERE pixel_id = ?");
        $checkStmt->execute([$pixel_id]);
        if ($checkStmt->fetch()) {
          $error = 'Este Pixel ID já está cadastrado.';
        } else {
          // CORRIGIDO: Não desativa outros pixels automaticamente
          // Apenas insere o novo pixel como ativo
          // Se o usuário quiser que seja o único ativo, deve desativar os outros manualmente
          $stmt = $pdo->prepare("INSERT INTO bc_pixels (pixel_id, pixel_name, access_token, is_active) VALUES (?, ?, ?, 1)");
          $stmt->execute([$pixel_id, $pixel_name, $access_token]);
          log_pixel_action('CREATE', ['pixel_id' => $pixel_id]);
          $msg = 'Pixel salvo e ativado!';
        }
      }
    }

    if ($action === 'activate') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        // Desativa todos os outros e ativa apenas este
        $pdo->exec("UPDATE bc_pixels SET is_active = 0");
        $stmt = $pdo->prepare("UPDATE bc_pixels SET is_active = 1 WHERE id = ?");
        $stmt->execute([$id]);
        log_pixel_action('ACTIVATE', ['id' => $id]);
        $msg = 'Pixel ativado!';
      }
    }

    if ($action === 'deactivate') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        // Verifica se é o único pixel ativo
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM bc_pixels WHERE is_active = 1");
        $activeCount = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($activeCount <= 1 && $pixelConfig['auto_recovery']) {
          $error = 'Não pode desativar o único pixel ativo enquanto auto-recovery está ligado. Desative o auto-recovery primeiro.';
        } else {
          $stmt = $pdo->prepare("UPDATE bc_pixels SET is_active = 0 WHERE id = ?");
          $stmt->execute([$id]);
          log_pixel_action('DEACTIVATE', ['id' => $id]);
          $msg = 'Pixel desativado!';
        }
      }
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $checkStmt = $pdo->prepare("SELECT is_active FROM bc_pixels WHERE id = ?");
        $checkStmt->execute([$id]);
        $pixel = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM bc_pixels");
        $count = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($count <= 1) {
          $error = 'Não pode remover o único pixel!';
        } else {
          $stmt = $pdo->prepare("DELETE FROM bc_pixels WHERE id = ?");
          $stmt->execute([$id]);
          
          if ($pixel && (int)$pixel['is_active'] === 1) {
            $pdo->exec("UPDATE bc_pixels SET is_active = 1 ORDER BY id DESC LIMIT 1");
          }
          
          log_pixel_action('DELETE', ['id' => $id]);
          $msg = 'Pixel removido!';
        }
      }
    }

    if ($action === 'update_token') {
      $id = (int)($_POST['id'] ?? 0);
      $access_token = trim($_POST['access_token'] ?? '');
      
      if ($id > 0 && $access_token !== '') {
        $stmt = $pdo->prepare("UPDATE bc_pixels SET access_token = ? WHERE id = ?");
        $stmt->execute([$access_token, $id]);
        log_pixel_action('UPDATE_TOKEN', ['id' => $id]);
        $msg = 'Token atualizado!';
      }
    }

  } catch (Exception $e) {
    $error = 'Erro: ' . $e->getMessage();
  }
}

// Auto-recovery (apenas se habilitado)
if ($pixelConfig['auto_recovery'] ?? true) {
  try {
    $activeCount = $pdo->query("SELECT COUNT(*) as c FROM bc_pixels WHERE is_active = 1")->fetch(PDO::FETCH_ASSOC)['c'];
    if ((int)$activeCount === 0) {
      $lastPixel = $pdo->query("SELECT id, pixel_id FROM bc_pixels ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
      if ($lastPixel) {
        $pdo->prepare("UPDATE bc_pixels SET is_active = 1 WHERE id = ?")->execute([$lastPixel['id']]);
        log_pixel_action('AUTO_RECOVERY_DASHBOARD', ['id' => $lastPixel['id'], 'pixel_id' => $lastPixel['pixel_id']]);
        $msg = 'Pixel reativado automaticamente!';
      }
    }
  } catch (Exception $e) {}
}

$stmt = $pdo->query("SELECT * FROM bc_pixels ORDER BY is_active DESC, id DESC");
$pixels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ler logs recentes
$recentLogs = [];
$logFile = __DIR__ . '/_logs/pixel_actions.log';
if (file_exists($logFile)) {
  $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  $recentLogs = array_slice(array_reverse($lines), 0, 10);
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Barbara Cleaning – Pixels</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body{font-family:system-ui,sans-serif;margin:0;background:#f8fafc;color:#0f172a;}
    .container{max-width:900px;margin:0 auto;padding:24px 16px;}
    .header{background:#fff;border-bottom:1px solid #e2e8f0;padding:16px 24px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;}
    .header-title{font-size:18px;font-weight:600;color:#002842;}
    .nav a{margin-left:16px;text-decoration:none;color:#002842;font-weight:500;font-size:14px;}
    .card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;margin-bottom:18px;box-shadow:0 4px 12px rgba(0,0,0,.04);}
    .card-active{border-color:#22c55e;box-shadow:0 0 0 2px rgba(34,197,94,.2);}
    .card-header{display:flex;justify-content:space-between;align-items:center;}
    .card-title{font-weight:600;font-size:15px;}
    .pill{padding:6px 12px;border-radius:999px;font-size:12px;font-weight:600;}
    .pill-active{background:#dcfce7;color:#166534;}
    .pill-inactive{background:#f1f5f9;color:#475569;}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-top:14px;}
    .grid dt{font-size:12px;color:#64748b;font-weight:600;}
    .grid dd{margin:0;font-size:14px;word-break:break-all;}
    .actions{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;}
    .btn{border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:9px 12px;font-size:13px;font-weight:600;cursor:pointer;}
    .btn-primary{background:#cf0558;border-color:#cf0558;color:#fff;}
    .btn-success{background:#22c55e;border-color:#22c55e;color:#fff;}
    .btn-warning{background:#f59e0b;border-color:#f59e0b;color:#fff;}
    .btn-danger{background:#fee2e2;border-color:#fecaca;color:#b91c1c;}
    .btn:hover{transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,0,0,.1);}
    .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-top:14px;}
    .label{font-size:12px;color:#64748b;font-weight:600;display:block;margin-bottom:6px;}
    .input{width:100%;border-radius:10px;border:1px solid #d1d5db;padding:10px 12px;font-size:14px;box-sizing:border-box;}
    .input:focus{outline:none;border-color:#cf0558;}
    .alert{border-radius:12px;padding:12px 14px;margin-bottom:14px;font-weight:600;font-size:14px;}
    .alert-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
    .alert-error{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;}
    .health{background:#f0fdf4;border:1px solid #86efac;padding:12px;border-radius:10px;margin-bottom:18px;}
    .health span{color:#166534;font-weight:600;}
    .config-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;margin-bottom:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
    .config-label{font-size:14px;color:#475569;}
    .config-label strong{color:#0f172a;}
    .toggle{display:inline-flex;align-items:center;gap:8px;}
    .toggle-status{font-size:12px;font-weight:700;padding:4px 10px;border-radius:999px;}
    .toggle-on{background:#dcfce7;color:#166534;}
    .toggle-off{background:#fee2e2;color:#b91c1c;}
    .logs-box{background:#1e293b;border-radius:10px;padding:14px;margin-top:18px;}
    .logs-title{color:#94a3b8;font-size:12px;font-weight:700;margin-bottom:10px;text-transform:uppercase;letter-spacing:.05em;}
    .logs-list{font-family:ui-monospace,monospace;font-size:11px;color:#e2e8f0;line-height:1.6;max-height:200px;overflow-y:auto;}
    .logs-list div{padding:4px 0;border-bottom:1px solid #334155;}
    .logs-list div:last-child{border-bottom:none;}
  </style>
</head>
<body>
  <header class="header">
    <div class="header-title">Barbara Cleaning – Pixels</div>
    <nav class="nav">
      <a href="leads-dashboard.php">Leads</a>
      <a href="pixels-dashboard.php"><strong>Pixels</strong></a>
      <a href="google-ads-dashboard.php">Google Ads</a>
      <a href="?logout=1">Sair</a>
    </nav>
  </header>

  <main class="container">
    <h1 style="margin:0 0 18px;font-size:20px;">Facebook Pixel + CAPI</h1>

    <?php if ($msg): ?>
      <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Configuração de Auto-Recovery -->
    <div class="config-box">
      <div class="config-label">
        <strong>Auto-Recovery:</strong> Reativa automaticamente o último pixel se nenhum estiver ativo.
      </div>
      <div class="toggle">
        <span class="toggle-status <?php echo ($pixelConfig['auto_recovery'] ?? true) ? 'toggle-on' : 'toggle-off'; ?>">
          <?php echo ($pixelConfig['auto_recovery'] ?? true) ? 'LIGADO' : 'DESLIGADO'; ?>
        </span>
        <form method="post" style="margin:0;">
          <input type="hidden" name="action" value="toggle_auto_recovery">
          <button type="submit" class="btn <?php echo ($pixelConfig['auto_recovery'] ?? true) ? 'btn-warning' : 'btn-success'; ?>">
            <?php echo ($pixelConfig['auto_recovery'] ?? true) ? 'Desativar' : 'Ativar'; ?>
          </button>
        </form>
      </div>
    </div>

    <?php 
    $activePixel = null;
    foreach ($pixels as $px) {
      if ((int)$px['is_active'] === 1) { $activePixel = $px; break; }
    }
    if ($activePixel): ?>
      <div class="health">✅ <span>Pixel ativo:</span> <?php echo htmlspecialchars($activePixel['pixel_id']); ?></div>
    <?php else: ?>
      <div class="alert alert-error">⚠️ Nenhum pixel ativo! O tracking não está funcionando.</div>
    <?php endif; ?>

    <?php foreach ($pixels as $px): 
      $isActive = (int)$px['is_active'] === 1;
    ?>
      <section class="card <?php echo $isActive ? 'card-active' : ''; ?>">
        <header class="card-header">
          <div class="card-title"><?php echo htmlspecialchars($px['pixel_name'] ?: 'Pixel #'.$px['id']); ?></div>
          <div class="pill <?php echo $isActive ? 'pill-active' : 'pill-inactive'; ?>">
            <?php echo $isActive ? '● Ativo' : 'Inativo'; ?>
          </div>
        </header>

        <dl class="grid">
          <div><dt>Pixel ID</dt><dd><?php echo htmlspecialchars($px['pixel_id']); ?></dd></div>
          <div><dt>Token</dt><dd style="font-size:12px;"><?php echo htmlspecialchars(substr($px['access_token'],0,25).'...'); ?></dd></div>
        </dl>

        <div class="actions">
          <?php if (!$isActive): ?>
            <form method="post" style="margin:0;">
              <input type="hidden" name="action" value="activate">
              <input type="hidden" name="id" value="<?php echo (int)$px['id']; ?>">
              <button type="submit" class="btn btn-success">Ativar</button>
            </form>
          <?php else: ?>
            <form method="post" style="margin:0;">
              <input type="hidden" name="action" value="deactivate">
              <input type="hidden" name="id" value="<?php echo (int)$px['id']; ?>">
              <button type="submit" class="btn btn-warning">Desativar</button>
            </form>
          <?php endif; ?>

          <form method="post" style="margin:0;" onsubmit="return confirm('Remover pixel?');">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?php echo (int)$px['id']; ?>">
            <button type="submit" class="btn btn-danger">Remover</button>
          </form>

          <button type="button" class="btn" onclick="document.getElementById('token-<?php echo (int)$px['id']; ?>').style.display='block'">
            Atualizar Token
          </button>
        </div>

        <form method="post" id="token-<?php echo (int)$px['id']; ?>" style="display:none;margin-top:14px;">
          <input type="hidden" name="action" value="update_token">
          <input type="hidden" name="id" value="<?php echo (int)$px['id']; ?>">
          <div style="display:flex;gap:10px;">
            <input class="input" type="text" name="access_token" placeholder="Novo token..." required style="flex:1;">
            <button type="submit" class="btn btn-primary">Salvar</button>
          </div>
        </form>
      </section>
    <?php endforeach; ?>

    <section class="card">
      <h2 style="margin:0 0 10px;font-size:16px;">Adicionar Pixel</h2>
      <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div><label class="label">Nome</label><input class="input" type="text" name="pixel_name" placeholder="Ex: Principal"></div>
          <div><label class="label">Pixel ID *</label><input class="input" type="text" name="pixel_id" required></div>
          <div><label class="label">Access Token *</label><input class="input" type="text" name="access_token" required></div>
        </div>
        <div style="text-align:right;margin-top:12px;">
          <button type="submit" class="btn btn-primary">Salvar e Ativar</button>
        </div>
      </form>
    </section>

    <!-- Logs de Ações -->
    <?php if (!empty($recentLogs)): ?>
    <div class="logs-box">
      <div class="logs-title">📋 Últimas ações do pixel</div>
      <div class="logs-list">
        <?php foreach ($recentLogs as $log): ?>
          <div><?php echo htmlspecialchars($log); ?></div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </main>
</body>
</html>
