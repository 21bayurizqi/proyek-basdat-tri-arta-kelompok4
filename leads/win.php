<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app_helpers.php';

ensureLeadsExtraColumns($pdo);
migrateLegacyWinContracts($pdo);

$winIndicators = getLeadWinIndicators();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Edit Indikator / Alasan / Nominal Proyek Win
    if (isset($_POST['aksi_edit_outcome'], $_POST['id_lead'])) {
        $idLead           = (int)$_POST['id_lead'];
        $indikatorPilihan = trim((string)($_POST['indikator_pilihan'] ?? ''));
        $indikatorKustom  = trim((string)($_POST['indikator_kustom'] ?? ''));
        $alasanHasil      = trim((string)($_POST['alasan_hasil'] ?? ''));
        $nilaiProyekVal   = parseIdrCurrency($_POST['nilai_proyek'] ?? null);
        $keuntunganVal    = parseIdrCurrency($_POST['keuntungan_bersih'] ?? null);
        $indikatorFinal   = $indikatorPilihan === 'Lainnya' ? $indikatorKustom : $indikatorPilihan;

        if ($idLead <= 0 || $indikatorFinal === '' || $alasanHasil === '') {
            header('Location: /leads/win.php?err=' . urlencode('Indikator kemenangan dan alasan lengkap wajib diisi.'));
            exit;
        }
        if ($nilaiProyekVal !== null && $keuntunganVal !== null && $keuntunganVal > $nilaiProyekVal) {
            header('Location: /leads/win.php?err=' . urlencode('Keuntungan bersih tidak boleh melebihi nilai kontrak proyek.'));
            exit;
        }

        $marginPersen = ($nilaiProyekVal !== null && $nilaiProyekVal > 0 && $keuntunganVal !== null && $keuntunganVal >= 0)
            ? round(($keuntunganVal / $nilaiProyekVal) * 100, 2)
            : null;

        try {
            $upd = $pdo->prepare("
                UPDATE leads
                SET indikator_hasil   = :indikator,
                    alasan_hasil      = :alasan,
                    nilai_proyek      = COALESCE(:nilai, nilai_proyek),
                    keuntungan_bersih = COALESCE(:untung, keuntungan_bersih),
                    margin_persen     = COALESCE(:margin, margin_persen)
                WHERE id_lead = :id AND tahapan_pipeline = 'Win'
            ");
            $upd->execute([
                ':indikator' => substr($indikatorFinal, 0, 120),
                ':alasan'    => $alasanHasil,
                ':nilai'     => $nilaiProyekVal,
                ':untung'    => $keuntunganVal,
                ':margin'    => $marginPersen,
                ':id'        => $idLead,
            ]);
            header('Location: /leads/win.php?msg=' . urlencode("Detail Proyek Win #$idLead berhasil diperbarui."));
            exit;
        } catch (Throwable $e) {
            header('Location: /leads/win.php?err=' . urlencode('Gagal memperbarui Proyek Win: ' . $e->getMessage()));
            exit;
        }
    }

    // 2. Kembalikan Proyek Win ke Pipeline Aktif (Reopen)
    if (isset($_POST['aksi_reopen'], $_POST['id_lead'])) {
        $idLead = (int)$_POST['id_lead'];
        if ($idLead > 0) {
            try {
                $upd = $pdo->prepare("
                    UPDATE leads
                    SET tahapan_pipeline = 'Quotation',
                        indikator_hasil  = NULL,
                        alasan_hasil     = NULL
                    WHERE id_lead = :id AND tahapan_pipeline = 'Win'
                ");
                $upd->execute([':id' => $idLead]);
                header('Location: /leads/index.php?tahapan=Quotation&msg=' . urlencode("Proyek #$idLead dikembalikan ke Pipeline Aktif (Quotation)."));
                exit;
            } catch (Throwable $e) {
                header('Location: /leads/win.php?err=' . urlencode('Gagal mengembalikan proyek: ' . $e->getMessage()));
                exit;
            }
        }
    }
}

$keyword = trim((string)($_GET['q'] ?? ''));

// Ambil seluruh Proyek Win untuk KPI Eksekutif & Win Drivers (urutan ascending #1 di atas)
$allWinRows = $pdo->query("
    SELECT l.id_lead, l.nama_klien, l.nama_proyek_prospek, l.lokasi_proyek, l.latitude, l.longitude,
           l.sumber_informasi, l.sumber_lainnya, l.indikator_hasil, l.alasan_hasil,
           COALESCE(l.is_site_visit, FALSE) AS is_site_visit, l.tgl_site_visit,
           l.nilai_proyek, l.keuntungan_bersih, l.margin_persen, l.tanggal_masuk,
           s.nama AS nama_pic, j.nama_jabatan AS jabatan_pic,
           (
               SELECT p.no_kontrak
               FROM proyek p
               WHERE p.id_lead_sumber = l.id_lead
                  OR LOWER(TRIM(p.nama_proyek)) = LOWER(TRIM(COALESCE(NULLIF(TRIM(l.nama_proyek_prospek), ''), l.nama_klien)))
                  OR LOWER(TRIM(p.nama_proyek)) = LOWER(TRIM(l.nama_klien))
               ORDER BY p.id_proyek DESC
               LIMIT 1
           ) AS no_kontrak
    FROM leads l
    LEFT JOIN staf s ON l.id_staf_pic = s.id_staf
    LEFT JOIN jabatan j ON s.id_jabatan = j.id_jabatan
    WHERE l.tahapan_pipeline = 'Win'
    ORDER BY l.id_lead ASC
")->fetchAll();

// Hitung rasio Site Visit vs Tanpa Site Visit menggunakan query aman COALESCE
$winSvRow = $pdo->query("
    SELECT COUNT(*) AS total_win,
           COALESCE(SUM(CASE WHEN COALESCE(is_site_visit, FALSE) = TRUE THEN 1 ELSE 0 END), 0) AS jml_sv
    FROM leads
    WHERE tahapan_pipeline = 'Win'
")->fetch();
$winWithSvCount    = (int)($winSvRow['jml_sv'] ?? 0);
$winWithoutSvCount = max(0, (int)($winSvRow['total_win'] ?? 0) - $winWithSvCount);

// Hitung 4 KPI Eksekutif & Distribusi Faktor Kemenangan (Win Drivers)
$totalWinCount    = count($allWinRows);
$totalNilaiKontrak = 0.0;
$totalKeuntungan  = 0.0;
$marginSum        = 0.0;
$marginCnt        = 0;
$winDriversMap    = [];

foreach ($allWinRows as $r) {
    $np = $r['nilai_proyek'] !== null ? (float)$r['nilai_proyek'] : null;
    $kb = $r['keuntungan_bersih'] !== null ? (float)$r['keuntungan_bersih'] : null;
    $mp = $r['margin_persen'] !== null
        ? (float)$r['margin_persen']
        : (($np !== null && $np > 0 && $kb !== null) ? round(($kb / $np) * 100, 2) : null);

    if ($np !== null && $np > 0) {
        $totalNilaiKontrak += $np;
    }
    if ($kb !== null && $kb > 0) {
        $totalKeuntungan += $kb;
    }
    if ($mp !== null) {
        $marginSum += $mp;
        $marginCnt++;
    }

    $driver = trim((string)($r['indikator_hasil'] ?? '')) !== ''
        ? trim((string)$r['indikator_hasil'])
        : 'Relasi / Klien Percaya';
    $winDriversMap[$driver] = ($winDriversMap[$driver] ?? 0) + 1;
}
arsort($winDriversMap);

$avgMarginPct  = $marginCnt > 0 ? round($marginSum / $marginCnt, 1) : ($totalNilaiKontrak > 0 ? round(($totalKeuntungan / $totalNilaiKontrak) * 100, 1) : null);
$avgMarginMeta = getMarginHealthMeta($avgMarginPct);

// Filter pencarian pada daftar Proyek Win
$filteredWinRows = $allWinRows;
if ($keyword !== '') {
    $kwLow = mb_strtolower($keyword, 'UTF-8');
    $filteredWinRows = array_values(array_filter($allWinRows, function ($r) use ($kwLow) {
        $haystack = mb_strtolower(implode(' ', [
            (string)($r['no_kontrak'] ?? ''),
            (string)($r['nama_proyek_prospek'] ?? ''),
            (string)($r['nama_klien'] ?? ''),
            (string)($r['lokasi_proyek'] ?? ''),
            (string)($r['nama_pic'] ?? ''),
            (string)($r['indikator_hasil'] ?? ''),
            (string)($r['alasan_hasil'] ?? ''),
        ]), 'UTF-8');
        return str_contains($haystack, $kwLow);
    }));
}

$winDetailLookup = [];
foreach ($filteredWinRows as $r) {
    $proyekTitle = trim((string)($r['nama_proyek_prospek'] ?? '')) !== ''
        ? trim((string)$r['nama_proyek_prospek'])
        : $r['nama_klien'];
    $np = $r['nilai_proyek'] !== null ? (float)$r['nilai_proyek'] : null;
    $kb = $r['keuntungan_bersih'] !== null ? (float)$r['keuntungan_bersih'] : null;
    $mp = $r['margin_persen'] !== null
        ? (float)$r['margin_persen']
        : (($np !== null && $np > 0 && $kb !== null) ? round(($kb / $np) * 100, 2) : null);

    $isSv       = pgBool($r['is_site_visit'] ?? false);
    $tglSv      = !empty($r['tgl_site_visit']) ? substr((string)$r['tgl_site_visit'], 0, 10) : '';
    $tglSvFmt   = $tglSv !== '' ? formatIndoShortDate($tglSv) : '';
    $svBadgeTxt = $isSv ? ('Site Visit' . ($tglSvFmt !== '' ? " ($tglSvFmt)" : '')) : 'Tanpa Site Visit';

    $winDetailLookup[(int)$r['id_lead']] = [
        'id_lead'             => (int)$r['id_lead'],
        'no_kontrak'          => $r['no_kontrak'] ?: '-',
        'nama_proyek_prospek' => $proyekTitle,
        'nama_klien'          => $r['nama_klien'],
        'lokasi_proyek'       => trim((string)($r['lokasi_proyek'] ?? '')),
        'latitude'            => $r['latitude'] !== null ? (string)$r['latitude'] : '',
        'longitude'           => $r['longitude'] !== null ? (string)$r['longitude'] : '',
        'nama_pic'            => $r['nama_pic'] ?: 'Belum ditentukan',
        'indikator_hasil'     => trim((string)($r['indikator_hasil'] ?? '')) ?: 'Relasi / Klien Percaya',
        'alasan_hasil'        => trim((string)($r['alasan_hasil'] ?? '')) ?: 'Belum ada uraian alasan tercatat.',
        'is_site_visit'       => $isSv,
        'tgl_site_visit'      => $tglSv,
        'site_visit_label'    => $svBadgeTxt,
        'nilai_proyek'        => $np,
        'nilai_proyek_fmt'    => formatRupiah($np),
        'keuntungan_bersih'   => $kb,
        'keuntungan_fmt'      => formatRupiah($kb),
        'margin_persen'       => $mp,
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
    .driver-bar-track {
        width: 100%;
        height: 10px;
        border-radius: 999px;
        background: rgba(16, 185, 129, 0.14);
        overflow: hidden;
    }
    .driver-bar-fill {
        height: 100%;
        border-radius: 999px;
        background: linear-gradient(90deg, #10B981, #059669);
    }
    .win-row-clickable {
        cursor: pointer;
        transition: background-color 0.14s ease;
    }
    .win-row-clickable:hover td {
        background: rgba(236, 253, 245, 0.6);
    }
    .mini-margin-track {
        width: 82px;
        height: 6px;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.25);
        overflow: hidden;
    }
    .mini-margin-fill {
        height: 100%;
        border-radius: 999px;
    }
    .win-search-wrap {
        position: relative;
        min-width: 320px;
        width: 100%;
        max-width: 420px;
    }
    .win-search-wrap input {
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
            <h1 class="saas-title">Proyek Win</h1>
            <div class="text-muted" style="font-size: 0.85rem;">Portofolio kontrak dimenangkan, akumulasi profit bersih, dan faktor pendorong kemenangan</div>
        </div>
        <div class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 500px;">
            <form method="GET" class="top-search win-search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" name="q" placeholder="Cari no kontrak, proyek, klien, PIC, indikator..." value="<?= htmlspecialchars($keyword) ?>">
            </form>
            <?php if ($keyword !== ''): ?>
                <a href="/leads/win.php" class="btn-toolbar-white flex-shrink-0"><i class="bi bi-arrow-left"></i> Reset</a>
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

    <!-- 4 KARTU KPI EKSEKUTIF -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card exec-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <div class="stat-lbl mb-0">Total Proyek Win</div>
                        <div class="exec-kpi-val" style="color: #059669;"><?= $totalWinCount ?> <small class="fw-semibold" style="font-size: 0.9rem;">Deal</small></div>
                    </div>
                    <div class="exec-kpi-icon" style="background: rgba(16, 185, 129, 0.14); color: #059669;">
                        <i class="bi bi-trophy-fill"></i>
                    </div>
                </div>
                <div class="col-micro-lbl mt-3 mb-0">
                    <i class="bi bi-geo-alt-fill me-1" style="color: #059669;"></i><strong><?= $winWithSvCount ?> dari <?= $totalWinCount ?></strong> proyek melalui Site Visit
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card exec-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="min-w-0">
                        <div class="stat-lbl mb-0">Total Nilai Kontrak</div>
                        <div class="exec-kpi-val text-truncate" title="<?= htmlspecialchars(formatRupiah($totalNilaiKontrak)) ?>">
                            <?= htmlspecialchars($totalNilaiKontrak > 0 ? formatRupiah($totalNilaiKontrak) : 'Rp 0') ?>
                        </div>
                    </div>
                    <div class="exec-kpi-icon" style="background: rgba(13, 85, 155, 0.12); color: var(--ta-blue);">
                        <i class="bi bi-file-earmark-check-fill"></i>
                    </div>
                </div>
                <div class="col-micro-lbl mt-3 mb-0">Akumulasi nilai kontrak seluruh Proyek Win</div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card exec-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div class="min-w-0">
                        <div class="stat-lbl mb-0">Total Keuntungan Bersih</div>
                        <div class="exec-kpi-val text-truncate" style="color: var(--ta-green);" title="<?= htmlspecialchars(formatRupiah($totalKeuntungan)) ?>">
                            <?= htmlspecialchars($totalKeuntungan > 0 ? formatRupiah($totalKeuntungan) : 'Rp 0') ?>
                        </div>
                    </div>
                    <div class="exec-kpi-icon" style="background: rgba(19, 94, 56, 0.12); color: var(--ta-green);">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
                <div class="col-micro-lbl mt-3 mb-0">Estimasi profit bersih dari proyek dimenangkan</div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card exec-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <div class="stat-lbl mb-0">Rata-Rata Margin Proyek</div>
                        <div class="exec-kpi-val" style="color: <?= $avgMarginMeta['color'] ?>;">
                            <?= $avgMarginPct !== null ? number_format($avgMarginPct, 1) . '%' : '0%' ?>
                        </div>
                    </div>
                    <div class="exec-kpi-icon" style="background: <?= $avgMarginMeta['bg'] ?>; color: <?= $avgMarginMeta['color'] ?>;">
                        <i class="bi bi-percent"></i>
                    </div>
                </div>
                <div class="mt-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span class="col-micro-lbl mb-0"><?= htmlspecialchars($avgMarginMeta['label']) ?></span>
                        <span class="col-micro-lbl mb-0 fw-semibold"><?= $marginCnt ?> proyek bernilai</span>
                    </div>
                    <div style="width: 100%; height: 6px; border-radius: 999px; background: rgba(148, 163, 184, 0.25); overflow: hidden;">
                        <div style="width: <?= $avgMarginPct !== null ? max(4, min(100, (int)round($avgMarginPct))) : 0 ?>%; height: 100%; background: <?= $avgMarginMeta['color'] ?>; border-radius: 999px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- KARTU RINGKASAN FAKTOR KEMENANGAN (WIN DRIVERS) -->
    <div class="stat-box-card mb-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div class="d-flex align-items-center gap-2">
                <div class="prod-thumb" style="width: 36px; height: 36px; font-size: 0.95rem; background: #ECFDF5; color: #059669; border-color: rgba(16, 185, 129, 0.3);">
                    <i class="bi bi-stars"></i>
                </div>
                <div>
                    <div class="fw-bold" style="color: var(--ta-navy); font-size: 0.98rem;">Ringkasan Faktor Kemenangan (Win Drivers)</div>
                    <div class="col-micro-lbl mb-0">Distribusi indikator pendorong utama yang paling sering memenangkan proyek</div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge-pill-soft" style="background: #EFF6FF; color: var(--ta-blue);">
                    <i class="bi bi-check2-circle me-1"></i><?= $winWithSvCount ?> Site Visit &bull; <?= $winWithoutSvCount ?> Tanpa Site Visit
                </span>
                <span class="badge-pill-soft" style="background: #ECFDF5; color: #059669;"><?= $totalWinCount ?> Total Deal</span>
            </div>
        </div>

        <?php if (count($winDriversMap) === 0): ?>
            <div class="text-muted py-2" style="font-size: 13.5px;">Belum ada faktor kemenangan tercatat.</div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($winDriversMap as $driverLabel => $cnt):
                    $sharePct = $totalWinCount > 0 ? round(($cnt / $totalWinCount) * 100, 1) : 0;
                ?>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);"><?= htmlspecialchars($driverLabel) ?></span>
                            <span class="col-micro-lbl mb-0 fw-bold" style="color: #059669;"><?= $cnt ?> Deal (<?= $sharePct ?>%)</span>
                        </div>
                        <div class="driver-bar-track">
                            <div class="driver-bar-fill" style="width: <?= max(6, min(100, (int)round($sharePct))) ?>%;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- TABEL DAFTAR PROYEK WIN -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="fw-bold" style="color: var(--ta-navy); font-size: 1rem;">
            <i class="bi bi-trophy me-1" style="color: #059669;"></i>Daftar Proyek Win
        </span>
        <span class="text-muted" style="font-size: 13.5px;"><?= count($filteredWinRows) ?> Proyek &bull; Klik baris untuk detail alasan lengkap</span>
    </div>

    <div class="dribbble-list">
        <div class="table-responsive">
            <table class="dribbble-table">
                <thead>
                    <tr style="background: rgba(236, 253, 245, 0.72); border-bottom: 1px solid rgba(16, 185, 129, 0.2); font-size: 0.74rem; color: #065F46; text-transform: uppercase;">
                        <th class="py-2 px-3">Nomor Kontrak</th>
                        <th class="py-2 px-3">Proyek &amp; Klien</th>
                        <th class="py-2 px-3">Lokasi Proyek</th>
                        <th class="py-2 px-3">Staf PIC</th>
                        <th class="py-2 px-3">Indikator Kemenangan</th>
                        <th class="py-2 px-3">Nilai Kontrak</th>
                        <th class="py-2 px-3">Keuntungan Bersih</th>
                        <th class="py-2 px-3">Margin %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($filteredWinRows) === 0): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <?= $keyword !== '' ? 'Tidak ada Proyek Win yang cocok dengan pencarian.' : 'Belum ada proyek berstatus Win.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($filteredWinRows as $r):
                            $proyekTitle = trim((string)($r['nama_proyek_prospek'] ?? '')) !== '' ? trim((string)$r['nama_proyek_prospek']) : $r['nama_klien'];
                            $lokasi      = trim((string)($r['lokasi_proyek'] ?? ''));
                            $mapsQuery   = ($r['latitude'] !== null && $r['longitude'] !== null)
                                ? ($r['latitude'] . ',' . $r['longitude'])
                                : $lokasi;
                            $np = $r['nilai_proyek'] !== null ? (float)$r['nilai_proyek'] : null;
                            $kb = $r['keuntungan_bersih'] !== null ? (float)$r['keuntungan_bersih'] : null;
                            $mp = $r['margin_persen'] !== null
                                ? (float)$r['margin_persen']
                                : (($np !== null && $np > 0 && $kb !== null) ? round(($kb / $np) * 100, 2) : null);
                            $mMeta    = getMarginHealthMeta($mp);
                            $isSvRow  = pgBool($r['is_site_visit'] ?? false);
                            $tglSvRow = !empty($r['tgl_site_visit']) ? formatIndoShortDate(substr((string)$r['tgl_site_visit'], 0, 10)) : '';
                        ?>
                        <tr class="win-row-clickable" data-win-lead-id="<?= (int)$r['id_lead'] ?>">
                            <td style="white-space: nowrap;">
                                <span class="badge-pill-soft" style="background: #EFF6FF; color: var(--ta-navy); font-weight: 700;">
                                    <?= htmlspecialchars($r['no_kontrak'] ?: 'Menunggu SPK') ?>
                                </span>
                            </td>
                            <td style="min-width: 220px;">
                                <div class="col-main-val" style="color: var(--ta-navy);"><?= htmlspecialchars($proyekTitle) ?></div>
                                <div class="col-micro-lbl mb-0" style="color: var(--ta-blue); font-weight: 600;">
                                    #<?= (int)$r['id_lead'] ?> &bull; <?= htmlspecialchars($r['nama_klien']) ?>
                                </div>
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
                            <td style="min-width: 190px;">
                                <div style="font-size: 13px; color: var(--ta-navy);">
                                    <?= htmlspecialchars($lokasi !== '' ? mb_strimwidth($lokasi, 0, 50, '...') : '-') ?>
                                </div>
                                <?php if ($mapsQuery !== ''): ?>
                                    <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= urlencode($mapsQuery) ?>"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="no-row-click text-decoration-none fw-semibold d-inline-flex align-items-center gap-1 mt-1"
                                       style="font-size: 12px; color: var(--ta-orange);">
                                        <i class="bi bi-geo-alt-fill"></i> Buka Google Maps
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="col-main-val" style="font-size: 0.84rem;"><?= htmlspecialchars($r['nama_pic'] ?? '-') ?></div>
                            </td>
                            <td>
                                <span class="badge-pill-soft" style="background: #ECFDF5; color: #047857; border: 1px solid rgba(16, 185, 129, 0.28);">
                                    <i class="bi bi-check-circle-fill me-1"></i><?= htmlspecialchars(trim((string)($r['indikator_hasil'] ?? '')) ?: 'Win') ?>
                                </span>
                            </td>
                            <td style="white-space: nowrap; font-weight: 700; color: var(--ta-navy);">
                                <?= htmlspecialchars(formatRupiah($np)) ?>
                            </td>
                            <td style="white-space: nowrap; font-weight: 700; color: #059669;">
                                <?= htmlspecialchars(formatRupiah($kb)) ?>
                            </td>
                            <td style="min-width: 130px;">
                                <?php if ($mp !== null): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="mini-margin-track">
                                            <div class="mini-margin-fill" style="width: <?= max(4, min(100, (int)round($mp))) ?>%; background: <?= $mMeta['color'] ?>;"></div>
                                        </div>
                                        <span class="badge-pill-soft" style="background: <?= $mMeta['bg'] ?>; color: <?= $mMeta['color'] ?>; font-size: 11.5px; padding: 1px 8px;">
                                            <?= number_format($mp, 1) ?>%
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="col-micro-lbl mb-0">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<!-- MODAL DETAIL ALASAN KEMENANGAN LENGKAP (#winDetailModal) -->
<div class="modal fade" id="winDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 560px;">
        <div class="modal-content rounded-4 overflow-hidden">
            <div class="px-4 py-3 d-flex align-items-center justify-content-between" style="background: linear-gradient(90deg, rgba(16, 185, 129, 0.2), rgba(236, 253, 245, 0.92)); border-bottom: 1px solid rgba(16, 185, 129, 0.2);">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <div class="prod-thumb" style="width: 38px; height: 38px; background: #ECFDF5; color: #059669;"><i class="bi bi-trophy-fill"></i></div>
                    <div class="min-w-0">
                        <h5 class="mb-0 fw-bold text-truncate" style="color: #065F46; font-size: 1.05rem;">Detail Proyek Win</h5>
                        <div id="winDetSub" class="col-micro-lbl mb-0">-</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body px-4 py-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <span id="winDetKontrak" class="badge-pill-soft" style="background: #EFF6FF; color: var(--ta-navy); font-weight: 700;">SPK/...</span>
                    <span id="winDetIndikator" class="badge-pill-soft" style="background: #ECFDF5; color: #047857;">Indikator</span>
                </div>

                <div class="p-3 rounded-3 mb-3" style="background: linear-gradient(145deg, rgba(228, 238, 249, 0.6), rgba(252, 241, 229, 0.6)); border: 1px solid rgba(13, 85, 155, 0.14);">
                    <div class="fw-bold" id="winDetProyek" style="color: var(--ta-navy); font-size: 1rem;">-</div>
                    <div class="fw-semibold" id="winDetKlien" style="color: var(--ta-blue); font-size: 13.5px;">-</div>
                    <div class="mb-2">
                        <span id="winDetSvBadge" class="sv-history-badge is-none">Tanpa Site Visit</span>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <div class="col-micro-lbl mb-0">Nilai Kontrak</div>
                            <div id="winDetNilai" class="fw-bold" style="font-size: 13px; color: var(--ta-navy);">-</div>
                        </div>
                        <div class="col-4">
                            <div class="col-micro-lbl mb-0">Laba Bersih</div>
                            <div id="winDetUntung" class="fw-bold" style="font-size: 13px; color: #059669;">-</div>
                        </div>
                        <div class="col-4">
                            <div class="col-micro-lbl mb-0">Margin</div>
                            <div id="winDetMargin" class="fw-bold" style="font-size: 13px; color: var(--ta-blue);">-</div>
                        </div>
                    </div>
                    <div class="col-micro-lbl mb-0">Lokasi Proyek</div>
                    <div id="winDetLokasi" style="font-size: 13px; color: var(--ta-navy);">-</div>
                    <iframe id="winDetMap" class="detail-gmaps-mini d-none" loading="lazy" allowfullscreen></iframe>
                    <a id="winDetMapBtn" href="#" target="_blank" rel="noopener noreferrer" class="btn-toolbar-white mt-2 d-none" style="font-size: 12px; padding: 4px 11px;">
                        <i class="bi bi-geo-alt-fill" style="color: var(--ta-orange);"></i> Buka di Google Maps
                    </a>
                </div>

                <div class="col-micro-lbl mb-1">Alasan Kemenangan Lengkap</div>
                <div id="winDetAlasan" class="p-3 rounded-3" style="background: rgba(255,255,255,0.88); border: 1px solid rgba(13, 85, 155, 0.14); font-size: 13.5px; color: var(--ta-navy); white-space: pre-line;">-</div>
            </div>

            <div class="px-4 pb-4 pt-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <form method="POST" class="m-0" onsubmit="return confirm('Kembalikan Proyek Win ini ke Pipeline Aktif (Quotation)?');">
                    <input type="hidden" name="aksi_reopen" value="1">
                    <input type="hidden" name="id_lead" id="winReopenId" value="">
                    <button type="submit" class="btn-toolbar-white" style="color: #B45309; font-size: 13px;">
                        <i class="bi bi-arrow-counterclockwise"></i> Kembalikan ke Pipeline Aktif
                    </button>
                </form>
                <button type="button" id="btnWinSwitchEdit" class="btn-toolbar-primary" style="font-size: 13px;">
                    <i class="bi bi-pencil-square"></i> Edit Detail Win
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL EDIT DETAIL WIN (#winEditModal) -->
<div class="modal fade" id="winEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 500px;">
        <div class="modal-content rounded-4 overflow-hidden">
            <div class="px-4 py-3 d-flex align-items-center justify-content-between" style="background: linear-gradient(90deg, rgba(16, 185, 129, 0.2), rgba(236, 253, 245, 0.92));">
                <h5 class="mb-0 fw-bold" style="color: #065F46; font-size: 1.05rem;">Edit Detail Proyek Win</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="aksi_edit_outcome" value="1">
                <input type="hidden" name="id_lead" id="winEditId" value="">
                <div class="modal-body px-4 py-3">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">Indikator Kemenangan <span style="color: var(--ta-orange);">*</span></label>
                        <select id="winEditIndSelect" name="indikator_pilihan" class="form-select" required>
                            <?php foreach ($winIndicators as $wi): ?>
                                <option value="<?= htmlspecialchars($wi) ?>"><?= htmlspecialchars($wi) ?></option>
                            <?php endforeach; ?>
                            <option value="Lainnya">Lainnya (Tulis Indikator Baru)</option>
                        </select>
                    </div>
                    <div class="mb-3 d-none" id="winEditCustomWrap">
                        <label class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">Tulis Indikator Baru <span style="color: var(--ta-orange);">*</span></label>
                        <input type="text" id="winEditCustomInput" name="indikator_kustom" class="form-control" maxlength="120">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold" style="font-size: 13px; color: var(--ta-navy);">Nilai Kontrak (Rp)</label>
                            <input type="text" id="winEditNilai" name="nilai_proyek" class="form-control" placeholder="150.000.000">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold" style="font-size: 13px; color: var(--ta-navy);">Keuntungan Bersih (Rp)</label>
                            <input type="text" id="winEditUntung" name="keuntungan_bersih" class="form-control" placeholder="36.000.000">
                        </div>
                    </div>
                    <div>
                        <label class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">Alasan Lengkap <span style="color: var(--ta-orange);">*</span></label>
                        <textarea id="winEditAlasan" name="alasan_hasil" rows="3" class="form-control" required></textarea>
                    </div>
                </div>
                <div class="px-4 pb-4 pt-2 d-flex justify-content-end gap-2">
                    <button type="button" class="btn-toolbar-white" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-toolbar-primary" style="background: #059669; border-color: #059669;"><i class="bi bi-check-lg"></i> Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', function () {
    const winLookup  = <?= json_encode($winDetailLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const winPresets = <?= json_encode($winIndicators, JSON_UNESCAPED_UNICODE) ?>;
    const detModalEl = document.getElementById('winDetailModal');
    const edtModalEl = document.getElementById('winEditModal');
    if (!detModalEl || !edtModalEl || typeof bootstrap === 'undefined') return;

    const detModal = new bootstrap.Modal(detModalEl);
    const edtModal = new bootstrap.Modal(edtModalEl);
    let currentWin = null;

    function fmtDigits(v) {
        const d = String(v || '').replace(/\D+/g, '');
        return d ? d.replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '';
    }
    ['winEditNilai', 'winEditUntung'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', function () { el.value = fmtDigits(el.value); });
    });

    const selEditInd = document.getElementById('winEditIndSelect');
    const wrapCustom = document.getElementById('winEditCustomWrap');
    const inpCustom  = document.getElementById('winEditCustomInput');
    function syncCustom() {
        const isOther = selEditInd.value === 'Lainnya';
        wrapCustom.classList.toggle('d-none', !isOther);
        inpCustom.required = isOther;
        if (!isOther) inpCustom.value = '';
    }
    selEditInd.addEventListener('change', syncCustom);

    document.querySelectorAll('.win-row-clickable').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('a, button, .no-row-click')) return;
            const item = winLookup[row.getAttribute('data-win-lead-id')];
            if (!item) return;
            currentWin = item;

            document.getElementById('winDetSub').textContent       = `Lead #${item.id_lead} • ${item.tanggal_masuk} • PIC: ${item.nama_pic}`;
            document.getElementById('winDetKontrak').textContent   = item.no_kontrak;
            document.getElementById('winDetIndikator').textContent = item.indikator_hasil;
            document.getElementById('winDetProyek').textContent    = item.nama_proyek_prospek;
            document.getElementById('winDetKlien').textContent     = item.nama_klien;
            const winSvBadge = document.getElementById('winDetSvBadge');
            if (winSvBadge) {
                winSvBadge.className = 'sv-history-badge ' + (item.is_site_visit ? 'is-done' : 'is-none');
                winSvBadge.innerHTML = item.is_site_visit
                    ? '<i class="bi bi-check-lg"></i>' + item.site_visit_label
                    : 'Tanpa Site Visit';
            }
            document.getElementById('winDetNilai').textContent     = item.nilai_proyek_fmt;
            document.getElementById('winDetUntung').textContent    = item.keuntungan_fmt;
            document.getElementById('winDetMargin').textContent    = item.margin_persen !== null ? item.margin_persen.toFixed(1) + '%' : '-';
            document.getElementById('winDetLokasi').textContent    = item.lokasi_proyek || 'Lokasi belum tercatat';
            document.getElementById('winDetAlasan').textContent    = item.alasan_hasil;
            document.getElementById('winReopenId').value           = String(item.id_lead);

            const q = (item.latitude && item.longitude) ? `${item.latitude},${item.longitude}` : item.lokasi_proyek;
            const iframe = document.getElementById('winDetMap');
            const mapBtn = document.getElementById('winDetMapBtn');
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

    document.getElementById('btnWinSwitchEdit').addEventListener('click', function () {
        if (!currentWin) return;
        const item = currentWin;
        detModal.hide();
        setTimeout(function () {
            document.getElementById('winEditId').value     = String(item.id_lead);
            document.getElementById('winEditAlasan').value = item.alasan_hasil;
            document.getElementById('winEditNilai').value  = item.nilai_proyek !== null ? fmtDigits(Math.round(item.nilai_proyek)) : '';
            document.getElementById('winEditUntung').value = item.keuntungan_bersih !== null ? fmtDigits(Math.round(item.keuntungan_bersih)) : '';
            if (winPresets.includes(item.indikator_hasil)) {
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

