<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/qr_helper.php';
require_once __DIR__ . '/../config/app_helpers.php';

ensureSchemaMigrationsOnce($pdo);

// Sinkronisasi otomatis material berstok > 0 yang belum memiliki entri kemasan_qr
syncMissingKemasanQr($pdo);

$canManageMaterial = isApproverAdmin();

function generateNextMaterialIdIndex(PDO $pdo): string
{
    $maxNum = (int)$pdo->query("
        SELECT COALESCE(MAX(CAST(SUBSTRING(id_material FROM 5) AS INTEGER)), 0)
        FROM material
        WHERE id_material ~ '^MAT-[0-9]+$'
    ")->fetchColumn();

    return sprintf('MAT-%03d', $maxNum + 1);
}

$newMatError          = '';
$openNewMaterialModal = ($canManageMaterial && (($_GET['open'] ?? '') === 'new_material'));
$newMatForm           = [
    'nama_material'      => '',
    'kategori'           => '',
    'merek'              => '',
    'spesifikasi_teknis' => '',
    'satuan'             => '',
    'total_stok'         => '0',
    'batas_minimum'      => '10',
    'section_gudang'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_material') {
    if (!$canManageMaterial) {
        header('Location: /barang/index.php?err=' . urlencode('Akses ditolak. Fitur ini hanya tersedia untuk akun Ismail Sahid atau Taufan Kusuma Negara.'));
        exit;
    }

    $nama_material      = trim((string)($_POST['nama_material'] ?? ''));
    $katInput           = trim((string)($_POST['kategori'] ?? ''));
    $merek              = trim((string)($_POST['merek'] ?? ''));
    $spesifikasi_teknis = trim((string)($_POST['spesifikasi_teknis'] ?? ''));
    $satuan             = trim((string)($_POST['satuan'] ?? ''));
    $total_stok         = (int)($_POST['total_stok'] ?? 0);
    $batas_minimum      = (int)($_POST['batas_minimum'] ?? 0);
    $section_gudang     = trim((string)($_POST['section_gudang'] ?? ''));

    $newMatForm = [
        'nama_material'      => $nama_material,
        'kategori'           => $katInput,
        'merek'              => $merek,
        'spesifikasi_teknis' => $spesifikasi_teknis,
        'satuan'             => $satuan,
        'total_stok'         => (string)$total_stok,
        'batas_minimum'      => (string)$batas_minimum,
        'section_gudang'     => $section_gudang,
    ];

    if ($nama_material === '' || $katInput === '' || $satuan === '') {
        $newMatError = 'Nama Material, Kategori, dan Satuan wajib diisi.';
    } elseif ($total_stok < 0 || $batas_minimum < 0) {
        $newMatError = 'Stok awal dan batas minimum restock tidak boleh bernilai negatif.';
    } else {
        $uploadedFilePath = null;
        $gambarDbPath     = null;
        $fotoDataUri      = null;

        if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['gambar']['error'] !== UPLOAD_ERR_OK) {
                $newMatError = 'Gagal mengunggah file gambar (kode error: ' . (int)$_FILES['gambar']['error'] . ').';
            } elseif ($_FILES['gambar']['size'] > 5 * 1024 * 1024) {
                $newMatError = 'Ukuran gambar maksimal 5 MB.';
            } else {
                $allowedExt  = ['jpg', 'jpeg', 'png', 'webp'];
                $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
                $ext         = strtolower(pathinfo($_FILES['gambar']['name'], PATHINFO_EXTENSION));
                $finfo       = new finfo(FILEINFO_MIME_TYPE);
                $mime        = $finfo->file($_FILES['gambar']['tmp_name']);

                if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
                    $newMatError = 'Format gambar tidak didukung. Gunakan file JPG, JPEG, PNG, atau WEBP.';
                }
            }
        }

        if ($newMatError === '') {
            try {
                $pdo->beginTransaction();
                $pdo->exec("LOCK TABLE material IN SHARE ROW EXCLUSIVE MODE");

                $id_material = generateNextMaterialIdIndex($pdo);

                if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/../assets/uploads/material';
                    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                        throw new RuntimeException('Gagal membuat folder assets/uploads/material/. Periksa izin direktori / Persistent Volume.');
                    }
                    if (!is_writable($uploadDir)) {
                        @chmod($uploadDir, 0775);
                    }
                    if (!is_writable($uploadDir)) {
                        throw new RuntimeException('Folder assets/uploads/material/ tidak dapat ditulisi (not writable). Periksa izin Persistent Volume.');
                    }
                    $ext      = strtolower(pathinfo($_FILES['gambar']['name'], PATHINFO_EXTENSION));
                    $fileName = strtolower($id_material) . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $destPath = $uploadDir . '/' . $fileName;

                    if (!move_uploaded_file($_FILES['gambar']['tmp_name'], $destPath)) {
                        throw new RuntimeException('Gagal menyimpan file foto ke folder assets/uploads/material/.');
                    }
                    $uploadedFilePath = $destPath;
                    $gambarDbPath     = 'assets/uploads/material/' . $fileName;
                    $fotoDataUri      = compressImageToBase64DataUri($destPath, 800, 75);
                }

                $stmtIns = $pdo->prepare("
                    INSERT INTO material (id_material, nama_material, kategori, merek, spesifikasi_teknis, satuan, total_stok, batas_minimum, gambar, foto_data)
                    VALUES (:id, :nama, :kat, :merek, :spek, :satuan, :stok, :min, :gambar, :foto_data)
                ");
                $stmtIns->execute([
                    ':id'        => $id_material,
                    ':nama'      => $nama_material,
                    ':kat'       => $katInput,
                    ':merek'     => $merek !== '' ? $merek : null,
                    ':spek'      => $spesifikasi_teknis !== '' ? $spesifikasi_teknis : null,
                    ':satuan'    => $satuan,
                    ':stok'      => $total_stok,
                    ':min'       => $batas_minimum,
                    ':gambar'    => $gambarDbPath,
                    ':foto_data' => $fotoDataUri,
                ]);

                $generatedQr = null;
                if ($total_stok > 0) {
                    $generatedQr = createInitialKemasanQr($pdo, $id_material, $katInput, $total_stok, $section_gudang);
                }

                $pdo->commit();
                $msgText = $generatedQr
                    ? "Material $id_material ($nama_material) & kemasan QR $generatedQr berhasil ditambahkan!"
                    : "Material $id_material ($nama_material) berhasil ditambahkan!";
                header('Location: /barang/index.php?msg=' . urlencode($msgText));
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($uploadedFilePath && file_exists($uploadedFilePath)) {
                    @unlink($uploadedFilePath);
                }
                $newMatError = 'Gagal menyimpan data: ' . $e->getMessage();
            }
        }
    }

    if ($newMatError !== '') {
        $openNewMaterialModal = true;
    }
}

