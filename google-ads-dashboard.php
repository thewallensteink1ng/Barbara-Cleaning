<?php
session_start();
$dbcfg = __DIR__ . '/_private/db-config.php';
if (!is_file($dbcfg)) $dbcfg = __DIR__ . '/db-config.php';
require_once $dbcfg;

$AUTH_SESSION_KEY = 'logged_in_google';
$AUTH_REDIRECT = 'google-ads-dashboard.php';
require __DIR__ . '/admin-auth.php';

$msg = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'create') {
      $tag_name = trim($_POST['tag_name'] ?? '');
      $conversion_id = trim($_POST['conversion_id'] ?? '');
      $lead_label = trim($_POST['lead_label'] ?? '');
      $contact_label = trim($_POST['contact_label'] ?? '');
      $schedule_label = trim($_POST['schedule_label'] ?? '');

      if ($tag_name === '' || $conversion_id === '') {
        $error = 'Tag name e Conversion ID são obrigatórios.';
      } else {
        $stmt = $pdo->prepare("
          INSERT INTO bc_google_ads (tag_name, conversion_id, lead_label, contact_label, schedule_label, is_active)
          VALUES (?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([$tag_name, $conversion_id, $lead_label, $contact_label, $schedule_label]);
        $msg = 'Configuração salva com sucesso!';
      }
    }

    if ($action === 'activate') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $pdo->exec("UPDATE bc_google_ads SET is_active = 0");
        $stmt = $pdo->prepare("UPDATE bc_google_ads SET is_active = 1 WHERE id = ?");
        $stmt->execute([$id]);
        $msg = 'Configuração ativada com sucesso!';
      }
    }

    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM bc_google_ads WHERE id = ?");
        $stmt->execute([$id]);
        $msg = 'Configuração removida com sucesso!';
      }
    }
  } catch (Exception $e) {
    $error = 'Erro: ' . $e->getMessage();
  }
}

