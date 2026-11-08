<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

$base = base_url();
$boot = ['model' => asset('assets/maqueta/stand.glb'), 'home' => $base];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Maqueta 3D · Petersen Industria · Expo Maquila</title>
<meta name="description" content="Recorré en 3D el stand de Petersen Industria en Expo Maquila 2026: diez rubros y las marcas que representamos.">
<link rel="icon" href="<?= h(asset('assets/logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= h(asset('assets/app.css')) ?>">
<link rel="preload" href="<?= h($boot['model']) ?>" as="fetch" crossorigin>
<script type="importmap">{"imports":{
  "three": <?= json_encode(asset('assets/vendor/three/build/three.module.min.js'), JSON_UNESCAPED_SLASHES) ?>,
  "three/addons/": <?= json_encode($base . 'assets/vendor/three/addons/', JSON_UNESCAPED_SLASHES) ?>
}}</script>
</head>
<body class="mq-page">
<header class="top">
  <div class="wrap">
    <a href="<?= h($base) ?>" class="mq-home" aria-label="Petersen Industria, volver al inicio"><img src="<?= h(asset('assets/logo.png')) ?>" alt="Petersen Industria"></a>
    <nav aria-label="Secciones">
      <a href="<?= h($base) ?>" class="mq-home admin-link">← Volver</a>
      <a class="btn btn-accent btn-sm mq-cta" id="mqCta" href="<?= h($base) ?>#registro">Registrate y ganá</a>
    </nav>
  </div>
</header>

<main class="mq" id="mq">
  <canvas id="mqCanvas" aria-label="Maqueta 3D del stand de Petersen Industria. Arrastrá para girar."></canvas>
  <div class="mq-pins" id="mqPins"></div>

  <div class="mq-loading" id="mqLoading" role="status" aria-live="polite">
    <img src="<?= h(asset('assets/maqueta/portada.jpg')) ?>" alt="">
    <div class="mq-loading-box">
      <div class="eyebrow">Maqueta 3D del stand</div>
      <b id="mqLoadTxt">Cargando la maqueta…</b>
      <div class="mq-bar"><i id="mqBar"></i></div>
    </div>
  </div>

  <div class="mq-intro" id="mqIntro">
    <div class="eyebrow">Expo Maquila 2026 · Stand Petersen Industria</div>
    <h1>Recorré el stand</h1>
    <p class="mq-hint" id="mqHint">Arrastrá para girar · Rueda o pellizco para acercar · Clic derecho o dos dedos para mover</p>
  </div>

  <div class="mq-tools">
    <button class="mq-tool" id="mqReset" type="button" aria-label="Volver a la vista general" title="Vista general">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 11 12 4l9 7M5 10v10h14V10" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
    </button>
    <button class="mq-tool" id="mqSpin" type="button" aria-pressed="false" aria-label="Giro automático" title="Giro automático">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
  </div>

  <section class="mq-card" id="mqCard" hidden aria-live="polite">
    <button class="close" id="mqClose" type="button" aria-label="Cerrar">×</button>
    <div class="mq-card-head"><span class="mq-num" id="mqNum">01</span><h2 id="mqName">Rubro</h2></div>
    <p class="mq-desc" id="mqDesc"></p>
    <div class="eyebrow">Marcas</div>
    <ul class="mq-brands" id="mqBrands"></ul>
    <div class="mq-card-nav">
      <button class="btn btn-ghost btn-sm" id="mqPrev" type="button">← Anterior</button>
      <button class="btn btn-accent btn-sm" id="mqNext" type="button">Siguiente →</button>
    </div>
  </section>

  <nav class="mq-zones" id="mqZones" aria-label="Rubros del stand"></nav>

  <div class="mq-fallback" id="mqFallback" hidden>
    <img src="<?= h(asset('assets/maqueta/portada.jpg')) ?>" alt="Render de la maqueta del stand de Petersen Industria">
    <p>Tu navegador no puede mostrar la maqueta en 3D. Probá desde otro dispositivo o navegador actualizado.</p>
    <a class="btn btn-accent" href="<?= h($base) ?>">Volver al sitio</a>
  </div>
</main>

<script type="application/json" id="boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script type="module" src="<?= h(asset('assets/maqueta.js')) ?>"></script>
</body>
</html>
