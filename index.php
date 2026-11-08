<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

$c = site_content();
$brands = brands();
$finals = final_prizes();
$finalText = $finals ? implode(' · ', array_column($finals, 'name')) : 'Premio final';
$title = $c['heroTitle'] ?: DEFAULT_CONTENT['heroTitle'];
$comma = mb_strpos($title, ',');
$contact = array_filter([
    ['Dirección', $c['contact']['address'], false],
    ['Teléfono', $c['contact']['phone'], false],
    ['WhatsApp', $c['contact']['whatsapp'], false],
    ['Correo', $c['contact']['email'], false],
    ['Sitio web', $c['contact']['web'], true],
], fn($r) => $r[1] !== '');
$socials = array_filter(['instagram' => 'Instagram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'],
    fn($k) => $c['social'][$k] !== '', ARRAY_FILTER_USE_KEY);
$boot = ['prizes' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], public_prizes()), 'finalPrize' => $finalText];
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Petersen Industria · Expo Maquila</title>
<meta name="description" content="Registrate en el stand de Petersen Industria en Expo Maquila 2026, girá la ruleta y participá del sorteo final.">
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<meta name="app-base" content="<?= h(base_url()) ?>">
<link rel="icon" href="<?= h(asset('assets/logo.png')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@62..125,400..900&family=IBM+Plex+Mono:wght@500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="<?= h(asset('assets/app.css')) ?>">
</head>
<body>
<div id="site">
  <header class="top">
    <div class="wrap">
      <a href="#" data-go="hero" aria-label="Petersen Industria, inicio"><img src="<?= h(asset('assets/logo.png')) ?>" alt="Petersen Industria"></a>
      <nav aria-label="Secciones">
        <a href="#" data-go="hero">Stand 3D</a>
        <a href="#" data-go="registro">Registrate y ganá</a>
        <a href="#" data-go="marcas">Marcas</a>
        <a href="#" data-go="contacto">Contacto</a>
      </nav>
    </div>
  </header>

  <section class="hero<?= $c['heroImage'] ? ' has-img' : '' ?>" id="hero">
    <?php if ($c['heroImage']): ?><img class="hero-img" src="<?= h(media_url($c['heroImage'])) ?>" alt=""><?php endif ?>
    <div class="wrap">
      <div>
        <div class="eyebrow"><?= h($c['heroEyebrow'] ?: DEFAULT_CONTENT['heroEyebrow']) ?></div>
        <h1><?php if ($comma > 0): ?><?= h(mb_substr($title, 0, $comma + 1)) ?> <em><?= h(trim(mb_substr($title, $comma + 1))) ?></em><?php else: ?><?= h($title) ?><?php endif ?></h1>
        <?php if ($c['heroText']): ?><p><?= h($c['heroText']) ?></p><?php endif ?>
        <div class="ctas">
          <?php if ($c['threeDUrl']): ?>
            <a class="btn btn-accent" href="<?= h($c['threeDUrl']) ?>" target="_blank" rel="noopener">Hacer recorrido</a>
          <?php else: ?>
            <a class="btn btn-accent" href="maqueta.php" id="cta3d">Hacer recorrido</a>
          <?php endif ?>
          <a class="btn btn-ghost" href="#" data-go="registro">Registrate y ganá</a>
        </div>
      </div>
      <div class="stand" aria-hidden="true">
        <svg viewBox="0 0 400 400" fill="none" stroke-linejoin="round">
          <g stroke="rgba(156,196,234,.55)" stroke-width="1.2">
            <path d="M40 260 L200 350 L360 260 L200 170 Z" fill="rgba(255,255,255,.03)"/>
            <path d="M40 260 L40 110 L200 20 L200 170" fill="rgba(0,68,121,.55)"/>
            <path d="M200 170 L200 20 L360 110 L360 260" fill="rgba(0,51,92,.75)"/>
            <path d="M70 243 L70 128 M100 226 L100 111 M130 209 L130 94 M160 192 L160 77" stroke="rgba(156,196,234,.2)"/>
          </g>
          <path d="M220 110 L340 178 L340 120 L220 52 Z" fill="#f3b80a" opacity=".92"/>
          <g fill="#004479"><path d="M244 98 L262 108 L262 90 L244 80 Z"/></g>
          <g stroke="#9cc4ea" stroke-width="1.2">
            <path d="M120 270 L200 315 L240 292 L160 248 Z" fill="rgba(255,255,255,.12)"/>
            <path d="M120 270 L120 235 L200 280 L200 315" fill="rgba(0,68,121,.8)"/>
            <path d="M200 315 L200 280 L240 257 L240 292" fill="rgba(0,51,92,.9)"/>
            <path d="M120 235 L160 213 L240 257 L200 280 Z" fill="#f3b80a" stroke="#f3b80a"/>
          </g>
          <g stroke="#9cc4ea" stroke-width="1.2" fill="rgba(255,255,255,.08)">
            <ellipse cx="290" cy="270" rx="26" ry="14"/><path d="M264 270 L264 225 M316 270 L316 225"/><ellipse cx="290" cy="225" rx="26" ry="14"/>
          </g>
          <g stroke="rgba(243,184,10,.8)" stroke-dasharray="4 5"><path d="M40 300 L200 390 L360 300"/></g>
        </svg>
        <span class="tag" style="left:6%;top:6%">Planta · 6×6 m</span>
        <span class="tag" style="right:2%;bottom:16%">Ruleta</span>
      </div>
    </div>
  </section>

  <section class="block" id="registro">
    <div class="wrap">
      <div class="sec-head">
        <h2>Registrate y ganá</h2>
        <p>Un registro por persona. Con tu teléfono y correo quedás habilitado para girar la ruleta una vez y para el sorteo final.</p>
      </div>
      <div class="reg">
        <div>
          <div class="steps">
            <div class="step"><div class="n">1</div><div><h3>Completá tus datos</h3><p>Formulario rápido: nombre, empresa y contacto. Con tu teléfono validamos que cada persona juegue una sola vez.</p></div></div>
            <div class="step"><div class="n">2</div><div><h3>Girá la ruleta</h3><p>El resultado aparece en pantalla al instante. Si ganás, te damos un código.</p></div></div>
            <div class="step"><div class="n">3</div><div><h3>Retirá tu premio</h3><p>Presentá el código en el mostrador del stand. Ganes o no, ya participás del sorteo final.</p></div></div>
          </div>
          <div class="final-prize"><span class="eyebrow">Sorteo final entre todos los registrados</span>
            <?php if ($finals): foreach ($finals as $f): ?><b><?= h($f['name']) ?></b><?php endforeach; else: ?><b>Premio final</b><?php endif ?>
          </div>
        </div>

        <form class="form-card" id="regForm" novalidate>
          <h3>Formulario rápido</h3>
          <p>Completá tus datos y girá la ruleta.</p>
          <div class="fields">
            <div class="field full"><label for="f_nombre">Nombre *</label><input id="f_nombre" name="nombre" autocomplete="name" maxlength="120" required></div>
            <div class="field"><label for="f_empresa">Empresa *</label><input id="f_empresa" name="empresa" autocomplete="organization" maxlength="120" required></div>
            <div class="field"><label for="f_cargo">Cargo *</label><input id="f_cargo" name="cargo" autocomplete="organization-title" maxlength="120" required></div>
            <div class="field"><label for="f_telefono">Teléfono *</label><input id="f_telefono" name="telefono" type="tel" autocomplete="tel" maxlength="40" required placeholder="09xx xxx xxx"></div>
            <div class="field"><label for="f_email">Email *</label><input id="f_email" name="email" type="email" autocomplete="email" maxlength="160" required></div>
            <div class="field full"><label for="f_rubro">Rubro *</label>
              <select id="f_rubro" name="rubro" required>
                <option value="">Elegí una opción</option>
                <?php foreach (RUBROS as $r): ?><option><?= h($r) ?></option><?php endforeach ?>
              </select></div>
            <fieldset class="field full challenges">
              <legend>¿Cuál es tu mayor desafío?</legend>
              <div class="chips">
                <?php foreach (DESAFIOS as $d): ?><label><input type="checkbox" name="desafio" value="<?= h($d) ?>"><span><?= h($d) ?></span></label><?php endforeach ?>
              </div>
            </fieldset>
            <label class="check full field" style="grid-column:1/-1"><input type="checkbox" id="f_ok" name="consent" required><span>Acepto las bases y condiciones de la promoción y que Petersen Industria me contacte con información comercial.</span></label>
          </div>
          <div class="form-actions">
            <button class="btn btn-accent" type="submit" id="regBtn">Registrarme y jugar</button>
            <p class="msg" id="regMsg" role="status"></p>
          </div>
        </form>
      </div>
    </div>
  </section>

  <section class="block brands" id="marcas">
    <div class="wrap">
      <div class="sec-head">
        <h2>Marcas que representamos</h2>
        <p>Fabricantes globales con soporte técnico y repuestos en Paraguay.</p>
      </div>
      <?php if ($brands): ?>
      <div class="brand-grid">
        <?php foreach ($brands as $b): $tag = $b['link'] ? 'a' : 'div'; ?>
          <<?= $tag ?> class="brand-tile"<?= $b['link'] ? ' href="' . h($b['link']) . '" target="_blank" rel="noopener"' : '' ?>>
            <?php if ($b['logo']): ?><img src="<?= h(media_url($b['logo'])) ?>" alt="<?= h($b['name']) ?>" loading="lazy"><?php else: ?><span><?= h($b['name']) ?></span><?php endif ?>
          </<?= $tag ?>>
        <?php endforeach ?>
      </div>
      <?php else: ?>
      <div class="empty">Las marcas se mostrarán aquí.</div>
      <?php endif ?>
    </div>
  </section>

  <footer class="site" id="contacto">
    <div class="wrap">
      <div>
        <img src="<?= h(asset('assets/logo.png')) ?>" alt="Petersen Industria">
        <p class="tagline">Las herramientas correctas, en las manos correctas.</p>
      </div>
      <div><h4>Contacto</h4><ul>
        <?php if (!$contact): ?><li><span>Visitanos en el stand de Petersen Industria.</span></li><?php endif ?>
        <?php foreach ($contact as [$label, $value, $isLink]): ?>
          <li><small><?= h($label) ?></small><?php if ($isLink): ?><a href="<?= h(preg_match('#^https?://#i', $value) ? $value : 'https://' . $value) ?>" target="_blank" rel="noopener"><?= h($value) ?></a><?php else: ?><span><?= h($value) ?></span><?php endif ?></li>
        <?php endforeach ?>
      </ul></div>
      <div><h4>Redes</h4><div class="socials">
        <?php foreach ($socials as $k => $label): ?><a href="<?= h($c['social'][$k]) ?>" target="_blank" rel="noopener"><?= h($label) ?></a><?php endforeach ?>
      </div></div>
      <div class="legal"><span>© 2026 Petersen Industria · Paraguay</span><span>Expo Maquila 2026</span></div>
    </div>
  </footer>
</div>

<!-- Ruleta -->
<div class="overlay" id="gameOv" hidden role="dialog" aria-modal="true" aria-labelledby="gameTitle">
  <div class="game">
    <div class="wheel-box">
      <div class="pointer"></div>
      <canvas id="wheel" aria-label="Ruleta de premios"></canvas>
      <div class="hub"><b>P</b></div>
    </div>
    <div class="who" id="gameSide">
      <div class="eyebrow" id="gameHello">Hola</div>
      <h2 id="gameTitle">Tu turno de girar</h2>
      <p>Tocá el botón y esperá a que la ruleta se detenga. El resultado queda registrado con tu teléfono.</p>
      <button class="btn btn-accent" id="spinBtn" style="min-height:58px;font-size:20px;padding:0 34px">Girar la ruleta</button>
    </div>
    <div class="who result" id="gameResult" hidden aria-live="polite"></div>
  </div>
</div>

<canvas id="confetti" hidden></canvas>
<div class="toast" id="toast" hidden></div>

<script type="application/json" id="boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= h(asset('assets/common.js')) ?>"></script>
<script src="<?= h(asset('assets/site.js')) ?>"></script>
</body>
</html>
