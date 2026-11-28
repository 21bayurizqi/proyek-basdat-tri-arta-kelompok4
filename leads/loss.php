<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app_helpers.php';

ensureLeadsExtraColumns($pdo);

$lossIndicators = getLeadLossIndicators();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Edit Indikator / Alasan Evaluasi Loss
    if (isset($_POST['aksi_edit_outcome'], $_POST['id_lead'])) {
        $idLead           = (int)$_POST['id_lead'];
        $indikatorPilihan = trim((string)($_POST['indikator_pilihan'] ?? ''));
        $indikatorKustom  = trim((string)($_POST['indikator_kustom'] ?? ''));
        $alasanHasil      = trim((string)($_POST['alasan_hasil'] ?? ''));
        $indikatorFinal   = $indikatorPilihan === 'Lainnya' ? $indikatorKustom : $indikatorPilihan;

        if ($idLead <= 0 || $indikatorFinal === '' || $alasanHasil === '') {
            header('Location: /leads/loss.php?err=' . urlencode('Indikator kekalahan dan alasan evaluasi wajib diisi.'));
            exit;
        }

        try {
            $upd = $pdo->prepare("
                UPDATE leads
                SET indikator_hasil = :indikator,
                    alasan_hasil    = :alasan,
                    alasan_loss     = :alasan
                WHERE id_lead = :id AND tahapan_pipeline = 'Loss'
            ");
            $upd->execute([
                ':indikator' => substr($indikatorFinal, 0, 120),
                ':alasan'    => $alasanHasil,
                ':id'        => $idLead,
            ]);
            header('Location: /leads/loss.php?msg=' . urlencode("Evaluasi Proyek Loss #$idLead berhasil diperbarui."));
            exit;
        } catch (Throwable $e) {
            header('Location: /leads/loss.php?err=' . urlencode('Gagal memperbarui evaluasi Loss: ' . $e->getMessage()));
            exit;
        }
    }

    // 2. Kembalikan Proyek Loss ke Pipeline Aktif (Reopen)
    if (isset($_POST['aksi_reopen'], $_POST['id_lead'])) {
        $idLead = (int)$_POST['id_lead'];
        if ($idLead > 0) {
            try {
                $upd = $pdo->prepare("
                    UPDATE leads
                    SET tahapan_pipeline = 'Quotation',
                        indikator_hasil  = NULL,
                        alasan_hasil     = NULL,
                        alasan_loss      = NULL
                    WHERE id_lead = :id AND tahapan_pipeline = 'Loss'
                ");
                $upd->execute([':id' => $idLead]);
                header('Location: /leads/index.php?tahapan=Quotation&msg=' . urlencode("Proyek #$idLead dikembalikan ke Pipeline Aktif (Quotation)."));
                exit;
            } catch (Throwable $e) {
                header('Location: /leads/loss.php?err=' . urlencode('Gagal mengembalikan proyek: ' . $e->getMessage()));
                exit;
            }
        }
    }
}

$keyword = trim((string)($_GET['q'] ?? ''));

$totalWinCount = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE tahapan_pipeline = 'Win'")->fetchColumn();

$allLossRows = $pdo->query("
    SELECT l.id_lead, l.nama_klien, l.nama_proyek_prospek, l.lokasi_proyek, l.latitude, l.longitude,
           l.sumber_informasi, l.sumber_lainnya, l.indikator_hasil, l.alasan_hasil, l.alasan_loss,
           COALESCE(l.is_site_visit, FALSE) AS is_site_visit, l.tgl_site_visit,
           l.nilai_proyek, l.keuntungan_bersih, l.margin_persen, l.tanggal_masuk,
           s.nama AS nama_pic, j.nama_jabatan AS jabatan_pic
    FROM leads l
    LEFT JOIN staf s ON l.id_staf_pic = s.id_staf
    LEFT JOIN jabatan j ON s.id_jabatan = j.id_jabatan
    WHERE l.tahapan_pipeline = 'Loss'
    ORDER BY l.id_lead ASC
")->fetchAll();

// Hitung rasio Site Visit vs Tanpa Site Visit menggunakan query aman COALESCE
$lossSvRow = $pdo->query("
    SELECT COUNT(*) AS total_loss,
           COALESCE(SUM(CASE WHEN COALESCE(is_site_visit, FALSE) = TRUE THEN 1 ELSE 0 END), 0) AS jml_sv
    FROM leads
    WHERE tahapan_pipeline = 'Loss'
")->fetch();
$lossWithSvCount    = (int)($lossSvRow['jml_sv'] ?? 0);
$lossWithoutSvCount = max(0, (int)($lossSvRow['total_loss'] ?? 0) - $lossWithSvCount);

// Hitung 4 KPI Evaluasi & Distribusi Penyebab Kekalahan
$totalLossCount   = count($allLossRows);
$totalNilaiHilang = 0.0;
$lossReasonsMap   = [];

foreach ($allLossRows as $r) {
    if ($r['nilai_proyek'] !== null && (float)$r['nilai_proyek'] > 0) {
        $totalNilaiHilang += (float)$r['nilai_proyek'];
    }
    $ind = trim((string)($r['indikator_hasil'] ?? '')) !== ''
        ? trim((string)$r['indikator_hasil'])
        : 'Harga Kalah Bersaing';
    $lossReasonsMap[$ind] = ($lossReasonsMap[$ind] ?? 0) + 1;
}
arsort($lossReasonsMap);

$topLossReason = '-';
$topLossCount  = 0;
if (count($lossReasonsMap) > 0) {
    $topLossReason = (string)array_key_first($lossReasonsMap);
    $topLossCount  = (int)$lossReasonsMap[$topLossReason];
}
$closedTotal = $totalWinCount + $totalLossCount;
$lossRatePct = $closedTotal > 0 ? (int)round(($totalLossCount / $closedTotal) * 100) : 0;

// Filter pencarian
$filteredLossRows = $allLossRows;
if ($keyword !== '') {
    $kwLow = mb_strtolower($keyword, 'UTF-8');
    $filteredLossRows = array_values(array_filter($allLossRows, function ($r) use ($kwLow) {
        $haystack = mb_strtolower(implode(' ', [
            (string)($r['nama_proyek_prospek'] ?? ''),
            (string)($r['nama_klien'] ?? ''),
            (string)($r['lokasi_proyek'] ?? ''),
            (string)($r['sumber_informasi'] ?? ''),
            (string)($r['sumber_lainnya'] ?? ''),
            (string)($r['nama_pic'] ?? ''),
            (string)($r['indikator_hasil'] ?? ''),
            (string)($r['alasan_hasil'] ?? ''),
            (string)($r['alasan_loss'] ?? ''),
        ]), 'UTF-8');
        return str_contains($haystack, $kwLow);
    }));
}

$lossDetailLookup = [];
foreach ($filteredLossRows as $r) {
    $proyekTitle = trim((string)($r['nama_proyek_prospek'] ?? '')) !== ''
        ? trim((string)$r['nama_proyek_prospek'])
        : $r['nama_klien'];
    $alasanText = trim((string)($r['alasan_hasil'] ?? ''));
    if ($alasanText === '') {
        $alasanText = trim((string)($r['alasan_loss'] ?? ''));
    }
    $sumberLabel = $r['sumber_informasi'] ?? '-';
    if ($sumberLabel === 'Lainnya' && !empty(trim((string)($r['sumber_lainnya'] ?? '')))) {
        $sumberLabel = 'Lainnya (' . trim((string)$r['sumber_lainnya']) . ')';
    }
    $np = $r['nilai_proyek'] !== null ? (float)$r['nilai_proyek'] : null;

    $isSv       = pgBool($r['is_site_visit'] ?? false);
    $tglSv      = !empty($r['tgl_site_visit']) ? substr((string)$r['tgl_site_visit'], 0, 10) : '';
    $tglSvFmt   = $tglSv !== '' ? formatIndoShortDate($tglSv) : '';
    $svBadgeTxt = $isSv ? ('Site Visit' . ($tglSvFmt !== '' ? " ($tglSvFmt)" : '')) : 'Tanpa Site Visit';

    $lossDetailLookup[(int)$r['id_lead']] = [
        'id_lead'             => (int)$r['id_lead'],
        'nama_proyek_prospek' => $proyekTitle,
        'nama_klien'          => $r['nama_klien'],
        'lokasi_proyek'       => trim((string)($r['lokasi_proyek'] ?? '')),
        'latitude'            => $r['latitude'] !== null ? (string)$r['latitude'] : '',
        'longitude'           => $r['longitude'] !== null ? (string)$r['longitude'] : '',
        'sumber_label'        => $sumberLabel,
        'nama_pic'            => $r['nama_pic'] ?: 'Belum ditentukan',
        'indikator_hasil'     => trim((string)($r['indikator_hasil'] ?? '')) ?: 'Harga Kalah Bersaing',
        'alasan_hasil'        => $alasanText !== '' ? $alasanText : 'Belum ada catatan evaluasi.',
        'is_site_visit'       => $isSv,
        'tgl_site_visit'      => $tglSv,
        'site_visit_label'    => $svBadgeTxt,
        'nilai_proyek_fmt'    => formatRupiah($np),
        'tanggal_masuk'       => date('d M Y, H:i', strtotime($r['tanggal_masuk'])),
    ];
}

include __DIR__ . '/../layout/header.php';
?>

<style>
    .exec-kpi-card {
        border-radius: 16px;
        padding: 1.3rem 1.4rem;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .exec-kpi-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex-shrink: 0;
    }
    .exec-kpi-val {
        font-size: 1.45rem;
        font-weight: 800;
        color: var(--ta-navy);
        line-height: 1.2;
        letter-spacing: -0.02em;
        margin-top: 0.45rem;
    }
    .loss-bar-track {
        width: 100%;
        height: 10px;
        border-radius: 999px;
        background: rgba(244, 63, 94, 0.14);
        overflow: hidden;
    }
    .loss-bar-fill {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #F43F5E, #DC2626);
    }
    .loss-row-clickable {
        cursor: pointer;
        transition: background-color 0.14s ease;
    }
    .loss-row-clickable:hover td {
        background: rgba(255, 241, 242, 0.65);
    }
    .loss-search-wrap {
        position: relative;
        min-width: 320px;
        width: 100%;
        max-width: 420px;
    }
    .loss-search-wrap input {
        width: 100% !important;
        padding-right: 34px !important;
    }
    .detail-gmaps-mini {
        width: 100%;
        height: 190px;
        border-radius: 12px;
        border: 1px solid rgba(13, 85, 155, 0.16);
        margin-top: 10px;
        display: block;
        background: #E8F0FA;
    }
    .sv-history-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-top: 5px;
        padding: 2px 9px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 600;
        line-height: 1.35;
    }
    .sv-history-badge.is-done {
        background: #ECFDF5;
        color: #059669;
        border: 1px solid rgba(16, 185, 129, 0.26);
    }
    .sv-history-badge.is-none {
        background: #F1F5F9;
        color: #64748B;
        border: 1px solid rgba(100, 116, 139, 0.2);
    }
</style>

<header class="saas-topbar">
    <div class="d-flex align-items-center gap-3 flex-wrap flex-grow-1">
        <div>
            <h1 class="saas-title">Proyek Loss</h1>
            <div class="text-muted" style="font-size: 0.85rem;">Evaluasi objektif penawaran gagal, potensi nilai hilang, dan pemetaan faktor kekalahan tender</div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 500px;">
            <form method="GET" class="top-search loss-search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="q" placeholder="Cari proyek, klien, PIC, indikator, alasan..." value="<?= htmlspecialchars($keyword) ?>">
            </form>
            <?php if ($keyword !== ''): ?>
                <a href="/leads/loss.php" class="btn-toolbar-white flex-shrink-0"><i class="bi bi-arrow-left"></i> Reset</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="/leads/index.php" class="btn-toolbar-white"><i class="bi bi-kanban"></i> Pipeline Leads</a>
        <a href="/leads/analytics.php" class="btn-toolbar-primary"><i class="bi bi-bar-chart-line"></i> Analitik CRM</a>
    </div>
</header>

<main class="p-4">
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
            <span><?= htmlspecialchars($_GET['msg']) ?></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['err'])): ?>
        <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
            <span><?= htmlspecialchars($_GET['err']) ?></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 4 KARTU KPI EVALUASI TENDER -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card exec-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <div class="stat-lbl mb-0">Total Proyek Loss</div>
                        <div class="exec-kpi-val" style="color: #DC2626;"><?= $totalLossCount ?> <small class="fw-semibold" style="font-size: 0.9rem;">Kasus</small></div>
                    </div>
                    <div class="exec-kpi-icon" style="background: rgba(244, 63, 94, 0.12); color: #E11D48;">
                        <i class="bi bi-x-octagon-fill"></i>
                    </div>
                </div>
                <div class="col-micro-lbl mt-3 mb-0">
                    <i class="bi bi-geo-alt-fill me-1" style="color: #E11D48;"></i><strong><?= $lossWithSvCount ?> dari <?= $totalLossCount ?></strong> proyek melalui Site Visit
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card exec-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="min-w-0">
                        <div class="stat-lbl mb-0">Potensi Nilai Proyek Hilang</div>
                        <div class="exec-kpi-val text-truncate" title="<?= htmlspecialchars(formatRupiah($totalNilaiHilang)) ?>">
                            <?= htmlspecialchars($totalNilaiHilang > 0 ? formatRupiah($totalNilaiHilang) : 'Rp 0') ?>
                        </div>
                    </div>
                    <div class="exec-kpi-icon" style="background: rgba(243, 130, 24, 0.14); color: var(--ta-orange);">
                        <i class="bi bi-graph-down-arrow"></i>
                    </div>
                </div>
                <div class="col-micro-lbl mt-3 mb-0">Akumulasi estimasi nilai prospek Loss</div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card exec-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="min-w-0">
                        <div class="stat-lbl mb-0">Penyebab Kekalahan Utama</div>
                        <div class="exec-kpi-val text-truncate" style="font-size: 1.08rem; color: #BE123C;" title="<?= htmlspecialchars($topLossReason) ?>">
                            <?= htmlspecialchars($topLossReason) ?>
                        </div>
                    </div>
                    <div class="exec-kpi-icon" style="background: rgba(190, 18, 60, 0.1); color: #BE123C;">
                        <i class="bi bi-exclamation-diamond-fill"></i>
                    </div>
                </div>
                <div class="col-micro-lbl mt-3 mb-0">
                    <?= $topLossCount > 0 ? "$topLossCount kasus tercatat paling dominan" : 'Belum ada kasus tercatat' ?>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card exec-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <div class="stat-lbl mb-0">Loss Rate</div>
                        <div class="exec-kpi-val" style="color: #E11D48;"><?= $lossRatePct ?>%</div>
                    </div>
                    <div class="exec-kpi-icon" style="background: rgba(220, 38, 38, 0.1); color: #DC2626;">
                        <i class="bi bi-pie-chart-fill"></i>
                    </div>
                </div>
                <div class="col-micro-lbl mt-3 mb-0"><?= $totalLossCount ?> Loss dari <?= $closedTotal ?> proyek Closed</div>
            </div>
        </div>
    </div>

    <!-- KARTU ANALISIS PENYEBAB KEKALAHAN (LOSS REASON BREAKDOWN) -->
    <div class="stat-box-card mb-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div class="d-flex align-items-center gap-2">
                <div class="prod-thumb" style="width: 36px; height: 36px; font-size: 0.95rem; background: #FFF1F2; color: #E11D48; border-color: rgba(244, 63, 94, 0.3);">
                    <i class="bi bi-clipboard2-data-fill"></i>
                </div>
                <div>
                    <div class="fw-bold" style="color: var(--ta-navy); font-size: 0.98rem;">Analisis Penyebab Kekalahan (Loss Reason Breakdown)</div>
                    <div class="col-micro-lbl mb-0">Distribusi persentase faktor kegagalan penawaran sebagai bahan perbaikan strategi tender</div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge-pill-soft" style="background: #EFF6FF; color: var(--ta-blue);">
                    <i class="bi bi-check2-circle me-1"></i><?= $lossWithSvCount ?> Site Visit &bull; <?= $lossWithoutSvCount ?> Tanpa Site Visit
                </span>
                <span class="badge-pill-red"><?= $totalLossCount ?> Total Kasus</span>
            </div>
        </div>

        <?php if (count($lossReasonsMap) === 0): ?>
            <div class="text-muted py-2" style="font-size: 13.5px;">Belum ada data evaluasi kekalahan tercatat.</div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($lossReasonsMap as $reasonLabel => $cnt):
                    $sharePct = $totalLossCount > 0 ? round(($cnt / $totalLossCount) * 100, 1) : 0;
                ?>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);"><?= htmlspecialchars($reasonLabel) ?></span>
                            <span class="col-micro-lbl mb-0 fw-bold" style="color: #E11D48;"><?= $cnt ?> Kasus (<?= $sharePct ?>%)</span>
                        </div>
                        <div class="loss-bar-track">
                            <div class="loss-bar-fill" style="width: <?= max(6, min(100, (int)round($sharePct))) ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TABEL EVALUASI PROYEK LOSS -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="fw-bold" style="color: var(--ta-navy); font-size: 1rem;">
            <i class="bi bi-journal-x me-1" style="color: #E11D48;"></i>Tabel Evaluasi Proyek Loss
        </span>
        <span class="text-muted" style="font-size: 13.5px;"><?= count($filteredLossRows) ?> Proyek &bull; Klik baris untuk detail evaluasi &amp; lokasi</span>
    </div>

    <div class="dribbble-list">
        <div class="table-responsive">
            <table class="dribbble-table">
                <thead>
                    <tr style="background: rgba(255, 241, 242, 0.78); border-bottom: 1px solid rgba(244, 63, 94, 0.22); font-size: 0.74rem; color: #9F1239; text-transform: uppercase;">
                        <th class="py-2 px-3">Proyek &amp; Klien</th>
                        <th class="py-2 px-3">Sumber Informasi</th>
                        <th class="py-2 px-3">Staf PIC</th>
                        <th class="py-2 px-3">Indikator Kekalahan</th>
                        <th class="py-2 px-3">Ringkasan Alasan Evaluasi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($filteredLossRows) === 0): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <?= $keyword !== '' ? 'Tidak ada Proyek Loss yang cocok dengan pencarian.' : 'Belum ada proyek berstatus Loss.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($filteredLossRows as $r):
                            $proyekTitle = trim((string)($r['nama_proyek_prospek'] ?? '')) !== '' ? trim((string)$r['nama_proyek_prospek']) : $r['nama_klien'];
                            $sumberLabel = $r['sumber_informasi'] ?? '-';
                            if ($sumberLabel === 'Lainnya' && !empty(trim((string)($r['sumber_lainnya'] ?? '')))) {
                                $sumberLabel = 'Lainnya (' . trim((string)$r['sumber_lainnya']) . ')';
                            }
                            $alasanRingkas = trim((string)($r['alasan_hasil'] ?? ''));
                            if ($alasanRingkas === '') {
                                $alasanRingkas = trim((string)($r['alasan_loss'] ?? ''));
                            }
                            $isSvRow  = pgBool($r['is_site_visit'] ?? false);
                            $tglSvRow = !empty($r['tgl_site_visit']) ? formatIndoShortDate(substr((string)$r['tgl_site_visit'], 0, 10)) : '';
                        ?>
                        <tr class="loss-row-clickable" data-loss-lead-id="<?= (int)$r['id_lead'] ?>">
                            <td style="min-width: 220px;">
                                <div class="col-main-val" style="color: var(--ta-navy);"><?= htmlspecialchars($proyekTitle) ?></div>
                                <div class="col-micro-lbl mb-0" style="color: var(--ta-blue); font-weight: 600;">
                                    #<?= (int)$r['id_lead'] ?> &bull; <?= htmlspecialchars($r['nama_klien']) ?>
                                </div>
                                <?php if ($r['nilai_proyek'] !== null && (float)$r['nilai_proyek'] > 0): ?>
                                    <div class="col-micro-lbl mb-0">Estimasi: <?= htmlspecialchars(formatRupiah((float)$r['nilai_proyek'])) ?></div>
                                <?php endif; ?>
                                <?php if ($isSvRow): ?>
                                    <span class="sv-history-badge is-done">
                                        <i class="bi bi-check-lg"></i>Site Visit<?= $tglSvRow !== '' ? ' (' . htmlspecialchars($tglSvRow) . ')' : '' ?>
                                    </span>
                                <?php else: ?>
                                    <span class="sv-history-badge is-none">
                                        Tanpa Site Visit
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-pill-soft"><?= htmlspecialchars($sumberLabel) ?></span>
                            </td>
                            <td>
                                <div class="col-main-val" style="font-size: 0.84rem;"><?= htmlspecialchars($r['nama_pic'] ?? '-') ?></div>
                            </td>
                            <td>
                                <span class="badge-pill-soft" style="background: #FFF1F2; color: #BE123C; border: 1px solid rgba(244, 63, 94, 0.28);">
                                    <i class="bi bi-exclamation-circle-fill me-1"></i><?= htmlspecialchars(trim((string)($r['indikator_hasil'] ?? '')) ?: 'Loss') ?>
                                </span>
                            </td>
                            <td style="max-width: 360px; font-size: 13px; color: var(--text-muted);">
                                <?= htmlspecialchars($alasanRingkas !== '' ? mb_strimwidth($alasanRingkas, 0, 110, '...') : 'Belum ada catatan evaluasi.') ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- MODAL DETAIL EVALUASI PROYEK LOSS (#lossDetailModal) -->
<div class="modal fade" id="lossDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 560px;">
        <div class="modal-content rounded-4 overflow-hidden">
            <div class="px-4 py-3 d-flex align-items-center justify-content-between" style="background: linear-gradient(90deg, rgba(244, 63, 94, 0.18), rgba(255, 241, 242, 0.92)); border-bottom: 1px solid rgba(244, 63, 94, 0.2);">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <div class="prod-thumb" style="width: 38px; height: 38px; background: #FFF1F2; color: #E11D48;"><i class="bi bi-x-octagon-fill"></i></div>
                    <div class="min-w-0">
                        <h5 class="mb-0 fw-bold text-truncate" style="color: #9F1239; font-size: 1.05rem;">Detail Evaluasi Proyek Loss</h5>
                        <div id="lossDetSub" class="col-micro-lbl mb-0">-</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body px-4 py-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <span id="lossDetSumber" class="badge-pill-soft">-</span>
                    <span id="lossDetIndikator" class="badge-pill-soft" style="background: #FFF1F2; color: #BE123C;">Indikator</span>
                </div>

                <div class="p-3 rounded-3 mb-3" style="background: linear-gradient(145deg, rgba(228, 238, 249, 0.6), rgba(252, 241, 229, 0.6)); border: 1px solid rgba(13, 85, 155, 0.14);">
                    <div class="fw-bold" id="lossDetProyek" style="color: var(--ta-navy); font-size: 1rem;">-</div>
                    <div class="fw-semibold" id="lossDetKlien" style="color: var(--ta-blue); font-size: 13.5px;">-</div>
                    <div class="mb-2">
                        <span id="lossDetSvBadge" class="sv-history-badge is-none">Tanpa Site Visit</span>
                    </div>
                    <div class="mb-2">
                        <span class="col-micro-lbl">Estimasi Nilai Proyek: </span>
                        <strong id="lossDetNilai" style="font-size: 13.5px; color: var(--ta-navy);">-</strong>
                    </div>
                    <div class="col-micro-lbl mb-0">Lokasi Proyek</div>
                    <div id="lossDetLokasi" style="font-size: 13px; color: var(--ta-navy);">-</div>
                    <iframe id="lossDetMap" class="detail-gmaps-mini d-none" loading="lazy" allowfullscreen></iframe>
                    <a id="lossDetMapBtn" href="#" target="_blank" rel="noopener noreferrer" class="btn-toolbar-white mt-2 d-none" style="font-size: 12px; padding: 4px 11px;">
                        <i class="bi bi-geo-alt-fill" style="color: var(--ta-orange);"></i> Buka di Google Maps
                    </a>
                </div>

                <div class="col-micro-lbl mb-1">Alasan Evaluasi Lengkap</div>
                <div id="lossDetAlasan" class="p-3 rounded-3" style="background: rgba(255,255,255,0.88); border: 1px solid rgba(13, 85, 155, 0.14); font-size: 13.5px; color: var(--ta-navy); white-space: pre-line;">-</div>
            </div>

            <div class="px-4 pb-4 pt-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <form method="POST" class="m-0" onsubmit="return confirm('Kembalikan Proyek Loss ini ke Pipeline Aktif (Quotation)?');">
                    <input type="hidden" name="aksi_reopen" value="1">
                    <input type="hidden" name="id_lead" id="lossReopenId" value="">
                    <button type="submit" class="btn-toolbar-white" style="color: #B45309; font-size: 13px;">
                        <i class="bi bi-arrow-counterclockwise"></i> Kembalikan ke Pipeline Aktif
                    </button>
                </form>
                <button type="button" id="btnLossSwitchEdit" class="btn-toolbar-primary" style="font-size: 13px;">
                    <i class="bi bi-pencil-square"></i> Edit Evaluasi Loss
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL EDIT EVALUASI LOSS (#lossEditModal) -->
<div class="modal fade" id="lossEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 500px;">
        <div class="modal-content rounded-4 overflow-hidden">
            <div class="px-4 py-3 d-flex align-items-center justify-content-between" style="background: linear-gradient(90deg, rgba(244, 63, 94, 0.18), rgba(255, 241, 242, 0.92));">
                <h5 class="mb-0 fw-bold" style="color: #9F1239; font-size: 1.05rem;">Edit Evaluasi Proyek Loss</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="aksi_edit_outcome" value="1">
                <input type="hidden" name="id_lead" id="lossEditId" value="">
                <div class="modal-body px-4 py-3">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">Indikator Kekalahan <span style="color: var(--ta-orange);">*</span></label>
                        <select id="lossEditIndSelect" name="indikator_pilihan" class="form-select" required>
                            <?php foreach ($lossIndicators as $li): ?>
                                <option value="<?= htmlspecialchars($li) ?>"><?= htmlspecialchars($li) ?></option>
                            <?php endforeach; ?>
                            <option value="Lainnya">Lainnya (Tulis Indikator Baru)</option>
                        </select>
                    </div>
                    <div class="mb-3 d-none" id="lossEditCustomWrap">
                        <label class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">Tulis Indikator Baru <span style="color: var(--ta-orange);">*</span></label>
                        <input type="text" id="lossEditCustomInput" name="indikator_kustom" class="form-control" maxlength="120">
                    </div>
                    <div>
                        <label class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">Alasan Evaluasi Lengkap <span style="color: var(--ta-orange);">*</span></label>
                        <textarea id="lossEditAlasan" name="alasan_hasil" rows="3" class="form-control" required></textarea>
                    </div>
                </div>
                <div class="px-4 pb-4 pt-2 d-flex justify-content-end gap-2">
                    <button type="button" class="btn-toolbar-white" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-toolbar-primary" style="background: #E11D48; border-color: #E11D48;"><i class="bi bi-check-lg"></i> Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', function () {
    const lossLookup  = <?= json_encode($lossDetailLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const lossPresets = <?= json_encode($lossIndicators, JSON_UNESCAPED_UNICODE) ?>;
    const detModalEl  = document.getElementById('lossDetailModal');
    const edtModalEl  = document.getElementById('lossEditModal');
    if (!detModalEl || !edtModalEl || typeof bootstrap === 'undefined') return;

    const detModal = new bootstrap.Modal(detModalEl);
    const edtModal = new bootstrap.Modal(edtModalEl);
    let currentLoss = null;

    const selEditInd = document.getElementById('lossEditIndSelect');
    const wrapCustom = document.getElementById('lossEditCustomWrap');
    const inpCustom  = document.getElementById('lossEditCustomInput');
    function syncCustom() {
        const isOther = selEditInd.value === 'Lainnya';
        wrapCustom.classList.toggle('d-none', !isOther);
        inpCustom.required = isOther;
        if (!isOther) inpCustom.value = '';
    }
    selEditInd.addEventListener('change', syncCustom);

    document.querySelectorAll('.loss-row-clickable').forEach(function (row) {
        row.addEventListener('click', function () {
            const item = lossLookup[row.getAttribute('data-loss-lead-id')];
            if (!item) return;
            currentLoss = item;

            document.getElementById('lossDetSub').textContent       = `Lead #${item.id_lead} • ${item.tanggal_masuk} • PIC: ${item.nama_pic}`;
            document.getElementById('lossDetSumber').textContent    = item.sumber_label;
            document.getElementById('lossDetIndikator').textContent = item.indikator_hasil;
            document.getElementById('lossDetProyek').textContent    = item.nama_proyek_prospek;
            document.getElementById('lossDetKlien').textContent     = item.nama_klien;
            const lossSvBadge = document.getElementById('lossDetSvBadge');
            if (lossSvBadge) {
                lossSvBadge.className = 'sv-history-badge ' + (item.is_site_visit ? 'is-done' : 'is-none');
                lossSvBadge.innerHTML = item.is_site_visit
                    ? '<i class="bi bi-check-lg"></i>' + item.site_visit_label
                    : 'Tanpa Site Visit';
            }
            document.getElementById('lossDetNilai').textContent     = item.nilai_proyek_fmt;
            document.getElementById('lossDetLokasi').textContent    = item.lokasi_proyek || 'Lokasi belum tercatat';
            document.getElementById('lossDetAlasan').textContent    = item.alasan_hasil;
            document.getElementById('lossReopenId').value           = String(item.id_lead);

            const q = (item.latitude && item.longitude) ? `${item.latitude},${item.longitude}` : item.lokasi_proyek;
            const iframe = document.getElementById('lossDetMap');
            const mapBtn = document.getElementById('lossDetMapBtn');
            if (q) {
                iframe.src = `https://maps.google.com/maps?q=${encodeURIComponent(q)}&t=m&z=15&ie=UTF8&iwloc=&output=embed`;
                iframe.classList.remove('d-none');
                mapBtn.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`;
                mapBtn.classList.remove('d-none');
                mapBtn.classList.add('d-inline-flex');
            } else {
                iframe.removeAttribute('src');
                iframe.classList.add('d-none');
                mapBtn.classList.add('d-none');
            }

            detModal.show();
        });
    });

    document.getElementById('btnLossSwitchEdit').addEventListener('click', function () {
        if (!currentLoss) return;
        const item = currentLoss;
        detModal.hide();
        setTimeout(function () {
            document.getElementById('lossEditId').value     = String(item.id_lead);
            document.getElementById('lossEditAlasan').value = item.alasan_hasil;
            if (lossPresets.includes(item.indikator_hasil)) {
                selEditInd.value = item.indikator_hasil;
                inpCustom.value = '';
            } else {
                selEditInd.value = 'Lainnya';
                inpCustom.value = item.indikator_hasil;
            }
            syncCustom();
            edtModal.show();
        }, 200);
    });
});
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>

