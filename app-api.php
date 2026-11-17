<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_name('elingker_app');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Strict',
    'path' => '/',
]);
session_start();

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $origin_host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $request_host = strtolower((string) parse_url('https://' . $host, PHP_URL_HOST));
    if ($origin_host === '' || $request_host === '' || $origin_host !== $request_host) {
        respond(403, ['error' => 'Sumber permintaan tidak valid. Muat ulang halaman dari website aplikasi.']);
    }
}

function read_request(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 3 * 1024 * 1024) {
        respond(413, ['error' => 'Ukuran permintaan melebihi batas 3 MB.']);
    }
    $request = json_decode($raw, true);
    if (!is_array($request)) {
        respond(400, ['error' => 'Format JSON tidak valid.']);
    }
    return $request;
}

function require_login(PDO $pdo): array
{
    $user_id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$user_id) {
        respond(401, ['error' => 'Sesi login berakhir. Silakan masuk kembali.']);
    }
    $statement = $pdo->prepare(
        'SELECT id, username, role FROM lingker_users WHERE id = :id AND active = 1'
    );
    $statement->execute([':id' => $user_id]);
    $user = $statement->fetch();
    if (!$user) {
        $_SESSION = [];
        session_destroy();
        respond(401, ['error' => 'Akun tidak aktif. Hubungi Admin.']);
    }
    return $user;
}

function require_csrf(): void
{
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || !is_string($csrf)
        || !hash_equals((string) $_SESSION['csrf_token'], $csrf)) {
        respond(403, ['error' => 'Token keamanan tidak valid. Muat ulang halaman dan masuk kembali.']);
    }
}

function username_normalize(string $username): string
{
    $username = strtolower(trim($username));
    if (!preg_match('/^[a-z0-9._-]{3,32}$/', $username)) {
        respond(422, ['error' => 'Username harus 3–32 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.']);
    }
    return $username;
}

function validate_password($password): string
{
    if (!is_string($password) || strlen($password) < 12 || strlen($password) > 128) {
        respond(422, ['error' => 'Password harus 12–128 karakter.']);
    }
    return $password;
}

function validate_master(array $data): array
{
    $areas = $data['areas'] ?? null;
    $places = $data['places'] ?? null;
    if (!is_array($areas) || !is_array($places) || count($areas) < 1
        || count($areas) > 200 || count($places) > 1000) {
        respond(422, ['error' => 'Daftar area atau tempat tidak valid.']);
    }
    $normalized_areas = [];
    $area_keys = [];
    foreach ($areas as $area) {
        if (!is_string($area)) {
            respond(422, ['error' => 'Nama area tidak valid.']);
        }
        $area = trim($area);
        $key = function_exists('mb_strtolower') ? mb_strtolower($area, 'UTF-8') : strtolower($area);
        if ($area === '' || strlen($area) > 200 || isset($area_keys[$key])) {
            respond(422, ['error' => 'Nama area kosong, terlalu panjang, atau duplikat.']);
        }
        $area_keys[$key] = true;
        $normalized_areas[] = $area;
    }

    $normalized_places = [];
    $place_keys = [];
    foreach ($places as $place) {
        if (!is_array($place) || !is_string($place['area'] ?? null)
            || !is_string($place['name'] ?? null)) {
            respond(422, ['error' => 'Data tempat tidak valid.']);
        }
        $area = trim($place['area']);
        $name = trim($place['name']);
        $area_key = function_exists('mb_strtolower') ? mb_strtolower($area, 'UTF-8') : strtolower($area);
        $name_key = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $key = $area_key . "\0" . $name_key;
        if (!isset($area_keys[$area_key]) || $name === '' || strlen($name) > 200 || isset($place_keys[$key])) {
            respond(422, ['error' => 'Nama tempat kosong, terlalu panjang, duplikat, atau area tidak terdaftar.']);
        }
        $place_keys[$key] = true;
        $normalized_places[] = ['area' => $area, 'name' => $name];
    }
    return ['areas' => $normalized_areas, 'places' => $normalized_places];
}

