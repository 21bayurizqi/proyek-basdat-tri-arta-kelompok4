<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
require_once __DIR__ . '/../config/db.php';
include __DIR__ . '/../layout/header.php';

// 1. Ambil data riil dari tabel leads JOIN staf
$totalLeadsDb = 0;
$stageMap = [
    'New Lead'    => 0,
    'Opportunity' => 0,
    'Site Visit'  => 0,
    'Quotation'   => 0,
    'Win'         => 0,
    'Loss'        => 0,
];
$channelMap = [
    'WhatsApp'     => 0,
    'Kabar Burung' => 0,
    'Relasi'       => 0,
    'Lainnya'      => 0,
];
$picStats    = [];
$monthlyMap  = [];
$lossReasons = [];

try {
    $totalLeadsDb = (int)$pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();

    if ($totalLeadsDb > 0) {
        // a. Distribusi tahapan funnel
        foreach ($pdo->query("SELECT tahapan_pipeline, COUNT(*) AS jml FROM leads GROUP BY tahapan_pipeline")->fetchAll() as $r) {
            if (array_key_exists($r['tahapan_pipeline'], $stageMap)) {
                $stageMap[$r['tahapan_pipeline']] = (int)$r['jml'];
            }
        }

        // b. Distribusi sumber informasi / channel
        foreach ($pdo->query("SELECT COALESCE(sumber_informasi, 'Lainnya') AS sumber, COUNT(*) AS jml FROM leads GROUP BY COALESCE(sumber_informasi, 'Lainnya')")->fetchAll() as $r) {
            $k = array_key_exists($r['sumber'], $channelMap) ? $r['sumber'] : 'Lainnya';
            $channelMap[$k] += (int)$r['jml'];
        }

        // c. Tren bulanan (6 bulan terakhir)
        $mRows = $pdo->query("
            SELECT TO_CHAR(DATE_TRUNC('month', tanggal_masuk), 'YYYY-MM') AS ym,
                   COUNT(*) AS total_masuk,
                   SUM(CASE WHEN tahapan_pipeline = 'Win' THEN 1 ELSE 0 END) AS total_win
            FROM leads
            WHERE tanggal_masuk >= DATE_TRUNC('month', CURRENT_DATE) - INTERVAL '5 months'
            GROUP BY ym
            ORDER BY ym ASC
        ")->fetchAll();
        foreach ($mRows as $mr) {
            $monthlyMap[$mr['ym']] = [
                'masuk' => (int)$mr['total_masuk'],
                'win'   => (int)$mr['total_win'],
            ];
        }

        // d. Performa & beban kerja PIC Sales
        $picRows = $pdo->query("
            SELECT COALESCE(s.nama, 'Belum Ditentukan') AS nama_pic,
                   SUM(CASE WHEN l.tahapan_pipeline NOT IN ('Win', 'Loss') THEN 1 ELSE 0 END) AS jml_aktif,
                   SUM(CASE WHEN l.tahapan_pipeline = 'Win' THEN 1 ELSE 0 END) AS jml_win,
                   SUM(CASE WHEN l.tahapan_pipeline = 'Loss' THEN 1 ELSE 0 END) AS jml_loss
            FROM leads l
            LEFT JOIN staf s ON l.id_staf_pic = s.id_staf
            GROUP BY COALESCE(s.nama, 'Belum Ditentukan')
            ORDER BY (COUNT(*)) DESC, nama_pic ASC
            LIMIT 8
        ")->fetchAll();
        foreach ($picRows as $pr) {
            $picStats[] = [
                'nama'  => $pr['nama_pic'],
                'aktif' => (int)$pr['jml_aktif'],
                'win'   => (int)$pr['jml_win'],
                'loss'  => (int)$pr['jml_loss'],
            ];
        }

        // e. Analisis penyebab gagal / Loss Reasons
        $lossRows = $pdo->query("
            SELECT COALESCE(NULLIF(TRIM(alasan_loss), ''), 'Alasan Belum Dicatat') AS alasan,
                   COUNT(*) AS jml
            FROM leads
            WHERE tahapan_pipeline = 'Loss'
            GROUP BY COALESCE(NULLIF(TRIM(alasan_loss), ''), 'Alasan Belum Dicatat')
            ORDER BY jml DESC, alasan ASC
            LIMIT 6
        ")->fetchAll();
        foreach ($lossRows as $lr) {
            $lossReasons[$lr['alasan']] = (int)$lr['jml'];
        }
    }
} catch (PDOException $e) {
    $totalLeadsDb = 0;
}

// Fallback proporsional apabila tabel leads masih kosong / sangat minim (< 3 data)
$isSimulated = ($totalLeadsDb < 3);

if ($isSimulated) {
    $stageMap = [
        'New Lead'    => 14,
        'Opportunity' => 10,
        'Site Visit'  => 7,
        'Quotation'   => 5,
        'Win'         => 9,
        'Loss'        => 4,
    ];
    $channelMap = [
        'WhatsApp'     => 19,
        'Relasi'       => 15,
        'Kabar Burung' => 9,
        'Lainnya'      => 6,
    ];
}

// Hitung 4 KPI Utama
$kpiTotalLeads = array_sum($stageMap);
$kpiWin        = $stageMap['Win'];
$kpiLoss       = $stageMap['Loss'];
$kpiClosed     = $kpiWin + $kpiLoss;
$kpiWinRate    = $kpiClosed > 0 ? (int)round(($kpiWin / $kpiClosed) * 100) : 0;
$kpiAktifJalan = $stageMap['Opportunity'] + $stageMap['Site Visit'] + $stageMap['Quotation'];

// Data Grafik 1: Sales Funnel Conversion
$funnelLabels = ['New Lead', 'Opportunity', 'Site Visit', 'Quotation', 'Win'];
$funnelValues = [
    $stageMap['New Lead'],
    $stageMap['Opportunity'],
    $stageMap['Site Visit'],
    $stageMap['Quotation'],
    $stageMap['Win'],
];

// Data Grafik 2: Channel Effectiveness
$channelLabels = array_keys($channelMap);
$channelValues = array_values($channelMap);

// Data Grafik 3: Tren 6 Bulan Terakhir (Leads Masuk vs Proyek Win)
$monthNames = [
    '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
    '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu',
    '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des',
];
$trendLabels = [];
$trendMasuk  = [];
$trendWin    = [];
$simMasuk    = [5, 7, 6, 9, 10, 12];
$simWin      = [1, 2, 1, 2, 2, 3];

for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-$i months");
    $ym = date('Y-m', $ts);
    $label = ($monthNames[date('m', $ts)] ?? date('M', $ts)) . ' ' . date('y', $ts);
    $trendLabels[] = $label;

    if ($isSimulated) {
        $idx = 5 - $i;
        $trendMasuk[] = $simMasuk[$idx];
        $trendWin[]   = $simWin[$idx];
    } else {
        $trendMasuk[] = $monthlyMap[$ym]['masuk'] ?? 0;
        $trendWin[]   = $monthlyMap[$ym]['win'] ?? 0;
    }
}

// Data Grafik 4: Performa & Beban Kerja PIC Sales (Stacked Bar)
if ($isSimulated || count($picStats) === 0) {
    $stafSample = $pdo->query("SELECT nama FROM staf ORDER BY id_staf ASC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
    if (count($stafSample) < 3) {
        $stafSample = ['Dofa Mairika', 'Taufan Kusuma', 'Rizky Pratama', 'Ismail Sahid'];
    }
    $simPicData = [
        ['aktif' => 7, 'win' => 4, 'loss' => 1],
        ['aktif' => 6, 'win' => 3, 'loss' => 1],
        ['aktif' => 5, 'win' => 1, 'loss' => 1],
        ['aktif' => 4, 'win' => 1, 'loss' => 1],
    ];
    $picStats = [];
    foreach ($stafSample as $idx => $nm) {
        $d = $simPicData[$idx % count($simPicData)];
        $picStats[] = ['nama' => $nm, 'aktif' => $d['aktif'], 'win' => $d['win'], 'loss' => $d['loss']];
    }
}
$picLabels = array_map(fn($p) => $p['nama'], $picStats);
$picAktif  = array_map(fn($p) => $p['aktif'], $picStats);
$picWin    = array_map(fn($p) => $p['win'], $picStats);
$picLoss   = array_map(fn($p) => $p['loss'], $picStats);

// Data Grafik 5: Analisis Penyebab Proyek Gagal (Loss Reasons)
$isLossSimulated = ($isSimulated || count($lossReasons) === 0);
if ($isLossSimulated) {
    $lossReasons = [
        'Harga Penawaran'    => 5,
        'Kalah Tender'       => 3,
        'Proyek Ditunda'     => 2,
        'Spesifikasi Teknis' => 2,
    ];
}
$lossLabels = array_keys($lossReasons);
$lossValues = array_values($lossReasons);
?>

<style>
    .crm-kpi-card {
        border-radius: 16px;
        padding: 1.35rem 1.45rem;
        height: 100%;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    }
    .crm-kpi-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }
    .crm-kpi-val {
        font-size: 1.85rem;
        font-weight: 800;
        color: var(--ta-navy);
        line-height: 1.15;
        letter-spacing: -0.02em;
        margin-top: 0.5rem;
    }
    .crm-analytics-card {
        border-radius: 16px;
        padding: 1.45rem 1.55rem;
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    .crm-card-title {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--ta-navy);
        margin: 0;
    }
    .crm-card-sub {
        font-size: 13.5px;
        color: var(--text-muted);
        margin-top: 3px;
    }
    .crm-chart-box {
        position: relative;
        width: 100%;
        height: 270px;
        min-height: 270px;
        max-height: 270px;
        margin-top: 1rem;
    }
    .crm-chart-box.tall {
        height: 290px;
        min-height: 290px;
        max-height: 290px;
    }
    .channel-center-kpi {
        position: absolute;
        top: 45%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        pointer-events: none;
    }
</style>

<header class="saas-topbar">
    <div>
        <h1 class="saas-title">Analitik &amp; Rangkuman CRM</h1>
        <div class="text-muted" style="font-size: 14px;">Analisis mendalam konversi sales funnel, efektivitas channel, tren bulanan, beban kerja PIC, &amp; evaluasi proyek gagal</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="/leads/index.php" class="btn-toolbar-primary">
            <i class="bi bi-kanban"></i> Kelola Pipeline
        </a>
        <a href="/leads/create.php" class="btn-toolbar-orange">
            <i class="bi bi-plus-lg"></i> Tambah Lead
        </a>
    </div>
</header>

<main class="p-4">
    <?php if ($isSimulated): ?>
        <div class="alert py-2 px-3 mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background: linear-gradient(90deg, rgba(255, 243, 230, 0.92), rgba(235, 243, 253, 0.92)); border: 1px solid rgba(243, 130, 24, 0.35); border-radius: 12px; font-size: 14px; color: var(--ta-navy);">
            <span>
                <i class="bi bi-info-circle-fill me-2" style="color: var(--ta-orange);"></i>
                Data lead di PostgreSQL saat ini berjumlah <strong><?= $totalLeadsDb ?></strong> — grafik menampilkan <strong>proyeksi simulasi proporsional</strong> dan akan otomatis beralih ke mode <em>Live PostgreSQL</em> setelah tersedia minimal 3 lead.
            </span>
            <a href="/leads/create.php" class="fw-semibold text-decoration-none" style="color: var(--ta-orange);">Input Lead Sekarang &rarr;</a>
        </div>
    <?php endif; ?>

    <!-- 4 KARTU KPI UTAMA -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card crm-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <div class="stat-lbl mb-0">Total Leads Masuk</div>
                        <div class="crm-kpi-val"><?= $kpiTotalLeads ?></div>
                    </div>
                    <div class="crm-kpi-icon" style="background: rgba(6, 42, 78, 0.1); color: var(--ta-navy);">
                        <i class="bi bi-people-fill"></i>
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-between mt-3">
                    <span class="col-micro-lbl mb-0">Seluruh prospek tercatat</span>
                    <span class="badge-pill-soft" style="<?= $isSimulated ? 'background: #FFF7ED; color: var(--ta-orange);' : 'background: #ECFDF5; color: var(--ta-green);' ?>">
                        <?= $isSimulated ? 'Simulasi' : 'Live CRM' ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card crm-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <div class="stat-lbl mb-0">Win Rate Proyek</div>
                        <div class="crm-kpi-val" style="color: var(--ta-green);"><?= $kpiWinRate ?>%</div>
                    </div>
                    <div class="crm-kpi-icon" style="background: rgba(19, 94, 56, 0.12); color: var(--ta-green);">
                        <i class="bi bi-trophy-fill"></i>
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-between mt-3">
                    <span class="col-micro-lbl mb-0"><?= $kpiWin ?> Win dari <?= $kpiClosed ?> Closed</span>
                    <span class="badge-pill-soft" style="background: #ECFDF5; color: var(--ta-green);">Konversi</span>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card crm-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <div class="stat-lbl mb-0">Proyek Aktif Berjalan</div>
                        <div class="crm-kpi-val" style="color: var(--ta-blue);"><?= $kpiAktifJalan ?></div>
                    </div>
                    <div class="crm-kpi-icon" style="background: rgba(13, 85, 155, 0.12); color: var(--ta-blue);">
                        <i class="bi bi-kanban-fill"></i>
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-between mt-3">
                    <span class="col-micro-lbl mb-0">Opportunity &bull; Site Visit &bull; Quotation</span>
                </div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="card crm-kpi-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <div class="stat-lbl mb-0">Total Proyek Loss</div>
                        <div class="crm-kpi-val" style="color: #DC2626;"><?= $kpiLoss ?></div>
                    </div>
                    <div class="crm-kpi-icon" style="background: rgba(220, 38, 38, 0.1); color: #DC2626;">
                        <i class="bi bi-x-octagon-fill"></i>
                    </div>
                </div>
                <div class="d-flex align-items-center justify-content-between mt-3">
                    <span class="col-micro-lbl mb-0">Bahan evaluasi tender gagal</span>
                    <span class="badge-pill-red"><?= $kpiClosed > 0 ? (100 - $kpiWinRate) : 0 ?>% Loss</span>
                </div>
            </div>
        </div>
    </div>

    <!-- BARIS GRAFIK 1: Sales Funnel Conversion (7 col) + Efektivitas Channel (5 col) -->
    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="analytics-card crm-analytics-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <h2 class="crm-card-title">Sales Funnel Conversion</h2>
                        <div class="crm-card-sub">Perbandingan jumlah prospek di tiap tahapan (New Lead &rarr; Win)</div>
                    </div>
                    <span class="badge-pill-soft" style="background: #FFF7ED; color: var(--ta-orange);">Funnel</span>
                </div>
                <div class="crm-chart-box">
                    <canvas id="chartFunnelConv"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="analytics-card crm-analytics-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <h2 class="crm-card-title">Efektivitas Sumber Lead (Channel)</h2>
                        <div class="crm-card-sub">Distribusi asal informasi prospek proyek</div>
                    </div>
                    <span class="badge-pill-soft" style="background: #EFF6FF; color: var(--ta-blue);">Channel</span>
                </div>
                <div class="crm-chart-box d-flex align-items-center justify-content-center">
                    <canvas id="chartLeadChannel"></canvas>
                    <div class="channel-center-kpi">
                        <div style="font-size: 1.55rem; font-weight: 800; color: var(--ta-navy); line-height: 1.1;"><?= array_sum($channelValues) ?></div>
                        <div style="font-size: 11.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Prospek</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- BARIS GRAFIK 2: Tren Leads Masuk vs Proyek Win Bulanan (Full-Width Multi-Line) -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="analytics-card crm-analytics-card">
                <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                    <div>
                        <h2 class="crm-card-title">Tren Leads Masuk vs Proyek Win Bulanan</h2>
                        <div class="crm-card-sub">Pergerakan volume prospek masuk dibanding proyek yang berhasil dimenangkan dalam 6 bulan terakhir</div>
                    </div>
                    <span class="badge-pill-soft" style="background: #ECFDF5; color: var(--ta-green);">6 Bulan Terakhir</span>
                </div>
                <div class="crm-chart-box tall">
                    <canvas id="chartMonthlyTrend"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- BARIS GRAFIK 3: Performa PIC Sales (Stacked Bar) + Analisis Penyebab Loss (Horizontal Bar) -->
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="analytics-card crm-analytics-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <h2 class="crm-card-title">Performa &amp; Beban Kerja PIC Sales</h2>
                        <div class="crm-card-sub">Komposisi leads aktif, proyek Win, dan Loss per staf penanggung jawab</div>
                    </div>
                    <span class="badge-pill-soft" style="background: #EFF6FF; color: var(--ta-navy);">PIC Sales</span>
                </div>
                <div class="crm-chart-box tall">
                    <canvas id="chartPicWorkload"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="analytics-card crm-analytics-card">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <h2 class="crm-card-title">Analisis Penyebab Proyek Gagal (Loss Reasons)</h2>
                        <div class="crm-card-sub">Rekap faktor utama kegagalan penawaran / tender dari kolom <code>alasan_loss</code></div>
                    </div>
                    <span class="badge-pill-red"><?= $isLossSimulated ? 'Simulasi' : 'Evaluasi' ?></span>
                </div>
                <div class="crm-chart-box tall">
                    <canvas id="chartLossReasons"></canvas>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, sans-serif";
    Chart.defaults.font.size = 13;
    Chart.defaults.color = '#475569';

    const commonTooltip = {
        backgroundColor: '#062A4E',
        titleColor: '#FFFFFF',
        bodyColor: '#F1F5F9',
        padding: 11,
        cornerRadius: 9,
        displayColors: true,
    };

    // a. Sales Funnel Conversion (Horizontal Bar Chart)
    const ctxFunnel = document.getElementById('chartFunnelConv');
    if (ctxFunnel) {
        const funnelData = <?= json_encode($funnelValues) ?>;
        const baseCount = Math.max(1, funnelData[0] || 1);
        new Chart(ctxFunnel, {
            type: 'bar',
            data: {
                labels: <?= json_encode($funnelLabels) ?>,
                datasets: [{
                    label: 'Jumlah Prospek',
                    data: funnelData,
                    backgroundColor: ['#062A4E', '#0D559B', '#2B78C5', '#F38218', '#135E38'],
                    borderRadius: 9,
                    barThickness: 26,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...commonTooltip,
                        callbacks: {
                            label: function (ctx) {
                                const ratio = Math.round((ctx.parsed.x / baseCount) * 100);
                                return ` ${ctx.parsed.x} prospek (${ratio}% terhadap New Lead)`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { stepSize: 2, precision: 0 },
                        grid: { color: 'rgba(13, 85, 155, 0.08)' },
                        border: { display: false }
                    },
                    y: {
                        ticks: { color: '#062A4E', font: { weight: '600' } },
                        grid: { display: false },
                        border: { display: false }
                    }
                }
            }
        });
    }

    // b. Efektivitas Sumber Lead / Channel (Doughnut Chart)
    const ctxChannel = document.getElementById('chartLeadChannel');
    if (ctxChannel) {
        new Chart(ctxChannel, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($channelLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    data: <?= json_encode($channelValues) ?>,
                    backgroundColor: ['#135E38', '#F38218', '#0D559B', '#64748B'],
                    borderWidth: 3,
                    borderColor: 'rgba(244, 248, 253, 0.95)',
                    hoverOffset: 7,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '66%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'circle', padding: 14, color: '#062A4E', font: { size: 12.5, weight: '500' } }
                    },
                    tooltip: {
                        ...commonTooltip,
                        callbacks: {
                            label: function (ctx) {
                                const tot = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = tot > 0 ? Math.round((ctx.parsed / tot) * 100) : 0;
                                return ` ${ctx.label}: ${ctx.parsed} prospek (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // c. Tren Leads Masuk vs Proyek Win Bulanan (Multi-Line Chart)
    const ctxTrend = document.getElementById('chartMonthlyTrend');
    if (ctxTrend) {
        new Chart(ctxTrend, {
            type: 'line',
            data: {
                labels: <?= json_encode($trendLabels) ?>,
                datasets: [
                    {
                        label: 'Leads Masuk',
                        data: <?= json_encode($trendMasuk) ?>,
                        borderColor: '#0D559B',
                        backgroundColor: 'rgba(13, 85, 155, 0.14)',
                        fill: true,
                        tension: 0.35,
                        borderWidth: 3,
                        pointRadius: 5,
                        pointBackgroundColor: '#0D559B',
                        pointBorderColor: '#FFFFFF',
                        pointBorderWidth: 2,
                    },
                    {
                        label: 'Proyek Win',
                        data: <?= json_encode($trendWin) ?>,
                        borderColor: '#F38218',
                        backgroundColor: 'rgba(243, 130, 24, 0.16)',
                        fill: true,
                        tension: 0.35,
                        borderWidth: 3,
                        pointRadius: 5,
                        pointBackgroundColor: '#F38218',
                        pointBorderColor: '#FFFFFF',
                        pointBorderWidth: 2,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, color: '#062A4E', font: { weight: '600' } }
                    },
                    tooltip: commonTooltip
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 2, precision: 0 },
                        grid: { color: 'rgba(13, 85, 155, 0.08)' },
                        border: { display: false }
                    },
                    x: {
                        ticks: { color: '#062A4E', font: { weight: '600' } },
                        grid: { display: false },
                        border: { display: false }
                    }
                }
            }
        });
    }

    // d. Performa & Beban Kerja PIC Sales (Stacked Bar Chart)
    const ctxPic = document.getElementById('chartPicWorkload');
    if (ctxPic) {
        new Chart(ctxPic, {
            type: 'bar',
            data: {
                labels: <?= json_encode($picLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [
                    {
                        label: 'Leads Aktif',
                        data: <?= json_encode($picAktif) ?>,
                        backgroundColor: '#0D559B',
                        borderRadius: 6,
                    },
                    {
                        label: 'Win',
                        data: <?= json_encode($picWin) ?>,
                        backgroundColor: '#135E38',
                        borderRadius: 6,
                    },
                    {
                        label: 'Loss',
                        data: <?= json_encode($picLoss) ?>,
                        backgroundColor: '#EF4444',
                        borderRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'circle', padding: 14, color: '#062A4E', font: { weight: '500' } }
                    },
                    tooltip: commonTooltip
                },
                scales: {
                    x: {
                        stacked: true,
                        ticks: { color: '#062A4E', font: { weight: '600' } },
                        grid: { display: false },
                        border: { display: false }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        ticks: { stepSize: 2, precision: 0 },
                        grid: { color: 'rgba(13, 85, 155, 0.08)' },
                        border: { display: false }
                    }
                }
            }
        });
    }

    // e. Analisis Penyebab Proyek Gagal / Loss Reasons (Horizontal Bar Chart)
    const ctxLoss = document.getElementById('chartLossReasons');
    if (ctxLoss) {
        new Chart(ctxLoss, {
            type: 'bar',
            data: {
                labels: <?= json_encode($lossLabels, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{
                    label: 'Jumlah Proyek Loss',
                    data: <?= json_encode($lossValues) ?>,
                    backgroundColor: ['#DC2626', '#F38218', '#B45309', '#64748B', '#062A4E', '#0D559B'],
                    borderRadius: 8,
                    barThickness: 24,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...commonTooltip,
                        callbacks: {
                            label: function (ctx) {
                                return ` ${ctx.parsed.x} proyek gagal karena ${ctx.label}`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, precision: 0 },
                        grid: { color: 'rgba(13, 85, 155, 0.08)' },
                        border: { display: false }
                    },
                    y: {
                        ticks: { color: '#062A4E', font: { weight: '600' } },
                        grid: { display: false },
                        border: { display: false }
                    }
                }
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>

