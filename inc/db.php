<?php
declare(strict_types=1);

const DB_PATH = __DIR__ . '/../data/app.sqlite';

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    db_migrate($pdo);
    return $pdo;
}

function now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function db_migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE IF NOT EXISTS admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE COLLATE NOCASE,
  name TEXT NOT NULL DEFAULT '',
  password_hash TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL,
  last_login_at INTEGER
);
CREATE TABLE IF NOT EXISTS prizes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL CHECK (type IN ('ruleta','sorteo')),
  name TEXT NOT NULL,
  stock INTEGER NOT NULL DEFAULT 0,
  prob REAL NOT NULL DEFAULT 0,
  awarded INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  sort INTEGER NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS participants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre TEXT NOT NULL,
  empresa TEXT NOT NULL,
  cargo TEXT NOT NULL,
  telefono TEXT NOT NULL,
  phone_key TEXT NOT NULL UNIQUE,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  rubro TEXT NOT NULL,
  desafio TEXT NOT NULL DEFAULT '[]',
  device TEXT NOT NULL DEFAULT 'web',
  ip TEXT,
  created_at INTEGER NOT NULL,
  token TEXT NOT NULL,
  spun INTEGER NOT NULL DEFAULT 0,
  spun_at INTEGER,
  result TEXT,
  prize_id INTEGER,
  prize_name TEXT,
  code TEXT UNIQUE,
  delivered INTEGER NOT NULL DEFAULT 0,
  delivered_at INTEGER
);
CREATE TABLE IF NOT EXISTS draws (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  prize_id INTEGER,
  prize_name TEXT NOT NULL,
  participant_id INTEGER REFERENCES participants(id) ON DELETE SET NULL,
  winner_name TEXT NOT NULL,
  empresa TEXT,
  cargo TEXT,
  telefono TEXT,
  email TEXT,
  pool INTEGER NOT NULL,
  excluded_instant INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'valido',
  delivered INTEGER NOT NULL DEFAULT 0,
  delivered_at INTEGER,
  admin_id INTEGER,
  at INTEGER NOT NULL,
  voided_at INTEGER
);
CREATE TABLE IF NOT EXISTS brands (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  link TEXT NOT NULL DEFAULT '',
  logo TEXT NOT NULL DEFAULT '',
  sort INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS hits (ip TEXT NOT NULL, action TEXT NOT NULL, at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS hits_idx ON hits (ip, action, at);
CREATE INDEX IF NOT EXISTS draws_participant ON draws (participant_id);
SQL);

    $cols = array_column($pdo->query('PRAGMA table_info(participants)')->fetchAll(), 'name');
    foreach (['brochure' => 'INTEGER NOT NULL DEFAULT 0', 'brochure_sent_at' => 'INTEGER'] as $col => $type) {
        if (in_array($col, $cols, true)) continue;
        try {
            $pdo->exec("ALTER TABLE participants ADD COLUMN $col $type");
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'duplicate column')) throw $e; // otro request ya la creó
        }
    }

    if (setting_get('seeded', $pdo) === null) db_seed($pdo);

    // Una sola vez, para que llegue a las bases ya sembradas y se pueda borrar desde el panel.
    if (setting_get('brand_gedore', $pdo) === null) {
        $pdo->exec("INSERT INTO brands (name, logo, sort) SELECT 'Gedore', 'logos/gedore.png', COALESCE(MAX(sort), 0) + 10 FROM brands WHERE NOT EXISTS (SELECT 1 FROM brands WHERE name = 'Gedore' COLLATE NOCASE)");
        setting_set('brand_gedore', '1', $pdo);
    }
}

