<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
require_once __DIR__ . '/../config/db.php';

$id = $_GET['id'] ?? '';
$stmt = $pdo->prepare("SELECT * FROM material WHERE id_material = :id");
$stmt->execute([':id' => $id]);
$mat = $stmt->fetch();

if (!$mat) {
    header('Location: /barang/index.php?err=' . urlencode('Data material tidak ditemukan.'));
    exit;
}

$defaultKats  = ['Irigasi', 'Perpipaan', 'Kontrol & Otomasi', 'Elektrikal'];
$dbKats       = $pdo->query("SELECT DISTINCT kategori FROM material ORDER BY kategori ASC")->fetchAll(PDO::FETCH_COLUMN);
$kategoriList = array_values(array_unique(array_merge($defaultKats, $dbKats, [$mat['kategori']])));
sort($kategoriList);

$existingGambar = !empty($mat['gambar']) ? ltrim($mat['gambar'], '/') : '';
$hasExistingImg = $existingGambar !== '' && file_exists(__DIR__ . '/../' . $existingGambar);

$error = '';
$form = [
    'nama_material'      => $mat['nama_material'],
    'kategori'           => $mat['kategori'],
    'merek'              => $mat['merek'] ?? '',
    'spesifikasi_teknis' => $mat['spesifikasi_teknis'] ?? '',
    'satuan'             => $mat['satuan'],
    'total_stok'         => (string)(int)$mat['total_stok'],
    'batas_minimum'      => (string)(int)$mat['batas_minimum'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_material      = trim($_POST['nama_material'] ?? '');
    $kategori           = trim($_POST['kategori'] ?? '');
    $merek              = trim($_POST['merek'] ?? '');
    $spesifikasi_teknis = trim($_POST['spesifikasi_teknis'] ?? '');
    $satuan             = trim($_POST['satuan'] ?? '');
    $total_stok         = (int)($_POST['total_stok'] ?? 0);
    $batas_minimum      = (int)($_POST['batas_minimum'] ?? 0);

    $form = [
        'nama_material'      => $nama_material,
        'kategori'           => $kategori,
        'merek'              => $merek,
        'spesifikasi_teknis' => $spesifikasi_teknis,
        'satuan'             => $satuan,
        'total_stok'         => (string)$total_stok,
        'batas_minimum'      => (string)$batas_minimum,
    ];

    if ($nama_material === '' || $kategori === '' || $satuan === '') {
        $error = 'Nama Material, Kategori, dan Satuan wajib diisi.';
    } elseif ($total_stok < 0 || $batas_minimum < 0) {
        $error = 'Total stok dan batas minimum tidak boleh bernilai negatif.';
    } else {
        $newGambarDbPath  = $mat['gambar'] ?? null;
        $uploadedFilePath = null;
        $oldFileToRemove  = null;

        if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['gambar']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Gagal mengunggah file gambar (kode error: ' . (int)$_FILES['gambar']['error'] . ').';
            } elseif ($_FILES['gambar']['size'] > 5 * 1024 * 1024) {
                $error = 'Ukuran gambar maksimal 5 MB.';
            } else {
                $allowedExt  = ['jpg', 'jpeg', 'png', 'webp'];
                $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
                $ext   = strtolower(pathinfo($_FILES['gambar']['name'], PATHINFO_EXTENSION));
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($_FILES['gambar']['tmp_name']);

                if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
                    $error = 'Format gambar tidak didukung. Gunakan file JPG, JPEG, PNG, atau WEBP.';
                } else {
                    $uploadDir = __DIR__ . '/../assets/uploads/material';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }
                    $fileName = strtolower($id) . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
                    $destPath = $uploadDir . '/' . $fileName;

                    if (!move_uploaded_file($_FILES['gambar']['tmp_name'], $destPath)) {
                        $error = 'Gagal menyimpan file foto ke folder assets/uploads/material/.';
                    } else {
                        $uploadedFilePath = $destPath;
                        $newGambarDbPath  = 'assets/uploads/material/' . $fileName;
                        if ($hasExistingImg && str_starts_with($existingGambar, 'assets/uploads/material/')) {
                            $oldFileToRemove = __DIR__ . '/../assets/uploads/material/' . basename($existingGambar);
                        }
                    }
                }
            }
        }

        if ($error === '') {
            try {
                $upd = $pdo->prepare("
                    UPDATE material
                    SET nama_material = :nama,
                        kategori = :kat,
                        merek = :merek,
                        spesifikasi_teknis = :spek,
                        satuan = :satuan,
                        total_stok = :stok,
                        batas_minimum = :min,
                        gambar = :gambar
                    WHERE id_material = :id
                ");
                $upd->execute([
                    ':nama'   => $nama_material,
                    ':kat'    => $kategori,
                    ':merek'  => $merek !== '' ? $merek : null,
                    ':spek'   => $spesifikasi_teknis !== '' ? $spesifikasi_teknis : null,
                    ':satuan' => $satuan,
                    ':stok'   => $total_stok,
                    ':min'    => $batas_minimum,
                    ':gambar' => $newGambarDbPath,
                    ':id'     => $id,
                ]);
                if ($oldFileToRemove && file_exists($oldFileToRemove) && $oldFileToRemove !== $uploadedFilePath) {
                    @unlink($oldFileToRemove);
                }
                header('Location: /barang/index.php?msg=' . urlencode("Material $id berhasil diperbarui!"));
                exit;
            } catch (PDOException $e) {
                if ($uploadedFilePath && file_exists($uploadedFilePath)) {
                    @unlink($uploadedFilePath);
                }
                $error = 'Gagal memperbarui data: ' . $e->getMessage();
            }
        }
    }
}

