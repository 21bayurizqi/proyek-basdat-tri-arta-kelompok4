<?php
require_once __DIR__ . '/../config/db.php';

$proyekList = $pdo->query("SELECT id_proyek, no_kontrak, nama_proyek FROM proyek ORDER BY id_proyek ASC")->fetchAll();
$stafList   = $pdo->query("
    SELECT s.id_staf, s.nama, j.nama_jabatan
    FROM staf s
    JOIN jabatan j ON s.id_jabatan = j.id_jabatan
    ORDER BY s.id_staf ASC
")->fetchAll();
$qrList     = $pdo->query("
    SELECT k.kode_qr, k.qty_sisa, k.section_gudang, m.id_material, m.nama_material, m.satuan
    FROM kemasan_qr k
    JOIN material m ON k.id_material = m.id_material
    WHERE k.qty_sisa > 0
    ORDER BY k.kode_qr ASC
")->fetchAll();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_keluar  = strtoupper(trim($_POST['id_keluar'] ?? ''));
    $id_proyek  = $_POST['id_proyek'] ?? '';
    $id_pemohon = $_POST['id_staf_pemohon'] ?? '';
    $keperluan  = trim($_POST['tujuan_keperluan'] ?? '');
    $kode_qr    = $_POST['kode_qr'] ?? '';
    $qty_keluar = (int)($_POST['qty_keluar'] ?? 0);

    if ($id_keluar === '' || $id_proyek === '' || $id_pemohon === '' || $kode_qr === '' || $qty_keluar <= 0) {
        $error = 'Semua field wajib diisi dan jumlah keluar harus lebih dari 0.';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Insert header barang_keluar
            $stmtBk = $pdo->prepare("
                INSERT INTO barang_keluar (id_keluar, id_proyek, id_staf_pemohon, tgl_keluar, tujuan_keperluan, status_approval)
                VALUES (:id, :proyek, :pemohon, CURRENT_DATE, :keperluan, 'Pending Approval')
            ");
            $stmtBk->execute([
                ':id'        => $id_keluar,
                ':proyek'    => $id_proyek,
                ':pemohon'   => $id_pemohon,
                ':keperluan' => $keperluan,
            ]);

            // 2. Insert detail_keluar (scan QR)
            $id_detail = 'DTL-' . substr(uniqid(), -5);
            $stmtDk = $pdo->prepare("
                INSERT INTO detail_keluar (id_detail_keluar, id_keluar, kode_qr, qty_keluar)
                VALUES (:idd, :idk, :qr, :qty)
            ");
            $stmtDk->execute([
                ':idd' => $id_detail,
                ':idk' => $id_keluar,
                ':qr'  => $kode_qr,
                ':qty' => $qty_keluar,
            ]);

            // 3. Kurangi qty_sisa di kemasan_qr & total_stok di material
            $updQr = $pdo->prepare("
                UPDATE kemasan_qr
                SET qty_sisa = qty_sisa - :qty,
                    status_kemasan = CASE WHEN (qty_sisa - :qty) <= 0 THEN 'Habis' ELSE 'Sebagian Terpakai' END
                WHERE kode_qr = :qr
            ");
            $updQr->execute([':qty' => $qty_keluar, ':qr' => $kode_qr]);

            $updMat = $pdo->prepare("
                UPDATE material
                SET total_stok = total_stok - :qty
                WHERE id_material = (SELECT id_material FROM kemasan_qr WHERE kode_qr = :qr)
            ");
            $updMat->execute([':qty' => $qty_keluar, ':qr' => $kode_qr]);

            $pdo->commit();
            header('Location: /transaksi_qr/index.php?msg=' . urlencode("Transaksi $id_keluar berhasil dicatat & stok QR diperbarui!"));
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = 'Gagal mencatat transaksi QR: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../layout/header.php';
?>

<header class="top-header">
    <h1 class="page-heading">Input Barang Keluar (Scan QR)</h1>
    <a href="/transaksi_qr/index.php" class="btn-ta-outline"><i class="bi bi-arrow-left"></i> Kembali</a>
</header>

<main class="p-4">
    <div class="list-card p-4" style="max-width: 760px;">
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">ID Transaksi Keluar *</label>
                <input type="text" name="id_keluar" class="form-control" value="OUT-2026-006" required>
            </div>
            <div class="col-md-8">
                <label class="form-label fw-semibold">Proyek Tujuan *</label>
                <select name="id_proyek" class="form-select" required>
                    <option value="">-- Pilih Proyek --</option>
                    <?php foreach ($proyekList as $p): ?>
                        <option value="<?= htmlspecialchars($p['id_proyek']) ?>">
                            <?= htmlspecialchars($p['no_kontrak'] . ' — ' . $p['nama_proyek']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Staf Pemohon *</label>
                <select name="id_staf_pemohon" class="form-select" required>
                    <option value="">-- Pilih Staf --</option>
                    <?php foreach ($stafList as $s): ?>
                        <option value="<?= htmlspecialchars($s['id_staf']) ?>">
                            <?= htmlspecialchars($s['nama'] . ' (' . $s['nama_jabatan'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Tujuan / Keperluan Lapangan *</label>
                <input type="text" name="tujuan_keperluan" class="form-control" placeholder="Contoh: Instalasi zona sprinkler lapangan utama" required>
            </div>
            <div class="col-md-8">
                <label class="form-label fw-semibold">Pilih Kemasan / Kode QR Gudang *</label>
                <select name="kode_qr" class="form-select" required>
                    <option value="">-- Scan / Pilih Kode QR --</option>
                    <?php foreach ($qrList as $q): ?>
                        <option value="<?= htmlspecialchars($q['kode_qr']) ?>">
                            [<?= htmlspecialchars($q['kode_qr']) ?>] <?= htmlspecialchars($q['nama_material']) ?> — Sisa: <?= $q['qty_sisa'] . ' ' . htmlspecialchars($q['satuan']) ?> (<?= htmlspecialchars($q['section_gudang']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Jumlah Diambil *</label>
                <input type="number" name="qty_keluar" class="form-control" min="1" value="1" required>
            </div>
            <div class="col-12 pt-2">
                <button type="submit" class="btn-ta-orange"><i class="bi bi-qr-code-scan"></i> Proses Barang Keluar</button>
            </div>
        </form>
    </div>
</main>

<?php include __DIR__ . '/../layout/footer.php'; ?>
