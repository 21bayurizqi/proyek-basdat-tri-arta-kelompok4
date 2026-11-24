<?php
require_once __DIR__ . '/config/db.php';
include __DIR__ . '/layout/header.php';

$totalMaterial = (int)$pdo->query("SELECT COUNT(*) FROM material")->fetchColumn();
$totalKemasan  = (int)$pdo->query("SELECT COUNT(*) FROM kemasan_qr WHERE status_kemasan != 'Habis'")->fetchColumn();
$pendingKeluar = (int)$pdo->query("SELECT COUNT(*) FROM barang_keluar WHERE status_approval = 'Pending Approval'")->fetchColumn();
$totalProyek   = (int)$pdo->query("SELECT COUNT(*) FROM proyek WHERE status_proyek = 'Berjalan'")->fetchColumn();

$restockList = $pdo->query("
    SELECT id_material, nama_material, kategori, merek, total_stok, batas_minimum, satuan,
           (batas_minimum - total_stok) AS jumlah_harus_restock
    FROM material
    WHERE total_stok < batas_minimum
    ORDER BY jumlah_harus_restock DESC
")->fetchAll();

$qrList = $pdo->query("
    SELECT k.section_gudang, k.kode_qr, m.id_material, m.nama_material, m.kategori, m.merek,
           k.qty_awal, k.qty_sisa, m.satuan, k.status_kemasan
    FROM kemasan_qr k
    JOIN material m ON k.id_material = m.id_material
    ORDER BY k.section_gudang ASC, k.kode_qr ASC
")->fetchAll();

function renderQrArc(int $percent, string $color): string {
    $percent = max(5, min(100, $percent));
    $dashArray = 63;
    $dashOffset = $dashArray - ($dashArray * $percent / 100);
    return <<<SVG
    <div class="d-flex flex-column align-items-center" style="width: 68px;">
        <svg width="56" height="32" viewBox="0 0 56 32">
            <path d="M 8 28 A 20 20 0 0 1 48 28" fill="none" stroke="#E5E7EB" stroke-width="5.5" stroke-linecap="round"/>
            <path d="M 8 28 A 20 20 0 0 1 48 28" fill="none" stroke="{$color}" stroke-width="5.5" stroke-linecap="round"
                  stroke-dasharray="{$dashArray}" stroke-dashoffset="{$dashOffset}"/>
        </svg>
        <span style="font-size: 0.68rem; font-weight: 700; color: {$color}; margin-top: -6px;">{$percent}%</span>
    </div>
SVG;
}
?>

<header class="saas-topbar">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <h1 class="saas-title">Warehouse Analytics & QR Stock</h1>
        <form action="/barang/index.php" method="GET" class="top-search">
            <i class="bi bi-search"></i>
            <input type="text" name="q" placeholder="Search Material or QR...">
        </form>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="/barang/index.php" class="btn-toolbar-white"><i class="bi bi-box-seam"></i> All Material List</a>
        <a href="/transaksi_qr/create.php" class="btn-toolbar-orange"><i class="bi bi-qr-code-scan"></i> Scan Barang Keluar</a>
    </div>
</header>

<main class="p-4">
    <!-- 5-Column Statistic Card -->
    <div class="stat-box-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="fw-semibold" style="font-size: 0.92rem; color: var(--ta-navy);">Warehouse Overview</span>
            <span class="badge-pill-soft" style="color: var(--ta-green); background: #ECFDF5;">&#9679; Live PostgreSQL</span>
        </div>
        <div class="row g-3">
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Total Material</div>
                <div class="stat-val"><?= $totalMaterial ?> <small>Jenis</small></div>
            </div>
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Kemasan QR Aktif</div>
                <div class="stat-val" style="color: var(--ta-blue);"><?= $totalKemasan ?> <small>Batch</small></div>
            </div>
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Stock Condition</div>
                <div class="stat-val" style="color: var(--ta-green);"><i class="bi bi-graph-up-arrow me-1"></i>Optimal</div>
            </div>
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Pending Approval</div>
                <div class="stat-val" style="color: var(--ta-orange);"><?= $pendingKeluar ?> <small>Pengajuan</small></div>
            </div>
            <div class="col-12 col-md stat-col-item">
                <div class="stat-lbl">Active Projects</div>
                <div class="stat-val"><?= $totalProyek ?> <small>Kontrak</small></div>
            </div>
        </div>
    </div>

    <?php if (count($restockList) > 0): ?>
    <div class="dribbble-list mb-4" style="border-color: #FED7AA;">
        <div class="px-3 py-2 d-flex justify-content-between align-items-center" style="background: #FFF7ED; border-bottom: 1px solid #FED7AA;">
            <span class="fw-semibold" style="color: var(--ta-orange); font-size: 0.83rem;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>Peringatan Restock: <?= count($restockList) ?> Material di Bawah Batas Minimum
            </span>
            <a href="/barang/index.php?sort=stok_asc" class="text-decoration-none fw-semibold" style="color: var(--ta-navy); font-size: 0.78rem;">Lihat Semua &rarr;</a>
        </div>
        <div class="table-responsive">
            <table class="dribbble-table">
                <tbody>
                    <?php foreach ($restockList as $r): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-3">
                                <div class="prod-thumb" style="background: #FFF7ED; color: var(--ta-orange); border-color: #FED7AA;"><i class="bi bi-box-seam"></i></div>
                                <div>
                                    <div class="col-main-val"><?= htmlspecialchars($r['nama_material']) ?></div>
                                    <div class="col-micro-lbl mb-0"><?= htmlspecialchars($r['id_material']) ?> &bull; <?= htmlspecialchars($r['merek']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="col-micro-lbl">Current Stock</div>
                            <div class="col-main-val text-danger"><?= $r['total_stok'] . ' ' . htmlspecialchars($r['satuan']) ?></div>
                        </td>
                        <td>
                            <div class="col-micro-lbl">Minimum Limit</div>
                            <div class="col-main-val"><?= $r['batas_minimum'] . ' ' . htmlspecialchars($r['satuan']) ?></div>
                        </td>
                        <td class="text-end">
                            <span class="badge-pill-red">Kurang <?= $r['jumlah_harus_restock'] . ' ' . htmlspecialchars($r['satuan']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div id="rak-qr" class="d-flex justify-content-between align-items-center mb-2">
        <span class="fw-bold" style="color: var(--ta-navy); font-size: 0.95rem;">Monitoring Kemasan QR per Section Gudang</span>
        <span class="text-muted" style="font-size: 0.78rem;"><?= count($qrList) ?> Batch Terdaftar</span>
    </div>

    <div class="dribbble-list">
        <div class="table-responsive">
            <table class="dribbble-table">
                <tbody>
                    <?php foreach ($qrList as $q):
                        $persen = $q['qty_awal'] > 0 ? (int)round(($q['qty_sisa'] / $q['qty_awal']) * 100) : 0;
                        $arcColor = $persen > 60 ? '#135E38' : ($persen > 25 ? '#F38218' : '#DC2626');
                        $labelKondisi = $persen > 60 ? 'Excellent' : ($persen > 25 ? 'Good' : 'Low');
                    ?>
                    <tr>
                        <td style="min-width: 240px;">
                            <div class="d-flex align-items-center gap-3">
                                <div class="prod-thumb"><i class="bi bi-qr-code"></i></div>
                                <div>
                                    <div class="col-main-val" style="color: var(--ta-navy);"><?= htmlspecialchars($q['nama_material']) ?></div>
                                    <div class="col-micro-lbl mb-0">
                                        QR: <strong style="color: var(--ta-blue);"><?= htmlspecialchars($q['kode_qr']) ?></strong>
                                        &bull; <?= htmlspecialchars($q['merek']) ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="col-micro-lbl">Capacity <span class="fw-semibold" style="color: <?= $arcColor ?>;"><?= $labelKondisi ?></span></div>
                            <div style="font-size: 0.78rem; color: var(--text-muted);">
                                <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($q['section_gudang']) ?>
                            </div>
                        </td>
                        <td style="width: 90px;" class="text-center">
                            <?= renderQrArc($persen, $arcColor) ?>
                        </td>
                        <td>
                            <div class="col-micro-lbl">Remaining</div>
                            <div class="col-main-val"><?= $q['qty_sisa'] ?> <span class="fw-normal text-muted">/ <?= $q['qty_awal'] . ' ' . htmlspecialchars($q['satuan']) ?></span></div>
                        </td>
                        <td>
                            <div class="col-micro-lbl">Status</div>
                            <span class="saas-switch <?= $q['qty_sisa'] > 0 ? 'on' : '' ?>"></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/layout/footer.php'; ?>