$isLowStock = (int)$mat['total_stok'] < (int)$mat['batas_minimum'];

include __DIR__ . '/../layout/header.php';
?>

<style>
    .upload-panel-card {
        background: linear-gradient(145deg, rgba(226, 237, 249, 0.72) 0%, rgba(252, 240, 228, 0.72) 100%);
        border: 1px solid rgba(13, 85, 155, 0.16);
        border-radius: 14px;
        padding: 1.15rem;
    }
    .upload-preview-stage {
        width: 100%;
        aspect-ratio: 4 / 3;
        min-height: 220px;
        border-radius: 12px;
        border: 1.5px dashed rgba(13, 85, 155, 0.3);
        background: linear-gradient(145deg, rgba(242, 247, 253, 0.9) 0%, rgba(254, 247, 240, 0.9) 100%);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
        cursor: pointer;
        transition: border-color 0.16s ease, background 0.16s ease;
    }
    .upload-preview-stage:hover {
        border-color: var(--ta-blue);
        background: linear-gradient(145deg, rgba(232, 242, 253, 0.95) 0%, rgba(254, 242, 230, 0.95) 100%);
    }
    .upload-preview-stage img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
</style>

<header class="saas-topbar">
    <div>
        <h1 class="saas-title">Edit Material: <?= htmlspecialchars($mat['id_material']) ?></h1>
        <div class="text-muted" style="font-size: 0.8rem;">Pembaruan spesifikasi, stok gudang, batas minimum restock, dan foto material</div>
    </div>
    <a href="/barang/index.php" class="btn-toolbar-white">
        <i class="bi bi-arrow-left"></i> Kembali ke Daftar Material
    </a>
</header>

