<?php
require_once __DIR__ . '/../config/db.php';

$id = $_GET['id'] ?? '';
$stmt = $pdo->prepare("SELECT * FROM material WHERE id_material = :id");
$stmt->execute([':id' => $id]);
$mat = $stmt->fetch();

if (!$mat) {
    header('Location: /barang/index.php?err=' . urlencode('Data material tidak ditemukan.'));
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_material      = trim($_POST['nama_material'] ?? '');
    $kategori           = trim($_POST['kategori'] ?? '');
    $merek              = trim($_POST['merek'] ?? '');
    $spesifikasi_teknis = trim($_POST['spesifikasi_teknis'] ?? '');
    $satuan             = trim($_POST['satuan'] ?? '');
    $total_stok         = (int)($_POST['total_stok'] ?? 0);
    $batas_minimum      = (int)($_POST['batas_minimum'] ?? 0);

    try {
        $upd = $pdo->prepare("
            UPDATE material
            SET nama_material = :nama, kategori = :kat, merek = :merek,
                spesifikasi_teknis = :spek, satuan = :satuan,
                total_stok = :stok, batas_minimum = :min
            WHERE id_material = :id
        ");
        $upd->execute([
            ':nama'   => $nama_material,
            ':kat'    => $kategori,
            ':merek'  => $merek,
            ':spek'   => $spesifikasi_teknis,
            ':satuan' => $satuan,
            ':stok'   => $total_stok,
            ':min'    => $batas_minimum,
            ':id'     => $id,
        ]);
        header('Location: /barang/index.php?msg=' . urlencode("Material $id berhasil diperbarui!"));
        exit;
    } catch (PDOException $e) {
        $error = 'Gagal memperbarui data: ' . $e->getMessage();
    }
}

include __DIR__ . '/../layout/header.php';
?>

<header class="top-header">
    <h1 class="page-heading">Edit Material: <?= htmlspecialchars($mat['id_material']) ?></h1>
    <a href="/barang/index.php" class="btn-ta-outline"><i class="bi bi-arrow-left"></i> Kembali</a>
</header>

<main class="p-4">
    <div class="list-card p-4" style="max-width: 760px;">
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">ID Material</label>
                <input type="text" class="form-control bg-light" value="<?= htmlspecialchars($mat['id_material']) ?>" disabled>
            </div>
            <div class="col-md-8">
                <label class="form-label fw-semibold">Nama Material *</label>
                <input type="text" name="nama_material" class="form-control" value="<?= htmlspecialchars($mat['nama_material']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Kategori *</label>
                <input type="text" name="kategori" class="form-control" value="<?= htmlspecialchars($mat['kategori']) ?>" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Merek</label>
                <input type="text" name="merek" class="form-control" value="<?= htmlspecialchars($mat['merek'] ?? '') ?>">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Spesifikasi Teknis</label>
                <input type="text" name="spesifikasi_teknis" class="form-control" value="<?= htmlspecialchars($mat['spesifikasi_teknis'] ?? '') ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Satuan *</label>
                <input type="text" name="satuan" class="form-control" value="<?= htmlspecialchars($mat['satuan']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Total Stok</label>
                <input type="number" name="total_stok" class="form-control" min="0" value="<?= (int)$mat['total_stok'] ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Batas Minimum</label>
                <input type="number" name="batas_minimum" class="form-control" min="0" value="<?= (int)$mat['batas_minimum'] ?>" required>
            </div>
            <div class="col-12 pt-2">
                <button type="submit" class="btn-ta-navy"><i class="bi bi-save"></i> Simpan Perubahan</button>
            </div>
        </form>
    </div>
</main>

<?php include __DIR__ . '/../layout/footer.php'; ?>