$stmt = $pdo->query("SELECT * FROM bc_google_ads ORDER BY id DESC");
$tags = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <title>Barbara Cleaning – Google Ads</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <style>
    body {
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      margin: 0;
      background: #f8fafc;
      color: #0f172a;
    }
    .bc-container { max-width: 980px; margin: 0 auto; padding: 24px 16px; }
    .bc-header {
      background: #ffffff;
      border-bottom: 1px solid #e2e8f0;
      padding: 16px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .bc-header-title { font-size: 18px; font-weight: 600; color: #002842; }
    .bc-header-nav a {
      margin-left: 16px;
      text-decoration: none;
      color: #002842;
      font-weight: 500;
      font-size: 14px;
    }
    .bc-page-title { margin: 0; font-size: 20px; color: #002842; }
    .bc-page-sub { margin: 10px 0 18px; color: #64748b; font-size: 14px; line-height: 1.5; }

    .bc-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 18px;
      box-shadow: 0 10px 20px rgba(15, 23, 42, 0.06);
      margin-bottom: 18px;
    }
    .bc-card-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
    .bc-card-title { font-weight: 600; font-size: 15px; color: #002842; }
    .bc-pill {
      display: inline-flex;
      align-items: center;
      padding: 6px 12px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
    }
    .bc-pill-active { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
    .bc-pill-inactive { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

    .bc-alert {
      border-radius: 12px;
      padding: 12px 14px;
      margin-bottom: 14px;
      font-size: 14px;
      font-weight: 600;
    }
    .bc-alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .bc-alert-error { background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; }

    .bc-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px;
      margin-top: 14px;
    }
    .bc-grid dt { font-size: 12px; color: #64748b; font-weight: 600; }
    .bc-grid dd { margin: 0; font-size: 14px; font-weight: 500; color: #0f172a; word-break: break-all; }

    .bc-actions { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; }
    .bc-btn {
      border: 1px solid #cbd5e1;
      background: #ffffff;
      border-radius: 10px;
      padding: 9px 12px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: all .15s ease;
    }
    .bc-btn-primary { background: #10b981; border-color: #059669; color: #ffffff; }
    .bc-btn-danger { background: #fee2e2; border-color: #fecaca; color: #b91c1c; }
    .bc-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 10px rgba(15, 23, 42, 0.12); }

    .bc-form-card { margin-top: 24px; }
    .bc-form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px 20px;
      margin-bottom: 10px;
    }
    .bc-field { display: flex; flex-direction: column; gap: 4px; }
    .bc-label { font-size: 13px; font-weight: 500; color: #374151; }
    .bc-input {
      border-radius: 10px;
      border: 1px solid #d1d5db;
      padding: 8px 10px;
      font-size: 16px; /* prevent iOS zoom */
    }
    .bc-input:focus {
      outline: none;
      border-color: #10b981;
      box-shadow: 0 0 0 1px rgba(16, 185, 129, 0.2);
    }
    .bc-form-footer { display: flex; justify-content: flex-end; margin-top: 8px; }

    @media (max-width: 640px) {
      .bc-header { flex-direction: column; align-items: flex-start; gap: 6px; }
      .bc-header-title { font-size: 16px; }
      .bc-header-nav a { margin-left: 0; margin-right: 12px; }
    }
  </style>
</head>
<body>
  <header class="bc-header">
    <div class="bc-header-title">Barbara Cleaning – Google Ads</div>
    <nav class="bc-header-nav">
      <a href="leads-dashboard.php">Leads</a>
      <a href="pixels-dashboard.php">Facebook Pixels</a>
      <a href="google-ads-dashboard.php"><strong>Google Ads</strong></a>
      <a href="?logout=1">Sair</a>
    </nav>
  </header>

  <main class="bc-container">
    <h1 class="bc-page-title">Google Ads Conversion Tags</h1>
    <p class="bc-page-sub">
      Cadastre seu <strong>Conversion ID</strong> (AW-XXXXXXX) e os rótulos (labels) das conversões
      de <strong>Lead</strong>, <strong>Contact</strong> (WhatsApp) e, se quiser no futuro,
      <strong>Schedule</strong>. Apenas uma tag fica ativa por vez.
    </p>

    <?php if ($msg): ?>
      <div class="bc-alert bc-alert-success"><?php echo htmlspecialchars($msg, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="bc-alert bc-alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <?php if (empty($tags)): ?>
      <div class="bc-card">
        <p style="margin:0; font-size:14px; color:#6b7280;">
          Nenhuma tag cadastrada ainda. Adicione a primeira configuração de Google Ads abaixo.
        </p>
      </div>
    <?php else: ?>
      <?php foreach ($tags as $tag): ?>
        <section class="bc-card">
          <header class="bc-card-header">
            <div class="bc-card-title">
              <?php echo htmlspecialchars($tag['tag_name'], ENT_QUOTES); ?>
            </div>
            <div class="bc-pill <?php echo $tag['is_active'] ? 'bc-pill-active' : 'bc-pill-inactive'; ?>">
              <?php echo $tag['is_active'] ? 'Ativa' : 'Inativa'; ?>
            </div>
          </header>

          <dl class="bc-grid">
            <div><dt>Conversion ID (AW-...)</dt><dd><?php echo htmlspecialchars($tag['conversion_id'], ENT_QUOTES); ?></dd></div>
            <div><dt>Lead label</dt><dd><?php echo htmlspecialchars($tag['lead_label'] ?? '', ENT_QUOTES); ?></dd></div>
            <div><dt>Contact label</dt><dd><?php echo htmlspecialchars($tag['contact_label'] ?? '', ENT_QUOTES); ?></dd></div>
            <div><dt>Schedule label</dt><dd><?php echo htmlspecialchars($tag['schedule_label'] ?? '', ENT_QUOTES); ?></dd></div>
            <div><dt>Criado em</dt><dd><?php echo htmlspecialchars($tag['created_at'], ENT_QUOTES); ?></dd></div>
          </dl>

          <div class="bc-actions">
            <?php if (!$tag['is_active']): ?>
              <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="activate">
                <input type="hidden" name="id" value="<?php echo (int)$tag['id']; ?>">
                <button type="submit" class="bc-btn bc-btn-primary">Ativar</button>
              </form>
            <?php endif; ?>

            <form method="post" onsubmit="return confirm('Tem certeza que deseja remover esta tag?');" style="margin:0;">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?php echo (int)$tag['id']; ?>">
              <button type="submit" class="bc-btn bc-btn-danger">Remover</button>
            </form>
          </div>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>

    <section class="bc-card bc-form-card">
      <h2 style="margin:0 0 8px; font-size:16px; font-weight:600;">Adicionar nova configuração</h2>
      <p style="margin:0 0 14px; font-size:13px; color:#6b7280;">
        Copie os dados de conversão do Google Ads (Conversion ID e labels) e cole aqui.
      </p>

      <form method="post">
        <input type="hidden" name="action" value="create">

        <div class="bc-form-grid">
          <div class="bc-field">
            <label class="bc-label">Nome interno da tag *</label>
            <input class="bc-input" type="text" name="tag_name" placeholder="Google Ads – Leads Campanha X" required>
          </div>

          <div class="bc-field">
            <label class="bc-label">Conversion ID (AW-...) *</label>
            <input class="bc-input" type="text" name="conversion_id" placeholder="AW-123456789" required>
          </div>

          <div class="bc-field">
            <label class="bc-label">Lead label (opcional)</label>
            <input class="bc-input" type="text" name="lead_label" placeholder="abcdEFGHijklMNopQR">
          </div>

          <div class="bc-field">
            <label class="bc-label">Contact label (WhatsApp) (opcional)</label>
            <input class="bc-input" type="text" name="contact_label" placeholder="xyz123ABC456def789">
          </div>

          <div class="bc-field">
            <label class="bc-label">Schedule label (opcional)</label>
            <input class="bc-input" type="text" name="schedule_label" placeholder="(se quiser usar no futuro)">
          </div>
        </div>

        <div class="bc-form-footer">
          <button type="submit" class="bc-btn bc-btn-primary">Salvar configuração</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>
