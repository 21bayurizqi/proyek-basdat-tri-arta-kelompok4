<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app_helpers.php';

ensureKategoriMaterialTable($pdo);

$redirectBase = '/barang/index.php';
if (!empty($_POST['return_to']) && is_string($_POST['return_to'])) {
    $candPath = parse_url($_POST['return_to'], PHP_URL_PATH) ?: '';
    // Arahkan kembali hanya ke halaman yang memiliki banner alert msg/err
    if (in_array($candPath, ['/barang/index.php', '/leads/index.php', '/leads/win.php', '/leads/loss.php', '/transaksi_qr/index.php'], true)) {
        $redirectBase = $candPath;
    }
}

function redirectWithFlash(string $base, string $type, string $message): void
{
    header('Location: ' . $base . '?' . $type . '=' . urlencode($message));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectBase);
    exit;
}

$aksi = $_POST['aksi'] ?? '';

if ($aksi === 'tambah') {
    $namaKategori = trim((string)($_POST['nama_kategori'] ?? ''));

    if ($namaKategori === '') {
        redirectWithFlash($redirectBase, 'err', 'Nama kategori material wajib diisi.');
    }
    if (strlen($namaKategori) > 100) {
        redirectWithFlash($redirectBase, 'err', 'Nama kategori maksimal 100 karakter.');
    }

    try {
        $stmtCheck = $pdo->prepare("SELECT 1 FROM kategori_material WHERE LOWER(TRIM(nama_kategori)) = LOWER(TRIM(:nama)) LIMIT 1");
        $stmtCheck->execute([':nama' => $namaKategori]);
        if ($stmtCheck->fetchColumn()) {
            redirectWithFlash($redirectBase, 'err', "Kategori \"$namaKategori\" sudah terdaftar.");
        }

        // Pilih warna indikator dot otomatis yang unik & kontras terhadap kategori eksisting
        $warnaDot = pickDistinctCategoryColor($pdo);

        $stmt = $pdo->prepare("INSERT INTO kategori_material (nama_kategori, warna_dot) VALUES (:nama, :warna)");
        $stmt->execute([
            ':nama'  => $namaKategori,
            ':warna' => $warnaDot,
        ]);

        redirectWithFlash($redirectBase, 'msg', "Kategori \"$namaKategori\" berhasil ditambahkan ke Material Category.");
    } catch (PDOException $e) {
        redirectWithFlash($redirectBase, 'err', 'Gagal menambahkan kategori: ' . $e->getMessage());
    }
}

if ($aksi === 'hapus') {
    $idKategori = (int)($_POST['id_kategori'] ?? 0);
    if ($idKategori <= 0) {
        redirectWithFlash($redirectBase, 'err', 'ID kategori tidak valid.');
    }

    try {
        $stmtKat = $pdo->prepare("SELECT id_kategori, nama_kategori FROM kategori_material WHERE id_kategori = :id LIMIT 1");
        $stmtKat->execute([':id' => $idKategori]);
        $kat = $stmtKat->fetch();

        if (!$kat) {
            redirectWithFlash($redirectBase, 'err', 'Kategori material tidak ditemukan.');
        }

        $stmtUsed = $pdo->prepare("SELECT COUNT(*) FROM material WHERE kategori = :nama");
        $stmtUsed->execute([':nama' => $kat['nama_kategori']]);
        $usedCount = (int)$stmtUsed->fetchColumn();

        if ($usedCount > 0) {
            redirectWithFlash(
                $redirectBase,
                'err',
                "Kategori \"{$kat['nama_kategori']}\" tidak dapat dihapus karena masih digunakan oleh $usedCount material aktif."
            );
        }

        $stmtDel = $pdo->prepare("DELETE FROM kategori_material WHERE id_kategori = :id");
        $stmtDel->execute([':id' => $idKategori]);

        redirectWithFlash($redirectBase, 'msg', "Kategori \"{$kat['nama_kategori']}\" berhasil dihapus.");
    } catch (PDOException $e) {
        redirectWithFlash($redirectBase, 'err', 'Gagal menghapus kategori: ' . $e->getMessage());
    }
}

header('Location: ' . $redirectBase);
exit;

