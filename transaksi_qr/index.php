<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi_approval'], $_POST['id_keluar'])) {
    $idKeluar   = $_POST['id_keluar'];
    $statusBaru = $_POST['aksi_approval'] === 'approve' ? 'Approved' : 'Rejected';
    $validator  = $_SESSION['id_staf'] ?? ($_POST['id_validator'] ?? 'STF-001');

    $upd = $pdo->prepare("UPDATE barang_keluar SET status_approval = :st, id_staf_validator = :val WHERE id_keluar = :id");
    $upd->execute([':st' => $statusBaru, ':val' => $validator, ':id' => $idKeluar]);
    header('Location: /transaksi_qr/index.php?msg=' . urlencode("Status transaksi $idKeluar diubah menjadi $statusBaru."));
    exit;
}

include __DIR__ . '/../layout/header.php';

// Katalog Material + Lokasi Rak QR dari PostgreSQL untuk lookup cepat hasil scan
$catalogRows = $pdo->query("
    SELECT m.id_material, m.nama_material, m.kategori, m.merek, m.spesifikasi_teknis,
           m.total_stok, m.batas_minimum, m.satuan, m.gambar,
           (
               SELECT k.section_gudang
               FROM kemasan_qr k
               WHERE k.id_material = m.id_material
               ORDER BY k.qty_sisa DESC, k.tgl_masuk DESC
               LIMIT 1
           ) AS lokasi_rak,
           (
               SELECT k.kode_qr
               FROM kemasan_qr k
               WHERE k.id_material = m.id_material AND k.qty_sisa > 0
               ORDER BY k.qty_sisa DESC, k.tgl_masuk DESC
               LIMIT 1
           ) AS kode_qr_aktif
    FROM material m
    ORDER BY m.id_material ASC
")->fetchAll();

// Peta tambahan berdasarkan kode_qr kemasan agar scan kode QR rak (QR-SEC-...) juga langsung cocok
$qrBatchRows = $pdo->query("
    SELECT k.kode_qr, k.section_gudang, k.qty_sisa, k.status_kemasan,
           m.id_material, m.nama_material, m.kategori, m.merek, m.spesifikasi_teknis,
           m.total_stok, m.batas_minimum, m.satuan, m.gambar
    FROM kemasan_qr k
    JOIN material m ON k.id_material = m.id_material
    ORDER BY k.kode_qr ASC
")->fetchAll();

$resolveMaterialImage = function (?string $rawPath): string {
    $clean = !empty($rawPath) ? ltrim($rawPath, '/') : '';
    if ($clean !== '' && file_exists(__DIR__ . '/../' . $clean)) {
        return '/' . $clean;
    }
    return '';
};

$scanLookup = [];
foreach ($catalogRows as $c) {
    $key = strtoupper($c['id_material']);
    $scanLookup[$key] = [
        'id_material'        => $c['id_material'],
        'nama_material'      => $c['nama_material'],
        'kategori'           => $c['kategori'],
        'merek'              => $c['merek'] ?: 'Standard',
        'spesifikasi_teknis' => $c['spesifikasi_teknis'] ?: 'Material konstruksi terverifikasi gudang',
        'total_stok'         => (int)$c['total_stok'],
        'batas_minimum'      => (int)$c['batas_minimum'],
        'satuan'             => $c['satuan'],
        'lokasi_rak'         => $c['lokasi_rak'] ?: 'Gudang Utama',
        'kode_qr'            => $c['kode_qr_aktif'] ?: '',
        'gambar'             => $resolveMaterialImage($c['gambar'] ?? null),
    ];
}
foreach ($qrBatchRows as $qb) {
    $qrKey = strtoupper($qb['kode_qr']);
    $scanLookup[$qrKey] = [
        'id_material'        => $qb['id_material'],
        'nama_material'      => $qb['nama_material'],
        'kategori'           => $qb['kategori'],
        'merek'              => $qb['merek'] ?: 'Standard',
        'spesifikasi_teknis' => $qb['spesifikasi_teknis'] ?: 'Material konstruksi terverifikasi gudang',
        'total_stok'         => (int)$qb['total_stok'],
        'batas_minimum'      => (int)$qb['batas_minimum'],
        'satuan'             => $qb['satuan'],
        'lokasi_rak'         => $qb['section_gudang'] ?: 'Gudang Utama',
        'kode_qr'            => $qb['kode_qr'],
        'gambar'             => $resolveMaterialImage($qb['gambar'] ?? null),
    ];
}

// Daftar Transaksi Barang Keluar & Approval
$transaksiList = $pdo->query("
    SELECT bk.id_keluar, bk.tgl_keluar, bk.status_approval, bk.tujuan_keperluan,
           p.no_kontrak, p.nama_proyek,
           sp.nama AS nama_pemohon,
           sv.nama AS nama_validator,
           COALESCE(SUM(dk.qty_keluar), 0) AS total_qty,
           COUNT(dk.id_detail_keluar) AS jumlah_item_qr
    FROM barang_keluar bk
    JOIN proyek p ON bk.id_proyek = p.id_proyek
    JOIN staf sp ON bk.id_staf_pemohon = sp.id_staf
    LEFT JOIN staf sv ON bk.id_staf_validator = sv.id_staf
    LEFT JOIN detail_keluar dk ON bk.id_keluar = dk.id_keluar
    GROUP BY bk.id_keluar, bk.tgl_keluar, bk.status_approval, bk.tujuan_keperluan,
             p.no_kontrak, p.nama_proyek, sp.nama, sv.nama
    ORDER BY bk.tgl_keluar DESC, bk.id_keluar DESC
")->fetchAll();

$placeholderLogo = file_exists(__DIR__ . '/../assets/logo-tri-arta.png')
    ? '/assets/logo-tri-arta.png'
    : (file_exists(__DIR__ . '/../assets/logo-tri-arta.jpg') ? '/assets/logo-tri-arta.jpg' : '');
?>

<style>
    /* Area Aksi Scanner QR Modern (Simetris, Tanpa Kotak Putus-Putus) */
    .scanner-stage-card {
        border-radius: 20px;
        padding: 1.55rem 1.7rem;
    }
    .qr-center-stage {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 28px 24px;
        margin-top: 14px;
        border-radius: 18px;
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.65) 0%, rgba(232, 241, 252, 0.55) 100%);
        border: 1.5px solid rgba(13, 85, 155, 0.16);
    }
    .qr-center-icon {
        width: 54px;
        height: 54px;
        border-radius: 15px;
        background: linear-gradient(135deg, rgba(6, 42, 78, 0.1) 0%, rgba(243, 130, 24, 0.14) 100%);
        border: 1px solid rgba(13, 85, 155, 0.18);
        color: var(--ta-navy);
        font-size: 1.65rem;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 12px;
        box-shadow: 0 6px 16px rgba(6, 42, 78, 0.08);
    }
    .qr-action-stack {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 14px;
        width: 100%;
        max-width: 310px;
    }
    .btn-qr-camera-primary {
        width: 100%;
        background: linear-gradient(135deg, #062A4E, #0D559B);
        color: #FFFFFF;
        border: none;
        border-radius: 14px;
        padding: 15px 28px;
        font-size: 15.5px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.6rem;
        cursor: pointer;
        box-shadow: 0 8px 18px rgba(13, 85, 155, 0.22);
        transition: transform 0.16s ease, box-shadow 0.16s ease, filter 0.16s ease;
    }
    .btn-qr-camera-primary:hover {
        color: #FFFFFF;
        transform: translateY(-1px);
        box-shadow: 0 12px 24px rgba(13, 85, 155, 0.28);
        filter: brightness(1.06);
    }
    .btn-qr-file-glass {
        width: 100%;
        border: 1.5px solid #0D559B;
        color: #062A4E;
        background: rgba(255, 255, 255, 0.88);
        backdrop-filter: blur(6px);
        border-radius: 14px;
        padding: 15px 28px;
        font-size: 15.5px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.6rem;
        cursor: pointer;
        transition: all 0.16s ease;
    }
    .btn-qr-file-glass:hover {
        background: rgba(232, 242, 253, 0.96);
        border-color: #062A4E;
        color: #062A4E;
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(6, 42, 78, 0.1);
    }
    .sim-chip-btn {
        border: 1px solid rgba(13, 85, 155, 0.16);
        background: linear-gradient(145deg, rgba(242, 247, 253, 0.88) 0%, rgba(253, 246, 238, 0.88) 100%);
        border-radius: 11px;
        padding: 0.52rem 0.8rem;
        text-align: left;
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.6rem;
        transition: all 0.15s ease;
        cursor: pointer;
    }
    .sim-chip-btn:hover {
        border-color: var(--ta-orange);
        background: linear-gradient(145deg, rgba(235, 244, 253, 0.96) 0%, rgba(254, 240, 224, 0.96) 100%);
        transform: translateY(-1px);
    }

    /* ============================================================
       POP-UP MODAL KAMERA SCANNER (#cameraScannerModal)
       ============================================================ */
    .cam-modal-content {
        border-radius: 22px !important;
        overflow: hidden;
    }
    .cam-viewport-shell {
        width: 100%;
        min-height: 300px;
        border-radius: 16px;
        overflow: hidden;
        background: #07192D;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    #qr-reader-popup video {
        width: 100% !important;
        border-radius: 16px;
        object-fit: cover;
        display: block;
    }
    .cam-overlay-state {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        text-align: center;
        color: #E2E8F0;
        background: rgba(7, 25, 45, 0.86);
        z-index: 4;
    }
    .cam-engine-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--ta-navy);
        background: linear-gradient(90deg, rgba(223, 234, 246, 0.85), rgba(252, 238, 224, 0.85));
        border: 1px solid rgba(13, 85, 155, 0.16);
        border-radius: 999px;
        padding: 6px 14px;
    }

    /* ============================================================
       PREMIUM POP-UP CARD DETAIL MODAL (#scanResultModal)
       ============================================================ */
    .scan-premium-modal {
        border-radius: 26px !important;
        box-shadow: 0 28px 70px rgba(6, 42, 78, 0.25), 0 6px 20px rgba(6, 42, 78, 0.08) !important;
        overflow: hidden;
        position: relative;
    }
    .scan-modal-close {
        position: absolute;
        top: 14px;
        right: 14px;
        z-index: 10;
        width: 34px;
        height: 34px;
        border-radius: 999px;
        border: 1px solid rgba(6, 42, 78, 0.08);
        background: rgba(255, 255, 255, 0.92);
        backdrop-filter: blur(6px);
        color: var(--ta-navy);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.15s ease;
        box-shadow: 0 4px 12px rgba(6, 42, 78, 0.08);
    }
    .scan-modal-close:hover {
        background: var(--ta-navy);
        color: #FFFFFF;
        transform: rotate(90deg);
    }
    .scan-hero-stage {
        height: 235px;
        background:
            radial-gradient(circle at 85% 20%, rgba(243, 130, 24, 0.12) 0%, transparent 45%),
            linear-gradient(155deg, #E8F0FA 0%, #F7E8D8 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1.5rem;
        position: relative;
        overflow: hidden;
    }
    .scan-hero-stage.has-photo {
        padding: 0;
        background: #0F172A;
    }
    .scan-hero-photo {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .scan-hero-visual {
        width: 162px;
        height: 152px;
        border-radius: 24px;
        background: linear-gradient(145deg, rgba(240, 246, 253, 0.9) 0%, rgba(253, 245, 237, 0.9) 100%);
        border: 1px solid rgba(13, 85, 155, 0.14);
        box-shadow: 0 16px 32px rgba(6, 42, 78, 0.1);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 1.15rem;
        position: relative;
    }
    .scan-hero-visual img {
        max-width: 100%;
        max-height: 82px;
        object-fit: contain;
    }
    .scan-hero-verified {
        position: absolute;
        bottom: 14px;
        left: 16px;
        background: rgba(244, 248, 253, 0.94);
        backdrop-filter: blur(6px);
        border: 1px solid rgba(19, 94, 56, 0.18);
        color: var(--ta-green);
        font-size: 12px;
        font-weight: 700;
        padding: 4px 11px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        box-shadow: 0 4px 12px rgba(6, 42, 78, 0.08);
    }
    .scan-detail-body {
        padding: 1.45rem 1.65rem 1.2rem;
    }
    .scan-brand-eyebrow {
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--ta-blue);
        margin-bottom: 0.25rem;
    }
    .scan-product-title {
        font-size: 1.38rem;
        font-weight: 700;
        color: var(--ta-navy);
        line-height: 1.25;
        letter-spacing: -0.02em;
        margin: 0;
    }
    .scan-product-spec {
        font-size: 13.5px;
        color: var(--text-muted);
        margin-top: 0.35rem;
        line-height: 1.45;
    }
    .scan-detail-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem;
        margin-top: 1.15rem;
    }
    .scan-spec-tile {
        background: linear-gradient(145deg, rgba(228, 238, 249, 0.65) 0%, rgba(252, 241, 229, 0.65) 100%);
        border: 1px solid rgba(13, 85, 155, 0.14);
        border-radius: 14px;
        padding: 0.72rem 0.85rem;
    }
    .scan-spec-lbl {
        font-size: 11.5px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #475569;
        margin-bottom: 4px;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .scan-spec-val {
        font-size: 14px;
        font-weight: 700;
        color: var(--ta-navy);
        line-height: 1.3;
        word-break: break-word;
    }
    .scan-action-footer {
        padding: 0.2rem 1.65rem 1.65rem;
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
    }
    .btn-scan-navy {
        width: 100%;
        border: none;
        border-radius: 14px;
        padding: 0.8rem 1.15rem;
        background: var(--ta-navy);
        color: #FFFFFF;
        font-size: 14.5px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        text-decoration: none;
        transition: all 0.16s ease;
        box-shadow: 0 8px 20px rgba(6, 42, 78, 0.18);
    }
    .btn-scan-navy:hover {
        background: var(--ta-blue);
        color: #FFFFFF;
        transform: translateY(-1px);
    }
    .btn-scan-orange {
        width: 100%;
        border: none;
        border-radius: 14px;
        padding: 0.8rem 1.15rem;
        background: var(--ta-orange);
        color: #FFFFFF;
        font-size: 14.5px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        text-decoration: none;
        transition: all 0.16s ease;
        box-shadow: 0 8px 20px rgba(243, 130, 24, 0.22);
    }
    .btn-scan-orange:hover {
        background: #DD700C;
        color: #FFFFFF;
        transform: translateY(-1px);
    }
</style>

<header class="saas-topbar">
    <div>
        <h1 class="saas-title">QR Scanner &amp; Transaksi Gudang</h1>
        <div class="text-muted" style="font-size: 14px;">Pindai label QR material melalui kamera atau gambar untuk cek stok cepat, update gudang, &amp; catat barang keluar</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="/barang/index.php" class="btn-toolbar-white">
            <i class="bi bi-qr-code"></i> Cetak QR Material
        </a>
        <a href="/transaksi_qr/create.php" class="btn-toolbar-orange">
            <i class="bi bi-plus-lg"></i> Input Barang Keluar
        </a>
    </div>
</header>

<main class="p-4">
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2 mb-3" role="alert">
            <?= htmlspecialchars($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- AREA AKSI SCANNER QR BERSIH (SIMETRIS, TANPA KOTAK PUTUS-PUTUS) -->
    <div class="row g-4 mb-4 align-items-stretch">
        <div class="col-lg-7 d-flex">
            <div class="scanner-stage-card w-100 h-100 d-flex flex-column">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-3">
                        <div class="prod-thumb" style="width: 44px; height: 44px; font-size: 1.25rem; color: var(--ta-blue);">
                            <i class="bi bi-qr-code-scan"></i>
                        </div>
                        <div>
                            <h2 class="mb-0 fw-bold" style="font-size: 1.12rem; color: var(--ta-navy);">Tri Arta Smart QR Scanner</h2>
                            <div class="col-micro-lbl mb-0">Identifikasi material instan melalui kamera live atau unggah foto QR</div>
                        </div>
                    </div>
                    <span class="badge-pill-soft" style="background: #ECFDF5; color: var(--ta-green);">
                        &#9679; Siap Memindai
                    </span>
                </div>

                <!-- Kotak Aksi Simetris di Tengah Card -->
                <div class="qr-center-stage">
                    <div class="qr-center-icon">
                        <i class="bi bi-qr-code-scan"></i>
                    </div>
                    <p class="mb-4 fw-medium" style="font-size: 14px; color: var(--text-muted); max-width: 380px;">
                        Pilih metode pemindaian untuk melihat detail stok dan aksi cepat gudang
                    </p>

                    <div class="qr-action-stack">
                        <button type="button" id="btnOpenCameraModal" class="btn-qr-camera-primary">
                            <i class="bi bi-camera-video-fill"></i>
                            <span>Scan Kamera QR</span>
                        </button>

                        <button type="button" id="btnScanImageFile" class="btn-qr-file-glass">
                            <i class="bi bi-image"></i>
                            <span>Scan dari File Gambar</span>
                        </button>
                        <input type="file" id="qr-input-file" accept="image/*" hidden>
                    </div>

                    <!-- Feedback error/proses scan file gambar -->
                    <div id="qrScanFeedback" class="alert py-2 px-3 mt-3 mb-0 d-none w-100" style="max-width: 360px; border-radius: 12px; font-size: 13.5px;"></div>

                    <!-- Container tersembunyi untuk decoding scanFile() -->
                    <div id="qr-file-decoder" style="display: none;"></div>
                </div>
            </div>
        </div>

        <!-- Panel Pendamping: Cek Cepat SKU / Simulasi -->
        <div class="col-lg-5 d-flex">
            <div class="scanner-stage-card w-100 h-100 d-flex flex-column">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div class="prod-thumb" style="width: 40px; height: 40px; font-size: 1.05rem; background: rgba(255, 243, 230, 0.9); color: var(--ta-orange); border-color: rgba(243, 130, 24, 0.32);">
                        <i class="bi bi-lightning-charge-fill"></i>
                    </div>
                    <div>
                        <div class="col-main-val" style="color: var(--ta-navy);">Lookup Cepat &amp; Simulasi QR</div>
                        <div class="col-micro-lbl mb-0">Ketik ID/Kode QR manual atau klik material untuk pratinjau cepat</div>
                    </div>
                </div>

                <form id="manualQrForm" class="d-flex gap-2 mb-3">
                    <input type="text" id="manualQrInput" class="form-control" style="font-size: 14px; border-radius: 10px;" placeholder="Ketik ID (MAT-001) atau Kode QR...">
                    <button type="submit" class="btn-toolbar-primary flex-shrink-0" style="border-radius: 10px;">
                        <i class="bi bi-search"></i> Cek
                    </button>
                </form>

                <div class="d-flex flex-column gap-2 flex-grow-1">
                    <?php foreach (array_slice($catalogRows, 0, 4) as $item):
                        $isLow = (int)$item['total_stok'] < (int)$item['batas_minimum'];
                        $simImg = $resolveMaterialImage($item['gambar'] ?? null);
                    ?>
                        <button type="button" class="sim-chip-btn" data-sim-qr="<?= htmlspecialchars($item['id_material']) ?>">
                            <div class="d-flex align-items-center gap-2 min-w-0">
                                <?php if ($simImg !== ''): ?>
                                    <img src="<?= htmlspecialchars($simImg) ?>" alt="<?= htmlspecialchars($item['nama_material']) ?>" style="width: 38px; height: 38px; object-fit: cover; border-radius: 8px; border: 1px solid #D8E2EC; flex-shrink: 0;">
                                <?php endif; ?>
                                <div class="min-w-0">
                                    <div class="col-main-val text-truncate" style="font-size: 14px; color: var(--ta-navy);">
                                        <?= htmlspecialchars($item['nama_material']) ?>
                                    </div>
                                    <div class="col-micro-lbl mb-0">
                                        <strong style="color: var(--ta-blue);"><?= htmlspecialchars($item['id_material']) ?></strong>
                                        &bull; <?= htmlspecialchars($item['lokasi_rak'] ?: 'Gudang Utama') ?>
                                    </div>
                                </div>
                            </div>
                            <span class="badge-pill-soft flex-shrink-0" style="<?= $isLow ? 'background: #FFF7ED; color: var(--ta-orange);' : 'background: #ECFDF5; color: var(--ta-green);' ?>">
                                <?= (int)$item['total_stok'] . ' ' . htmlspecialchars($item['satuan']) ?>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Riwayat & Validasi Approval Transaksi Barang Keluar -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="fw-bold" style="color: var(--ta-navy); font-size: 1rem;">Riwayat Transaksi Barang Keluar &amp; Validasi Approval</span>
        <span class="text-muted" style="font-size: 13.5px;"><?= count($transaksiList) ?> Transaksi</span>
    </div>

    <div class="dribbble-list">
        <div class="table-responsive">
            <table class="dribbble-table">
                <thead>
                    <tr style="background: rgba(223, 234, 246, 0.58); border-bottom: 1px solid rgba(13, 85, 155, 0.14); color: var(--text-muted); text-transform: uppercase;">
                        <th class="py-2 px-3">ID &amp; Tanggal</th>
                        <th class="py-2 px-3">Proyek Tujuan</th>
                        <th class="py-2 px-3">Pemohon &amp; Keperluan</th>
                        <th class="py-2 px-3">Total Scan QR</th>
                        <th class="py-2 px-3">Status Approval</th>
                        <th class="py-2 px-3 text-end">Validasi Gudang</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transaksiList as $t):
                        $isApproved = $t['status_approval'] === 'Approved';
                        $isPending  = $t['status_approval'] === 'Pending Approval';
                        $badgeStyle = $isApproved
                            ? 'background: #ECFDF5; color: #135E38;'
                            : ($isPending ? 'background: #FFF7ED; color: #F38218;' : 'background: #FEF2F2; color: #DC2626;');
                    ?>
                    <tr>
                        <td>
                            <div class="col-main-val" style="color: var(--ta-navy);"><?= htmlspecialchars($t['id_keluar']) ?></div>
                            <div class="col-micro-lbl mb-0"><?= htmlspecialchars($t['tgl_keluar']) ?></div>
                        </td>
                        <td>
                            <div class="col-main-val"><?= htmlspecialchars($t['nama_proyek']) ?></div>
                            <div class="col-micro-lbl mb-0"><?= htmlspecialchars($t['no_kontrak']) ?></div>
                        </td>
                        <td>
                            <div class="col-main-val"><?= htmlspecialchars($t['nama_pemohon']) ?></div>
                            <div class="col-micro-lbl mb-0"><?= htmlspecialchars($t['tujuan_keperluan']) ?></div>
                        </td>
                        <td>
                            <span class="badge-pill-soft" style="background: #EFF6FF; color: var(--ta-blue);">
                                <?= $t['jumlah_item_qr'] ?> QR (<?= $t['total_qty'] ?> unit)
                            </span>
                        </td>
                        <td>
                            <span class="badge-pill-soft" style="<?= $badgeStyle ?>">
                                <?= htmlspecialchars($t['status_approval']) ?>
                            </span>
                            <?php if ($t['nama_validator']): ?>
                                <div class="col-micro-lbl mt-1 mb-0">Oleh: <?= htmlspecialchars($t['nama_validator']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($isPending): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="id_keluar" value="<?= htmlspecialchars($t['id_keluar']) ?>">
                                    <input type="hidden" name="id_validator" value="<?= htmlspecialchars($_SESSION['id_staf'] ?? 'STF-001') ?>">
                                    <button type="submit" name="aksi_approval" value="approve" class="btn btn-sm text-white" style="background: var(--ta-green); font-size: 13px;">
                                        <i class="bi bi-check-lg"></i> Approve
                                    </button>
                                    <button type="submit" name="aksi_approval" value="reject" class="btn btn-sm btn-outline-danger" style="font-size: 13px;">
                                        Reject
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="col-micro-lbl mb-0">Tervalidasi</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- POP-UP MODAL KAMERA SCANNER LIVE (#cameraScannerModal) -->
<div class="modal fade" id="cameraScannerModal" tabindex="-1" aria-labelledby="cameraScannerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;">
        <div class="modal-content rounded-4 cam-modal-content">
            <div class="px-4 pt-4 pb-2 d-flex align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="prod-thumb" style="width: 38px; height: 38px; font-size: 1.1rem; color: var(--ta-blue);">
                        <i class="bi bi-camera-video-fill"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold" id="cameraScannerModalLabel" style="color: var(--ta-navy); font-size: 1.08rem;">Scan Kamera QR</h5>
                        <div id="camModalSubStatus" class="col-micro-lbl mb-0">Mengaktifkan kamera perangkat...</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            <div class="modal-body px-4 pt-2 pb-4">
                <!-- Dropdown Pemilih Kamera (tampil otomatis bila device punya > 1 kamera) -->
                <div id="camSelectWrap" class="mb-3 d-none">
                    <label for="camDeviceSelect" class="form-label fw-semibold mb-1" style="font-size: 13px; color: var(--ta-navy);">
                        <i class="bi bi-camera me-1"></i>Pilih Kamera
                    </label>
                    <select id="camDeviceSelect" class="form-select form-select-sm" style="font-size: 13.5px; border-radius: 10px;"></select>
                </div>

                <!-- Viewfinder Kamera Live -->
                <div class="cam-viewport-shell mb-3">
                    <div id="qr-reader-popup" style="width: 100%; border-radius: 16px; overflow: hidden;"></div>
                    <div id="camOverlayState" class="cam-overlay-state">
                        <div class="spinner-border text-light mb-2" role="status" style="width: 2rem; height: 2rem;"></div>
                        <div class="fw-semibold" style="font-size: 14px;">Meminta izin kamera...</div>
                        <div style="font-size: 12.5px; opacity: 0.8;">Pastikan browser diizinkan mengakses kamera perangkat Anda</div>
                    </div>
                </div>

                <!-- Badge Status Branded (Menggantikan Powered by ScanApp) -->
                <div class="text-center">
                    <span class="cam-engine-badge">
                        <i class="bi bi-shield-check" style="color: var(--ta-orange);"></i>
                        <span>Tri Arta Smart QR Engine &bull; Arahkan kamera ke stiker QR material</span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- POP-UP CARD DETAIL MATERIAL PREMIUM (#scanResultModal) -->
<div class="modal fade" id="scanResultModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content scan-premium-modal">
            <button type="button" class="scan-modal-close" data-bs-dismiss="modal" aria-label="Tutup">
                <i class="bi bi-x-lg" style="font-size: 0.85rem;"></i>
            </button>

            <!-- KELAS ATAS: Gambar/Visual Material Dominan -->
            <div class="scan-hero-stage" id="scanHeroStage">
                <img id="scanHeroPhoto" class="scan-hero-photo d-none" src="" alt="Foto Material">
                <div class="scan-hero-visual" id="scanHeroFallback">
                    <?php if ($placeholderLogo !== ''): ?>
                        <img src="<?= htmlspecialchars($placeholderLogo) ?>" alt="Material PT Tri Arta Konstruksi">
                    <?php else: ?>
                        <i class="bi bi-box-seam" style="font-size: 3.4rem; color: var(--ta-navy);"></i>
                    <?php endif; ?>
                    <span class="badge-pill-soft mt-2" id="scanHeroTag" style="background: #EFF6FF; color: var(--ta-blue); font-size: 11.5px;">
                        QR MATERIAL
                    </span>
                </div>
                <span class="scan-hero-verified" id="scanVerifiedBadge">
                    <i class="bi bi-patch-check-fill"></i> Terverifikasi Gudang
                </span>
            </div>

            <!-- KELAS TENGAH: Nama Besar + Grid 2 Kolom Detail -->
            <div class="scan-detail-body">
                <div class="scan-brand-eyebrow" id="scanBrandEyebrow">Merek Material</div>
                <h3 class="scan-product-title" id="scanMaterialName">Sprinkler Rotor</h3>
                <div class="scan-product-spec" id="scanMaterialSpec">Spesifikasi teknis material konstruksi</div>

                <div class="scan-detail-grid">
                    <div class="scan-spec-tile">
                        <div class="scan-spec-lbl"><i class="bi bi-upc-scan"></i> SKU / ID</div>
                        <div class="scan-spec-val" id="scanSku">MAT-001</div>
                    </div>
                    <div class="scan-spec-tile">
                        <div class="scan-spec-lbl"><i class="bi bi-tag"></i> Kategori</div>
                        <div class="scan-spec-val" id="scanCategory">Irigasi</div>
                    </div>
                    <div class="scan-spec-tile">
                        <div class="scan-spec-lbl"><i class="bi bi-boxes"></i> Total Stok</div>
                        <div class="scan-spec-val d-flex align-items-center gap-1 flex-wrap" id="scanStockWrap">
                            <span id="scanStockVal">0 Unit</span>
                            <span id="scanStockBadge" class="badge-pill-soft">Aman</span>
                        </div>
                    </div>
                    <div class="scan-spec-tile">
                        <div class="scan-spec-lbl"><i class="bi bi-geo-alt"></i> Lokasi Rak</div>
                        <div class="scan-spec-val" id="scanRack">Section A1</div>
                    </div>
                </div>
            </div>

            <!-- KELAS BAWAH: Full-Width Action Buttons -->
            <div class="scan-action-footer">
                <a href="/barang/index.php" id="scanBtnUpdate" class="btn-scan-navy">
                    <i class="bi bi-pencil-square"></i> Update Stok Gudang
                </a>
                <a href="/transaksi_qr/create.php" id="scanBtnOut" class="btn-scan-orange">
                    <i class="bi bi-box-arrow-up-right"></i> Catat Keluar
                </a>
            </div>
        </div>
    </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', function () {
    const scanCatalog   = <?= json_encode($scanLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const resultModalEl = document.getElementById('scanResultModal');
    const camModalEl    = document.getElementById('cameraScannerModal');
    const btnOpenCam    = document.getElementById('btnOpenCameraModal');
    const btnScanFile   = document.getElementById('btnScanImageFile');
    const fileInput     = document.getElementById('qr-input-file');
    const feedbackBox   = document.getElementById('qrScanFeedback');
    const camOverlay    = document.getElementById('camOverlayState');
    const camSubStatus  = document.getElementById('camModalSubStatus');
    const camSelectWrap = document.getElementById('camSelectWrap');
    const camSelect     = document.getElementById('camDeviceSelect');

    if (!resultModalEl || !camModalEl || typeof bootstrap === 'undefined') return;

    const resultModal = new bootstrap.Modal(resultModalEl);
    const cameraModal = new bootstrap.Modal(camModalEl);

    let html5QrCode    = null;
    let isStopping     = false;
    let scanLock       = false;
    let activeCameraId = null;

    function showFeedback(type, message) {
        if (!feedbackBox) return;
        feedbackBox.className = 'alert py-2 px-3 mt-3 mb-0 alert-' + type;
        feedbackBox.innerHTML = message;
        feedbackBox.classList.remove('d-none');
    }

    function hideFeedback() {
        if (feedbackBox) feedbackBox.classList.add('d-none');
    }

    function openDetailModal(rawCode) {
        const cleanCode = String(rawCode || '').trim();
        if (!cleanCode) return;

        const item = scanCatalog[cleanCode.toUpperCase()] || null;

        const elHeroStage = document.getElementById('scanHeroStage');
        const elHeroPhoto = document.getElementById('scanHeroPhoto');
        const elHeroFallb = document.getElementById('scanHeroFallback');
        const elEyebrow   = document.getElementById('scanBrandEyebrow');
        const elName      = document.getElementById('scanMaterialName');
        const elSpec      = document.getElementById('scanMaterialSpec');
        const elSku       = document.getElementById('scanSku');
        const elCat       = document.getElementById('scanCategory');
        const elStockVal  = document.getElementById('scanStockVal');
        const elStockBdg  = document.getElementById('scanStockBadge');
        const elRack      = document.getElementById('scanRack');
        const elHeroTag   = document.getElementById('scanHeroTag');
        const elVerified  = document.getElementById('scanVerifiedBadge');
        const btnUpdate   = document.getElementById('scanBtnUpdate');
        const btnOut      = document.getElementById('scanBtnOut');

        if (item) {
            if (item.gambar && elHeroPhoto && elHeroFallb && elHeroStage) {
                elHeroPhoto.src = item.gambar;
                elHeroPhoto.alt = item.nama_material;
                elHeroPhoto.classList.remove('d-none');
                elHeroFallb.classList.add('d-none');
                elHeroStage.classList.add('has-photo');
            } else if (elHeroPhoto && elHeroFallb && elHeroStage) {
                elHeroPhoto.src = '';
                elHeroPhoto.classList.add('d-none');
                elHeroFallb.classList.remove('d-none');
                elHeroStage.classList.remove('has-photo');
            }

            elEyebrow.textContent  = item.merek;
            elName.textContent     = item.nama_material;
            elSpec.textContent     = item.spesifikasi_teknis;
            elSku.textContent      = item.id_material;
            elCat.textContent      = item.kategori;
            elStockVal.textContent = `${item.total_stok} ${item.satuan}`;
            elRack.textContent     = item.lokasi_rak;
            elHeroTag.textContent  = item.kode_qr ? item.kode_qr : item.id_material;

            elVerified.innerHTML = '<i class="bi bi-patch-check-fill"></i> Terverifikasi Gudang';
            elVerified.style.color = '#135E38';

            if (item.total_stok <= 0) {
                elStockBdg.textContent = 'Habis';
                elStockBdg.style.background = '#FEF2F2';
                elStockBdg.style.color = '#DC2626';
            } else if (item.total_stok < item.batas_minimum) {
                elStockBdg.textContent = 'Low Stock';
                elStockBdg.style.background = '#FFF7ED';
                elStockBdg.style.color = '#F38218';
            } else {
                elStockBdg.textContent = 'Stok Aman';
                elStockBdg.style.background = '#ECFDF5';
                elStockBdg.style.color = '#135E38';
            }

            btnUpdate.href = '/barang/edit.php?id=' + encodeURIComponent(item.id_material);
            btnOut.href    = item.kode_qr
                ? '/transaksi_qr/create.php?kode_qr=' + encodeURIComponent(item.kode_qr)
                : '/transaksi_qr/create.php';
        } else {
            if (elHeroPhoto && elHeroFallb && elHeroStage) {
                elHeroPhoto.src = '';
                elHeroPhoto.classList.add('d-none');
                elHeroFallb.classList.remove('d-none');
                elHeroStage.classList.remove('has-photo');
            }

            elEyebrow.textContent  = 'Hasil Pemindaian QR';
            elName.textContent     = cleanCode;
            elSpec.textContent     = 'Kode belum terdaftar di master material atau kemasan QR gudang.';
            elSku.textContent      = cleanCode;
            elCat.textContent      = 'Tidak Terdaftar';
            elStockVal.textContent = '0 Unit';
            elRack.textContent     = '-';
            elHeroTag.textContent  = 'QR UNKNOWN';

            elVerified.innerHTML = '<i class="bi bi-exclamation-circle-fill"></i> Kode Belum Terdaftar';
            elVerified.style.color = '#F38218';

            elStockBdg.textContent = 'Cek Master';
            elStockBdg.style.background = '#F1F5F9';
            elStockBdg.style.color = '#475569';

            btnUpdate.href = '/barang/create.php';
            btnOut.href    = '/transaksi_qr/create.php';
        }

        resultModal.show();
    }

    async function stopCameraStream() {
        if (!html5QrCode || isStopping) return;
        isStopping = true;
        try {
            if (html5QrCode.isScanning) {
                await html5QrCode.stop();
            }
            html5QrCode.clear();
        } catch (err) {
            // Abaikan error bila kamera belum sempat aktif
        } finally {
            isStopping = false;
        }
    }

    function setCamOverlayLoading(msg) {
        if (!camOverlay) return;
        camOverlay.classList.remove('d-none');
        camOverlay.innerHTML = `
            <div class="spinner-border text-light mb-2" role="status" style="width: 2rem; height: 2rem;"></div>
            <div class="fw-semibold" style="font-size: 14px;">${msg}</div>
            <div style="font-size: 12.5px; opacity: 0.8;">Pastikan browser diizinkan mengakses kamera perangkat Anda</div>
        `;
    }

    function setCamOverlayError(msg) {
        if (!camOverlay) return;
        camOverlay.classList.remove('d-none');
        camOverlay.innerHTML = `
            <i class="bi bi-camera-video-off mb-2" style="font-size: 2rem; color: #F38218;"></i>
            <div class="fw-semibold mb-1" style="font-size: 14.5px;">Kamera Tidak Dapat Diakses</div>
            <div style="font-size: 12.5px; opacity: 0.85; max-width: 320px;">${msg}</div>
            <button type="button" id="btnFallbackFileFromModal" class="btn btn-sm mt-3 text-white fw-semibold px-3 rounded-pill" style="background: var(--ta-orange);">
                <i class="bi bi-image me-1"></i> Gunakan Scan dari File Gambar
            </button>
        `;
        const fallbackBtn = document.getElementById('btnFallbackFileFromModal');
        if (fallbackBtn) {
            fallbackBtn.addEventListener('click', function () {
                cameraModal.hide();
                if (fileInput) fileInput.click();
            });
        }
    }

    async function startCameraWithConfig(cameraIdOrConfig) {
        if (typeof Html5Qrcode === 'undefined') {
            setCamOverlayError('Library Html5Qrcode belum termuat. Periksa koneksi internet Anda.');
            return;
        }

        await stopCameraStream();
        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode('qr-reader-popup');
        }

        scanLock = false;
        setCamOverlayLoading('Menghubungkan ke kamera...');
        if (camSubStatus) camSubStatus.textContent = 'Meminta akses kamera...';

        const qrConfig = {
            fps: 10,
            qrbox: { width: 240, height: 240 },
            aspectRatio: 1.0
        };

        try {
            await html5QrCode.start(
                cameraIdOrConfig,
                qrConfig,
                async function onScanSuccess(decodedText) {
                    if (scanLock) return;
                    scanLock = true;
                    await stopCameraStream();
                    cameraModal.hide();
                    openDetailModal(decodedText);
                },
                function onScanFailure() {
                    // Abaikan frame tanpa QR
                }
            );
            if (camOverlay) camOverlay.classList.add('d-none');
            if (camSubStatus) camSubStatus.textContent = 'Kamera aktif — arahkan ke kode QR';
        } catch (err) {
            if (camSubStatus) camSubStatus.textContent = 'Gagal mengaktifkan kamera';
            setCamOverlayError('Izin kamera ditolak atau tidak ada kamera terdeteksi pada perangkat ini.');
        }
    }

    async function initCameraModal() {
        if (typeof Html5Qrcode === 'undefined') {
            setCamOverlayError('Library Html5Qrcode belum termuat.');
            return;
        }

        setCamOverlayLoading('Mendeteksi kamera perangkat...');
        try {
            const cameras = await Html5Qrcode.getCameras();
            if (cameras && cameras.length > 1 && camSelect && camSelectWrap) {
                camSelect.innerHTML = '';
                let preferredId = cameras[0].id;
                cameras.forEach(function (cam, idx) {
                    const opt = document.createElement('option');
                    opt.value = cam.id;
                    opt.textContent = cam.label || ('Kamera ' + (idx + 1));
                    if (/back|rear|environment/i.test(cam.label || '')) {
                        preferredId = cam.id;
                    }
                    camSelect.appendChild(opt);
                });
                activeCameraId = preferredId;
                camSelect.value = preferredId;
                camSelectWrap.classList.remove('d-none');
                await startCameraWithConfig(activeCameraId);
            } else {
                if (camSelectWrap) camSelectWrap.classList.add('d-none');
                await startCameraWithConfig({ facingMode: 'environment' });
            }
        } catch (err) {
            if (camSelectWrap) camSelectWrap.classList.add('d-none');
            await startCameraWithConfig({ facingMode: 'environment' });
        }
    }

    // 1. Tombol Utama "Scan Kamera QR" -> Buka Modal & Start Kamera
    if (btnOpenCam) {
        btnOpenCam.addEventListener('click', function () {
            hideFeedback();
            cameraModal.show();
        });
    }

    camModalEl.addEventListener('shown.bs.modal', function () {
        initCameraModal();
    });

    // Pastikan stream kamera langsung mati total saat modal ditutup
    camModalEl.addEventListener('hidden.bs.modal', function () {
        stopCameraStream();
        if (camSubStatus) camSubStatus.textContent = 'Kamera dinonaktifkan';
    });

    if (camSelect) {
        camSelect.addEventListener('change', function () {
            activeCameraId = camSelect.value;
            startCameraWithConfig(activeCameraId);
        });
    }

    // 2. Tombol Sekunder "Scan dari File Gambar" -> Trigger file input & scanFile()
    if (btnScanFile && fileInput) {
        btnScanFile.addEventListener('click', function () {
            hideFeedback();
            fileInput.click();
        });

        fileInput.addEventListener('change', async function () {
            const file = fileInput.files && fileInput.files[0];
            if (!file) return;

            if (typeof Html5Qrcode === 'undefined') {
                showFeedback('danger', '<i class="bi bi-exclamation-circle me-1"></i>Library QR belum termuat.');
                fileInput.value = '';
                return;
            }

            showFeedback('info', '<i class="bi bi-hourglass-split me-1"></i>Membaca kode QR dari gambar...');
            const fileQrDecoder = new Html5Qrcode('qr-file-decoder');
            try {
                const decodedText = await fileQrDecoder.scanFile(file, true);
                hideFeedback();
                if (camModalEl.classList.contains('show')) {
                    await stopCameraStream();
                    cameraModal.hide();
                }
                openDetailModal(decodedText);
            } catch (err) {
                showFeedback('warning', '<i class="bi bi-exclamation-triangle me-1"></i>Kode QR tidak ditemukan pada gambar tersebut. Pastikan gambar QR terlihat jelas dan tidak terpotong.');
            } finally {
                try { fileQrDecoder.clear(); } catch (e) {}
                fileInput.value = '';
            }
        });
    }

    // 3. Handler Simulasi & Lookup Manual
    document.querySelectorAll('[data-sim-qr]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            hideFeedback();
            openDetailModal(btn.getAttribute('data-sim-qr'));
        });
    });

    const manualForm  = document.getElementById('manualQrForm');
    const manualInput = document.getElementById('manualQrInput');
    if (manualForm && manualInput) {
        manualForm.addEventListener('submit', function (e) {
            e.preventDefault();
            hideFeedback();
            if (manualInput.value.trim() !== '') {
                openDetailModal(manualInput.value.trim());
            }
        });
    }
});
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
