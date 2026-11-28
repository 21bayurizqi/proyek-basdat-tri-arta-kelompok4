<?php
require_once __DIR__ . '/config/auth.php';
requireAuth();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/app_helpers.php';
ensureLeadsExtraColumns($pdo);
include __DIR__ . '/layout/header.php';

// 1. Data SCM / Warehouse (Live PostgreSQL)
$totalMaterial = (int)$pdo->query("SELECT COUNT(*) FROM material")->fetchColumn();
$totalUnitAll  = (int)$pdo->query("SELECT COALESCE(SUM(total_stok), 0) FROM material")->fetchColumn();
$pendingKeluar = (int)$pdo->query("SELECT COUNT(*) FROM barang_keluar WHERE status_approval = 'Pending Approval'")->fetchColumn();

$restockTop5 = $pdo->query("
    SELECT id_material, nama_material, kategori, merek, total_stok, batas_minimum, satuan, gambar, foto_data,
           (batas_minimum - total_stok) AS jumlah_harus_restock
    FROM material
    WHERE total_stok < batas_minimum
    ORDER BY jumlah_harus_restock DESC, id_material ASC
    LIMIT 5
")->fetchAll();

$lowStockCount   = (int)$pdo->query("SELECT COUNT(*) FROM material WHERE total_stok < batas_minimum")->fetchColumn();
$safeStockCount  = max(0, $totalMaterial - $lowStockCount);
$hasMaterialData = ($totalMaterial > 0);
$stockHealthPct  = $totalMaterial > 0 ? (int)round(($safeStockCount / $totalMaterial) * 100) : 0;

// 2. Distribusi Kategori Material (Live PostgreSQL)
$katRows = $pdo->query("
    SELECT kategori, COUNT(*) AS jml, COALESCE(SUM(total_stok), 0) AS total_unit
    FROM material
    GROUP BY kategori
    ORDER BY jml DESC, kategori ASC
")->fetchAll();

$katLabels   = array_column($katRows, 'kategori');
$katCounts   = array_map('intval', array_column($katRows, 'jml'));
$katUnits    = array_map('intval', array_column($katRows, 'total_unit'));
$hasKatData  = ($totalMaterial > 0 && array_sum($katCounts) > 0);

// 3. Data CRM / Leads Pipeline & Win/Loss Ratio (Live PostgreSQL)
$funnelStages = ['New Lead', 'Opportunity', 'Quotation'];
$stageCounts  = [
    'New Lead'    => 0,
    'Opportunity' => 0,
    'Quotation'   => 0,
    'Win'         => 0,
    'Loss'        => 0,
];

try {
    $leadRows = $pdo->query("
        SELECT tahapan_pipeline, COUNT(*) AS jml
        FROM leads
        GROUP BY tahapan_pipeline
    ")->fetchAll();

    foreach ($leadRows as $lr) {
        $st = $lr['tahapan_pipeline'];
        $cn = (int)$lr['jml'];
        if (array_key_exists($st, $stageCounts)) {
            $stageCounts[$st] = $cn;
        }
    }
} catch (PDOException $e) {
    // Biarkan 0 agar menampilkan empty state dengan aman
}

$funnelCounts = [
    $stageCounts['New Lead'],
    $stageCounts['Opportunity'],
    $stageCounts['Quotation'],
];
$leadsAktif  = array_sum($funnelCounts);
$leadsWin    = $stageCounts['Win'];
$leadsLoss   = $stageCounts['Loss'];
$leadsClosed = $leadsWin + $leadsLoss;
$winRatePct  = $leadsClosed > 0 ? (int)round(($leadsWin / $leadsClosed) * 100) : 0;
$hasPipelineData = ($leadsAktif > 0);
$hasWinLossData  = ($leadsClosed > 0);
?>

<style>
    .analytics-card {
        border-radius: 16px;
        padding: 1.35rem 1.5rem;
        height: auto;
        display: flex;
        flex-direction: column;
        transition: box-shadow 0.2s ease, border-color 0.2s ease;
    }
    .analytics-card:hover {
        box-shadow: 0 10px 26px rgba(6, 42, 78, 0.09) !important;
        border-color: rgba(13, 85, 155, 0.28) !important;
    }
    .analytics-card-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 1.15rem;
    }
    .analytics-card-title {
        font-size: 0.96rem;
        font-weight: 700;
        color: var(--ta-navy);
        margin: 0;
        letter-spacing: -0.01em;
    }
    .analytics-card-sub {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 2px;
    }
    .chart-canvas-wrap {
        position: relative;
        width: 100%;
        height: 250px;
        min-height: 250px;
        max-height: 250px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .chart-canvas-wrap canvas {
        max-width: 100%;
        max-height: 100%;
    }
    .chart-empty-state {
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 1.25rem 1.5rem;
        border-radius: 14px;
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.56) 0%, rgba(235, 243, 252, 0.45) 100%);
        border: 1px dashed rgba(13, 85, 155, 0.18);
    }
    .chart-empty-icon {
        width: 54px;
        height: 54px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        margin-bottom: 0.75rem;
        flex-shrink: 0;
    }
    .chart-empty-icon.theme-orange {
        background: rgba(242, 101, 34, 0.12);
        color: #F26522;
        border: 1px solid rgba(242, 101, 34, 0.2);
    }
    .chart-empty-icon.theme-navy {
        background: rgba(13, 85, 155, 0.12);
        color: var(--ta-blue);
        border: 1px solid rgba(13, 85, 155, 0.2);
    }
    .chart-empty-icon.theme-green {
        background: rgba(16, 185, 129, 0.12);
        color: #059669;
        border: 1px solid rgba(16, 185, 129, 0.22);
    }
    .chart-empty-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--ta-navy);
        margin-bottom: 0.3rem;
    }
    .chart-empty-desc {
        font-size: 13px;
        color: var(--text-muted);
        max-width: 340px;
        line-height: 1.45;
        margin-bottom: 0;
    }
    .doughnut-center-kpi {
        position: absolute;
        top: 46%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        pointer-events: none;
    }
    .doughnut-center-val {
        font-size: 1.55rem;
        font-weight: 700;
        color: var(--ta-navy);
        line-height: 1.1;
    }
    .doughnut-center-lbl {
        font-size: 0.69rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .restock-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.75rem 0.95rem;
        border: 1px solid rgba(243, 130, 24, 0.32);
        background: linear-gradient(135deg, rgba(255, 246, 236, 0.85) 0%, rgba(241, 246, 253, 0.85) 100%);
        border-radius: 11px;
        transition: border-color 0.15s ease, background 0.15s ease;
    }
    .restock-row:hover {
        border-color: var(--ta-orange);
        background: linear-gradient(135deg, rgba(255, 241, 224, 0.95) 0%, rgba(235, 243, 252, 0.95) 100%);
    }