$nextMaterialId = $canManageMaterial ? generateNextMaterialIdIndex($pdo) : '';
$kategoriList   = $canManageMaterial ? getKategoriMaterialNames($pdo) : [];

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

// Query Material + Lokasi Rak & Kode QR Aktif
$where = "WHERE 1=1";
$params = [];
if ($keyword !== '') {
    $where .= " AND (m.nama_material ILIKE :kw OR m.id_material ILIKE :kw OR m.merek ILIKE :kw)";
    $params[':kw'] = "%$keyword%";
}
if ($kategori !== '') {
    $where .= " AND m.kategori = :kat";
    $params[':kat'] = $kategori;
}

$orderBy = match ($sort) {
    'stok_desc' => 'ORDER BY m.total_stok DESC',
    'stok_asc'  => 'ORDER BY m.total_stok ASC',
    'nama_asc'  => 'ORDER BY m.nama_material ASC',
    default     => 'ORDER BY m.id_material ASC',
};

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM material m $where");
$stmtCount->execute($params);
$filteredTotal = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT m.*,
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
           ) AS kode_qr_aktif,
           (
               SELECT k.kode_qr
               FROM kemasan_qr k
               WHERE k.id_material = m.id_material
               ORDER BY k.qty_sisa DESC, k.tgl_masuk DESC
               LIMIT 1
           ) AS kode_qr_terakhir
    FROM material m
    $where
    $orderBy
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$materials = $stmt->fetchAll();

$materialDetailLookup = [];
foreach ($materials as $m) {
    $resolvedImg = resolveMaterialImageSrc($m['gambar'] ?? null, $m['foto_data'] ?? null, __DIR__ . '/..');
    $materialDetailLookup[$m['id_material']] = [
        'id_material'        => $m['id_material'],
        'nama_material'      => $m['nama_material'],
        'kategori'           => $m['kategori'],
        'merek'              => $m['merek'] ?: 'Standard',
        'spesifikasi_teknis' => $m['spesifikasi_teknis'] ?: 'Material konstruksi terverifikasi gudang',
        'total_stok'         => (int)$m['total_stok'],
        'batas_minimum'      => (int)$m['batas_minimum'],
        'satuan'             => $m['satuan'],
        'lokasi_rak'         => $m['lokasi_rak'] ?: 'Gudang Utama',
        'kode_qr'            => $m['kode_qr_aktif'] ?: ($m['kode_qr_terakhir'] ?: ''),
        'kode_qr_aktif'      => $m['kode_qr_aktif'] ?: '',
        'gambar'             => $resolvedImg,
    ];
}

