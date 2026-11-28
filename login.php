<?php
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/db.php';

if (isLoggedIn()) {
    header('Location: /index.php');
    exit;
}

$error    = '';
$username = trim($_POST['username'] ?? '');
$next     = $_GET['next'] ?? ($_POST['next'] ?? '/index.php');
if (!is_string($next) || !str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, '/login.php')) {
    $next = '/index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT s.id_staf, s.username, s.nama, s.email, s.id_jabatan, s.password_hash,
                       j.nama_jabatan, j.role_akses
                FROM staf s
                JOIN jabatan j ON s.id_jabatan = j.id_jabatan
                WHERE s.username = :username
                LIMIT 1
            ");
            $stmt->execute([':username' => $username]);
            $staf = $stmt->fetch();

            $storedHash = $staf ? (string)($staf['password_hash'] ?? '') : '';
            $isValidPassword = $storedHash !== '' && (
                password_verify($password, $storedHash) ||
                hash_equals($storedHash, $password)
            );

            if ($staf && $isValidPassword) {
                setAuthSession($staf);
                header('Location: ' . $next);
                exit;
            }

            $error = 'Username atau password salah. Silakan coba kembali.';
        } catch (PDOException $e) {
            $error = 'Terjadi kendala saat memverifikasi akun: ' . $e->getMessage();
        }
    }
}