<main class="p-4">
    <div class="dribbble-list w-100">
        <div class="px-4 py-3 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background: linear-gradient(90deg, rgba(223, 234, 246, 0.72) 0%, rgba(250, 238, 224, 0.72) 100%); border-bottom: 1px solid rgba(13, 85, 155, 0.14);">
            <div class="d-flex align-items-center gap-3">
                <div class="prod-thumb" style="width: 38px; height: 38px; font-size: 1rem; color: var(--ta-navy);">
                    <i class="bi bi-pencil-square"></i>
                </div>
                <div>
                    <div class="col-main-val" style="color: var(--ta-navy);"><?= htmlspecialchars($mat['nama_material']) ?></div>
                    <div class="col-micro-lbl mb-0">
                        Kode: <strong style="color: var(--ta-blue);"><?= htmlspecialchars($mat['id_material']) ?></strong>
                        &bull; Kolom bertanda <span style="color: var(--ta-orange); font-weight: 700;">*</span> wajib diisi
                    </div>
                </div>
            </div>
            <span class="badge-pill-soft" style="<?= $isLowStock ? 'background: #FFF7ED; color: var(--ta-orange);' : 'background: #ECFDF5; color: var(--ta-green);' ?>">
                <?= $isLowStock ? 'Low Stock' : 'Stok Aman' ?>
            </span>
        </div>

        <div class="p-4">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger py-2 mb-3" style="font-size: 0.82rem;">
                    <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div class="row g-4 align-items-start">
                    <!-- KOLOM KIRI: Input Data & Spesifikasi Material -->
                    <div class="col-lg-8">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="font-size: 0.8rem; color: var(--ta-navy);">ID Material</label>
                                <input type="text" class="form-control fw-bold" style="font-size: 0.84rem; border-color: var(--border-color); color: var(--ta-navy);" value="<?= htmlspecialchars($mat['id_material']) ?>" disabled>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-semibold" style="font-size: 0.8rem; color: var(--ta-navy);">
                                    Nama Material <span style="color: var(--ta-orange);">*</span>
                                </label>
                                <input type="text" name="nama_material" class="form-control" style="font-size: 0.84rem; border-color: var(--border-color);" maxlength="100" value="<?= htmlspecialchars($form['nama_material']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-semibold" style="font-size: 0.8rem; color: var(--ta-navy);">
                                    Kategori <span style="color: var(--ta-orange);">*</span>
                                </label>
                                <select name="kategori" class="form-select" style="font-size: 0.84rem; border-color: var(--border-color);" required>
                                    <option value="">-- Pilih Kategori --</option>
                                    <?php foreach ($kategoriList as $kat): ?>
                                        <option value="<?= htmlspecialchars($kat) ?>" <?= $form['kategori'] === $kat ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($kat) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" style="font-size: 0.8rem; color: var(--ta-navy);">Merek</label>
                                <input type="text" name="merek" class="form-control" style="font-size: 0.84rem; border-color: var(--border-color);" maxlength="50" value="<?= htmlspecialchars($form['merek']) ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-semibold" style="font-size: 0.8rem; color: var(--ta-navy);">Spesifikasi Teknis</label>
                                <textarea name="spesifikasi_teknis" rows="3" class="form-control" style="font-size: 0.84rem; border-color: var(--border-color);"><?= htmlspecialchars($form['spesifikasi_teknis']) ?></textarea>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="font-size: 0.8rem; color: var(--ta-navy);">
                                    Satuan <span style="color: var(--ta-orange);">*</span>
                                </label>
                                <input type="text" name="satuan" class="form-control" style="font-size: 0.84rem; border-color: var(--border-color);" maxlength="20" value="<?= htmlspecialchars($form['satuan']) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="font-size: 0.8rem; color: var(--ta-navy);">
                                    Total Stok <span style="color: var(--ta-orange);">*</span>
                                </label>
                                <input type="number" name="total_stok" class="form-control" style="font-size: 0.84rem; border-color: var(--border-color);" min="0" value="<?= htmlspecialchars($form['total_stok']) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold" style="font-size: 0.8rem; color: var(--ta-navy);">
                                    Batas Minimum <span style="color: var(--ta-orange);">*</span>
                                </label>
                                <input type="number" name="batas_minimum" class="form-control" style="font-size: 0.84rem; border-color: var(--border-color);" min="0" value="<?= htmlspecialchars($form['batas_minimum']) ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- KOLOM KANAN: Upload & Preview Gambar Material -->
                    <div class="col-lg-4">
                        <div class="upload-panel-card">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label for="inputGambar" class="form-label fw-semibold mb-0" style="font-size: 0.82rem; color: var(--ta-navy);">
                                    <i class="bi bi-image me-1" style="color: var(--ta-orange);"></i>Foto Material
                                </label>
                                <span class="badge-pill-soft" style="<?= $hasExistingImg ? 'background: #ECFDF5; color: var(--ta-green);' : '' ?> font-size: 0.66rem;">
                                    <?= $hasExistingImg ? 'Foto Tersimpan' : 'Belum Ada Foto' ?>
                                </span>
                            </div>

                            <label for="inputGambar" class="upload-preview-stage mb-3" id="previewStage">
                                <img id="livePreviewImg"
                                     src="<?= $hasExistingImg ? '/' . htmlspecialchars($existingGambar) : '' ?>"
                                     data-original-src="<?= $hasExistingImg ? '/' . htmlspecialchars($existingGambar) : '' ?>"
                                     alt="Preview Foto Material"
                                     class="<?= $hasExistingImg ? '' : 'd-none' ?>">
                                <div id="previewPlaceholder" class="text-center p-3 <?= $hasExistingImg ? 'd-none' : '' ?>">
                                    <div class="prod-thumb mx-auto mb-2" style="width: 52px; height: 52px; font-size: 1.4rem; background: #FFF7ED; color: var(--ta-orange); border-color: #FED7AA;">
                                        <i class="bi bi-cloud-arrow-up"></i>
                                    </div>
                                    <div class="fw-semibold" style="font-size: 0.82rem; color: var(--ta-navy);">Klik untuk ganti / unggah foto</div>
                                    <div class="col-micro-lbl mb-0 mt-1">JPG, JPEG, PNG, atau WEBP (maks. 5 MB)</div>
                                </div>
                            </label>

                            <input type="file" id="inputGambar" name="gambar" class="form-control form-control-sm" style="font-size: 0.78rem; border-color: var(--border-color);" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">

                            <div id="fileInfoBar" class="d-none mt-2 d-flex align-items-center justify-content-between gap-2">
                                <span id="fileInfoName" class="col-micro-lbl mb-0 text-truncate" style="color: var(--ta-blue); font-weight: 600;"></span>
                                <button type="button" id="btnResetPhoto" class="btn btn-sm p-0 text-danger border-0 bg-transparent" style="font-size: 0.74rem;">
                                    <i class="bi bi-arrow-counterclockwise me-1"></i>Batal Ganti
                                </button>
                            </div>
                            <div class="col-micro-lbl mt-2 mb-0">Biarkan kosong jika tidak ingin mengubah foto saat ini.</div>
                        </div>
                    </div>

                    <!-- Footer Aksi Full-Width -->
                    <div class="col-12 pt-3 mt-1 d-flex align-items-center justify-content-end gap-2" style="border-top: 1px solid var(--border-color);">
                        <a href="/barang/index.php" class="btn-toolbar-white">Batal</a>
                        <button type="submit" class="btn-toolbar-primary">
                            <i class="bi bi-check-lg"></i> Simpan Perubahan
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</main>

