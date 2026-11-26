<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
require_once __DIR__ . '/../config/db.php';

$tahapanList = ['New Lead', 'Opportunity', 'Site Visit', 'Quotation', 'Win', 'Loss'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aksi_pipeline'], $_POST['id_lead'], $_POST['tahapan_baru'])) {
    $idLead      = (int)$_POST['id_lead'];
    $tahapanBaru = trim($_POST['tahapan_baru']);
    $alasanLoss  = trim($_POST['alasan_loss'] ?? '');

    if ($idLead > 0 && in_array($tahapanBaru, $tahapanList, true)) {
        $upd = $pdo->prepare("
            UPDATE leads
            SET tahapan_pipeline = :tahapan,
                alasan_loss = :alasan
            WHERE id_lead = :id
        ");
        $upd->execute([
            ':tahapan' => $tahapanBaru,
            ':alasan'  => $tahapanBaru === 'Loss' && $alasanLoss !== '' ? $alasanLoss : null,
            ':id'      => $idLead,
        ]);
        header('Location: /leads/index.php?msg=' . urlencode("Tahapan lead #$idLead diperbarui menjadi $tahapanBaru."));
        exit;
    }
}

$filterStage = trim($_GET['tahapan'] ?? '');
$keyword     = trim($_GET['q'] ?? '');

$where  = "WHERE 1=1";
$params = [];
if ($filterStage !== '' && in_array($filterStage, $tahapanList, true)) {
    $where .= " AND l.tahapan_pipeline = :tahapan";
    $params[':tahapan'] = $filterStage;
}
if ($keyword !== '') {
    $where .= " AND (l.nama_klien ILIKE :kw OR l.sumber_informasi ILIKE :kw OR COALESCE(s.nama, '') ILIKE :kw)";
    $params[':kw'] = "%$keyword%";
}

$stmt = $pdo->prepare("
    SELECT l.id_lead, l.nama_klien, l.sumber_informasi, l.tahapan_pipeline,
           l.alasan_loss, l.tanggal_masuk, l.id_staf_pic,
           s.nama AS nama_pic, j.nama_jabatan AS jabatan_pic
    FROM leads l
    LEFT JOIN staf s ON l.id_staf_pic = s.id_staf
    LEFT JOIN jabatan j ON s.id_jabatan = j.id_jabatan
    $where
    ORDER BY l.tanggal_masuk DESC, l.id_lead DESC
");
$stmt->execute($params);
$leadsList = $stmt->fetchAll();

$totalLeads  = (int)$pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();
$aktifLeads  = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE tahapan_pipeline NOT IN ('Win', 'Loss')")->fetchColumn();
$winLeads    = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE tahapan_pipeline = 'Win'")->fetchColumn();
$lossLeads   = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE tahapan_pipeline = 'Loss'")->fetchColumn();
$closedLeads = $winLeads + $lossLeads;
$winRate     = $closedLeads > 0 ? (int)round(($winLeads / $closedLeads) * 100) : 0;

include __DIR__ . '/../layout/header.php';
?>

<header class="saas-topbar">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <div>
            <h1 class="saas-title">CRM Leads &amp; Pipeline Proyek</h1>
            <div class="text-muted" style="font-size: 0.8rem;">Pelacakan prospek klien, tahapan penawaran, dan konversi proyek</div>
        </div>
        <form method="GET" class="top-search">
            <i class="bi bi-search"></i>
            <input type="text" name="q" placeholder="Cari klien, sumber, PIC..." value="<?= htmlspecialchars($keyword) ?>">
            <?php if ($filterStage !== ''): ?><input type="hidden" name="tahapan" value="<?= htmlspecialchars($filterStage) ?>"><?php endif; ?>
        </form>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="dropdown">
            <button class="btn-toolbar-white dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-funnel"></i> <?= $filterStage !== '' ? htmlspecialchars($filterStage) : 'Semua Tahapan' ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="font-size: 0.82rem;">
                <li><a class="dropdown-item" href="/leads/index.php">Semua Tahapan</a></li>
                <?php foreach ($tahapanList as $st): ?>
                    <li><a class="dropdown-item" href="?tahapan=<?= urlencode($st) ?><?= $keyword !== '' ? '&q=' . urlencode($keyword) : '' ?>"><?= htmlspecialchars($st) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <a href="/leads/create.php" class="btn-toolbar-orange">
            <i class="bi bi-plus-lg"></i> Tambah Lead Baru
        </a>
    </div>
</header>

<main class="p-4">
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
            <?= htmlspecialchars($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- KPI Ringkasan Pipeline -->
    <div class="stat-box-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="fw-semibold" style="font-size: 0.92rem; color: var(--ta-navy);">CRM Pipeline Overview</span>
            <span class="badge-pill-soft" style="color: var(--ta-orange); background: #FFF7ED;">&#9679; Sales Funnel</span>
        </div>
        <div class="row g-3">
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Total Leads</div>
                <div class="stat-val"><?= $totalLeads ?> <small>Prospek</small></div>
            </div>
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Leads Aktif</div>
                <div class="stat-val" style="color: var(--ta-blue);"><?= $aktifLeads ?> <small>Berjalan</small></div>
            </div>
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Proyek Win</div>
                <div class="stat-val" style="color: var(--ta-green);"><?= $winLeads ?> <small>Deal</small></div>
            </div>
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Proyek Loss</div>
                <div class="stat-val" style="color: #DC2626;"><?= $lossLeads ?> <small>Gagal</small></div>
            </div>
            <div class="col-12 col-md stat-col-item">
                <div class="stat-lbl">Win Rate</div>
                <div class="stat-val" style="color: var(--ta-orange);"><?= $winRate ?>% <small>(<?= $winLeads ?>/<?= $closedLeads ?> Closed)</small></div>
            </div>
        </div>
    </div>

    <!-- Tabel Daftar Leads -->
    <div class="dribbble-list">
        <div class="table-responsive">
            <table class="dribbble-table">
                <thead>
                    <tr style="background: rgba(223, 234, 246, 0.58); border-bottom: 1px solid rgba(13, 85, 155, 0.14); font-size: 0.74rem; color: var(--text-muted); text-transform: uppercase;">
                        <th class="py-2 px-3">Klien &amp; Tanggal Masuk</th>
                        <th class="py-2 px-3">Sumber Informasi</th>
                        <th class="py-2 px-3">Staf PIC</th>
                        <th class="py-2 px-3">Tahapan Pipeline</th>
                        <th class="py-2 px-3 text-end">Update Tahapan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($leadsList) === 0): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <div class="mb-2" style="font-size: 1.8rem; color: var(--ta-orange);"><i class="bi bi-funnel"></i></div>
                                <div class="col-main-val mb-1" style="color: var(--ta-navy);">Belum ada data lead tercatat</div>
                                <div class="col-micro-lbl mb-3">Mulai tambahkan prospek proyek baru untuk memantau pipeline CRM PT Tri Arta Konstruksi.</div>
                                <a href="/leads/create.php" class="btn-toolbar-orange"><i class="bi bi-plus-lg"></i> Tambah Lead Pertama</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leadsList as $l):
                            $stageStyle = match ($l['tahapan_pipeline']) {
                                'Win'         => 'background: #ECFDF5; color: #135E38;',
                                'Loss'        => 'background: #FEF2F2; color: #DC2626;',
                                'Quotation'   => 'background: #FFF7ED; color: #F38218;',
                                'Site Visit'  => 'background: #EFF6FF; color: #0D559B;',
                                'Opportunity' => 'background: #EEF2FF; color: #062A4E;',
                                default       => 'background: #F1F5F9; color: #475569;',
                            };
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="prod-thumb" style="width: 38px; height: 38px; font-size: 0.95rem;"><i class="bi bi-building"></i></div>
                                    <div>
                                        <div class="col-main-val" style="color: var(--ta-navy);"><?= htmlspecialchars($l['nama_klien']) ?></div>
                                        <div class="col-micro-lbl mb-0">
                                            #<?= (int)$l['id_lead'] ?> &bull; <?= htmlspecialchars(date('d M Y, H:i', strtotime($l['tanggal_masuk']))) ?>
                                        </div>
                                        <?php if (!empty($l['alasan_loss'])): ?>
                                            <div class="col-micro-lbl mb-0 text-danger mt-1">
                                                <i class="bi bi-info-circle me-1"></i>Alasan Loss: <?= htmlspecialchars($l['alasan_loss']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge-pill-soft"><?= htmlspecialchars($l['sumber_informasi'] ?? '-') ?></span>
                            </td>
                            <td>
                                <div class="col-main-val" style="font-size: 0.82rem;"><?= htmlspecialchars($l['nama_pic'] ?? 'Belum ditentukan') ?></div>
                                <?php if (!empty($l['jabatan_pic'])): ?>
                                    <div class="col-micro-lbl mb-0"><?= htmlspecialchars($l['jabatan_pic']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-pill-soft" style="<?= $stageStyle ?>">
                                    <?= htmlspecialchars($l['tahapan_pipeline']) ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <form method="POST" class="d-inline-flex align-items-center gap-1">
                                    <input type="hidden" name="aksi_pipeline" value="1">
                                    <input type="hidden" name="id_lead" value="<?= (int)$l['id_lead'] ?>">
                                    <select name="tahapan_baru" class="form-select form-select-sm" style="width: 130px; font-size: 0.76rem; border-color: var(--border-color);">
                                        <?php foreach ($tahapanList as $st): ?>
                                            <option value="<?= htmlspecialchars($st) ?>" <?= $l['tahapan_pipeline'] === $st ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($st) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn-toolbar-primary" style="padding: 0.28rem 0.65rem; font-size: 0.75rem;">
                                        Simpan
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../layout/footer.php'; ?>

