<?php
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi_approval'], $_POST['id_keluar'])) {
    $idKeluar   = $_POST['id_keluar'];
    $statusBaru = $_POST['aksi_approval'] === 'approve' ? 'Approved' : 'Rejected';
    $validator  = $_POST['id_validator'] ?? 'STF-001';

    $upd = $pdo->prepare("UPDATE barang_keluar SET status_approval = :st, id_staf_validator = :val WHERE id_keluar = :id");
    $upd->execute([':st' => $statusBaru, ':val' => $validator, ':id' => $idKeluar]);
    header('Location: /transaksi_qr/index.php?msg=' . urlencode("Status transaksi $idKeluar diubah menjadi $statusBaru."));
    exit;
}

include __DIR__ . '/../layout/header.php';

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
?>

<header class="saas-topbar">
    <div>
        <h1 class="saas-title">Transaksi Barang Keluar QR</h1>
        <div class="text-muted" style="font-size: 0.8rem;">Pengajuan pengeluaran material proyek &amp; validasi scan QR gudang</div>
    </div>
    <a href="/transaksi_qr/create.php" class="btn-toolbar-orange">
        <i class="bi bi-qr-code-scan"></i> Input Barang Keluar Baru
    </a>
</header>

<main class="p-4">
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
            <?= htmlspecialchars($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="dribbble-list">
        <div class="table-responsive">
            <table class="dribbble-table">
                <thead>
                    <tr style="background: #FAFAFA; border-bottom: 1px solid var(--border-color); font-size: 0.74rem; color: var(--text-muted); text-transform: uppercase;">
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
                                    <input type="hidden" name="id_validator" value="STF-001">
                                    <button type="submit" name="aksi_approval" value="approve" class="btn btn-sm text-white" style="background: var(--ta-green); font-size: 0.76rem;">
                                        <i class="bi bi-check-lg"></i> Approve
                                    </button>
                                    <button type="submit" name="aksi_approval" value="reject" class="btn btn-sm btn-outline-danger" style="font-size: 0.76rem;">
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

<?php include __DIR__ . '/../layout/footer.php'; ?>
