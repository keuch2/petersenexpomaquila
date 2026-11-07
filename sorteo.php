<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';
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
<title>Sorteo final · Expo Maquila</title>
<link rel="icon" href="<?= h(asset('assets/logo.png')) ?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= h(asset('assets/app.css')) ?>">
</head>
<body class="sorteo-page">
<div id="admin">
  <div class="adm-top"><div class="wrap">
    <div class="t"><img src="<?= h(asset('assets/logo.png')) ?>" alt="Petersen Industria"><span>Sorteo final</span></div>
    <div style="display:flex;gap:14px;align-items:center;font-size:14px">
      <a href="<?= h(base_url()) ?>admin/#sorteos">Volver al panel</a>
    </div>
  </div></div>

  <div class="wrap adm-body">
    <div class="stage" id="stage">
      <img class="stage-logo" src="<?= h(asset('assets/logo.png')) ?>" alt="Petersen Industria">
      <div class="eyebrow">Sorteo final · Expo Maquila 2026</div>
      <div class="prize-name" id="prizeName">Elegí un premio</div>
      <div class="ticker" id="ticker"><div><div class="name" id="tkName">Petersen Industria</div><div class="co" id="tkCo"><span id="poolN">…</span> participantes en el sorteo</div></div></div>
      <div class="stage-opts">
        <label class="stage-select">Premio
          <select id="prizeSel"></select>
        </label>
        <label><input type="checkbox" id="exWin">Excluir ganadores de la ruleta</label>
        <label><input type="checkbox" id="exDraw" checked>Excluir ganadores de sorteos anteriores</label>
      </div>
      <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
        <button class="btn btn-accent" id="drawBtn" style="min-height:56px;font-size:20px">Realizar sorteo</button>
        <button class="btn btn-ghost" id="fsBtn" style="color:#fff">Pantalla completa</button>
      </div>
    </div>

    <div class="card" style="margin-top:20px">
      <h3>Últimos ganadores</h3>
      <p class="sub">Los resultados quedan registrados en el panel, en «Ganadores sorteo», donde se pueden anular o marcar como entregados.</p>
      <div class="hist" id="histList"></div>
    </div>
  </div>
</div>

<canvas id="confetti" hidden></canvas>
<div class="toast" id="toast" hidden></div>
<script src="<?= h(asset('assets/common.js')) ?>"></script>
<script src="<?= h(asset('assets/sorteo.js')) ?>"></script>
</body>
</html>
