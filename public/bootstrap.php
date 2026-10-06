<?php
declare(strict_types=1);

const APP_NAME = 'Økonomi';

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/config.generated.php';
    if (!is_file($path)) {
        $config = [
            'google_client_id' => '',
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_name' => 'coiurr9fr_db1394934',
            'db_user' => 'coiurr9fr_db1394934',
            'db_password' => '',
        ];
        return $config;
    }

    $loaded = require $path;
    $config = is_array($loaded) ? $loaded : [];
    return $config;
}

function app_base_path(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = rtrim(dirname($script), '/');
    if (str_ends_with($dir, '/api')) {
        $dir = substr($dir, 0, -4);
    }
    return $dir === '' || $dir === '.' ? '' : $dir;
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('okonomi_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => app_base_path() . '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

start_app_session();

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        json_response(['error' => 'Ugyldig JSON.'], 400);
    }
    return $decoded;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = app_config();
    foreach (['db_host', 'db_name', 'db_user', 'db_password'] as $key) {
        if (!isset($cfg[$key]) || $cfg[$key] === '') {
            throw new RuntimeException("Manglende databasekonfigurasjon: {$key}");
        }
    }

    $port = (int)($cfg['db_port'] ?? 3306);
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $cfg['db_host'],
        $port,
        $cfg['db_name']
    );

    $pdo = new PDO($dsn, (string)$cfg['db_user'], (string)$cfg['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS okonomi_users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            google_sub VARCHAR(255) NOT NULL UNIQUE,
            email VARCHAR(320) NOT NULL,
            name VARCHAR(255) NOT NULL DEFAULT '',
            picture_url TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS okonomi_categories (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('asset','liability','income','expense') NOT NULL,
            name VARCHAR(120) NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_category (user_id, kind, name),
            INDEX idx_categories_user_kind (user_id, kind, active),
            CONSTRAINT fk_okonomi_categories_user FOREIGN KEY (user_id) REFERENCES okonomi_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS okonomi_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('asset','liability','income','expense') NOT NULL,
            name VARCHAR(160) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_item (user_id, kind, name),
            INDEX idx_items_user_kind (user_id, kind, active),
            INDEX idx_items_category (category_id),
            CONSTRAINT fk_okonomi_items_user FOREIGN KEY (user_id) REFERENCES okonomi_users(id) ON DELETE CASCADE,
            CONSTRAINT fk_okonomi_items_category FOREIGN KEY (category_id) REFERENCES okonomi_categories(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS okonomi_balance_snapshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            snapshot_date DATE NOT NULL,
            is_complete TINYINT(1) NOT NULL DEFAULT 0,
            note VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_snapshot (user_id, snapshot_date),
            INDEX idx_snapshots_user_date (user_id, snapshot_date),
            CONSTRAINT fk_okonomi_snapshots_user FOREIGN KEY (user_id) REFERENCES okonomi_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS okonomi_balance_values (
            snapshot_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            amount_cents BIGINT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (snapshot_id, item_id),
            INDEX idx_balance_values_item (item_id),
            CONSTRAINT fk_okonomi_balance_values_snapshot FOREIGN KEY (snapshot_id) REFERENCES okonomi_balance_snapshots(id) ON DELETE CASCADE,
            CONSTRAINT fk_okonomi_balance_values_item FOREIGN KEY (item_id) REFERENCES okonomi_items(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS okonomi_flow_entries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            entry_date DATE NOT NULL,
            amount_cents BIGINT NOT NULL,
            note VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_flow_user_date (user_id, entry_date),
            INDEX idx_flow_item (item_id),
            CONSTRAINT fk_okonomi_flow_user FOREIGN KEY (user_id) REFERENCES okonomi_users(id) ON DELETE CASCADE,
            CONSTRAINT fk_okonomi_flow_item FOREIGN KEY (item_id) REFERENCES okonomi_items(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
}

function current_user(): ?array
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_user_id(): int
{
    $user = current_user();
    if (!$user || empty($user['id'])) {
        json_response(['error' => 'Du må være logget inn.'], 401);
    }
    return (int)$user['id'];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['csrf'];
}

function require_csrf(): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($provided === '' || !hash_equals(csrf_token(), $provided)) {
        json_response(['error' => 'Ugyldig sikkerhetstoken. Last siden på nytt.'], 403);
    }
}

function valid_kind(string $kind): bool
{
    return in_array($kind, ['asset', 'liability', 'income', 'expense'], true);
}

function valid_date(string $date): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

function to_cents(mixed $value): int
{
    if (is_int($value)) {
        return $value * 100;
    }
    if (is_float($value)) {
        return (int)round($value * 100);
    }

    $normalized = trim((string)$value);
    $normalized = str_replace([" ", ' '], '', $normalized);
    if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
        $normalized = str_replace('.', '', $normalized);
    }
    $normalized = str_replace(',', '.', $normalized);

    if ($normalized === '' || !is_numeric($normalized)) {
        throw new InvalidArgumentException('Beløpet er ugyldig.');
    }
    return (int)round(((float)$normalized) * 100);
}

function seed_default_categories(PDO $pdo, int $userId): void
{
    $defaults = [
        'asset' => ['Bankkonto', 'Eiendom', 'Aksjer', 'Krypto', 'Råvarer', 'Utlån'],
        'liability' => ['Boliglån', 'Privatlån', 'Studielån'],
        'income' => ['Lønn', 'Ytelser', 'Kapitalinntekt', 'Annen inntekt'],
        'expense' => ['Bolig', 'Mat', 'Transport', 'Helse', 'Fritid', 'Abonnement', 'Forsikring', 'Skatt og avgifter', 'Annet'],
    ];

    $stmt = $pdo->prepare(
        "INSERT INTO okonomi_categories (user_id, kind, name, is_default)
         VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE active = 1"
    );

    foreach ($defaults as $kind => $names) {
        foreach ($names as $name) {
            $stmt->execute([$userId, $kind, $name]);
        }
    }
}