function db_seed(PDO $pdo): void
{
    $t = now_ms();
    $pdo->beginTransaction();
    setting_set('content', json_encode(DEFAULT_CONTENT, JSON_UNESCAPED_UNICODE), $pdo);

    $ins = $pdo->prepare('INSERT INTO prizes (type,name,stock,prob,awarded,active,sort,created_at) VALUES (?,?,?,?,0,1,?,?)');
    foreach ([
        ['ruleta', 'Vaso chopero', 60, 20, 10],
        ['ruleta', 'Remera THOR', 30, 10, 20],
        ['ruleta', 'Llavero 3D', 80, 25, 30],
        ['sorteo', 'Vales de productos por Gs. 300.000', 1, 0, 10],
    ] as [$type, $name, $stock, $prob, $sort]) {
        $ins->execute([$type, $name, $stock, $prob, $sort, $t]);
    }

    $ins = $pdo->prepare('INSERT INTO brands (name,logo,sort) VALUES (?,?,?)');
    foreach ([
        ['EMAX', 'emax.png'], ['Spirax Sarco', 'spiraxsarco.png'], ['Grundfos', 'grundfos.jpg'],
        ['Genebre', 'genebre.png'], ['Sullair', 'sullair.jpg'], ['Dunlop', 'dunlop.jpg'],
        ['Klinger', 'klinger.jpg'], ['Rexnord', 'rexnord.jpg'], ['Danfoss', 'danfoss.jpg'],
        ['Metalplan', 'metalplan.jpg'], ['Starrett', 'starret.png'],
    ] as $i => [$name, $file]) {
        $ins->execute([$name, 'logos/' . $file, ($i + 1) * 10]);
    }

    $password = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 12);
    $pdo->prepare('INSERT INTO admins (username,name,password_hash,active,created_at) VALUES (?,?,?,1,?)')
        ->execute(['admin', 'Administrador', password_hash($password, PASSWORD_DEFAULT), $t]);
    @file_put_contents(
        __DIR__ . '/../data/admin-inicial.txt',
        "Usuario: admin\nContraseña: $password\nCambiala desde Admin › Administradores y borrá este archivo.\n"
    );

    setting_set('seeded', (string) $t, $pdo);
    $pdo->commit();
}

const DEFAULT_CONTENT = [
    'heroEyebrow' => 'Expo Maquila 2026 · Stand Petersen Industria',
    'heroTitle' => 'Recorré nuestro Stand Virtual',
    'heroText' => '',
    'threeDUrl' => '',
    'heroImage' => '',
    'contact' => [
        'address' => '',
        'phone' => '',
        'whatsapp' => '+595 98 680 4200 (ventas mayoristas y corporativas)',
        'email' => '',
        'web' => 'https://www.petersen.com.py/division-industrial',
    ],
    'social' => [
        'instagram' => 'https://www.instagram.com/petersen_py/',
        'facebook' => 'https://www.facebook.com/petersenpy',
        'linkedin' => '',
        'youtube' => '',
        'tiktok' => '',
    ],
];

function setting_get(string $key, ?PDO $pdo = null): ?string
{
    $st = ($pdo ?? db())->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function setting_set(string $key, string $value, ?PDO $pdo = null): void
{
    ($pdo ?? db())->prepare('INSERT INTO settings (key,value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function site_content(): array
{
    $c = json_decode(setting_get('content') ?? '', true) ?: [];
    $c = array_replace(DEFAULT_CONTENT, $c);
    $c['contact'] = array_replace(DEFAULT_CONTENT['contact'], (array) ($c['contact'] ?? []));
    $c['social'] = array_replace(DEFAULT_CONTENT['social'], (array) ($c['social'] ?? []));
    return $c;
}

/** Marca un cambio para que el panel sepa que tiene que refrescar. */
function bump(): void
{
    db()->exec("INSERT INTO settings (key,value) VALUES ('rev','1') ON CONFLICT(key) DO UPDATE SET value = CAST(value AS INTEGER) + 1");
}

function public_prizes(): array
{
    return db()->query("SELECT id, name, stock, awarded FROM prizes WHERE type='ruleta' AND active=1 AND prob>0 AND stock>awarded ORDER BY sort, id")->fetchAll();
}

function final_prizes(): array
{
    return db()->query("SELECT id, name FROM prizes WHERE type='sorteo' AND active=1 ORDER BY sort, id")->fetchAll();
}

function brands(): array
{
    return db()->query('SELECT id, name, link, logo FROM brands ORDER BY sort, id')->fetchAll();
}
