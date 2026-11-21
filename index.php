<?php
require_once __DIR__ . '/config/db.php';
include __DIR__ . '/layout/header.php';

// Ringkasan angka dashboard
$totalMaterial = $pdo->query("SELECT COUNT(*) FROM material")->fetchColumn();
$totalKemasan  = $pdo->query("SELECT COUNT(*) FROM kemasan_qr WHERE status_kemasan != 'Habis'")->fetchColumn();
$pendingKeluar = $pdo->query("SELECT COUNT(*) FROM barang_keluar WHERE status_approval = 'Pending Approval'")->fetchColumn();

// Query 2 Fase 3: Alert Restock (Stok < Batas Minimum)
$stmtRestock = $pdo->query("
    SELECT id_material, nama_material, kategori, merek, total_stok, batas_minimum, satuan,
           (batas_minimum - total_stok) AS jumlah_harus_restock
    FROM material
    WHERE total_stok < batas_minimum
    ORDER BY jumlah_harus_restock DESC
");
$restockList = $stmtRestock->fetchAll();

// Query 1 Fase 3: Monitoring Stok per Section & Kode QR
$stmtQr = $pdo->query("
    SELECT k.section_gudang, k.kode_qr, m.id_material, m.nama_material, m.merek,
           k.qty_awal, k.qty_sisa, m.satuan, k.status_kemasan
    FROM kemasan_qr k
    JOIN material m ON k.id_material = m.id_material
    ORDER BY k.section_gudang ASC, k.kode_qr ASC
");
$qrList = $stmtQr->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-0">Dashboard Gudang & Inventori QR</h3>
        <p class="text-muted mb-0">Monitoring stok material konstruksi irigasi & olahraga secara real-time</p>
    </div>
    <div>
        <a href="/barang/create.php" class="btn btn-primary me-2"><i class="bi bi-plus-circle me-1"></i>Tambah Material</a>
        <a href="/transaksi_qr/create.php" class="btn btn-warning fw-semibold"><i class="bi bi-qr-code me-1"></i>Input Barang Keluar</a>
    </div>
</div>

<!-- Kartu Ringkasan -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm border-start border-primary border-4">
            <div class="card-body">
                <div class="text-muted small">Total Jenis Material</div>
                <div class="fs-3 fw-bold"><?= $totalMaterial ?> <span class="fs-6 fw-normal text-muted">Item</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm border-start border-success border-4">
            <div class="card-body">
                <div class="text-muted small">Kemasan QR Aktif di Gudang</div>
                <div class="fs-3 fw-bold"><?= $totalKemasan ?> <span class="fs-6 fw-normal text-muted">Kemasan/Batch</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm border-start border-danger border-4">
            <div class="card-body">
                <div class="text-muted small">Pengajuan Keluar Menunggu Approval</div>
                <div class="fs-3 fw-bold"><?= $pendingKeluar ?> <span class="fs-6 fw-normal text-muted">Transaksi</span></div>
            </div>
        </div>
    </div>
</div>

<!-- Alert Restock -->
<?php if (count($restockList) > 0): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-danger text-white fw-semibold">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>Peringatan Restock: Material di Bawah Batas Minimum
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Nama Material</th>
                        <th>Merek</th>
                        <th>Stok Saat Ini</th>
                        <th>Batas Minimum</th>
                        <th>Kekurangan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($restockList as $r): ?>
                    <tr>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($r['id_material']) ?></span></td>
                        <td class="fw-semibold"><?= htmlspecialchars($r['nama_material']) ?></td>
                        <td><?= htmlspecialchars($r['merek']) ?></td>
                        <td class="text-danger fw-bold"><?= $r['total_stok'] . ' ' . htmlspecialchars($r['satuan']) ?></td>
                        <td><?= $r['batas_minimum'] . ' ' . htmlspecialchars($r['satuan']) ?></td>
                        <td><span class="badge bg-danger">Kurang <?= $r['jumlah_harus_restock'] . ' ' . htmlspecialchars($r['satuan']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Monitoring Kemasan QR per Section -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-bold py-3">
        <i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i>Monitoring Stok per Rak / Section & Kode QR
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>Section Gudang</th>
                        <th>Kode QR</th>
                        <th>Material</th>
                        <th>Merek</th>
                        <th>Qty Awal</th>
                        <th>Qty Sisa</th>
                        <th>Status Kemasan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($qrList as $q): ?>
                    <tr>
                        <td><?= htmlspecialchars($q['section_gudang']) ?></td>
                        <td><code><?= htmlspecialchars($q['kode_qr']) ?></code></td>
                        <td class="fw-semibold"><?= htmlspecialchars($q['nama_material']) ?></td>
                        <td><?= htmlspecialchars($q['merek']) ?></td>
                        <td><?= $q['qty_awal'] ?></td>
                        <td class="fw-bold"><?= $q['qty_sisa'] . ' ' . htmlspecialchars($q['satuan']) ?></td>
                        <td>
                            <?php
                            $badge = $q['status_kemasan'] === 'Tersedia' ? 'bg-success' : ($q['status_kemasan'] === 'Sebagian Terpakai' ? 'bg-warning text-dark' : 'bg-secondary');
                            ?>
                            <span class="badge <?= $badge ?>"><?= htmlspecialchars($q['status_kemasan']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/layout/footer.php'; ?>