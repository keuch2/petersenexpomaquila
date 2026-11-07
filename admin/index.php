<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
$me = require_admin_page();
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<meta name="app-base" content="<?= h(base_url()) ?>">
<meta name="admin" content="1">
<title>Panel · Expo Maquila</title>
<link rel="icon" href="<?= h(asset('assets/logo.png')) ?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= h(asset('assets/app.css')) ?>">
</head>
<body>
<div id="admin">
  <div class="adm-top"><div class="wrap">
    <div class="t"><img src="<?= h(asset('assets/logo.png')) ?>" alt="Petersen Industria"><span>Panel Expo Maquila</span></div>
    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;font-size:14px">
      <a href="<?= h(base_url()) ?>" target="_blank" rel="noopener">Ver sitio</a>
      <a href="<?= h(base_url()) ?>#kiosco" target="_blank" rel="noopener">Modo kiosco</a>
      <a href="<?= h(base_url()) ?>sorteo.php" target="_blank" rel="noopener">Pantalla de sorteo</a>
      <span style="color:#9cc4ea"><?= h($me['name'] ?: $me['username']) ?></span>
      <button class="btn btn-ghost btn-sm" id="logoutBtn" style="color:#fff">Salir</button>
    </div>
  </div></div>
  <div class="tabs" role="tablist" id="tabs">
    <button data-tab="resumen">Resumen</button>
    <button data-tab="participantes">Participantes</button>
    <button data-tab="ganadores">Ganadores ruleta</button>
    <button data-tab="sorteos">Ganadores sorteo</button>
    <button data-tab="premios">Premios</button>
    <button data-tab="admins">Administradores</button>
    <button data-tab="contenido">Contenido</button>
  </div>
  <div class="wrap adm-body" id="admBody"><p class="sub">Cargando…</p></div>
</div>

<div class="overlay" id="editOv" hidden role="dialog" aria-modal="true" aria-labelledby="editTitle">
  <form class="panel" id="editForm" novalidate>
    <button class="close" type="button" data-close="editOv" aria-label="Cerrar">×</button>
    <div class="eyebrow">Participante</div>
    <h2 id="editTitle" style="font-size:30px;font-stretch:78%;margin:8px 0 16px">Editar datos</h2>
    <input type="hidden" name="id">
    <div class="fields">
      <div class="field full"><label for="e_nombre">Nombre</label><input id="e_nombre" name="nombre" required></div>
      <div class="field"><label for="e_empresa">Empresa</label><input id="e_empresa" name="empresa" required></div>
      <div class="field"><label for="e_cargo">Cargo</label><input id="e_cargo" name="cargo" required></div>
      <div class="field"><label for="e_telefono">Teléfono</label><input id="e_telefono" name="telefono" type="tel" required></div>
      <div class="field"><label for="e_email">Email</label><input id="e_email" name="email" type="email" required></div>
      <div class="field full"><label for="e_rubro">Rubro</label><select id="e_rubro" name="rubro"><?php foreach (RUBROS as $r): ?><option><?= h($r) ?></option><?php endforeach ?></select></div>
    </div>
    <div class="form-actions"><button class="btn btn-accent" type="submit">Guardar</button><p class="msg bad" id="editMsg"></p></div>
  </form>
</div>

<canvas id="confetti" hidden></canvas>
<div class="toast" id="toast" hidden></div>
<script src="<?= h(asset('assets/common.js')) ?>"></script>
<script src="<?= h(asset('assets/admin.js')) ?>"></script>
</body>
</html>
