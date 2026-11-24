<?php
require_once __DIR__ . '/../config/db.php';
include __DIR__ . '/../layout/header.php';

// Parameter Search, Filter Kategori, Sort, dan Pagination
$keyword  = trim($_GET['q'] ?? '');
$kategori = trim($_GET['kategori'] ?? '');
$sort     = $_GET['sort'] ?? 'id_asc';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 7;

// Statistik Ringkasan Atas (Persis 5 kolom Product Statistic di gambar)
$totalMat     = (int)$pdo->query("SELECT COUNT(*) FROM material")->fetchColumn();
$topMat       = $pdo->query("SELECT nama_material FROM material ORDER BY total_stok DESC LIMIT 1")->fetchColumn() ?: '-';
$amanCount    = (int)$pdo->query("SELECT COUNT(*) FROM material WHERE total_stok >= batas_minimum")->fetchColumn();
$persenSehat  = $totalMat > 0 ? round(($amanCount / $totalMat) * 100) : 100;
$totalUnitAll = (int)$pdo->query("SELECT COALESCE(SUM(total_stok), 0) FROM material")->fetchColumn();
$restockCount = $totalMat - $amanCount;

// Query Material
$where = "WHERE 1=1";
$params = [];
if ($keyword !== '') {
    $where .= " AND (nama_material ILIKE :kw OR id_material ILIKE :kw OR merek ILIKE :kw)";
    $params[':kw'] = "%$keyword%";
}
if ($kategori !== '') {
    $where .= " AND kategori = :kat";
    $params[':kat'] = $kategori;
}

$orderBy = match ($sort) {
    'stok_desc' => 'ORDER BY total_stok DESC',
    'stok_asc'  => 'ORDER BY total_stok ASC',
    'nama_asc'  => 'ORDER BY nama_material ASC',
    default     => 'ORDER BY id_material ASC',
};

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM material $where");
$stmtCount->execute($params);
$filteredTotal = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM material $where $orderBy LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$materials = $stmt->fetchAll();

