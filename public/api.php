<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$action = (string)($_GET['action'] ?? '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($action === 'health' && $method === 'GET') {
        $pdo = db();
        json_response(['ok' => true]);
    }

    if ($action === 'me' && $method === 'GET') {
        $user = current_user();
        json_response([
            'authenticated' => $user !== null,
            'user' => $user,
            'csrf' => $user ? csrf_token() : null,
            'googleClientId' => (string)(app_config()['google_client_id'] ?? ''),
            'basePath' => app_base_path(),
        ]);
    }

    if ($action === 'googleLogin' && $method === 'POST') {
        $data = request_json();
        $credential = trim((string)($data['credential'] ?? ''));
        if ($credential === '') {
            json_response(['error' => 'Google-token mangler.'], 400);
        }

        $cfg = app_config();
        $clientId = trim((string)($cfg['google_client_id'] ?? ''));
        if ($clientId === '') {
            json_response(['error' => 'Google-innlogging er ikke konfigurert på serveren.'], 503);
        }

        $tokenInfo = verify_google_id_token($credential, $clientId);
        $pdo = db();

        $stmt = $pdo->prepare(
            "INSERT INTO okonomi_users (google_sub, email, name, picture_url)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                email = VALUES(email),
                name = VALUES(name),
                picture_url = VALUES(picture_url),
                updated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([
            $tokenInfo['sub'],
            $tokenInfo['email'],
            $tokenInfo['name'] ?? '',
            $tokenInfo['picture'] ?? null,
        ]);

        $stmt = $pdo->prepare("SELECT id, email, name, picture_url FROM okonomi_users WHERE google_sub = ?");
        $stmt->execute([$tokenInfo['sub']]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new RuntimeException('Kunne ikke opprette bruker.');
        }

        seed_default_categories($pdo, (int)$user['id']);
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'picture' => $user['picture_url'],
        ];
        $_SESSION['csrf'] = bin2hex(random_bytes(24));

        json_response([
            'authenticated' => true,
            'user' => $_SESSION['user'],
            'csrf' => $_SESSION['csrf'],
        ]);
    }

    if ($action === 'logout' && $method === 'POST') {
        require_csrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], '', (bool)$params['secure'], true);
        }
        session_destroy();
        json_response(['ok' => true]);
    }

    $userId = require_user_id();
    $pdo = db();

    if ($action === 'categories' && $method === 'GET') {
        $stmt = $pdo->prepare(
            "SELECT id, kind, name, is_default, active
             FROM okonomi_categories
             WHERE user_id = ? AND active = 1
             ORDER BY FIELD(kind,'asset','liability','income','expense'), name"
        );
        $stmt->execute([$userId]);
        json_response(['categories' => $stmt->fetchAll()]);
    }

    if ($action === 'category' && $method === 'POST') {
        require_csrf();
        $data = request_json();
        $kind = (string)($data['kind'] ?? '');
        $name = trim((string)($data['name'] ?? ''));
        if (!valid_kind($kind) || $name === '' || mb_strlen($name) > 120) {
            json_response(['error' => 'Ugyldig kategori.'], 422);
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO okonomi_categories (user_id, kind, name) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $kind, $name]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                json_response(['error' => 'Denne kategorien finnes allerede.'], 409);
            }
            throw $e;
        }
        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($action === 'items' && $method === 'GET') {
        $stmt = $pdo->prepare(
            "SELECT i.id, i.kind, i.name, i.category_id, c.name AS category_name
             FROM okonomi_items i
             JOIN okonomi_categories c ON c.id = i.category_id
             WHERE i.user_id = ? AND i.active = 1
             ORDER BY FIELD(i.kind,'asset','liability','income','expense'), c.name, i.name"
        );
        $stmt->execute([$userId]);
        json_response(['items' => $stmt->fetchAll()]);
    }

    if ($action === 'item' && $method === 'POST') {
        require_csrf();
        $data = request_json();
        $kind = (string)($data['kind'] ?? '');
        $name = trim((string)($data['name'] ?? ''));
        $categoryId = (int)($data['categoryId'] ?? 0);

        if (!valid_kind($kind) || $name === '' || mb_strlen($name) > 160 || $categoryId < 1) {
            json_response(['error' => 'Ugyldig post.'], 422);
        }

        $check = $pdo->prepare("SELECT id FROM okonomi_categories WHERE id = ? AND user_id = ? AND kind = ? AND active = 1");
        $check->execute([$categoryId, $userId, $kind]);
        if (!$check->fetchColumn()) {
            json_response(['error' => 'Kategorien finnes ikke.'], 422);
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO okonomi_items (user_id, category_id, kind, name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $categoryId, $kind, $name]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                json_response(['error' => 'Denne posten finnes allerede.'], 409);
            }
            throw $e;
        }

        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($action === 'snapshot' && $method === 'GET') {
        $date = (string)($_GET['date'] ?? date('Y-m-01'));
        if (!valid_date($date)) {
            json_response(['error' => 'Ugyldig dato.'], 422);
        }

        $stmt = $pdo->prepare(
            "SELECT i.id, i.kind, i.name, c.name AS category_name, bv.amount_cents
             FROM okonomi_items i
             JOIN okonomi_categories c ON c.id = i.category_id
             LEFT JOIN okonomi_balance_snapshots bs ON bs.user_id = i.user_id AND bs.snapshot_date = ?
             LEFT JOIN okonomi_balance_values bv ON bv.snapshot_id = bs.id AND bv.item_id = i.id
             WHERE i.user_id = ? AND i.active = 1 AND i.kind IN ('asset','liability')
             ORDER BY FIELD(i.kind,'asset','liability'), c.name, i.name"
        );
        $stmt->execute([$date, $userId]);
        $items = array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['amount'] = $row['amount_cents'] === null ? null : ((int)$row['amount_cents']) / 100;
            unset($row['amount_cents']);
            return $row;
        }, $stmt->fetchAll());

        $meta = $pdo->prepare("SELECT is_complete, note FROM okonomi_balance_snapshots WHERE user_id = ? AND snapshot_date = ?");
        $meta->execute([$userId, $date]);
        $snapshot = $meta->fetch();

        json_response([
            'date' => $date,
            'isComplete' => $snapshot ? (bool)$snapshot['is_complete'] : false,
            'note' => $snapshot['note'] ?? '',
            'items' => $items,
        ]);
    }

    if ($action === 'snapshot' && $method === 'POST') {
        require_csrf();
        $data = request_json();
        $date = (string)($data['date'] ?? '');
        $values = is_array($data['values'] ?? null) ? $data['values'] : [];
        $complete = !empty($data['complete']);
        $note = trim((string)($data['note'] ?? ''));

        if (!valid_date($date) || mb_strlen($note) > 500) {
            json_response(['error' => 'Ugyldige snapshot-data.'], 422);
        }

        $active = $pdo->prepare("SELECT id FROM okonomi_items WHERE user_id = ? AND active = 1 AND kind IN ('asset','liability')");
        $active->execute([$userId]);
        $activeIds = array_map('intval', $active->fetchAll(PDO::FETCH_COLUMN));

        $valueMap = [];
        foreach ($values as $value) {
            $itemId = (int)($value['itemId'] ?? 0);
            if ($itemId < 1 || !in_array($itemId, $activeIds, true)) {
                json_response(['error' => 'Snapshot inneholder en ugyldig post.'], 422);
            }
            try {
                $valueMap[$itemId] = to_cents($value['amount'] ?? '');
            } catch (InvalidArgumentException $e) {
                json_response(['error' => $e->getMessage()], 422);
            }
        }

        if ($complete && count($valueMap) !== count($activeIds)) {
            json_response(['error' => 'Et komplett øyeblikksbilde må ha verdi for alle aktive eiendeler og all aktiv gjeld.'], 422);
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO okonomi_balance_snapshots (user_id, snapshot_date, is_complete, note)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_complete = VALUES(is_complete), note = VALUES(note)"
            );
            $stmt->execute([$userId, $date, $complete ? 1 : 0, $note === '' ? null : $note]);

            $idStmt = $pdo->prepare("SELECT id FROM okonomi_balance_snapshots WHERE user_id = ? AND snapshot_date = ?");
            $idStmt->execute([$userId, $date]);
            $snapshotId = (int)$idStmt->fetchColumn();

            $upsert = $pdo->prepare(
                "INSERT INTO okonomi_balance_values (snapshot_id, item_id, amount_cents)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE amount_cents = VALUES(amount_cents)"
            );
            foreach ($valueMap as $itemId => $cents) {
                $upsert->execute([$snapshotId, $itemId, $cents]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        json_response(['ok' => true, 'complete' => $complete]);
    }

    if ($action === 'flows' && $method === 'GET') {
        $month = (string)($_GET['month'] ?? date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            json_response(['error' => 'Ugyldig måned.'], 422);
        }
        $from = $month . '-01';
        $to = (new DateTimeImmutable($from))->modify('+1 month')->format('Y-m-d');

        $stmt = $pdo->prepare(
            "SELECT f.id, f.entry_date, f.amount_cents, f.note,
                    i.id AS item_id, i.name AS item_name, i.kind, c.name AS category_name
             FROM okonomi_flow_entries f
             JOIN okonomi_items i ON i.id = f.item_id
             JOIN okonomi_categories c ON c.id = i.category_id
             WHERE f.user_id = ? AND f.entry_date >= ? AND f.entry_date < ?
             ORDER BY f.entry_date DESC, f.id DESC"
        );
        $stmt->execute([$userId, $from, $to]);
        $entries = array_map(static function (array $row): array {
            $row['id'] = (int)$row['id'];
            $row['item_id'] = (int)$row['item_id'];
            $row['amount'] = ((int)$row['amount_cents']) / 100;
            unset($row['amount_cents']);
            return $row;
        }, $stmt->fetchAll());

        json_response(['month' => $month, 'entries' => $entries]);
    }

    if ($action === 'flow' && $method === 'POST') {
        require_csrf();
        $data = request_json();
        $itemId = (int)($data['itemId'] ?? 0);
        $date = (string)($data['date'] ?? '');
        $note = trim((string)($data['note'] ?? ''));

        if ($itemId < 1 || !valid_date($date) || mb_strlen($note) > 500) {
            json_response(['error' => 'Ugyldig registrering.'], 422);
        }

        try {
            $cents = to_cents($data['amount'] ?? '');
        } catch (InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        if ($cents < 0) {
            json_response(['error' => 'Beløpet må være positivt.'], 422);
        }

        $item = $pdo->prepare("SELECT kind FROM okonomi_items WHERE id = ? AND user_id = ? AND active = 1");
        $item->execute([$itemId, $userId]);
        $kind = $item->fetchColumn();
        if (!in_array($kind, ['income', 'expense'], true)) {
            json_response(['error' => 'Velg en inntekts- eller utgiftspost.'], 422);
        }

        $stmt = $pdo->prepare("INSERT INTO okonomi_flow_entries (user_id, item_id, entry_date, amount_cents, note) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $itemId, $date, $cents, $note === '' ? null : $note]);
        json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    }

    if ($action === 'deleteFlow' && $method === 'POST') {
        require_csrf();
        $data = request_json();
        $id = (int)($data['id'] ?? 0);
        if ($id < 1) {
            json_response(['error' => 'Ugyldig registrering.'], 422);
        }
        $stmt = $pdo->prepare("DELETE FROM okonomi_flow_entries WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        json_response(['ok' => true]);
    }

    if ($action === 'summary' && $method === 'GET') {
        $snapshotStmt = $pdo->prepare(
            "SELECT id, snapshot_date
             FROM okonomi_balance_snapshots
             WHERE user_id = ? AND is_complete = 1
             ORDER BY snapshot_date DESC
             LIMIT 1"
        );
        $snapshotStmt->execute([$userId]);
        $snapshot = $snapshotStmt->fetch();

        $assets = 0;
        $liabilities = 0;
        if ($snapshot) {
            $sum = $pdo->prepare(
                "SELECT i.kind, COALESCE(SUM(bv.amount_cents),0) AS total
                 FROM okonomi_balance_values bv
                 JOIN okonomi_items i ON i.id = bv.item_id
                 WHERE bv.snapshot_id = ?
                 GROUP BY i.kind"
            );
            $sum->execute([(int)$snapshot['id']]);
            foreach ($sum->fetchAll() as $row) {
                if ($row['kind'] === 'asset') {
                    $assets = (int)$row['total'];
                } elseif ($row['kind'] === 'liability') {
                    $liabilities = (int)$row['total'];
                }
            }
        }

        $month = date('Y-m');
        $from = $month . '-01';
        $to = (new DateTimeImmutable($from))->modify('+1 month')->format('Y-m-d');
        $flow = $pdo->prepare(
            "SELECT i.kind, COALESCE(SUM(f.amount_cents),0) AS total
             FROM okonomi_flow_entries f
             JOIN okonomi_items i ON i.id = f.item_id
             WHERE f.user_id = ? AND f.entry_date >= ? AND f.entry_date < ?
             GROUP BY i.kind"
        );
        $flow->execute([$userId, $from, $to]);
        $income = 0;
        $expense = 0;
        foreach ($flow->fetchAll() as $row) {
            if ($row['kind'] === 'income') {
                $income = (int)$row['total'];
            } elseif ($row['kind'] === 'expense') {
                $expense = (int)$row['total'];
            }
        }

        json_response([
            'snapshotDate' => $snapshot['snapshot_date'] ?? null,
            'assets' => $assets / 100,
            'liabilities' => $liabilities / 100,
            'netWorth' => ($assets - $liabilities) / 100,
            'month' => $month,
            'income' => $income / 100,
            'expenses' => $expense / 100,
            'surplus' => ($income - $expense) / 100,
        ]);
    }

    json_response(['error' => 'Endepunkt finnes ikke.'], 404);
} catch (Throwable $e) {
    error_log(APP_NAME . ' API error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    json_response(['error' => 'Serverfeil. Kontroller server- og databasekonfigurasjonen.'], 500);
}

function verify_google_id_token(string $credential, string $clientId): array
{
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential);
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Kunne ikke starte Google-verifisering.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        throw new RuntimeException('Google-token kunne ikke verifiseres. ' . $error);
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Ugyldig svar fra Google.');
    }

    $issuer = (string)($data['iss'] ?? '');
    $emailVerified = $data['email_verified'] ?? false;
    $verified = $emailVerified === true || $emailVerified === 'true' || $emailVerified === '1' || $emailVerified === 1;

    if (
        !hash_equals($clientId, (string)($data['aud'] ?? '')) ||
        !in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true) ||
        !$verified ||
        (int)($data['exp'] ?? 0) <= time() ||
        empty($data['sub']) ||
        empty($data['email'])
    ) {
        json_response(['error' => 'Google-innlogging kunne ikke valideres.'], 401);
    }

    return $data;
}
