<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

final class ApiError extends Exception
{
    public function __construct(string $message, public int $status = 400, public array $extra = [])
    {
        parent::__construct($message);
    }
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $in = json_decode((string) file_get_contents('php://input'), true);
        return $in = is_array($in) ? $in : [];
    }
    return $in = $_POST;
}

function str_in(string $key, int $max = 200): string
{
    $v = input()[$key] ?? '';
    return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
}

function int_in(string $key): int
{
    return (int) (input()[$key] ?? 0);
}

function bool_in(string $key): bool
{
    return filter_var(input()[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
}

/** Ejecuta $fn dentro de una transacción con bloqueo de escritura (serializa giros y sorteos). */
function tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $r = $fn($pdo);
        $pdo->exec('COMMIT');
        return $r;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function one(string $sql, array $args = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->fetch() ?: null;
}

function run(string $sql, array $args = []): int
{
    $st = db()->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

function new_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $c = 'PI-';
        for ($i = 0; $i < 5; $i++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    } while (one('SELECT 1 FROM participants WHERE code = ?', [$c]));
    return $c;
}

/** Valida los datos de contacto de un participante (registro público y edición desde el admin). */
function participant_fields(): array
{
    $d = [
        'nombre' => str_in('nombre', 120),
        'empresa' => str_in('empresa', 120),
        'cargo' => str_in('cargo', 120),
        'telefono' => str_in('telefono', 40),
        'email' => mb_strtolower(str_in('email', 160)),
        'rubro' => str_in('rubro', 80),
    ];
    $bad = [];
    if (mb_strlen($d['nombre']) < 3) $bad[] = 'nombre';
    if ($d['empresa'] === '') $bad[] = 'empresa';
    if ($d['cargo'] === '') $bad[] = 'cargo';
    if (strlen(preg_replace('/\D/', '', $d['telefono'])) < 7) $bad[] = 'telefono';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $bad[] = 'email';
    if (!in_array($d['rubro'], RUBROS, true)) $bad[] = 'rubro';
    if ($bad) throw new ApiError('Revisá los campos marcados en rojo.', 422, ['fields' => $bad]);
    $d['phone_key'] = phone_key($d['telefono']);
    return $d;
}

function pool_query(bool $exWin, bool $exDraw): array
{
    $sql = 'SELECT id, nombre, empresa, cargo, telefono, email FROM participants WHERE 1=1';
    if ($exWin) $sql .= " AND (result IS NULL OR result <> 'win')";
    if ($exDraw) $sql .= " AND id NOT IN (SELECT participant_id FROM draws WHERE status = 'valido' AND participant_id IS NOT NULL)";
    return db()->query($sql)->fetchAll();
}

function return_ruleta_stock(array $p): void
{
    if ($p['result'] === 'win' && !$p['delivered'] && $p['prize_id']) {
        run('UPDATE prizes SET awarded = MAX(0, awarded - 1) WHERE id = ?', [$p['prize_id']]);
    }
}

function is_url_or_empty(string $v): bool
{
    return $v === '' || (bool) preg_match('#^https?://\S+$#i', $v);
}

function delete_upload(string $path): void
{
    if (str_starts_with($path, 'uploads/') && !str_contains($path, '..')) @unlink(__DIR__ . '/' . $path);
}

/* ------------------------------------------------------------------ */

$action = (string) ($_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$public = ['state', 'register', 'spin', 'brochure_send', 'leave'];

try {
    if ($isPost && !csrf_ok($_SERVER['HTTP_X_CSRF'] ?? null)) {
        throw new ApiError('La sesión expiró. Recargá la página.', 419);
    }
    if (!in_array($action, $public, true)) {
        $me = current_admin();
        if (!$me) throw new ApiError('Iniciá sesión en el panel.', 401);
    }
    $postOnly = static function () use ($isPost) {
        if (!$isPost) throw new ApiError('Método no permitido.', 405);
    };

    switch ($action) {
        /* ---------------- Público ---------------- */
        case 'state':
            json_out(['prizes' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], public_prizes())]);

        case 'register':
            $postOnly();
            $d = participant_fields();
            $desafio = array_values(array_intersect(DESAFIOS, (array) (input()['desafio'] ?? [])));
            if (!bool_in('consent')) throw new ApiError('Para participar tenés que aceptar las bases y condiciones.', 422);
            if (!rate_limit('register', 20, 600)) {
                throw new ApiError('Demasiados registros desde esta conexión. Esperá unos minutos o acercate al stand.', 429);
            }
            $device = str_in('device') === 'kiosco' ? 'kiosco' : 'web';
            $wants = bool_in('brochure') && brochure_can_email();

            $out = tx(function (PDO $pdo) use ($d, $desafio, $device, $wants) {
                $st = $pdo->prepare('SELECT * FROM participants WHERE phone_key = ? OR email = ?');
                $st->execute([$d['phone_key'], $d['email']]);
                $rows = $st->fetchAll();
                foreach ($rows as $r) {
                    if ($r['spun']) {
                        // Quien ya jugó y vuelve con sus mismos datos recupera el acceso al recorrido.
                        $same = count($rows) === 1 && $r['phone_key'] === $d['phone_key'] && $r['email'] === $d['email'];
                        if ($same) $_SESSION['visitor'] = (int) $r['id'];
                        throw new ApiError($r['result'] === 'win'
                            ? 'Este teléfono o correo ya participó y ganó: ' . $r['prize_name'] . '. Retiralo en el mostrador del stand con tu código.'
                            : 'Este teléfono o correo ya participó. Ya está en el sorteo final.', 409, $same ? ['played' => true] : []);
                    }
                }
                if ($rows) {
                    $r = $rows[0];
                    if (count($rows) > 1 || $r['phone_key'] !== $d['phone_key'] || $r['email'] !== $d['email']) {
                        throw new ApiError('Este teléfono o correo ya está registrado con otros datos. Acercate al mostrador del stand.', 409);
                    }
                    $pdo->prepare('UPDATE participants SET nombre=?, empresa=?, cargo=?, telefono=?, rubro=?, desafio=?, brochure=MAX(brochure, ?) WHERE id=?')
                        ->execute([$d['nombre'], $d['empresa'], $d['cargo'], $d['telefono'], $d['rubro'], json_encode($desafio, JSON_UNESCAPED_UNICODE), $wants ? 1 : 0, $r['id']]);
                    return ['id' => (int) $r['id'], 'token' => $r['token'], 'nombre' => $d['nombre'], 'brochure' => $wants && $r['brochure_sent_at'] === null];
                }
                $token = bin2hex(random_bytes(16));
                $pdo->prepare('INSERT INTO participants (nombre,empresa,cargo,telefono,phone_key,email,rubro,desafio,device,ip,created_at,token,brochure) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$d['nombre'], $d['empresa'], $d['cargo'], $d['telefono'], $d['phone_key'], $d['email'], $d['rubro'],
                        json_encode($desafio, JSON_UNESCAPED_UNICODE), $device, client_ip(), now_ms(), $token, $wants ? 1 : 0]);
                return ['id' => (int) $pdo->lastInsertId(), 'token' => $token, 'nombre' => $d['nombre'], 'brochure' => $wants];
            });
            $_SESSION['visitor'] = $out['id'];
            unset($out['id']);
            bump();
            json_out($out);

        case 'brochure_send':
            // Pedido aparte para que el juego no espere al SMTP; se suelta la sesión para no frenar el giro.
            $postOnly();
            session_write_close();
            ignore_user_abort(true);
            $p = one('SELECT id, email FROM participants WHERE token = ? AND brochure = 1 AND brochure_sent_at IS NULL', [str_in('token', 64)]);
            if (!$p || !brochure_can_email()) json_out(['sent' => false]);
            if (!run('UPDATE participants SET brochure_sent_at = ? WHERE id = ? AND brochure_sent_at IS NULL', [now_ms(), $p['id']])) json_out(['sent' => false]);
            $sent = send_brochure($p['email']);
            if (!$sent) run('UPDATE participants SET brochure_sent_at = NULL WHERE id = ?', [$p['id']]);
            bump();
            json_out(['sent' => $sent]);

        case 'leave':
            $postOnly();
            unset($_SESSION['visitor']);
            json_out(['ok' => true]);

        case 'spin':
            $postOnly();
            $token = str_in('token', 64);
            $out = tx(function (PDO $pdo) use ($token) {
                $p = one('SELECT id, spun FROM participants WHERE token = ?', [$token]);
                if (!$p) throw new ApiError('No encontramos tu registro. Completá el formulario de nuevo.', 404);
                if ($p['spun']) throw new ApiError('Ya giraste la ruleta con este registro.', 409);

                $avail = $pdo->query("SELECT id, name, prob FROM prizes WHERE type='ruleta' AND active=1 AND prob>0 AND stock>awarded ORDER BY sort, id")->fetchAll();
                $rnd = random_int(0, 999999) / 10000;
                $acc = 0.0;
                $won = null;
                foreach ($avail as $prize) {
                    $acc += (float) $prize['prob'];
                    if ($rnd < $acc) { $won = $prize; break; }
                }
                $code = null;
                if ($won && run('UPDATE prizes SET awarded = awarded + 1 WHERE id = ? AND stock > awarded', [$won['id']]) === 1) {
                    $code = new_code();
                } else {
                    $won = null;
                }
                $pdo->prepare('UPDATE participants SET spun=1, spun_at=?, result=?, prize_id=?, prize_name=?, code=? WHERE id=?')
                    ->execute([now_ms(), $won ? 'win' : 'lose', $won['id'] ?? null, $won['name'] ?? null, $code, $p['id']]);
                return [
                    'result' => $won ? 'win' : 'lose',
                    'prize' => $won ? ['id' => $won['id'], 'name' => $won['name']] : null,
                    'code' => $code,
                ];
            });
            bump();
            json_out($out);

        /* ---------------- Admin: lectura ---------------- */
        case 'admin_state':
            $rev = setting_get('rev') ?? '0';
            if (($_GET['rev'] ?? null) === $rev) json_out(['rev' => $rev, 'same' => true]);
            $participants = db()->query("SELECT p.id, nombre, empresa, cargo, telefono, email, rubro, desafio, device, created_at, brochure, brochure_sent_at, spun, spun_at, result, prize_id, prize_name, code, delivered, delivered_at,
                (SELECT COUNT(*) FROM draws d WHERE d.participant_id = p.id AND d.status = 'valido') AS draw_wins
                FROM participants p ORDER BY created_at DESC")->fetchAll();
            foreach ($participants as &$p) $p['desafio'] = json_decode($p['desafio'] ?: '[]', true) ?: [];
            unset($p);
            json_out([
                'rev' => $rev,
                'me' => $me['id'],
                'participants' => $participants,
                'prizes' => db()->query('SELECT * FROM prizes ORDER BY type, sort, id')->fetchAll(),
                'draws' => db()->query('SELECT d.*, a.name AS admin_name FROM draws d LEFT JOIN admins a ON a.id = d.admin_id ORDER BY d.at DESC')->fetchAll(),
                'admins' => db()->query('SELECT id, username, name, active, created_at, last_login_at FROM admins ORDER BY username')->fetchAll(),
                'brands' => brands(),
                'content' => site_content(),
                'brochureFile' => brochure_exists() ? ['size' => filesize(BROCHURE_PATH), 'at' => filemtime(BROCHURE_PATH) * 1000] : null,
                'mailReady' => mail_config() !== null,
            ]);

        case 'draw_info':
            $pool = pool_query(!empty($_GET['exWin']), !empty($_GET['exDraw']));
            shuffle($pool);
            json_out([
                'count' => count($pool),
                'sample' => array_map(fn($p) => ['nombre' => $p['nombre'], 'empresa' => $p['empresa']], array_slice($pool, 0, 80)),
            ]);

        case 'export_csv':
            $cols = ['created_at' => 'createdAt', 'nombre' => 'nombre', 'empresa' => 'empresa', 'cargo' => 'cargo', 'telefono' => 'telefono',
                'email' => 'email', 'rubro' => 'rubro', 'desafio' => 'desafio', 'device' => 'device', 'result' => 'result',
                'prize_name' => 'prizeName', 'code' => 'code', 'delivered' => 'delivered', 'sorteo' => 'sorteoFinal', 'brochure' => 'brochure'];
            $rows = db()->query("SELECT p.*, (SELECT GROUP_CONCAT(prize_name, ' | ') FROM draws d WHERE d.participant_id = p.id AND d.status = 'valido') AS sorteo
                FROM participants p ORDER BY created_at DESC")->fetchAll();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="participantes-expo-maquila.csv"');
            header('Cache-Control: no-store');
            $q = fn($v) => '"' . str_replace('"', '""', (string) $v) . '"';
            echo "\u{FEFF}" . implode(';', array_values($cols)) . "\n";
            foreach ($rows as $r) {
                $r['created_at'] = date('c', intdiv((int) $r['created_at'], 1000));
                $r['desafio'] = implode(', ', json_decode($r['desafio'] ?: '[]', true) ?: []);
                $r['delivered'] = $r['delivered'] ? 'sí' : '';
                $r['brochure'] = !$r['brochure'] ? '' : ($r['brochure_sent_at'] ? 'enviado' : 'pendiente');
                echo implode(';', array_map(fn($k) => $q($r[$k] ?? ''), array_keys($cols))) . "\n";
            }
            exit;

        /* ---------------- Admin: participantes ---------------- */
        case 'participant_save':
            $postOnly();
            $id = int_in('id');
            $d = participant_fields();
            $dup = one('SELECT id FROM participants WHERE (phone_key = ? OR email = ?) AND id <> ?', [$d['phone_key'], $d['email'], $id]);
            if ($dup) throw new ApiError('Ya existe otro participante con ese teléfono o correo.', 409);
            if (!run('UPDATE participants SET nombre=?, empresa=?, cargo=?, telefono=?, phone_key=?, email=?, rubro=? WHERE id=?',
                [$d['nombre'], $d['empresa'], $d['cargo'], $d['telefono'], $d['phone_key'], $d['email'], $d['rubro'], $id])) {
                throw new ApiError('Participante no encontrado.', 404);
            }
            bump();
            json_out(['ok' => true]);

        case 'participant_delete':
            $postOnly();
            $id = int_in('id');
            tx(function () use ($id) {
                $p = one('SELECT * FROM participants WHERE id = ?', [$id]);
                if (!$p) throw new ApiError('Participante no encontrado.', 404);
                return_ruleta_stock($p);
                run('DELETE FROM participants WHERE id = ?', [$id]);
            });
            bump();
            json_out(['ok' => true]);

        case 'participant_reset':
            $postOnly();
            $id = int_in('id');
            tx(function () use ($id) {
                $p = one('SELECT * FROM participants WHERE id = ?', [$id]);
                if (!$p) throw new ApiError('Participante no encontrado.', 404);
                return_ruleta_stock($p);
                run('UPDATE participants SET spun=0, spun_at=NULL, result=NULL, prize_id=NULL, prize_name=NULL, code=NULL, delivered=0, delivered_at=NULL WHERE id=?', [$id]);
            });
            bump();
            json_out(['ok' => true]);

        case 'deliver':
            $postOnly();
            $p = one("SELECT id, delivered FROM participants WHERE id = ? AND result = 'win'", [int_in('id')]);
            if (!$p) throw new ApiError('Ganador no encontrado.', 404);
            run('UPDATE participants SET delivered = ?, delivered_at = ? WHERE id = ?', [$p['delivered'] ? 0 : 1, $p['delivered'] ? null : now_ms(), $p['id']]);
            bump();
            json_out(['ok' => true, 'delivered' => !$p['delivered']]);

        /* ---------------- Admin: premios ---------------- */
        case 'prize_add':
        case 'prize_save':
            $postOnly();
            $name = str_in('name', 120);
            if ($name === '') throw new ApiError('El premio necesita un nombre.', 422);
            $stock = max(0, int_in('stock'));
            $prob = max(0.0, min(100.0, (float) (input()['prob'] ?? 0)));
            if ($action === 'prize_add') {
                $type = str_in('type') === 'sorteo' ? 'sorteo' : 'ruleta';
                if ($stock < 1) throw new ApiError('La cantidad tiene que ser al menos 1.', 422);
                $sort = (int) db()->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM prizes')->fetchColumn();
                run('INSERT INTO prizes (type,name,stock,prob,awarded,active,sort,created_at) VALUES (?,?,?,?,0,1,?,?)',
                    [$type, $name, $stock, $type === 'ruleta' ? $prob : 0, $sort, now_ms()]);
            } else {
                $p = one('SELECT * FROM prizes WHERE id = ?', [int_in('id')]);
                if (!$p) throw new ApiError('Premio no encontrado.', 404);
                if ($stock < $p['awarded']) throw new ApiError('La cantidad no puede ser menor a la ya entregada (' . $p['awarded'] . ').', 422);
                run('UPDATE prizes SET name=?, stock=?, prob=?, active=? WHERE id=?',
                    [$name, $stock, $p['type'] === 'ruleta' ? $prob : 0, bool_in('active') ? 1 : 0, $p['id']]);
            }
            bump();
            json_out(['ok' => true]);

        case 'prize_delete':
            $postOnly();
            $p = one('SELECT * FROM prizes WHERE id = ?', [int_in('id')]);
            if (!$p) throw new ApiError('Premio no encontrado.', 404);
            $used = $p['awarded'] > 0
                || one("SELECT 1 FROM participants WHERE prize_id = ? AND result = 'win'", [$p['id']])
                || one("SELECT 1 FROM draws WHERE prize_id = ? AND status = 'valido'", [$p['id']]);
            if ($used) throw new ApiError('Este premio ya tiene ganadores: desactivalo en lugar de eliminarlo.', 409);
            run('DELETE FROM prizes WHERE id = ?', [$p['id']]);
            bump();
            json_out(['ok' => true]);

        /* ---------------- Admin: sorteo final ---------------- */
        case 'draw':
            $postOnly();
            $prizeId = int_in('prize_id');
            $exWin = bool_in('exWin');
            $exDraw = bool_in('exDraw');
            $out = tx(function () use ($prizeId, $exWin, $exDraw, $me) {
                $prize = one("SELECT * FROM prizes WHERE id = ? AND type = 'sorteo'", [$prizeId]);
                if (!$prize || !$prize['active']) throw new ApiError('Elegí un premio de sorteo activo.', 422);
                if ($prize['awarded'] >= $prize['stock']) throw new ApiError('Ya se sortearon todas las unidades de este premio.', 409);
                $pool = pool_query($exWin, $exDraw);
                if (!$pool) throw new ApiError('No hay participantes para sortear.', 409);
                $w = $pool[random_int(0, count($pool) - 1)];
                $at = now_ms();
                run('INSERT INTO draws (prize_id,prize_name,participant_id,winner_name,empresa,cargo,telefono,email,pool,excluded_instant,status,admin_id,at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$prize['id'], $prize['name'], $w['id'], $w['nombre'], $w['empresa'], $w['cargo'], $w['telefono'], $w['email'], count($pool), $exWin ? 1 : 0, 'valido', $me['id'], $at]);
                run('UPDATE prizes SET awarded = awarded + 1 WHERE id = ?', [$prize['id']]);
                return [
                    'id' => (int) db()->lastInsertId(),
                    'winner' => ['nombre' => $w['nombre'], 'empresa' => $w['empresa'], 'cargo' => $w['cargo']],
                    'prize' => $prize['name'],
                    'pool' => count($pool),
                    'remaining' => $prize['stock'] - $prize['awarded'] - 1,
                ];
            });
            bump();
            json_out($out);

        case 'draw_void':
            $postOnly();
            tx(function () {
                $d = one("SELECT * FROM draws WHERE id = ? AND status = 'valido'", [int_in('id')]);
                if (!$d) throw new ApiError('Sorteo no encontrado o ya anulado.', 404);
                run("UPDATE draws SET status = 'anulado', voided_at = ? WHERE id = ?", [now_ms(), $d['id']]);
                if ($d['prize_id']) run('UPDATE prizes SET awarded = MAX(0, awarded - 1) WHERE id = ?', [$d['prize_id']]);
            });
            bump();
            json_out(['ok' => true]);

        case 'draw_delete':
            $postOnly();
            tx(function () {
                $d = one('SELECT * FROM draws WHERE id = ?', [int_in('id')]);
                if (!$d) throw new ApiError('Ganador no encontrado.', 404);
                if ($d['status'] === 'valido' && $d['prize_id']) run('UPDATE prizes SET awarded = MAX(0, awarded - 1) WHERE id = ?', [$d['prize_id']]);
                run('DELETE FROM draws WHERE id = ?', [$d['id']]);
            });
            bump();
            json_out(['ok' => true]);

        case 'draw_deliver':
            $postOnly();
            $d = one("SELECT id, delivered FROM draws WHERE id = ? AND status = 'valido'", [int_in('id')]);
            if (!$d) throw new ApiError('Sorteo no encontrado.', 404);
            run('UPDATE draws SET delivered = ?, delivered_at = ? WHERE id = ?', [$d['delivered'] ? 0 : 1, $d['delivered'] ? null : now_ms(), $d['id']]);
            bump();
            json_out(['ok' => true]);

        /* ---------------- Admin: administradores ---------------- */
        case 'admin_add':
            $postOnly();
            $user = mb_strtolower(str_in('username', 32));
            $name = str_in('name', 80);
            $pass = (string) (input()['password'] ?? '');
            if (!preg_match('/^[a-z0-9._-]{3,32}$/', $user)) throw new ApiError('Usuario: de 3 a 32 caracteres (letras, números, punto, guion).', 422);
            if (mb_strlen($pass) < 8) throw new ApiError('La contraseña debe tener al menos 8 caracteres.', 422);
            if (one('SELECT 1 FROM admins WHERE username = ?', [$user])) throw new ApiError('Ese usuario ya existe.', 409);
            run('INSERT INTO admins (username,name,password_hash,active,created_at) VALUES (?,?,?,1,?)',
                [$user, $name ?: $user, password_hash($pass, PASSWORD_DEFAULT), now_ms()]);
            bump();
            json_out(['ok' => true]);

        case 'admin_save':
            $postOnly();
            $a = one('SELECT * FROM admins WHERE id = ?', [int_in('id')]);
            if (!$a) throw new ApiError('Administrador no encontrado.', 404);
            $active = bool_in('active');
            $pass = (string) (input()['password'] ?? '');
            if (!$active && $a['id'] === $me['id']) throw new ApiError('No podés desactivar tu propio usuario.', 409);
            if (!$active && (int) one('SELECT COUNT(*) AS n FROM admins WHERE active = 1 AND id <> ?', [$a['id']])['n'] === 0) {
                throw new ApiError('Tiene que quedar al menos un administrador activo.', 409);
            }
            if ($pass !== '' && mb_strlen($pass) < 8) throw new ApiError('La contraseña debe tener al menos 8 caracteres.', 422);
            run('UPDATE admins SET name = ?, active = ? WHERE id = ?', [str_in('name', 80) ?: $a['username'], $active ? 1 : 0, $a['id']]);
            if ($pass !== '') run('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $a['id']]);
            bump();
            json_out(['ok' => true]);

        case 'admin_delete':
            $postOnly();
            $id = int_in('id');
            if ($id === $me['id']) throw new ApiError('No podés eliminar tu propio usuario.', 409);
            if ((int) one('SELECT COUNT(*) AS n FROM admins WHERE active = 1 AND id <> ?', [$id])['n'] === 0) {
                throw new ApiError('Tiene que quedar al menos un administrador activo.', 409);
            }
            if (!run('DELETE FROM admins WHERE id = ?', [$id])) throw new ApiError('Administrador no encontrado.', 404);
            bump();
            json_out(['ok' => true]);

        /* ---------------- Admin: contenido y marcas ---------------- */
        case 'content_save':
            $postOnly();
            $c = site_content();
            foreach (['heroEyebrow' => 120, 'heroTitle' => 120, 'heroText' => 400, 'threeDUrl' => 500] as $k => $max) $c[$k] = str_in($k, $max);
            if ($c['threeDUrl'] !== '' && !preg_match('#^https://\S+$#i', $c['threeDUrl'])) throw new ApiError('El enlace 3D tiene que empezar con https://', 422);
            $contact = (array) (input()['contact'] ?? []);
            $social = (array) (input()['social'] ?? []);
            foreach (array_keys(DEFAULT_CONTENT['contact']) as $k) $c['contact'][$k] = mb_substr(trim((string) ($contact[$k] ?? '')), 0, 200);
            foreach (array_keys(DEFAULT_CONTENT['social']) as $k) {
                $v = mb_substr(trim((string) ($social[$k] ?? '')), 0, 300);
                if (!is_url_or_empty($v)) throw new ApiError('Los enlaces de redes tienen que empezar con https://', 422);
                $c['social'][$k] = $v;
            }
            setting_set('content', json_encode($c, JSON_UNESCAPED_UNICODE));
            bump();
            json_out(['ok' => true]);

        case 'hero_remove':
            $postOnly();
            $c = site_content();
            delete_upload($c['heroImage']);
            $c['heroImage'] = '';
            setting_set('content', json_encode($c, JSON_UNESCAPED_UNICODE));
            bump();
            json_out(['ok' => true]);

        case 'brand_add':
            $postOnly();
            $name = str_in('name', 80);
            if ($name === '') throw new ApiError('La marca necesita un nombre.', 422);
            $sort = (int) db()->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM brands')->fetchColumn();
            run('INSERT INTO brands (name, sort) VALUES (?, ?)', [$name, $sort]);
            bump();
            json_out(['ok' => true]);

        case 'brand_save':
            $postOnly();
            $name = str_in('name', 80);
            $link = str_in('link', 300);
            if ($name === '') throw new ApiError('La marca necesita un nombre.', 422);
            if (!is_url_or_empty($link)) throw new ApiError('El enlace tiene que empezar con https://', 422);
            run('UPDATE brands SET name = ?, link = ? WHERE id = ?', [$name, $link, int_in('id')]);
            bump();
            json_out(['ok' => true]);

        case 'brand_delete':
            $postOnly();
            $b = one('SELECT * FROM brands WHERE id = ?', [int_in('id')]);
            if (!$b) throw new ApiError('Marca no encontrada.', 404);
            delete_upload($b['logo']);
            run('DELETE FROM brands WHERE id = ?', [$b['id']]);
            bump();
            json_out(['ok' => true]);

        case 'upload':
            $postOnly();
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new ApiError('No se recibió el archivo.', 422);
            if ($f['size'] > 8 * 1024 * 1024) throw new ApiError('La imagen supera 8 MB. Reducila antes de subirla.', 422);
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])] ?? null;
            if (!$ext || !@getimagesize($f['tmp_name'])) throw new ApiError('Formato no admitido. Usá PNG, JPG o WEBP.', 422);
            $kind = str_in('kind');
            $brand = $kind === 'brand' ? one('SELECT * FROM brands WHERE id = ?', [int_in('id')]) : null;
            if ($kind === 'brand' && !$brand) throw new ApiError('Marca no encontrada.', 404);
            if ($kind !== 'brand' && $kind !== 'hero') throw new ApiError('Destino inválido.', 422);
            $path = 'uploads/' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/' . $path)) throw new ApiError('No se pudo guardar el archivo.', 500);
            if ($brand) {
                delete_upload($brand['logo']);
                run('UPDATE brands SET logo = ? WHERE id = ?', [$path, $brand['id']]);
            } else {
                $c = site_content();
                delete_upload($c['heroImage']);
                $c['heroImage'] = $path;
                setting_set('content', json_encode($c, JSON_UNESCAPED_UNICODE));
            }
            bump();
            json_out(['ok' => true, 'path' => $path]);

        case 'brochure_upload':
            $postOnly();
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new ApiError('No se pudo subir el PDF. Revisá que no supere 15 MB.', 422);
            if ($f['size'] > BROCHURE_MAX) throw new ApiError('El PDF supera 15 MB. Reducilo antes de subirlo.', 422);
            if ((new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) !== 'application/pdf') throw new ApiError('El brochure tiene que ser un PDF.', 422);
            if (!move_uploaded_file($f['tmp_name'], BROCHURE_PATH)) throw new ApiError('No se pudo guardar el archivo.', 500);
            bump();
            json_out(['ok' => true]);

        case 'logout':
            $postOnly();
            unset($_SESSION['admin_id']);
            session_regenerate_id(true);
            json_out(['ok' => true]);

        default:
            throw new ApiError('Acción desconocida.', 404);
    }
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()] + $e->extra, $e->status);
} catch (PDOException $e) {
    error_log('[petersenexpo] ' . $e->getMessage());
    $msg = str_contains($e->getMessage(), 'UNIQUE') ? 'Ese teléfono o correo ya está registrado.' : 'Error de base de datos. Intentá de nuevo.';
    json_out(['error' => $msg], str_contains($e->getMessage(), 'UNIQUE') ? 409 : 500);
} catch (Throwable $e) {
    error_log('[petersenexpo] ' . $e);
    json_out(['error' => 'Error inesperado. Intentá de nuevo.'], 500);
}
