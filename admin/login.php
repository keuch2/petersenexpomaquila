<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';

if (current_admin()) {
    header('Location: ' . base_url() . 'admin/');
    exit;
}

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = mb_strtolower(trim((string) ($_POST['username'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'La sesión expiró. Intentá de nuevo.';
    } else {
        $st = db()->prepare('SELECT id, password_hash FROM admins WHERE username = ? AND active = 1');
        $st->execute([$username]);
        $a = $st->fetch();
        if ($a && password_verify($password, $a['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $a['id'];
            db()->prepare('UPDATE admins SET last_login_at = ? WHERE id = ?')->execute([now_ms(), $a['id']]);
            if (password_needs_rehash($a['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $a['id']]);
            }
            header('Location: ' . base_url() . 'admin/');
            exit;
        }
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>Ingresar · Panel Expo Maquila</title>
<link rel="icon" href="<?= h(asset('assets/logo.png')) ?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= h(asset('assets/app.css')) ?>">
</head>
<body>
<div id="admin">
  <div class="adm-top"><div class="wrap">
    <div class="t"><img src="<?= h(asset('assets/logo.png')) ?>" alt="Petersen Industria"><span>Panel Expo Maquila</span></div>
    <a href="<?= h(base_url()) ?>" style="font-size:14px">Ver sitio</a>
  </div></div>
  <div class="wrap">
    <form class="gate" method="post" autocomplete="on">
      <div class="eyebrow">Acceso restringido</div>
      <h2>Panel de administración</h2>
      <p style="color:var(--muted);margin:0 0 16px">Ingresá con tu usuario para ver participantes, premios y sorteos.</p>
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <div class="fields" style="grid-template-columns:1fr">
        <div class="field"><label for="username">Usuario</label><input id="username" name="username" autocomplete="username" value="<?= h($username) ?>" required autofocus></div>
        <div class="field"><label for="password">Contraseña</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
      </div>
      <div class="form-actions"><button class="btn btn-blue" type="submit">Entrar</button><p class="msg bad" role="alert"><?= h($error) ?></p></div>
    </form>
  </div>
</div>
</body>
</html>