$placeholderLogo = file_exists(__DIR__ . '/../assets/logo-tri-arta.png')
    ? '/assets/logo-tri-arta.png'
    : (file_exists(__DIR__ . '/../assets/logo-tri-arta.jpg') ? '/assets/logo-tri-arta.jpg' : '');

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

        <?php if ($canManageMaterial): ?>
        <button type="button" class="btn-toolbar-primary" data-bs-toggle="modal" data-bs-target="#modalNewMaterial">
            <i class="bi bi-plus-lg"></i> New Material
        </button>
        <?php endif; ?>
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
                        <tr class="material-row-clickable" data-mat-id="<?= htmlspecialchars($m['id_material']) ?>">
                            <!-- Kolom 1: Thumbnail + Nama Material + Kode & Merek -->
                            <td style="min-width: 240px;">
                                <div class="d-flex align-items-center gap-3">
                                    <?php
                                    $matImgSrc = resolveMaterialImageSrc($m['gambar'] ?? null, $m['foto_data'] ?? null, __DIR__ . '/..');
                                    if ($matImgSrc !== ''):
                                    ?>
                                        <img src="<?= htmlspecialchars($matImgSrc) ?>" alt="<?= htmlspecialchars($m['nama_material']) ?>" style="width: 46px; height: 46px; object-fit: cover; border-radius: 10px; border: 1px solid #E2E8F0; flex-shrink: 0;">
                                    <?php else: ?>
                                        <div class="prod-thumb">
                                            <i class="bi bi-box-seam"></i>
                                        </div>
                                    <?php endif; ?>
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

                            <!-- Kolom 7: QR Code -->
                            <td style="width: 95px;" class="text-center no-row-click">
                                <div class="col-micro-lbl">QR Code</div>
                                <button type="button"
                                        class="btn-qr-trigger no-row-click"
                                        data-qr-id="<?= htmlspecialchars($m['id_material']) ?>"
                                        data-qr-name="<?= htmlspecialchars($m['nama_material']) ?>"
                                        data-qr-cat="<?= htmlspecialchars($m['kategori']) ?>"
                                        data-qr-merek="<?= htmlspecialchars($m['merek'] ?? 'Standard') ?>"
                                        data-qr-satuan="<?= htmlspecialchars($m['satuan']) ?>"
                                        title="Generate &amp; Download QR <?= htmlspecialchars($m['id_material']) ?>">
                                    <i class="bi bi-qr-code"></i>
                                </button>
                            </td>

                            <!-- Kolom 8: Aksi (Detail untuk semua user; Edit & Hapus khusus Approver Admin) -->
                            <td class="text-end no-row-click" style="width: 115px;">
                                <?php if ($canManageMaterial): ?>
                                    <a href="/barang/edit.php?id=<?= urlencode($m['id_material']) ?>" class="row-act-icon no-row-click" title="Edit Material">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                <?php endif; ?>
                                <button type="button"
                                        class="row-act-icon btn-detail-trigger no-row-click border-0 bg-transparent"
                                        data-mat-id="<?= htmlspecialchars($m['id_material']) ?>"
                                        title="Lihat Detail Material &amp; QR">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <?php if ($canManageMaterial): ?>
                                    <a href="/barang/delete.php?id=<?= urlencode($m['id_material']) ?>"
                                       class="row-act-icon del no-row-click"
                                       title="Hapus Material"
                                       onclick="return confirm('Hapus material <?= htmlspecialchars($m['nama_material']) ?>?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                <?php endif; ?>
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