// Fungsi SVG Semi-Circle Arc Gauge (Persis seperti lengkungan hijau/kuning/merah di referensi)
function renderArcGauge(int $percent, string $color): string {
    $percent = max(5, min(100, $percent));
    $dashArray = 63; // panjang busur setengah lingkaran r=20
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

<!-- Top Header Bar (Persis: Judul | Search | Sort By | Show All Material | + New Material) -->
<header class="saas-topbar">
    <div class="d-flex align-items-center gap-3 flex-wrap">
        <h1 class="saas-title">All Material List</h1>
        <form method="GET" class="top-search">
            <i class="bi bi-search"></i>
            <input type="text" name="q" placeholder="Search Material..." value="<?= htmlspecialchars($keyword) ?>">
            <?php if ($kategori !== ''): ?><input type="hidden" name="kategori" value="<?= htmlspecialchars($kategori) ?>"><?php endif; ?>
            <?php if ($sort !== 'id_asc'): ?><input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>"><?php endif; ?>
        </form>
    </div>

    <div class="d-flex align-items-center gap-2 flex-wrap">
        <!-- Dropdown Sort By -->
        <div class="dropdown">
            <button class="btn-toolbar-white dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-filter"></i> Sort By
            </button>
            <ul class="dropdown-menu dropdown-menu-end" style="font-size: 0.82rem;">
                <li><a class="dropdown-item" href="?sort=id_asc<?= $kategori ? '&kategori='.urlencode($kategori) : '' ?>">ID Material (Default)</a></li>
                <li><a class="dropdown-item" href="?sort=stok_desc<?= $kategori ? '&kategori='.urlencode($kategori) : '' ?>">Stok Terbanyak</a></li>
                <li><a class="dropdown-item" href="?sort=stok_asc<?= $kategori ? '&kategori='.urlencode($kategori) : '' ?>">Stok Paling Sedikit</a></li>
                <li><a class="dropdown-item" href="?sort=nama_asc<?= $kategori ? '&kategori='.urlencode($kategori) : '' ?>">Nama A - Z</a></li>
            </ul>
        </div>

        <a href="/barang/index.php" class="btn-toolbar-white">
            <i class="bi bi-collection"></i> Show All Material <span class="badge-pill-soft ms-1"><?= $totalMat ?></span>
        </a>

        <a href="/barang/create.php" class="btn-toolbar-primary">
            <i class="bi bi-plus-lg"></i> New Material
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
    <?php if (isset($_GET['err'])): ?>
        <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
            <?= htmlspecialchars($_GET['err']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Panel Material Statistic (Persis Product Statistic 5 Kolom + Tombol Collapse) -->
    <div class="stat-box-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="fw-semibold" style="font-size: 0.92rem; color: var(--ta-navy);">Material Statistic</span>
            <button class="btn btn-sm p-0 text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#statCollapse">
                <i class="bi bi-chevron-up"></i>
            </button>
        </div>
        <div class="collapse show" id="statCollapse">
            <div class="row g-3">
                <div class="col-6 col-md stat-col-item">
                    <div class="stat-lbl">Active Material</div>
                    <div class="stat-val"><?= $totalMat ?> <small>Material</small></div>
                </div>
                <div class="col-6 col-md stat-col-item">
                    <div class="stat-lbl">Highest Stock Item</div>
                    <div class="stat-val text-truncate" style="max-width: 170px; font-size: 1.05rem;" title="<?= htmlspecialchars($topMat) ?>">
                        <span style="color: var(--ta-orange);">&#9632;</span> <?= htmlspecialchars($topMat) ?>
                    </div>
                </div>
                <div class="col-6 col-md stat-col-item">
                    <div class="stat-lbl">Stock Health</div>
                    <div class="stat-val" style="color: var(--ta-green);">
                        <i class="bi bi-graph-up-arrow me-1"></i><?= $persenSehat >= 80 ? 'Excellent!' : 'Good!' ?> <small>(<?= $persenSehat ?>%)</small>
                    </div>
                </div>
                <div class="col-6 col-md stat-col-item">
                    <div class="stat-lbl">Total Warehouse Units</div>
                    <div class="stat-val"><?= number_format($totalUnitAll) ?> <small>Items</small></div>
                </div>
                <div class="col-12 col-md stat-col-item">
                    <div class="stat-lbl">Need Restock</div>
                    <div class="stat-val" style="color: <?= $restockCount > 0 ? '#DC2626' : 'var(--ta-navy)' ?>;">
                        <?= $restockCount ?> <small>Items</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- List Baris Material (Persis Struktur Kolom Referensi Dribbble) -->
    <div class="dribbble-list">
        <div class="table-responsive">
            <table class="dribbble-table">
                <tbody>
                    <?php if (count($materials) === 0): ?>
                        <tr><td class="text-center py-5 text-muted">Material tidak ditemukan.</td></tr>
                    <?php else: ?>
                        <?php foreach ($materials as $m):
                            // Hitung persentase stok terhadap target kapasitas ideal (2x batas minimum)
                            $targetIdeal = max(1, $m['batas_minimum'] * 2);
                            $persenStok  = min(100, (int)round(($m['total_stok'] / $targetIdeal) * 100));

                            if ($m['total_stok'] >= $m['batas_minimum'] * 1.5) {
                                $perfLabel = 'Excellent';
                                $perfColor = '#135E38'; // Forest Green Logo
                            } elseif ($m['total_stok'] >= $m['batas_minimum']) {
                                $perfLabel = 'Good';
                                $perfColor = '#0D559B'; // Royal Blue Logo
                            } else {
                                $perfLabel = 'Low Stock';
                                $perfColor = '#F38218'; // Construct Orange Logo
                            }
                        ?>
                        <tr>
                            <!-- Kolom 1: Thumbnail + Nama Material + Kode & Merek -->
                            <td style="min-width: 240px;">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="prod-thumb">
                                        <i class="bi bi-box-seam"></i>
                                    </div>
                                    <div>
                                        <div class="col-main-val" style="color: var(--ta-navy);"><?= htmlspecialchars($m['nama_material']) ?></div>
                                        <div class="col-micro-lbl mb-0">
                                            Code: <strong style="color: var(--ta-blue);"><?= htmlspecialchars($m['id_material']) ?></strong>
                                            &bull; <span style="color: var(--ta-orange);">&#9733;</span> <?= htmlspecialchars($m['merek'] ?? 'Standard') ?>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Kolom 2: Stock Status & Sub-metrics -->
                            <td style="min-width: 155px;">
                                <div class="col-micro-lbl">
                                    Status <span class="fw-semibold" style="color: <?= $perfColor ?>;"><?= $perfLabel ?></span>
                                </div>
                                <div class="d-flex align-items-center gap-3" style="font-size: 0.78rem; color: var(--text-muted);">
                                    <span><i class="bi bi-shield-check me-1"></i>Min: <?= $m['batas_minimum'] ?></span>
                                    <span><i class="bi bi-tag me-1"></i><?= htmlspecialchars($m['satuan']) ?></span>
                                </div>
                            </td>

                            <!-- Kolom 3: Semi-Circle Arc Gauge + Persentase -->
                            <td style="width: 90px;" class="text-center">
                                <?= renderArcGauge($persenStok, $perfColor) ?>
                            </td>

                            <!-- Kolom 4: Stock -->
                            <td style="min-width: 100px;">
                                <div class="col-micro-lbl">Stock</div>
                                <div class="col-main-val">
                                    <i class="bi bi-box me-1 text-muted"></i><?= number_format($m['total_stok']) ?>
                                </div>
                            </td>

                            <!-- Kolom 5: Category -->
                            <td style="min-width: 150px;">
                                <div class="col-micro-lbl">Category</div>
                                <div class="col-main-val" style="font-size: 0.81rem;"><?= htmlspecialchars($m['kategori']) ?></div>
                            </td>

                            <!-- Kolom 6: Availability Toggle Switch -->
                            <td style="width: 95px;">
                                <div class="col-micro-lbl">Availability</div>
                                <span class="saas-switch <?= $m['total_stok'] > 0 ? 'on' : '' ?>" title="<?= $m['total_stok'] > 0 ? 'Tersedia di Gudang' : 'Stok Kosong' ?>"></span>
                            </td>

                            <!-- Kolom 7: Aksi (Edit, Detail, Delete - Persis ikon pensil, mata, titik tiga di kanan) -->
                            <td class="text-end" style="width: 115px;">
                                <a href="/barang/edit.php?id=<?= urlencode($m['id_material']) ?>" class="row-act-icon" title="Edit Material">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="/index.php#rak-qr" class="row-act-icon" title="Lihat Kemasan QR">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="/barang/delete.php?id=<?= urlencode($m['id_material']) ?>"
                                   class="row-act-icon del"
                                   title="Hapus Material"
                                   onclick="return confirm('Hapus material <?= htmlspecialchars($m['nama_material']) ?>?')">
                                    <i class="bi bi-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination Bar Bawah (Persis: Previous | Page 1 of X | Next) -->
    <div class="dribbble-pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&sort=<?= urlencode($sort) ?>&kategori=<?= urlencode($kategori) ?>&q=<?= urlencode($keyword) ?>" class="btn-toolbar-white">Previous</a>
        <?php else: ?>
            <span class="btn-toolbar-white text-muted" style="opacity: 0.5; pointer-events: none;">Previous</span>
        <?php endif; ?>

        <span>Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong></span>

        <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&sort=<?= urlencode($sort) ?>&kategori=<?= urlencode($kategori) ?>&q=<?= urlencode($keyword) ?>" class="btn-toolbar-white">Next</a>
        <?php else: ?>
            <span class="btn-toolbar-white text-muted" style="opacity: 0.5; pointer-events: none;">Next</span>
        <?php endif; ?>
    </div>
</main>

<?php include __DIR__ . '/../layout/footer.php'; ?>
