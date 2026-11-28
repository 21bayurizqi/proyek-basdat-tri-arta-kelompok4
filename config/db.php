<?php
/**
 * Konfigurasi koneksi PostgreSQL (PDO) untuk Produksi (Railway / Neon.tech) & Lokal.
 * Prioritas:
 *   1. Environment Variable `DATABASE_URL` (PostgreSQL connection string)
 *   2. Environment Variables terpisah (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_SSLMODE`)
 *   3. Fallback konfigurasi lokal MacBook (`localhost` / `db_scm_tri_arta`)
 */

// Helper pembaca env aman (mendukung $_ENV, $_SERVER, getenv, dan file .env lokal bila ada)
$taEnvFromFile = [];
$taEnvPath     = __DIR__ . '/../.env';
if (is_file($taEnvPath) && is_readable($taEnvPath)) {
    foreach (file($taEnvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $trimmed, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k !== '' && strlen($v) >= 2 && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '') {
            $taEnvFromFile[$k] = $v;
        }
    }
}

$getEnvVar = static function (string $key, ?string $default = null) use ($taEnvFromFile): ?string {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
        return (string)$val;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        return (string)$_ENV[$key];
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        return (string)$_SERVER[$key];
    }
    if (isset($taEnvFromFile[$key]) && $taEnvFromFile[$key] !== '') {
        return $taEnvFromFile[$key];
    }
    return $default;
};

// Default fallback lokal (MacBook localhost)
$host           = 'localhost';
$port           = '5432';
$dbname         = 'db_scm_tri_arta';
$user           = 'postgres';
$password       = 'Hendrawansusana21';
$sslmode        = '';
$dsnExtraOption = '';

$databaseUrl = $getEnvVar('DATABASE_URL');
if ($databaseUrl !== null) {
    $parts = parse_url($databaseUrl);
    if ($parts === false || empty($parts['host'])) {
        die('Format DATABASE_URL tidak valid.');
    }
    $host     = (string)$parts['host'];
    $port     = isset($parts['port']) ? (string)$parts['port'] : '5432';
    $dbname   = isset($parts['path']) ? ltrim((string)$parts['path'], '/') : $dbname;
    $user     = isset($parts['user']) ? rawurldecode((string)$parts['user']) : $user;
    $password = isset($parts['pass']) ? rawurldecode((string)$parts['pass']) : '';

    $queryParams = [];
    if (!empty($parts['query'])) {
        parse_str((string)$parts['query'], $queryParams);
    }
    $sslmode = (string)($queryParams['sslmode'] ?? ($getEnvVar('DB_SSLMODE') ?? ''));
    if ($sslmode === '' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $sslmode = 'require';
    }
    if (!empty($queryParams['options'])) {
        $dsnExtraOption = (string)$queryParams['options'];
    }
} elseif ($getEnvVar('DB_HOST') !== null) {
    $host     = (string)$getEnvVar('DB_HOST', 'localhost');
    $port     = (string)$getEnvVar('DB_PORT', '5432');
    $dbname   = (string)$getEnvVar('DB_NAME', 'db_scm_tri_arta');
    $user     = (string)$getEnvVar('DB_USER', 'postgres');
    $password = (string)$getEnvVar('DB_PASS', '');
    $sslmode  = (string)$getEnvVar('DB_SSLMODE', '');
    if ($sslmode === '' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        $sslmode = 'require';
    }
}

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    if ($sslmode !== '') {
        $dsn .= ";sslmode=$sslmode";
    }
    if ($dsnExtraOption !== '') {
        $dsn .= ";options=$dsnExtraOption";
    }

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
} catch (PDOException $e) {
    die('Koneksi PostgreSQL gagal: ' . $e->getMessage());
}