function read_master(PDO $pdo): array
{
    $row = $pdo->query('SELECT data_json, revision FROM lingker_master WHERE id = 1')->fetch();
    if (!$row) {
        respond(500, ['error' => 'Master belum diinisialisasi. Impor master-schema.sql terlebih dahulu.']);
    }
    $data = json_decode((string) $row['data_json'], true);
    if (!is_array($data) || !isset($data['areas'], $data['places'])
        || !is_array($data['areas']) || !is_array($data['places'])) {
        respond(500, ['error' => 'Data master di database tidak valid.']);
    }
    return ['data' => $data, 'revision' => (int) $row['revision']];
}

function validate_record(array $record, string $username): array
{
    $id = $record['id'] ?? '';
    if (!is_string($id) || $id === '' || strlen($id) > 80) {
        respond(422, ['error' => 'ID catatan tidak valid.']);
    }
    if (!is_string($record['tgl'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $record['tgl'])) {
        respond(422, ['error' => 'Tanggal pengukuran tidak valid.']);
    }
    if (!is_numeric($record['hasil'] ?? null) || !is_finite((float) $record['hasil'])
        || (float) $record['hasil'] < 0) {
        respond(422, ['error' => 'Nilai pengukuran harus angka nol atau lebih.']);
    }
    $record['hasil'] = (float) $record['hasil'];
    if (array_key_exists('noiseForm', $record) && $record['noiseForm'] !== null) {
        $noise_form = $record['noiseForm'];
        if (($record['katKey'] ?? '') !== 'kebisingan' || !is_array($noise_form)) {
            respond(422, ['error' => 'Data formulir kebisingan tidak valid.']);
        }
        $time = $noise_form['time'] ?? null;
        $duration = $noise_form['durationHours'] ?? null;
        $activity = $noise_form['activity'] ?? null;
        $source = $noise_form['source'] ?? null;
        if (!is_string($time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            respond(422, ['error' => 'Waktu pengukuran kebisingan tidak valid.']);
        }
        if (!is_numeric($duration) || !is_finite((float) $duration)
            || (float) $duration <= 0 || (float) $duration > 24) {
            respond(422, ['error' => 'Durasi pengukuran kebisingan harus lebih dari 0 sampai 24 jam.']);
        }
        foreach (['Aktivitas' => $activity, 'Sumber kebisingan' => $source] as $label => $value) {
            if (!is_string($value) || trim($value) === '' || strlen($value) > 200) {
                respond(422, ['error' => $label . ' wajib diisi dan maksimal 200 karakter.']);
            }
        }
        $record['noiseForm'] = [
            'time' => $time,
            'durationHours' => (float) $duration,
            'activity' => trim($activity),
            'source' => trim($source),
        ];
    }
    $record['createdBy'] = $username;
    return $record;
}

try {
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        respond(426, ['error' => 'Website harus diakses melalui HTTPS untuk login yang aman.']);
    }
    $config_path = dirname(__DIR__) . '/lingker-private/master-config.php';
    if (!is_file($config_path)) {
        respond(503, ['error' => 'Konfigurasi server belum dipasang. Ikuti master-sync-setup.md.']);
    }
    $config = require $config_path;
    if (!is_array($config) || empty($config['db_dsn']) || empty($config['db_user'])
        || !array_key_exists('db_password', $config) || empty($config['setup_key'])) {
        respond(503, ['error' => 'Konfigurasi database server belum lengkap.']);
    }
    $pdo = new PDO($config['db_dsn'], $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';
        if ($action === 'status') {
            $state = $pdo->query('SELECT admin_created FROM lingker_auth_state WHERE id = 1')->fetchColumn();
            if ($state === false) {
                respond(500, ['error' => 'Database belum siap. Impor master-schema.sql terlebih dahulu.']);
            }
            $user = null;
            $user_id = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
            if ($user_id) {
                $statement = $pdo->prepare(
                    'SELECT id, username, role FROM lingker_users WHERE id = :id AND active = 1'
                );
                $statement->execute([':id' => $user_id]);
                $user = $statement->fetch() ?: null;
            }
            respond(200, [
                'setupRequired' => (int) $state === 0,
                'user' => $user,
                'csrfToken' => $user ? ($_SESSION['csrf_token'] ?? '') : '',
            ]);
        }
        if ($action === 'master') {
            respond(200, read_master($pdo));
        }
        if ($action === 'records') {
            $user = require_login($pdo);
            if ($user['role'] === 'admin') {
                $version = $pdo->query(
                    "SELECT COUNT(*) AS record_count, COALESCE(MAX(updated_at), 0) AS last_updated FROM lingker_records"
                )->fetch();
                $statement = $pdo->query(
                    'SELECT record_json, username FROM lingker_records r
                     JOIN lingker_users u ON u.id = r.owner_user_id ORDER BY r.created_at DESC'
                );
            } else {
                $version_statement = $pdo->prepare(
                    "SELECT COUNT(*) AS record_count, COALESCE(MAX(updated_at), 0) AS last_updated
                     FROM lingker_records WHERE owner_user_id = :user_id"
                );
                $version_statement->execute([':user_id' => $user['id']]);
                $version = $version_statement->fetch();
                $statement = $pdo->prepare(
                    'SELECT record_json, username FROM lingker_records r
                     JOIN lingker_users u ON u.id = r.owner_user_id
                     WHERE r.owner_user_id = :user_id ORDER BY r.created_at DESC'
                );
                $statement->execute([':user_id' => $user['id']]);
            }
            $records = [];
            foreach ($statement->fetchAll() as $row) {
                $record = json_decode((string) $row['record_json'], true);
                if (!is_array($record)) {
                    respond(500, ['error' => 'Terdapat catatan dengan format tidak valid.']);
                }
                $record['createdBy'] = $row['username'];
                $records[] = $record;
            }
            respond(200, [
                'records' => $records,
                'user' => $user,
                'version' => $version['record_count'] . ':' . $version['last_updated'],
            ]);
        }
        if ($action === 'records-version') {
            $user = require_login($pdo);
            if ($user['role'] === 'admin') {
                $version = $pdo->query(
                    "SELECT COUNT(*) AS record_count, COALESCE(MAX(updated_at), 0) AS last_updated FROM lingker_records"
                )->fetch();
            } else {
                $statement = $pdo->prepare(
                    "SELECT COUNT(*) AS record_count, COALESCE(MAX(updated_at), 0) AS last_updated
                     FROM lingker_records WHERE owner_user_id = :user_id"
                );
                $statement->execute([':user_id' => $user['id']]);
                $version = $statement->fetch();
            }
            respond(200, ['version' => $version['record_count'] . ':' . $version['last_updated']]);
        }
        if ($action === 'accounts') {
            $user = require_login($pdo);
            if ($user['role'] !== 'admin') {
                respond(403, ['error' => 'Hanya Admin yang dapat melihat daftar akun.']);
            }
            $accounts = $pdo->query(
                "SELECT username, role FROM lingker_users WHERE active = 1 ORDER BY username"
            )->fetchAll();
            respond(200, ['accounts' => $accounts]);
        }
        respond(400, ['error' => 'Aksi tidak dikenali.']);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(405, ['error' => 'Metode permintaan tidak diizinkan.']);
    }
    require_same_origin();
    $request = read_request();
    $action = $request['action'] ?? '';

    if ($action === 'setup') {
        $setup_key = is_string($request['setupKey'] ?? null) ? $request['setupKey'] : '';
        if (strlen($setup_key) < 32 || !hash_equals((string) $config['setup_key'], $setup_key)) {
            respond(403, ['error' => 'Kunci setup Admin tidak valid. Periksa file konfigurasi server.']);
        }
        $username = is_string($request['username'] ?? null) ? trim($request['username']) : '';
        $normalized = username_normalize($username);
        $password = validate_password($request['password'] ?? null);
        $pdo->beginTransaction();
        $state = $pdo->query('SELECT admin_created FROM lingker_auth_state WHERE id = 1 FOR UPDATE')->fetchColumn();
        if ($state === false || (int) $state !== 0) {
            $pdo->rollBack();
            respond(409, ['error' => 'Admin pertama sudah dibuat. Silakan login.']);
        }
        $statement = $pdo->prepare(
            "INSERT INTO lingker_users (username, username_normalized, password_hash, role)
             VALUES (:username, :normalized, :password_hash, 'admin')"
        );
        $statement->execute([
            ':username' => $username,
            ':normalized' => $normalized,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $user_id = (int) $pdo->lastInsertId();
        $pdo->exec('UPDATE lingker_auth_state SET admin_created = 1 WHERE id = 1');
        $pdo->commit();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user_id;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(201, ['user' => ['id' => $user_id, 'username' => $username, 'role' => 'admin'],
            'csrfToken' => $_SESSION['csrf_token']]);
    }

    if ($action === 'login') {
        $username = is_string($request['username'] ?? null) ? trim($request['username']) : '';
        $normalized = strtolower($username);
        $password = is_string($request['password'] ?? null) ? $request['password'] : '';
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $rate_key = hash('sha256', $normalized . '|' . $remote);
        $limit_statement = $pdo->prepare(
            'SELECT failed_attempts, locked_until FROM lingker_login_limits WHERE rate_key = :rate_key'
        );
        $limit_statement->execute([':rate_key' => $rate_key]);
        $limit = $limit_statement->fetch();
        if ($limit && (int) $limit['locked_until'] > time()) {
            respond(429, ['error' => 'Terlalu banyak percobaan masuk. Coba kembali beberapa menit lagi.']);
        }

        $statement = $pdo->prepare(
            'SELECT id, username, password_hash, role FROM lingker_users
             WHERE username_normalized = :username AND active = 1'
        );
        $statement->execute([':username' => $normalized]);
        $account = $statement->fetch();
        $hash = $account
            ? $account['password_hash']
            : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
        $valid = strlen($password) <= 128 && password_verify($password, $hash);
        if (!$account || !$valid) {
            $attempts = $limit ? (int) $limit['failed_attempts'] + 1 : 1;
            $locked_until = $attempts >= 5 ? time() + 900 : 0;
            $save_limit = $pdo->prepare(
                'INSERT INTO lingker_login_limits (rate_key, failed_attempts, locked_until)
                 VALUES (:rate_key, :attempts, :locked_until)
                 ON DUPLICATE KEY UPDATE failed_attempts = VALUES(failed_attempts),
                    locked_until = VALUES(locked_until)'
            );
            $save_limit->execute([
                ':rate_key' => $rate_key,
                ':attempts' => $attempts,
                ':locked_until' => $locked_until,
            ]);
            respond(401, ['error' => 'Username atau password salah.']);
        }
        $delete_limit = $pdo->prepare('DELETE FROM lingker_login_limits WHERE rate_key = :rate_key');
        $delete_limit->execute([':rate_key' => $rate_key]);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $account['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        respond(200, [
            'user' => ['id' => (int) $account['id'], 'username' => $account['username'], 'role' => $account['role']],
            'csrfToken' => $_SESSION['csrf_token'],
        ]);
    }

    if ($action === 'logout') {
        require_csrf();
        $_SESSION = [];
        session_destroy();
        respond(200, ['success' => true]);
    }

    $user = require_login($pdo);
    require_csrf();

    if ($action === 'account-create') {
        if ($user['role'] !== 'admin') {
            respond(403, ['error' => 'Hanya Admin yang dapat membuat akun.']);
        }
        $username = is_string($request['username'] ?? null) ? trim($request['username']) : '';
        $normalized = username_normalize($username);
        $password = validate_password($request['password'] ?? null);
        $statement = $pdo->prepare(
            "INSERT INTO lingker_users (username, username_normalized, password_hash, role)
             VALUES (:username, :normalized, :password_hash, 'paramedic')"
        );
        try {
            $statement->execute([
                ':username' => $username,
                ':normalized' => $normalized,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                respond(409, ['error' => 'Username sudah digunakan.']);
            }
            throw $error;
        }
        respond(201, ['success' => true]);
    }

    if ($action === 'record-save') {
        $record = is_array($request['record'] ?? null) ? validate_record($request['record'], $user['username']) : null;
        if (!$record) {
            respond(422, ['error' => 'Data pengukuran tidak valid.']);
        }
        $statement = $pdo->prepare('SELECT owner_user_id FROM lingker_records WHERE id = :id');
        $statement->execute([':id' => $record['id']]);
        $existing_owner = $statement->fetchColumn();
        if ($existing_owner !== false && $user['role'] !== 'admin') {
            respond(403, ['error' => 'Paramedis hanya dapat menambahkan catatan baru.']);
        }
        $owner_id = $existing_owner === false ? (int) $user['id'] : (int) $existing_owner;
        $save = $pdo->prepare(
            'INSERT INTO lingker_records (id, owner_user_id, record_json)
             VALUES (:id, :owner, :record)
             ON DUPLICATE KEY UPDATE record_json = VALUES(record_json)'
        );
        $save->execute([
            ':id' => $record['id'],
            ':owner' => $owner_id,
            ':record' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        respond(200, ['success' => true, 'record' => $record]);
    }

    if ($action === 'record-delete') {
        if ($user['role'] !== 'admin') {
            respond(403, ['error' => 'Hanya Admin yang dapat menghapus catatan.']);
        }
        $id = is_string($request['id'] ?? null) ? $request['id'] : '';
        $statement = $pdo->prepare('DELETE FROM lingker_records WHERE id = :id');
        $statement->execute([':id' => $id]);
        respond(200, ['success' => true]);
    }

    if ($action === 'records-clear') {
        if ($user['role'] !== 'admin') {
            respond(403, ['error' => 'Hanya Admin yang dapat menghapus semua catatan.']);
        }
        $pdo->exec('DELETE FROM lingker_records');
        respond(200, ['success' => true]);
    }

    if ($action === 'master-save') {
        if ($user['role'] !== 'admin') {
            respond(403, ['error' => 'Hanya Admin yang dapat mengubah master.']);
        }
        $expected_revision = filter_var($request['revision'] ?? null, FILTER_VALIDATE_INT);
        if ($expected_revision === false || $expected_revision < 0) {
            respond(422, ['error' => 'Versi master tidak valid. Muat ulang halaman.']);
        }
        $master = validate_master(is_array($request['data'] ?? null) ? $request['data'] : []);
        $pdo->beginTransaction();
        $revision = $pdo->query('SELECT revision FROM lingker_master WHERE id = 1 FOR UPDATE')->fetchColumn();
        if ($revision === false) {
            $pdo->rollBack();
            respond(500, ['error' => 'Master belum diinisialisasi. Impor master-schema.sql.']);
        }
        if ((int) $revision !== $expected_revision) {
            $pdo->rollBack();
            $current = read_master($pdo);
            respond(409, ['error' => 'Admin lain telah memperbarui master. Data terbaru sudah dimuat.',
                'data' => $current['data'], 'revision' => $current['revision']]);
        }
        $next_revision = (int) $revision + 1;
        $update = $pdo->prepare(
            'UPDATE lingker_master SET data_json = :data, revision = :revision WHERE id = 1'
        );
        $update->execute([
            ':data' => json_encode($master, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':revision' => $next_revision,
        ]);
        $pdo->commit();
        respond(200, ['data' => $master, 'revision' => $next_revision]);
    }

    respond(400, ['error' => 'Aksi tidak dikenali.']);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('E-LINGKER API error: ' . $error->getMessage());
    respond(500, ['error' => 'Kesalahan server. Periksa log error hosting.']);
}