</style>

<header class="saas-topbar">
    <div>
        <h1 class="saas-title">Executive Summary</h1>
        <div class="text-muted" style="font-size: 0.8rem;">Dashboard analitik inventaris gudang (SCM) &amp; performa pipeline proyek (CRM)</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="/barang/index.php" class="btn-toolbar-white"><i class="bi bi-box-seam"></i> Warehouse SCM</a>
        <a href="/leads/index.php" class="btn-toolbar-primary"><i class="bi bi-speedometer2"></i> CRM Leads</a>
        <a href="/transaksi_qr/index.php" class="btn-toolbar-orange"><i class="bi bi-qr-code-scan"></i> Scan &amp; Barang Keluar</a>
    </div>
</header>

<main class="p-4">
    <!-- Ringkasan KPI Cepat (5 Kolom) -->
    <div class="stat-box-card mb-4" style="border-radius: 16px; box-shadow: 0 4px 20px rgba(6, 42, 78, 0.04);">
        <div class="row g-3">
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Total Material</div>
                <div class="stat-val"><?= $totalMaterial ?> <small>Jenis</small></div>
            </div>
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Total Stok Gudang</div>
                <div class="stat-val" style="color: var(--ta-blue);"><?= number_format($totalUnitAll) ?> <small>Unit</small></div>
            </div>
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Barang Low Stock</div>
                <div class="stat-val" style="color: <?= $lowStockCount > 0 ? '#DC2626' : 'var(--ta-green)' ?>;"><?= $lowStockCount ?> <small>Kritis</small></div>
            </div>
            <div class="col-6 col-md stat-col-item">
                <div class="stat-lbl">Leads Aktif</div>
                <div class="stat-val" style="color: var(--ta-orange);"><?= $leadsAktif ?> <small>Prospek</small></div>
            </div>
            <div class="col-12 col-md stat-col-item">
                <div class="stat-lbl">Win Rate Proyek</div>
                <div class="stat-val" style="color: var(--ta-green);"><?= $winRatePct ?>% <small>(<?= $leadsWin ?> Win / <?= $leadsLoss ?> Loss)</small></div>
            </div>
        </div>
    </div>

    <!-- Grid 4 Grafik Analitik (2x2 di layar besar) -->
    <div class="row g-4 mb-4">
        <!-- CHART 1: Kesehatan Stok Material (Doughnut) -->
        <div class="col-lg-6">
            <div class="analytics-card h-100">
                <div class="analytics-card-head">
                    <div>
                        <h2 class="analytics-card-title">Kesehatan Stok Material</h2>
                        <div class="analytics-card-sub">Perbandingan porsi material stok aman vs kritis (low stock)</div>
                    </div>
                    <span class="badge-pill-soft" style="background: #ECFDF5; color: var(--ta-green);">
                        <?= $safeStockCount ?> Aman &bull; <?= $lowStockCount ?> Kritis
                    </span>
                </div>
                <div class="chart-canvas-wrap">
                    <?php if ($hasMaterialData): ?>
                        <canvas id="chartStockHealth"></canvas>
                        <div class="doughnut-center-kpi">
                            <div class="doughnut-center-val"><?= $stockHealthPct ?>%</div>
                            <div class="doughnut-center-lbl">Stok Aman</div>
                        </div>
                    <?php else: ?>
                        <div class="chart-empty-state">
                            <div class="chart-empty-icon theme-navy"><i class="bi bi-box-seam"></i></div>
                            <div class="chart-empty-title">Belum Ada Data Material Gudang</div>
                            <p class="chart-empty-desc">Tambahkan data material gudang untuk memantau kesehatan stok secara real-time.</p>
                            <a href="/barang/index.php" class="btn-toolbar-primary mt-3" style="font-size: 12.5px; padding: 0.38rem 0.85rem;">
                                <i class="bi bi-box-seam"></i> Kelola Material
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- CHART 2: Distribusi Kategori Material (Horizontal Bar) -->
        <div class="col-lg-6">
            <div class="analytics-card h-100">
                <div class="analytics-card-head">
                    <div>
                        <h2 class="analytics-card-title">Distribusi Kategori Material</h2>
                        <div class="analytics-card-sub">Jumlah jenis material terdaftar per kategori gudang</div>
                    </div>
                    <a href="/barang/index.php" class="text-decoration-none fw-semibold" style="font-size: 0.76rem; color: var(--ta-blue);">
                        Detail &rarr;
                    </a>
                </div>
                <div class="chart-canvas-wrap">
                    <?php if ($hasKatData): ?>
                        <canvas id="chartCategoryDist"></canvas>
                    <?php else: ?>
                        <div class="chart-empty-state">
                            <div class="chart-empty-icon theme-navy"><i class="bi bi-tags-fill"></i></div>
                            <div class="chart-empty-title">Belum Ada Data Material Gudang</div>
                            <p class="chart-empty-desc">Distribusi kategori akan tampil secara otomatis setelah material terdaftar di gudang.</p>
                            <a href="/barang/index.php" class="btn-toolbar-white mt-3" style="font-size: 12.5px; padding: 0.38rem 0.85rem;">
                                <i class="bi bi-collection"></i> Lihat Master Material
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- CHART 3: Sales Pipeline & Leads (Vertical Bar) -->
        <div class="col-lg-6">
            <div class="analytics-card h-100">
                <div class="analytics-card-head">
                    <div>
                        <h2 class="analytics-card-title">Sales Pipeline &amp; Leads</h2>
                        <div class="analytics-card-sub">Funnel jumlah prospek proyek berdasarkan tahapan aktif</div>
                    </div>
                    <span class="badge-pill-soft" style="<?= $hasPipelineData ? 'background: #ECFDF5; color: var(--ta-green);' : 'background: #FFF7ED; color: var(--ta-orange);' ?>">
                        <?= $hasPipelineData ? 'Live CRM' : '0 Prospek Aktif' ?>
                    </span>
                </div>
                <div class="chart-canvas-wrap">
                    <?php if ($hasPipelineData): ?>
                        <canvas id="chartSalesPipeline"></canvas>
                    <?php else: ?>
                        <div class="chart-empty-state">
                            <div class="chart-empty-icon theme-orange"><i class="bi bi-funnel-fill"></i></div>
                            <div class="chart-empty-title">Belum Ada Prospek Aktif Saat Ini</div>
                            <p class="chart-empty-desc">Seluruh prospek sebelumnya sudah selesai (Win/Loss) atau belum ada lead baru yang masuk ke pipeline.</p>
                            <a href="/leads/index.php?open=tambah_lead" class="btn-toolbar-orange mt-3" style="font-size: 12.5px; padding: 0.38rem 0.9rem;">
                                <i class="bi bi-plus-lg"></i> Tambah Lead Baru
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- CHART 4: Win/Loss Ratio (Pie Chart) -->
        <div class="col-lg-6">
            <div class="analytics-card h-100">
                <div class="analytics-card-head">
                    <div>
                        <h2 class="analytics-card-title">Win/Loss Ratio</h2>
                        <div class="analytics-card-sub">Rasio persentase proyek yang berhasil dimenangkan vs gagal</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <a href="/leads/index.php" class="text-decoration-none fw-semibold" style="font-size: 0.76rem; color: var(--ta-orange);">
                            CRM Pipeline &rarr;
                        </a>
                    </div>
                </div>
                <div class="chart-canvas-wrap">
                    <?php if ($hasWinLossData): ?>
                        <canvas id="chartWinLoss"></canvas>
                    <?php else: ?>
                        <div class="chart-empty-state">
                            <div class="chart-empty-icon theme-green"><i class="bi bi-trophy-fill"></i></div>
                            <div class="chart-empty-title">Belum Ada Riwayat Win / Loss</div>
                            <p class="chart-empty-desc">Rasio kemenangan akan muncul setelah ada prospek yang ditutup menjadi Win atau Loss.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Widget Call-to-Action: Prioritas Restock (Top 5 Low Stock) -->
    <div class="analytics-card">
        <div class="analytics-card-head mb-3">
            <div class="d-flex align-items-center gap-2">
                <div class="prod-thumb" style="width: 38px; height: 38px; font-size: 1rem; background: #FFF7ED; color: var(--ta-orange); border-color: #FED7AA;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <div>
                    <h2 class="analytics-card-title">Prioritas Restock Gudang (Top 5 Low Stock)</h2>
                    <div class="analytics-card-sub">Material dengan stok di bawah batas minimum yang memerlukan tindakan restock segera</div>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($pendingKeluar > 0): ?>
                    <a href="/transaksi_qr/index.php" class="badge-pill-red text-decoration-none py-1 px-2">
                        <?= $pendingKeluar ?> Pending QR
                    </a>
                <?php endif; ?>
                <a href="/barang/index.php?sort=stok_asc" class="btn-toolbar-white" style="font-size: 0.78rem; padding: 0.35rem 0.75rem;">
                    Lihat Semua <i class="bi bi-arrow-right"></i>
                </a>
            </div>
        </div>

        <?php if (count($restockTop5) === 0): ?>
            <div class="px-3 py-3 rounded-3 d-flex align-items-center gap-2" style="background: #ECFDF5; color: var(--ta-green); font-size: 0.83rem;">
                <i class="bi bi-check-circle-fill"></i>
                <span>Seluruh material gudang berada di atas batas minimum restock.</span>
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-2">
                <?php foreach ($restockTop5 as $r): ?>
                    <div class="restock-row flex-wrap">
                        <div class="d-flex align-items-center gap-3 min-w-0">
                            <?php
                            $rImgSrc = resolveMaterialImageSrc($r['gambar'] ?? null, $r['foto_data'] ?? null, __DIR__);
                            if ($rImgSrc !== ''):
                            ?>
                                <img src="<?= htmlspecialchars($rImgSrc) ?>" alt="<?= htmlspecialchars($r['nama_material']) ?>" style="width: 46px; height: 46px; object-fit: cover; border-radius: 10px; border: 1px solid #E2E8F0; flex-shrink: 0;">
                            <?php else: ?>
                                <div class="prod-thumb" style="width: 46px; height: 46px; font-size: 1rem; background: rgba(240, 246, 253, 0.9); color: var(--ta-navy); border-color: rgba(243, 130, 24, 0.35);">
                                    <i class="bi bi-box-seam"></i>
                                </div>
                            <?php endif; ?>
                            <div class="min-w-0">
                                <div class="col-main-val" style="color: var(--ta-navy);"><?= htmlspecialchars($r['nama_material']) ?></div>
                                <div class="col-micro-lbl mb-0">
                                    <strong style="color: var(--ta-blue);"><?= htmlspecialchars($r['id_material']) ?></strong>
                                    &bull; <?= htmlspecialchars($r['kategori']) ?>
                                    <?= !empty($r['merek']) ? ' &bull; ' . htmlspecialchars($r['merek']) : '' ?>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center gap-3 ms-auto flex-wrap">
                            <div class="text-end">
                                <div class="col-micro-lbl mb-0">Stok / Batas Min</div>
                                <div class="col-main-val" style="font-size: 0.82rem;">
                                    <span class="text-danger"><?= (int)$r['total_stok'] ?></span>
                                    <span class="text-muted fw-normal">/ <?= (int)$r['batas_minimum'] . ' ' . htmlspecialchars($r['satuan']) ?></span>
                                </div>
                            </div>
                            <span class="badge-pill-red py-1 px-2">
                                Kurang <?= (int)$r['jumlah_harus_restock'] . ' ' . htmlspecialchars($r['satuan']) ?>
                            </span>
                            <a href="/barang/edit.php?id=<?= urlencode($r['id_material']) ?>" class="btn-toolbar-primary" style="font-size: 0.76rem; padding: 0.34rem 0.75rem;">
                                <i class="bi bi-arrow-repeat"></i> Update Stok
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<script src="/assets/vendor/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    Chart.defaults.font.family = "'Inter', -apple-system, BlinkMacSystemFont, sans-serif";
    Chart.defaults.color = '#6B7280';

    const commonTooltip = {
        backgroundColor: '#062A4E',
        titleColor: '#FFFFFF',
        bodyColor: '#F3F4F6',
        padding: 10,
        cornerRadius: 8,
        displayColors: true,
    };

    // CHART 1: Kesehatan Stok Material (Doughnut Chart)
    const ctxStock = document.getElementById('chartStockHealth');
    if (ctxStock) {
        new Chart(ctxStock, {
            type: 'doughnut',
            data: {
                labels: ['Stok Aman', 'Low Stock (Kritis)'],
                datasets: [{
                    data: [<?= (int)$safeStockCount ?>, <?= (int)$lowStockCount ?>],
                    backgroundColor: ['#135E38', '#F38218'],
                    hoverBackgroundColor: ['#0D559B', '#DC2626'],
                    borderWidth: 3,
                    borderColor: '#FFFFFF',
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 12, weight: '500' } }
                    },
                    tooltip: {
                        ...commonTooltip,
                        callbacks: {
                            label: function (ctx) {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                                return ` ${ctx.label}: ${ctx.parsed} material (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // CHART 2: Distribusi Kategori Material (Horizontal Bar Chart)
    const katLabels = <?= json_encode($katLabels, JSON_UNESCAPED_UNICODE) ?>;
    const katCounts = <?= json_encode($katCounts) ?>;
    const katUnits  = <?= json_encode($katUnits) ?>;
    const katPalette = ['#062A4E', '#0D559B', '#F38218', '#135E38', '#617275'];
    const ctxCat = document.getElementById('chartCategoryDist');
    if (ctxCat) {
        new Chart(ctxCat, {
            type: 'bar',
            data: {
                labels: katLabels,
                datasets: [{
                    label: 'Jumlah Item',
                    data: katCounts,
                    backgroundColor: katLabels.map((_, i) => katPalette[i % katPalette.length]),
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
                                const unit = katUnits[ctx.dataIndex] ?? 0;
                                return ` ${ctx.parsed.x} jenis material (${unit.toLocaleString('id-ID')} total unit)`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, precision: 0, font: { size: 11 } },
                        grid: { color: '#F1F5F9' },
                        border: { display: false }
                    },
                    y: {
                        ticks: { font: { size: 12, weight: '500' }, color: '#111827' },
                        grid: { display: false },
                        border: { display: false }
                    }
                }
            }
        });
    }

    // CHART 3: Sales Pipeline & Leads (Vertical Bar Chart — Construct Orange)
    const ctxPipeline = document.getElementById('chartSalesPipeline');
    if (ctxPipeline) {
        new Chart(ctxPipeline, {
            type: 'bar',
            data: {
                labels: <?= json_encode($funnelStages) ?>,
                datasets: [{
                    label: 'Jumlah Prospek',
                    data: <?= json_encode($funnelCounts) ?>,
                    backgroundColor: [
                        'rgba(243, 130, 24, 0.58)',
                        'rgba(243, 130, 24, 0.80)',
                        '#F38218'
                    ],
                    hoverBackgroundColor: '#062A4E',
                    borderRadius: 8,
                    maxBarThickness: 46,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        ...commonTooltip,
                        callbacks: {
                            label: function (ctx) {
                                return ` ${ctx.parsed.y} prospek pada tahap ${ctx.label}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, precision: 0, font: { size: 11 } },
                        grid: { color: '#F1F5F9' },
                        border: { display: false }
                    },
                    x: {
                        ticks: { font: { size: 12, weight: '500' }, color: '#111827' },
                        grid: { display: false },
                        border: { display: false }
                    }
                }
            }
        });
    }

    // CHART 4: Win/Loss Ratio (Pie Chart)
    const ctxWinLoss = document.getElementById('chartWinLoss');
    if (ctxWinLoss) {
        new Chart(ctxWinLoss, {
            type: 'pie',
            data: {
                labels: ['Proyek Win', 'Proyek Loss'],
                datasets: [{
                    data: [<?= (int)$leadsWin ?>, <?= (int)$leadsLoss ?>],
                    backgroundColor: ['#135E38', '#EF4444'],
                    hoverBackgroundColor: ['#0D559B', '#DC2626'],
                    borderWidth: 3,
                    borderColor: '#FFFFFF',
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, font: { size: 12, weight: '500' } }
                    },
                    tooltip: {
                        ...commonTooltip,
                        callbacks: {
                            label: function (ctx) {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? Math.round((ctx.parsed / total) * 100) : 0;
                                return ` ${ctx.label}: ${ctx.parsed} proyek (${pct}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/layout/footer.php'; ?>