<style>
    .material-row-clickable {
        cursor: pointer;
        transition: background-color 0.14s ease;
    }
    .material-row-clickable:hover td {
        background: rgba(226, 238, 252, 0.52);
    }
    .btn-qr-trigger {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        border: 1px solid rgba(13, 85, 155, 0.2);
        background: rgba(228, 238, 249, 0.72);
        color: var(--ta-navy);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.98rem;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .btn-qr-trigger:hover {
        background: var(--ta-navy);
        border-color: var(--ta-navy);
        color: #FFFFFF;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(6, 42, 78, 0.16);
    }
    .qr-modal-content {
        border-radius: 22px;
        overflow: hidden;
    }
    .qr-label-frame {
        background: linear-gradient(145deg, rgba(244, 248, 254, 0.95) 0%, rgba(254, 248, 242, 0.95) 100%);
        border: 1.5px dashed rgba(13, 85, 155, 0.3);
        border-radius: 16px;
        padding: 1.35rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
    .qr-canvas-pad {
        padding: 10px;
        border-radius: 10px;
        background: #FFFFFF;
        border: 1px solid rgba(13, 85, 155, 0.12);
    }
    #qrCodeCanvas img,
    #qrCodeCanvas canvas {
        display: block;
        border-radius: 6px;
    }

    /* Pop-Up Detail Material & QR (Seragam dengan #scanResultModal) */
    .mat-detail-modal {
        border-radius: 26px !important;
        box-shadow: 0 28px 70px rgba(6, 42, 78, 0.25), 0 6px 20px rgba(6, 42, 78, 0.08) !important;
        overflow: hidden;
        position: relative;
    }
    .mat-modal-close {
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
    .mat-modal-close:hover {
        background: var(--ta-navy);
        color: #FFFFFF;
        transform: rotate(90deg);
    }
    .mat-hero-stage {
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
    .mat-hero-stage.has-photo {
        padding: 0;
        background: #0F172A;
    }
    .mat-hero-photo {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .mat-hero-visual {
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
    }
    .mat-hero-visual img {
        max-width: 100%;
        max-height: 82px;
        object-fit: contain;
    }
    .mat-hero-verified {
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
    .mat-detail-body {
        padding: 1.45rem 1.65rem 1.2rem;
    }
    .mat-brand-eyebrow {
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--ta-blue);
        margin-bottom: 0.25rem;
    }
    .mat-product-title {
        font-size: 1.38rem;
        font-weight: 700;
        color: var(--ta-navy);
        line-height: 1.25;
        letter-spacing: -0.02em;
        margin: 0;
    }
    .mat-product-spec {
        font-size: 13.5px;
        color: var(--text-muted);
        margin-top: 0.35rem;
        line-height: 1.45;
    }
    .mat-detail-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.65rem;
        margin-top: 1.15rem;
    }
    .mat-spec-tile {
        background: linear-gradient(145deg, rgba(228, 238, 249, 0.65) 0%, rgba(252, 241, 229, 0.65) 100%);
        border: 1px solid rgba(13, 85, 155, 0.14);
        border-radius: 14px;
        padding: 0.72rem 0.85rem;
    }
    .mat-spec-tile.full-span {
        grid-column: 1 / -1;
    }
    .mat-spec-lbl {
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
    .mat-spec-val {
        font-size: 14px;
        font-weight: 700;
        color: var(--ta-navy);
        line-height: 1.3;
        word-break: break-word;
    }
    .mat-action-footer {
        padding: 0.2rem 1.65rem 1.65rem;
        display: flex;
        flex-direction: column;
        gap: 0.6rem;
    }
    .btn-mat-navy {
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
    .btn-mat-navy:hover {
        background: var(--ta-blue);
        color: #FFFFFF;
        transform: translateY(-1px);
    }
    .btn-mat-orange {
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
    .btn-mat-orange:hover {
        background: #DD700C;
        color: #FFFFFF;
        transform: translateY(-1px);
    }

    /* Dropzone Foto Material pada #modalNewMaterial */
    .mat-dropzone {
        width: 100%;
        aspect-ratio: 4 / 3;
        min-height: 255px;
        border-radius: 16px;
        border: 2px dashed rgba(13, 85, 155, 0.3);
        background: linear-gradient(145deg, rgba(242, 247, 253, 0.92) 0%, rgba(254, 247, 240, 0.92) 100%);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
        cursor: pointer;
        transition: border-color 0.16s ease, background 0.16s ease, transform 0.16s ease;
    }
    .mat-dropzone:hover,
    .mat-dropzone.is-dragover {
        border-color: var(--ta-orange);
        background: linear-gradient(145deg, rgba(232, 242, 253, 0.98) 0%, rgba(254, 240, 224, 0.98) 100%);
    }
    .mat-dropzone img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .readonly-auto-mat {
        background-color: #EEF2F7 !important;
        color: var(--ta-navy) !important;
        font-weight: 700;
        letter-spacing: 0.03em;
        cursor: not-allowed;
    }
</style>

<?php if ($canManageMaterial): ?>
<!-- MODAL BESAR ESTETIK: NEW MATERIAL (#modalNewMaterial) -->
<div class="modal fade ta-large-modal" id="modalNewMaterial" tabindex="-1" aria-labelledby="modalNewMaterialLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="ta-lm-accent theme-navy"></div>

            <form method="POST" enctype="multipart/form-data" id="formNewMaterialModal" class="ta-lm-form">
                <input type="hidden" name="action" value="create_material">

                <!-- Sticky Header -->
                <div class="ta-lm-header">
                    <div class="d-flex align-items-center gap-3 min-w-0">
                        <div class="ta-lm-header-badge theme-navy">
                            <i class="bi bi-box-seam-fill"></i>
                        </div>
                        <div class="min-w-0">
                            <h5 class="mb-0 fw-bold" id="modalNewMaterialLabel" style="font-size: 1.15rem; color: var(--ta-navy);">Tambah Material Baru</h5>
                            <div class="col-micro-lbl mb-0">Registrasi master material gudang &amp; auto-generate label kemasan QR</div>
                        </div>
                    </div>
                    <button type="button" class="ta-lm-close" data-bs-dismiss="modal" aria-label="Tutup">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <!-- Scrollable 2-Column Body -->
                <div class="ta-lm-body">
                    <?php if ($newMatError !== ''): ?>
                        <div class="alert alert-danger py-2 mb-3" style="font-size: 14px;">
                            <span><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($newMatError) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="row g-4 align-items-start">
                        <!-- KOLOM KIRI: Spesifikasi & Stok Material -->
                        <div class="col-lg-7">
                            <div class="ta-lm-panel">
                                <div class="fw-bold mb-3 d-flex align-items-center gap-2" style="font-size: 0.94rem; color: var(--ta-navy);">
                                    <i class="bi bi-sliders2" style="color: var(--ta-blue);"></i>
                                    <span>Spesifikasi &amp; Stok Material</span>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-5">
                                        <label class="form-label fw-semibold d-flex align-items-center justify-content-between gap-2" style="font-size: 13.5px; color: var(--ta-navy);">
                                            <span>ID Material</span>
                                            <span class="badge-pill-soft" style="background: #EFF6FF; color: var(--ta-blue); font-size: 11px;">Otomatis</span>
                                        </label>
                                        <input type="text" class="form-control readonly-auto-mat" value="<?= htmlspecialchars($nextMaterialId) ?>" readonly>
                                    </div>
                                    <div class="col-md-7">
                                        <label for="newMatNama" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Nama Material <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <input type="text" id="newMatNama" name="nama_material" class="form-control" maxlength="100" placeholder="Contoh: Pop-Up Spray Sprinkler 4 Inch" value="<?= htmlspecialchars($newMatForm['nama_material']) ?>" required>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="newMatKategori" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Kategori <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <select id="newMatKategori" name="kategori" class="form-select" required>
                                            <option value="">-- Pilih Kategori --</option>
                                            <?php foreach ($kategoriList as $katOpt): ?>
                                                <option value="<?= htmlspecialchars($katOpt) ?>" <?= $newMatForm['kategori'] === $katOpt ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($katOpt) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="newMatMerek" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">Merek</label>
                                        <input type="text" id="newMatMerek" name="merek" class="form-control" maxlength="50" placeholder="Contoh: Rain Bird, Wavin, Supreme" value="<?= htmlspecialchars($newMatForm['merek']) ?>">
                                    </div>

                                    <div class="col-12">
                                        <label for="newMatSpek" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">Spesifikasi Teknis</label>
                                        <textarea id="newMatSpek" name="spesifikasi_teknis" rows="2" class="form-control" placeholder="Contoh: Inlet 1/2 inch, radius semprot 4.5m, tekanan kerja 2.1 bar"><?= htmlspecialchars($newMatForm['spesifikasi_teknis']) ?></textarea>
                                    </div>

                                    <div class="col-md-4">
                                        <label for="newMatSatuan" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Satuan <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <input type="text" id="newMatSatuan" name="satuan" class="form-control" maxlength="20" placeholder="Unit / Batang / Roll" value="<?= htmlspecialchars($newMatForm['satuan']) ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="newMatStok" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Stok Awal <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <input type="number" id="newMatStok" name="total_stok" class="form-control" min="0" value="<?= htmlspecialchars($newMatForm['total_stok']) ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="newMatMin" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Batas Min. Restock <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <input type="number" id="newMatMin" name="batas_minimum" class="form-control" min="0" value="<?= htmlspecialchars($newMatForm['batas_minimum']) ?>" required>
                                    </div>

                                    <div class="col-12">
                                        <label for="newMatSection" class="form-label fw-semibold d-flex align-items-center justify-content-between gap-2" style="font-size: 13.5px; color: var(--ta-navy);">
                                            <span><i class="bi bi-geo-alt me-1" style="color: var(--ta-blue);"></i>Section / Rak Gudang (Opsional)</span>
                                            <span class="badge-pill-soft" style="font-size: 11px;">Auto QR: QR-<?= htmlspecialchars($nextMaterialId) ?>-01</span>
                                        </label>
                                        <input type="text" id="newMatSection" name="section_gudang" class="form-control" maxlength="50" placeholder="Kosongkan untuk otomatis mengikuti kategori (mis. Section B1 - Zona Pipa)" value="<?= htmlspecialchars($newMatForm['section_gudang']) ?>">
                                        <div class="col-micro-lbl mt-1 mb-0">Jika Stok Awal &gt; 0, kemasan QR <code>QR-<?= htmlspecialchars($nextMaterialId) ?>-01</code> otomatis dibuatkan.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- KOLOM KANAN: Dropzone & Live Preview Foto Material -->
                        <div class="col-lg-5">
                            <div class="ta-lm-panel">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <div class="fw-bold d-flex align-items-center gap-2" style="font-size: 0.94rem; color: var(--ta-navy);">
                                        <i class="bi bi-image" style="color: var(--ta-orange);"></i>
                                        <span>Foto Material</span>
                                    </div>
                                    <span class="badge-pill-soft" style="font-size: 11px;">Opsional &bull; Maks 5 MB</span>
                                </div>

                                <label for="newMatInputGambar" class="mat-dropzone mb-3" id="newMatDropzone">
                                    <img id="newMatLivePreview" src="" alt="Preview Foto Material" class="d-none">
                                    <div id="newMatPlaceholder" class="text-center p-3">
                                        <div class="prod-thumb mx-auto mb-2" style="width: 56px; height: 56px; font-size: 1.5rem; background: #FFF7ED; color: var(--ta-orange); border-color: #FED7AA;">
                                            <i class="bi bi-cloud-arrow-up"></i>
                                        </div>
                                        <div class="fw-semibold" style="font-size: 14px; color: var(--ta-navy);">Tarik &amp; letakkan foto di sini</div>
                                        <div class="col-micro-lbl mb-0 mt-1">atau klik untuk pilih file (JPG, JPEG, PNG, WEBP)</div>
                                    </div>
                                </label>

                                <input type="file" id="newMatInputGambar" name="gambar" class="d-none" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">

                                <div id="newMatPhotoActions" class="d-none d-flex align-items-center justify-content-between gap-2">
                                    <span id="newMatFileInfo" class="col-micro-lbl mb-0 text-truncate" style="color: var(--ta-green); font-weight: 600;"></span>
                                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                        <button type="button" id="btnNewMatChangePhoto" class="btn-toolbar-white" style="font-size: 12px; padding: 4px 10px;">
                                            <i class="bi bi-arrow-repeat"></i> Ganti
                                        </button>
                                        <button type="button" id="btnNewMatClearPhoto" class="btn-toolbar-white text-danger" style="font-size: 12px; padding: 4px 10px;">
                                            <i class="bi bi-trash3"></i> Hapus
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sticky Footer -->
                <div class="ta-lm-footer">
                    <span class="col-micro-lbl mb-0 d-none d-sm-inline">Kolom bertanda <strong style="color: var(--ta-orange);">*</strong> wajib diisi</span>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <button type="button" class="btn-toolbar-white" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-toolbar-primary">
                            <i class="bi bi-check-lg"></i> Simpan Material
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal Detail Material & QR Gudang (#materialDetailModal) -->
<div class="modal fade" id="materialDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 450px;">
        <div class="modal-content mat-detail-modal">
            <button type="button" class="mat-modal-close" data-bs-dismiss="modal" aria-label="Tutup">
                <i class="bi bi-x-lg" style="font-size: 0.85rem;"></i>
            </button>

            <div class="mat-hero-stage" id="matDetailHeroStage">
                <img id="matDetailHeroPhoto" class="mat-hero-photo d-none" src="" alt="Foto Material">
                <div class="mat-hero-visual" id="matDetailHeroFallback">
                    <?php if ($placeholderLogo !== ''): ?>
                        <img src="<?= htmlspecialchars($placeholderLogo) ?>" alt="Material PT Tri Arta Konstruksi">
                    <?php else: ?>
                        <i class="bi bi-box-seam" style="font-size: 3.4rem; color: var(--ta-navy);"></i>
                    <?php endif; ?>
                    <span class="badge-pill-soft mt-2" id="matDetailHeroTag" style="background: #EFF6FF; color: var(--ta-blue); font-size: 11.5px;">
                        QR MATERIAL
                    </span>
                </div>
                <span class="mat-hero-verified">
                    <i class="bi bi-patch-check-fill"></i> Terverifikasi Gudang
                </span>
            </div>

            <div class="mat-detail-body">
                <div class="mat-brand-eyebrow" id="matDetailBrand">Merek Material</div>
                <h3 class="mat-product-title" id="matDetailName">Nama Material</h3>
                <div class="mat-product-spec" id="matDetailSpec">Spesifikasi teknis material konstruksi</div>

                <div class="mat-detail-grid">
                    <div class="mat-spec-tile">
                        <div class="mat-spec-lbl"><i class="bi bi-upc-scan"></i> SKU / ID</div>
                        <div class="mat-spec-val" id="matDetailSku">MAT-001</div>
                    </div>
                    <div class="mat-spec-tile">
                        <div class="mat-spec-lbl"><i class="bi bi-tag"></i> Kategori</div>
                        <div class="mat-spec-val" id="matDetailCategory">Irigasi</div>
                    </div>
                    <div class="mat-spec-tile">
                        <div class="mat-spec-lbl"><i class="bi bi-boxes"></i> Total Stok</div>
                        <div class="mat-spec-val d-flex align-items-center gap-1 flex-wrap">
                            <span id="matDetailStockVal">0 Unit</span>
                            <span id="matDetailStockBadge" class="badge-pill-soft">Aman</span>
                        </div>
                    </div>
                    <div class="mat-spec-tile">
                        <div class="mat-spec-lbl"><i class="bi bi-geo-alt"></i> Lokasi Rak</div>
                        <div class="mat-spec-val" id="matDetailRack">Gudang Utama</div>
                    </div>
                    <div class="mat-spec-tile full-span">
                        <div class="mat-spec-lbl"><i class="bi bi-qr-code"></i> Kode Kemasan QR Aktif</div>
                        <div class="mat-spec-val" id="matDetailQrCode">-</div>
                    </div>
                </div>
            </div>

            <div class="mat-action-footer">
                <?php if ($canManageMaterial): ?>
                    <a href="/barang/index.php" id="matDetailBtnUpdate" class="btn-mat-navy">
                        <i class="bi bi-pencil-square"></i> Update Stok Gudang
                    </a>
                <?php endif; ?>
                <a href="/transaksi_qr/create.php" id="matDetailBtnOut" class="btn-mat-orange">
                    <i class="bi bi-box-arrow-up-right"></i> Catat Keluar
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal Generate & Download/Print QR Code Material -->
<div class="modal fade" id="qrPrintModal" tabindex="-1" aria-labelledby="qrPrintModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 410px;">
        <div class="modal-content qr-modal-content">
            <div class="px-4 pt-4 pb-2 d-flex align-items-start justify-content-between gap-2">
                <div>
                    <span class="badge-pill-soft mb-1 d-inline-block" style="background: #EFF6FF; color: var(--ta-blue);">Label QR Gudang</span>
                    <h5 class="mb-0 fw-bold" id="qrPrintModalLabel" style="color: var(--ta-navy); font-size: 1.05rem;">QR Code Material</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body px-4 pt-2 pb-4">
                <div class="qr-label-frame mb-3">
                    <div id="qrCodeCanvas" class="qr-canvas-pad"></div>
                    <div class="mt-3 text-center">
                        <div id="qrModalId" class="fw-bold" style="color: var(--ta-navy); font-size: 1rem; letter-spacing: 0.04em;">MAT-001</div>
                        <div id="qrModalName" class="fw-semibold mt-1" style="color: var(--text-dark); font-size: 0.86rem;">Nama Material</div>
                        <div id="qrModalMeta" class="col-micro-lbl mb-0 mt-1">Kategori &bull; Merek</div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="button" id="btnDownloadQr" class="btn-toolbar-primary justify-content-center py-2" style="border-radius: 12px;">
                        <i class="bi bi-download"></i> Download QR (PNG)
                    </button>
                    <button type="button" id="btnPrintQr" class="btn-toolbar-orange justify-content-center py-2" style="border-radius: 12px;">
                        <i class="bi bi-printer"></i> Print Label QR
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', function () {
    const materialLookup = <?= json_encode($materialDetailLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    // 1. Pop-Up Detail Material saat baris atau ikon mata diklik
    const detailModalEl = document.getElementById('materialDetailModal');
    if (detailModalEl && typeof bootstrap !== 'undefined') {
        const detailModal = new bootstrap.Modal(detailModalEl);
        const elHeroStage = document.getElementById('matDetailHeroStage');
        const elHeroPhoto = document.getElementById('matDetailHeroPhoto');
        const elHeroFallb = document.getElementById('matDetailHeroFallback');
        const elHeroTag   = document.getElementById('matDetailHeroTag');
        const elBrand     = document.getElementById('matDetailBrand');
        const elName      = document.getElementById('matDetailName');
        const elSpec      = document.getElementById('matDetailSpec');
        const elSku       = document.getElementById('matDetailSku');
        const elCat       = document.getElementById('matDetailCategory');
        const elStockVal  = document.getElementById('matDetailStockVal');
        const elStockBdg  = document.getElementById('matDetailStockBadge');
        const elRack      = document.getElementById('matDetailRack');
        const elQrCode    = document.getElementById('matDetailQrCode');
        const btnUpdate   = document.getElementById('matDetailBtnUpdate');
        const btnOut      = document.getElementById('matDetailBtnOut');

        function openMaterialDetail(matId) {
            const item = materialLookup[matId];
            if (!item) return;

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

            if (elBrand) elBrand.textContent = item.merek;
            if (elName) elName.textContent = item.nama_material;
            if (elSpec) elSpec.textContent = item.spesifikasi_teknis;
            if (elSku) elSku.textContent = item.id_material;
            if (elCat) elCat.textContent = item.kategori;
            if (elStockVal) elStockVal.textContent = `${item.total_stok} ${item.satuan}`;
            if (elRack) elRack.textContent = item.lokasi_rak;
            if (elHeroTag) elHeroTag.textContent = item.kode_qr ? item.kode_qr : item.id_material;
            if (elQrCode) elQrCode.textContent = item.kode_qr ? item.kode_qr : 'Belum ada kemasan QR aktif';

            if (elStockBdg) {
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
            }

            if (btnUpdate) {
                btnUpdate.href = '/barang/edit.php?id=' + encodeURIComponent(item.id_material);
            }
            if (btnOut) {
                btnOut.href = item.kode_qr_aktif
                    ? '/transaksi_qr/create.php?kode_qr=' + encodeURIComponent(item.kode_qr_aktif)
                    : '/transaksi_qr/create.php';
            }

            detailModal.show();
        }

        document.querySelectorAll('.material-row-clickable').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target.closest('a, button, form, input, select, .no-row-click')) return;
                openMaterialDetail(row.getAttribute('data-mat-id'));
            });
        });

        document.querySelectorAll('.btn-detail-trigger').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                openMaterialDetail(btn.getAttribute('data-mat-id'));
            });
        });
    }

    // 2. Pop-Up Generate & Download/Print QR Code
    const modalEl   = document.getElementById('qrPrintModal');
    const qrTarget  = document.getElementById('qrCodeCanvas');
    const elId      = document.getElementById('qrModalId');
    const elName    = document.getElementById('qrModalName');
    const elMeta    = document.getElementById('qrModalMeta');
    const btnDl     = document.getElementById('btnDownloadQr');
    const btnPrint  = document.getElementById('btnPrintQr');

    if (!modalEl || !qrTarget || typeof bootstrap === 'undefined') return;

    const qrModal = new bootstrap.Modal(modalEl);
    let currentQrData = { id: '', name: '', meta: '' };

    document.querySelectorAll('.btn-qr-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id     = btn.getAttribute('data-qr-id') || '';
            const name   = btn.getAttribute('data-qr-name') || '';
            const cat    = btn.getAttribute('data-qr-cat') || '';
            const merek  = btn.getAttribute('data-qr-merek') || '';
            const satuan = btn.getAttribute('data-qr-satuan') || '';

            currentQrData = { id, name, meta: `${cat} • ${merek} (${satuan})` };
            elId.textContent   = id;
            elName.textContent = name;
            elMeta.textContent = currentQrData.meta;

            qrTarget.innerHTML = '';
            if (typeof QRCode !== 'undefined') {
                new QRCode(qrTarget, {
                    text: id,
                    width: 210,
                    height: 210,
                    colorDark: '#000000',
                    colorLight: '#FFFFFF',
                    correctLevel: QRCode.CorrectLevel.H
                });
            }
            qrModal.show();
        });
    });

    function getQrDataUrl() {
        const canvas = qrTarget.querySelector('canvas');
        if (canvas) return canvas.toDataURL('image/png');
        const img = qrTarget.querySelector('img');
        if (img && img.src) return img.src;
        return null;
    }

    if (btnDl) {
        btnDl.addEventListener('click', function () {
            const dataUrl = getQrDataUrl();
            if (!dataUrl) return;
            const link = document.createElement('a');
            link.href = dataUrl;
            link.download = `QR-${currentQrData.id || 'material'}.png`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }

    if (btnPrint) {
        btnPrint.addEventListener('click', function () {
            const dataUrl = getQrDataUrl();
            if (!dataUrl) return;
            const win = window.open('', '_blank', 'width=440,height=540');
            if (!win) return;
            win.document.write(`<!DOCTYPE html><html><head><title>Print QR ${currentQrData.id}</title>
                <style>
                    body { font-family: Inter, -apple-system, sans-serif; display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; background:#fff; }
                    .label { border:1.5px solid #111; border-radius:14px; padding:24px; text-align:center; width:280px; }
                    .label img { width:200px; height:200px; display:block; margin:0 auto 14px; }
                    .sku { font-size:18px; font-weight:800; color:#062A4E; letter-spacing:0.05em; }
                    .name { font-size:14px; font-weight:600; color:#111827; margin-top:4px; }
                    .meta { font-size:11px; color:#6B7280; margin-top:4px; }
                    .brand { font-size:10px; font-weight:700; color:#F38218; margin-top:12px; letter-spacing:0.08em; text-transform:uppercase; }
                </style></head><body>
                <div class="label">
                    <img src="${dataUrl}" alt="${currentQrData.id}">
                    <div class="sku">${currentQrData.id}</div>
                    <div class="name">${currentQrData.name}</div>
                    <div class="meta">${currentQrData.meta}</div>
                    <div class="brand">PT Tri Arta Konstruksi</div>
                </div>
                <script>window.onload = function(){ window.print(); };<\/script>
                </body></html>`);
            win.document.close();
        });
    }

    // 3. Pop-Up Modal Besar: New Material (#modalNewMaterial) + Dropzone Drag & Drop
    const newMatModalEl = document.getElementById('modalNewMaterial');
    if (newMatModalEl && typeof bootstrap !== 'undefined') {
        const newMatModal = new bootstrap.Modal(newMatModalEl);
        const dropzone    = document.getElementById('newMatDropzone');
        const fileInput   = document.getElementById('newMatInputGambar');
        const previewImg  = document.getElementById('newMatLivePreview');
        const placeholder = document.getElementById('newMatPlaceholder');
        const actionsBar  = document.getElementById('newMatPhotoActions');
        const fileInfo    = document.getElementById('newMatFileInfo');
        const btnChange   = document.getElementById('btnNewMatChangePhoto');
        const btnClear    = document.getElementById('btnNewMatClearPhoto');

        function resetNewMatPreview() {
            if (fileInput) fileInput.value = '';
            if (previewImg) {
                previewImg.src = '';
                previewImg.classList.add('d-none');
            }
            if (placeholder) placeholder.classList.remove('d-none');
            if (actionsBar) actionsBar.classList.add('d-none');
        }

        function renderNewMatFile(file) {
            if (!file) {
                resetNewMatPreview();
                return;
            }
            const reader = new FileReader();
            reader.onload = function (e) {
                if (previewImg) {
                    previewImg.src = e.target.result;
                    previewImg.classList.remove('d-none');
                }
                if (placeholder) placeholder.classList.add('d-none');
                if (actionsBar && fileInfo) {
                    const kb = Math.max(1, Math.round(file.size / 1024));
                    fileInfo.textContent = `${file.name} (${kb} KB)`;
                    actionsBar.classList.remove('d-none');
                }
            };
            reader.readAsDataURL(file);
        }

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                renderNewMatFile(fileInput.files && fileInput.files[0]);
            });
        }
        if (btnChange && fileInput) {
            btnChange.addEventListener('click', function () { fileInput.click(); });
        }
        if (btnClear) {
            btnClear.addEventListener('click', resetNewMatPreview);
        }
        if (dropzone && fileInput) {
            ['dragenter', 'dragover'].forEach(function (evName) {
                dropzone.addEventListener(evName, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function (evName) {
                dropzone.addEventListener(evName, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('is-dragover');
                });
            });
            dropzone.addEventListener('drop', function (e) {
                const dropped = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                if (!dropped) return;
                try {
                    const dt = new DataTransfer();
                    dt.items.add(dropped);
                    fileInput.files = dt.files;
                } catch (err) {}
                renderNewMatFile(dropped);
            });
        }

        const shouldAutoOpenNewMat = <?= json_encode($openNewMaterialModal) ?>;
        if (shouldAutoOpenNewMat) {
            newMatModal.show();
            if (window.history && window.history.replaceState) {
                const url = new URL(window.location.href);
                if (url.searchParams.has('open')) {
                    url.searchParams.delete('open');
                    window.history.replaceState({}, '', url.pathname + url.search);
                }
            }
        }
    }
});
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