$logoSrc = null;
if (file_exists(__DIR__ . '/assets/logo-tri-arta.png')) {
    $logoSrc = '/assets/logo-tri-arta.png';
} elseif (file_exists(__DIR__ . '/assets/logo-tri-arta.jpg')) {
    $logoSrc = '/assets/logo-tri-arta.jpg';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — PT Tri Arta Konstruksi</title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <link href="/assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/vendor/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --ta-navy: #062A4E;
            --ta-blue: #0D559B;
            --ta-orange: #F38218;
            --ta-green: #135E38;
            --ta-slate: #617275;
            --border-color: #E5E7EB;
            --text-dark: #111827;
            --text-muted: #6B7280;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background:
                radial-gradient(circle at 86% 16%, rgba(243, 130, 24, 0.22) 0%, rgba(243, 130, 24, 0) 36%),
                radial-gradient(circle at 12% 88%, rgba(243, 130, 24, 0.14) 0%, rgba(243, 130, 24, 0) 40%),
                linear-gradient(135deg, #041E38 0%, var(--ta-navy) 42%, var(--ta-blue) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            color: var(--text-dark);
            position: relative;
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px);
            background-size: 36px 36px;
            pointer-events: none;
        }
        .login-wrap {
            width: 100%;
            max-width: 450px;
            position: relative;
            z-index: 1;
        }
        .login-card {
            background: #FFFFFF;
            border-radius: 22px;
            box-shadow: 0 24px 64px rgba(3, 19, 38, 0.42), 0 4px 16px rgba(6, 42, 78, 0.14);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.65);
        }
        .card-accent-bar {
            height: 5px;
            background: linear-gradient(90deg, var(--ta-navy) 0%, var(--ta-blue) 55%, var(--ta-orange) 100%);
        }
        .login-body {
            padding: 44px 40px;
        }
        .brand-logo-wrap {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 32px;
        }
        .brand-logo-img {
            height: 80px;
            width: auto;
            max-width: 260px;
            object-fit: contain;
        }
        .brand-fallback-badge {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--ta-navy), var(--ta-blue));
            color: #FFFFFF;
            font-weight: 800;
            font-size: 1.5rem;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 24px rgba(6, 42, 78, 0.25);
            border-bottom: 3px solid var(--ta-orange);
        }
        .brand-fallback-badge span { color: var(--ta-orange); }
        .field-group-user {
            margin-bottom: 22px;
        }
        .field-label {
            font-size: 0.86rem;
            font-weight: 600;
            color: var(--ta-navy);
            margin-bottom: 8px;
            display: block;
        }
        .input-shell {
            position: relative;
        }
        .input-shell .field-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9CA3AF;
            font-size: 1rem;
            pointer-events: none;
            transition: color 0.15s;
        }
        .modern-input {
            width: 100%;
            padding: 13px 16px;
            padding-left: 44px;
            padding-right: 44px;
            border-radius: 12px;
            border: 1.5px solid var(--border-color);
            background: #F9FAFB;
            font-size: 0.94rem;
            color: var(--text-dark);
            transition: all 0.15s ease;
        }
        .modern-input:focus {
            outline: none;
            background: #FFFFFF;
            border-color: var(--ta-blue);
            box-shadow: 0 0 0 3.5px rgba(13, 85, 155, 0.12);
        }
        .input-shell:focus-within .field-icon {
            color: var(--ta-blue);
        }
        .toggle-pw-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #9CA3AF;
            padding: 5px 7px;
            border-radius: 8px;
            cursor: pointer;
            line-height: 1;
            transition: color 0.15s, background 0.15s;
        }
        .toggle-pw-btn:hover {
            color: var(--ta-navy);
            background: #F3F4F6;
        }
        .btn-login-cta {
            width: 100%;
            margin-top: 30px;
            border: none;
            border-radius: 999px;
            padding: 14px 20px;
            background: var(--ta-navy);
            border-bottom: 3px solid var(--ta-orange);
            color: #FFFFFF;
            font-size: 15.5px;
            font-weight: 600;
            letter-spacing: 0.01em;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            cursor: pointer;
            transition: background 0.18s ease, transform 0.15s ease, box-shadow 0.18s ease;
            box-shadow: 0 8px 20px rgba(6, 42, 78, 0.22);
        }
        .btn-login-cta:hover {
            background: var(--ta-blue);
            transform: translateY(-1px);
            box-shadow: 0 12px 24px rgba(13, 85, 155, 0.28);
        }
        .btn-login-cta:active {
            transform: translateY(0);
        }
        .login-footer {
            text-align: center;
            margin-top: 22px;
            font-size: 0.8rem;
            color: rgba(255, 255, 255, 0.78);
        }
        .login-footer strong { color: #FFFFFF; font-weight: 600; }
        .alert-compact {
            font-size: 0.85rem;
            border-radius: 11px;
            padding: 0.7rem 0.95rem;
            margin-bottom: 22px;
        }
        @media (max-width: 480px) {
            .login-body { padding: 34px 24px; }
        }
    </style>
</head>
<body>
    <div class="login-wrap">
        <div class="login-card">
            <div class="card-accent-bar"></div>
            <div class="login-body">
                <div class="brand-logo-wrap">
                    <?php if ($logoSrc !== null): ?>
                        <img src="<?= htmlspecialchars($logoSrc) ?>" alt="PT Tri Arta Konstruksi" class="brand-logo-img">
                    <?php else: ?>
                        <div class="brand-fallback-badge">T<span>A</span></div>
                    <?php endif; ?>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger alert-compact d-flex align-items-start gap-2" role="alert">
                        <i class="bi bi-exclamation-circle-fill mt-1 flex-shrink-0"></i>
                        <span><?= htmlspecialchars($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" autocomplete="on">
                    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

                    <div class="field-group-user">
                        <label for="username" class="field-label">Username</label>
                        <div class="input-shell">
                            <i class="bi bi-person field-icon"></i>
                            <input
                                type="text"
                                id="username"
                                name="username"
                                class="modern-input"
                                placeholder="Masukkan username"
                                value="<?= htmlspecialchars($username) ?>"
                                autocomplete="username"
                                required
                                autofocus
                            >
                        </div>
                    </div>

                    <div>
                        <label for="password" class="field-label">Password</label>
                        <div class="input-shell">
                            <i class="bi bi-lock field-icon"></i>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="modern-input"
                                placeholder="Masukkan password"
                                autocomplete="current-password"
                                required
                            >
                            <button type="button" class="toggle-pw-btn" id="togglePassword" aria-label="Tampilkan atau sembunyikan password" title="Tampilkan password">
                                <i class="bi bi-eye" id="togglePasswordIcon"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-login-cta">Login</button>
                </form>
            </div>
        </div>

        <footer class="login-footer">
            &copy; 2026 <strong>PT Tri Arta Konstruksi</strong>
        </footer>
    </div>

    <script>
        (function () {
            const pwInput = document.getElementById('password');
            const toggleBtn = document.getElementById('togglePassword');
            const toggleIcon = document.getElementById('togglePasswordIcon');

            if (toggleBtn && pwInput && toggleIcon) {
                toggleBtn.addEventListener('click', function () {
                    const isHidden = pwInput.type === 'password';
                    pwInput.type = isHidden ? 'text' : 'password';
                    toggleIcon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
                    toggleBtn.title = isHidden ? 'Sembunyikan password' : 'Tampilkan password';
                });
            }
        })();
    </script>
</body>
</html>