<script>
(function () {
    const fileInput   = document.getElementById('inputGambar');
    const previewImg  = document.getElementById('livePreviewImg');
    const placeholder = document.getElementById('previewPlaceholder');
    const infoBar     = document.getElementById('fileInfoBar');
    const infoName    = document.getElementById('fileInfoName');
    const resetBtn    = document.getElementById('btnResetPhoto');

    if (!fileInput || !previewImg || !placeholder) return;

    const originalSrc = previewImg.getAttribute('data-original-src') || '';

    function restoreOriginal() {
        fileInput.value = '';
        if (originalSrc !== '') {
            previewImg.src = originalSrc;
            previewImg.classList.remove('d-none');
            placeholder.classList.add('d-none');
        } else {
            previewImg.src = '';
            previewImg.classList.add('d-none');
            placeholder.classList.remove('d-none');
        }
        if (infoBar) infoBar.classList.add('d-none');
    }

    fileInput.addEventListener('change', function () {
        const file = fileInput.files && fileInput.files[0];
        if (!file) {
            restoreOriginal();
            return;
        }
        const reader = new FileReader();
        reader.onload = function (e) {
            previewImg.src = e.target.result;
            previewImg.classList.remove('d-none');
            placeholder.classList.add('d-none');
            if (infoBar && infoName) {
                const sizeKb = Math.max(1, Math.round(file.size / 1024));
                infoName.textContent = `Baru: ${file.name} (${sizeKb} KB)`;
                infoBar.classList.remove('d-none');
            }
        };
        reader.readAsDataURL(file);
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', restoreOriginal);
    }
})();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
