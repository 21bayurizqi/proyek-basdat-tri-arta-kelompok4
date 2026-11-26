<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
require_once __DIR__ . '/../config/db.php';

$proyekList = $pdo->query("SELECT id_proyek, no_kontrak, nama_proyek, status_proyek FROM proyek ORDER BY id_proyek ASC")->fetchAll();
$stafList   = $pdo->query("
    SELECT s.id_staf, s.nama, j.nama_jabatan
    FROM staf s
    JOIN jabatan j ON s.id_jabatan = j.id_jabatan
    ORDER BY s.id_staf ASC
")->fetchAll();
$qrList     = $pdo->query("
    SELECT k.kode_qr, k.qty_sisa, k.qty_awal, k.section_gudang, m.id_material, m.nama_material, m.satuan
    FROM kemasan_qr k
    JOIN material m ON k.id_material = m.id_material
    WHERE k.qty_sisa > 0
    ORDER BY k.kode_qr ASC
")->fetchAll();

// Generate saran ID transaksi berikutnya berdasarkan data PostgreSQL
$tahunIni = date('Y');
$stmtLastId = $pdo->prepare("
    SELECT id_keluar
    FROM barang_keluar
    WHERE id_keluar LIKE :prefix
    ORDER BY id_keluar DESC
    LIMIT 1
");
$stmtLastId->execute([':prefix' => "OUT-$tahunIni-%"]);
$lastIdKeluar = $stmtLastId->fetchColumn();
$nextNum = 1;
if ($lastIdKeluar && preg_match('/-(\d+)$/', $lastIdKeluar, $m)) {
    $nextNum = ((int)$m[1]) + 1;
}
$suggestedIdKeluar = sprintf('OUT-%s-%03d', $tahunIni, $nextNum);

$error = '';
$form = [
    'id_keluar'        => $suggestedIdKeluar,
    'id_proyek'        => '',
    'id_staf_pemohon'  => '',
    'tujuan_keperluan' => '',
    'kode_qr'          => trim($_GET['kode_qr'] ?? ''),
    'qty_keluar'       => '1',
    'keterangan'       => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_keluar  = strtoupper(trim($_POST['id_keluar'] ?? ''));
    $id_proyek  = trim($_POST['id_proyek'] ?? '');
    $id_pemohon = trim($_POST['id_staf_pemohon'] ?? '');
    $keperluan  = trim($_POST['tujuan_keperluan'] ?? '');
    $kode_qr    = trim($_POST['kode_qr'] ?? '');
    $qty_keluar = (int)($_POST['qty_keluar'] ?? 0);
    $keterangan = trim($_POST['keterangan'] ?? '');

    $form = [
        'id_keluar'        => $id_keluar,
        'id_proyek'        => $id_proyek,
        'id_staf_pemohon'  => $id_pemohon,
        'tujuan_keperluan' => $keperluan,
        'kode_qr'          => $kode_qr,
        'qty_keluar'       => (string)$qty_keluar,
        'keterangan'       => $keterangan,
    ];

    if ($id_keluar === '' || $id_proyek === '' || $id_pemohon === '' || $keperluan === '' || $kode_qr === '' || $qty_keluar <= 0) {
        $error = 'Semua field bertanda * wajib diisi dan jumlah keluar harus lebih dari 0.';
    } elseif (strlen($id_keluar) > 15) {
        $error = 'ID Transaksi Keluar maksimal 15 karakter (sesuai skema PostgreSQL).';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Kunci baris kemasan_qr & material terkait lalu validasi kecukupan stok
            $stmtCheckQr = $pdo->prepare("
                SELECT k.qty_sisa, k.id_material, m.total_stok, m.satuan
                FROM kemasan_qr k
                JOIN material m ON k.id_material = m.id_material
                WHERE k.kode_qr = :qr
                FOR UPDATE
            ");
            $stmtCheckQr->execute([':qr' => $kode_qr]);
            $qrRow = $stmtCheckQr->fetch();

            if (!$qrRow) {
                $pdo->rollBack();
                $error = 'Kode QR kemasan tidak ditemukan di database.';
            } elseif ($qty_keluar > (int)$qrRow['qty_sisa']) {
                $pdo->rollBack();
                $error = 'Jumlah diambil melebihi sisa kemasan QR (' . (int)$qrRow['qty_sisa'] . ' ' . $qrRow['satuan'] . ').';
            } elseif ($qty_keluar > (int)$qrRow['total_stok']) {
                $pdo->rollBack();
                $error = 'Jumlah diambil melebihi total stok material di gudang (' . (int)$qrRow['total_stok'] . ' ' . $qrRow['satuan'] . ').';
            } else {
                // 2. Insert header barang_keluar (id_staf_validator NULL agar sesuai chk_approval_validator)
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

                // 3. Insert detail_keluar (scan QR, panjang maksimal VARCHAR(15))
                $id_detail = 'DTL-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
                $stmtDk = $pdo->prepare("
                    INSERT INTO detail_keluar (id_detail_keluar, id_keluar, kode_qr, qty_keluar, keterangan)
                    VALUES (:idd, :idk, :qr, :qty, :ket)
                ");
                $stmtDk->execute([
                    ':idd' => $id_detail,
                    ':idk' => $id_keluar,
                    ':qr'  => $kode_qr,
                    ':qty' => $qty_keluar,
                    ':ket' => $keterangan !== '' ? $keterangan : null,
                ]);

                // 4. Kurangi qty_sisa di kemasan_qr & total_stok di material
                $sisaBaru      = (int)$qrRow['qty_sisa'] - $qty_keluar;
                $statusKemasan = $sisaBaru <= 0 ? 'Habis' : 'Sebagian Terpakai';

                $updQr = $pdo->prepare("
                    UPDATE kemasan_qr
                    SET qty_sisa = :sisa,
                        status_kemasan = :status
                    WHERE kode_qr = :qr
                ");
                $updQr->execute([
                    ':sisa'   => $sisaBaru,
                    ':status' => $statusKemasan,
                    ':qr'     => $kode_qr,
                ]);

                $updMat = $pdo->prepare("
                    UPDATE material
                    SET total_stok = total_stok - :qty
                    WHERE id_material = :id_mat
                ");
                $updMat->execute([
                    ':qty'    => $qty_keluar,
                    ':id_mat' => $qrRow['id_material'],
                ]);

                $pdo->commit();
                header('Location: /transaksi_qr/index.php?msg=' . urlencode("Transaksi $id_keluar berhasil dicatat & stok QR diperbarui!"));
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Gagal mencatat transaksi QR: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../layout/header.php';
?>

<header class="saas-topbar">
    <div>
        <h1 class="saas-title">Input Barang Keluar (Scan QR)</h1>
        <div class="text-muted" style="font-size: 14px;">Pencatatan pengeluaran material proyek berbasis scan kemasan QR gudang</div>
    </div>
    <a href="/transaksi_qr/index.php" class="btn-toolbar-white">
        <i class="bi bi-arrow-left"></i> Kembali ke Transaksi QR
    </a>
</header>

<main class="p-4">
    <div class="row g-0">
        <div class="col-12">
            <div class="dribbble-list w-100">
                <div class="px-4 py-3 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background: linear-gradient(90deg, rgba(255, 241, 224, 0.82) 0%, rgba(226, 237, 249, 0.72) 100%); border-bottom: 1px solid rgba(243, 130, 24, 0.25);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="prod-thumb" style="width: 42px; height: 42px; font-size: 1.1rem; background: rgba(255, 248, 240, 0.9); color: var(--ta-orange); border-color: rgba(243, 130, 24, 0.35);">
                            <i class="bi bi-qr-code-scan"></i>
                        </div>
                        <div>
                            <div class="col-main-val" style="color: var(--ta-navy);">Form Pengajuan Barang Keluar QR</div>
                            <div class="col-micro-lbl mb-0">Stok kemasan QR &amp; master material akan otomatis terpotong secara transaksional</div>
                        </div>
                    </div>
                    <span class="badge-pill-soft" style="background: rgba(255, 248, 240, 0.92); color: var(--ta-orange); border: 1px solid rgba(243, 130, 24, 0.35);">
                        Pending Approval
                    </span>
                </div>

                <div class="p-4">
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger py-2 mb-3" style="font-size: 14px;">
                            <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                                ID Transaksi Keluar <span style="color: var(--ta-orange);">*</span>
                            </label>
                            <input type="text" name="id_keluar" class="form-control" style="font-size: 14.5px;" maxlength="15" value="<?= htmlspecialchars($form['id_keluar']) ?>" required>
                            <div class="col-micro-lbl mt-1 mb-0">Format: OUT-YYYY-NNN (maks. 15 karakter)</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                                Proyek Tujuan <span style="color: var(--ta-orange);">*</span>
                            </label>
                            <select name="id_proyek" class="form-select" style="font-size: 14.5px;" required>
                                <option value="">-- Pilih Proyek --</option>
                                <?php foreach ($proyekList as $p): ?>
                                    <option value="<?= htmlspecialchars($p['id_proyek']) ?>" <?= $form['id_proyek'] === $p['id_proyek'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['no_kontrak'] . ' — ' . $p['nama_proyek']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                                Staf Pemohon <span style="color: var(--ta-orange);">*</span>
                            </label>
                            <select name="id_staf_pemohon" class="form-select" style="font-size: 14.5px;" required>
                                <option value="">-- Pilih Staf --</option>
                                <?php foreach ($stafList as $s): ?>
                                    <option value="<?= htmlspecialchars($s['id_staf']) ?>" <?= $form['id_staf_pemohon'] === $s['id_staf'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['nama'] . ' (' . $s['nama_jabatan'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                                Tujuan / Keperluan Lapangan <span style="color: var(--ta-orange);">*</span>
                            </label>
                            <input type="text" name="tujuan_keperluan" class="form-control" style="font-size: 14.5px;" placeholder="Contoh: Instalasi zona sprinkler lapangan utama" value="<?= htmlspecialchars($form['tujuan_keperluan']) ?>" required>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                                Pilih Kemasan / Kode QR Gudang <span style="color: var(--ta-orange);">*</span>
                            </label>
                            <select name="kode_qr" class="form-select" style="font-size: 14.5px;" required>
                                <option value="">-- Scan / Pilih Kode QR --</option>
                                <?php foreach ($qrList as $q): ?>
                                    <option value="<?= htmlspecialchars($q['kode_qr']) ?>" <?= $form['kode_qr'] === $q['kode_qr'] ? 'selected' : '' ?>>
                                        [<?= htmlspecialchars($q['kode_qr']) ?>] <?= htmlspecialchars($q['nama_material']) ?> — Sisa: <?= (int)$q['qty_sisa'] . ' ' . htmlspecialchars($q['satuan']) ?> (<?= htmlspecialchars($q['section_gudang']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                                Jumlah Diambil <span style="color: var(--ta-orange);">*</span>
                            </label>
                            <input type="number" name="qty_keluar" class="form-control" style="font-size: 14.5px;" min="1" value="<?= htmlspecialchars($form['qty_keluar']) ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">Catatan Scan (Opsional)</label>
                            <input type="text" name="keterangan" class="form-control" style="font-size: 14.5px;" placeholder="Contoh: Scan keluar dari Section A1 untuk pengiriman tahap 1" value="<?= htmlspecialchars($form['keterangan']) ?>">
                        </div>

                        <div class="col-12 pt-3 mt-1 d-flex align-items-center justify-content-end gap-2" style="border-top: 1px solid rgba(13, 85, 155, 0.14);">
                            <a href="/transaksi_qr/index.php" class="btn-toolbar-white">Batal</a>
                            <button type="submit" class="btn-toolbar-orange">
                                <i class="bi bi-qr-code-scan"></i> Proses Barang Keluar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../layout/footer.php'; ?>

