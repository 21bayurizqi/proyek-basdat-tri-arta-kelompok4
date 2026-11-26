<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['id_staf']) && !empty($_SESSION['username']);
}

function getAuthUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    return [
        'id_staf'      => $_SESSION['id_staf'],
        'username'     => $_SESSION['username'] ?? '',
        'nama'         => $_SESSION['nama'] ?? '',
        'email'        => $_SESSION['email'] ?? '',
        'id_jabatan'   => $_SESSION['id_jabatan'] ?? '',
        'nama_jabatan' => $_SESSION['nama_jabatan'] ?? '',
        'role_akses'   => $_SESSION['role_akses'] ?? '',
    ];
}

function setAuthSession(array $staf): void
{
    session_regenerate_id(true);
    $_SESSION['id_staf']      = $staf['id_staf'];
    $_SESSION['username']     = $staf['username'];
    $_SESSION['nama']         = $staf['nama'];
    $_SESSION['email']        = $staf['email'] ?? '';
    $_SESSION['id_jabatan']   = $staf['id_jabatan'] ?? '';
    $_SESSION['nama_jabatan'] = $staf['nama_jabatan'];
    $_SESSION['role_akses']   = $staf['role_akses'];
}

function requireAuth(): void
{
    if (!isLoggedIn()) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/index.php';
        $next = (str_starts_with($requestUri, '/') && !str_starts_with($requestUri, '//') && !str_contains($requestUri, '/login.php'))
            ? '?next=' . urlencode($requestUri)
            : '';
        header('Location: /login.php' . $next);
        exit;
    }
}

function clearAuthSession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();
}
