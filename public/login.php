<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/src/Logger.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Auth.php';

use BiaMiddleware\Auth;

$error = '';

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Por favor, preencha o usuário e a senha.';
    } elseif (Auth::login($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Usuário ou senha inválidos. Verifique suas credenciais.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login | Middleware Bia - PDV ⇄ Nuvemshop</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <style>
    body {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      background: #f8fafc;
      padding: 20px;
    }
    .login-card {
      width: 100%;
      max-width: 420px;
      background: #ffffff;
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      padding: 36px 32px;
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
    }
    .login-brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      margin-bottom: 28px;
    }
    .login-brand .brand-icon {
      width: 52px;
      height: 52px;
      font-size: 26px;
      margin-bottom: 12px;
    }
    .login-brand h1 {
      font-size: 20px;
      font-weight: 700;
      color: var(--text-main);
      letter-spacing: -0.3px;
    }
    .login-brand p {
      font-size: 13px;
      color: var(--text-muted);
      margin-top: 4px;
    }
    .login-alert {
      padding: 12px 14px;
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-radius: var(--radius-sm);
      color: #b91c1c;
      font-size: 13px;
      font-weight: 500;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .login-hint {
      margin-top: 24px;
      padding-top: 18px;
      border-top: 1px solid var(--border-color);
      text-align: center;
      font-size: 12px;
      color: var(--text-dim);
      line-height: 1.5;
    }
  </style>
</head>
<body>

<div class="login-card">
  <div class="login-brand">
    <div class="brand-icon">⚡</div>
    <h1>Bia Middleware</h1>
    <p>Integração PDV eGestor ⇄ Nuvemshop</p>
  </div>

  <?php if ($error !== ''): ?>
    <div class="login-alert">
      <span>⚠️</span>
      <span><?= htmlspecialchars($error) ?></span>
    </div>
  <?php endif; ?>

  <form method="POST" action="login.php">
    <div class="form-group">
      <label for="username">Usuário de Acesso</label>
      <input type="text" id="username" name="username" class="form-control" placeholder="admin" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
    </div>

    <div class="form-group" style="margin-bottom: 22px;">
      <label for="password">Senha</label>
      <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
    </div>

    <button type="submit" class="btn-primary-action" style="padding: 13px 20px; font-size: 15px;">
      🔒 Entrar no Sistema
    </button>
  </form>

  <div class="login-hint">
    Sistema exclusivo de controle de estoque e catálogo.<br>
    Em caso de dúvidas, contate o administrador.
  </div>
</div>

</body>
</html>
