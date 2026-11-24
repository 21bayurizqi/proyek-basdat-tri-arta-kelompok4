<?php
require_once __DIR__ . '/../config/db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_material        = strtoupper(trim($_POST['id_material'] ?? ''));
    $nama_material      = trim($_POST['nama_material'] ?? '');
    $kategori           = trim($_POST['kategori'] ?? '');
    $merek              = trim($_POST['merek'] ?? '');
    $spesifikasi_teknis = trim($_POST['spesifikasi_teknis'] ?? '');
    $satuan             = trim($_POST['satuan'] ?? '');
    $total_stok         = (int)($_POST['total_stok'] ?? 0);
    $batas_minimum      = (int)($_POST['batas_minimum'] ?? 0);

    if ($id_material === '' || $nama_material === '' || $kategori === '' || $satuan === '') {
        $error = 'ID Material, Nama Material, Kategori, dan Satuan wajib diisi.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO material (id_material, nama_material, kategori, merek, spesifikasi_teknis, satuan, total_stok, batas_minimum)
                VALUES (:id, :nama, :kat, :merek, :spek, :satuan, :stok, :min)
            ");
            $stmt->execute([
                ':id'     => $id_material,
                ':nama'   => $nama_material,
                ':kat'    => $kategori,
                ':merek'  => $merek,
                ':spek'   => $spesifikasi_teknis,
                ':satuan' => $satuan,
                ':stok'   => $total_stok,
                ':min'    => $batas_minimum,
            ]);
            header('Location: /barang/index.php?msg=' . urlencode("Material $nama_material berhasil ditambahkan!"));
            exit;
        } catch (PDOException $e) {
            $error = 'Gagal menyimpan data: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../layout/header.php';
?>

<header class="top-header">
    <h1 class="page-heading">Tambah Material Baru</h1>
    <a href="/barang/index.php" class="btn-ta-outline"><i class="bi bi-arrow-left"></i> Kembali</a>
</header>

<main class="p-4">
    <div class="list-card p-4" style="max-width: 760px;">
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">ID Material *</label>
                <input type="text" name="id_material" class="form-control" placeholder="Contoh: MAT-009" required>
            </div>
            <div class="col-md-8">
                <label class="form-label fw-semibold">Nama Material *</label>
                <input type="text" name="nama_material" class="form-control" placeholder="Contoh: Pop-Up Spray Sprinkler 4 Inch" required>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Kategori *</label>
                <select name="kategori" class="form-select" required>
                    <option value="">-- Pilih Kategori --</option>
                    <option value="Sprinkler & Irigasi">Sprinkler & Irigasi</option>
                    <option value="Pipa & Fitting">Pipa & Fitting</option>
                    <option value="Selang & Aksesoris">Selang & Aksesoris</option>
                    <option value="Kontrol & Elektrikal">Kontrol & Elektrikal</option>
                    <option value="Kabel & Instalasi">Kabel & Instalasi</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Merek</label>
                <input type="text" name="merek" class="form-control" placeholder="Contoh: Rain Bird, Hunter, Wavin">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold">Spesifikasi Teknis</label>
                <input type="text" name="spesifikasi_teknis" class="form-control" placeholder="Contoh: Inlet 1/2 inch, radius semprot 4.5m">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Satuan *</label>
                <input type="text" name="satuan" class="form-control" placeholder="Unit / Batang / Roll / Meter" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Stok Awal</label>
                <input type="number" name="total_stok" class="form-control" min="0" value="0" required>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Batas Minimum Restock</label>
                <input type="number" name="batas_minimum" class="form-control" min="0" value="5" required>
            </div>
            <div class="col-12 pt-2">
                <button type="submit" class="btn-ta-navy"><i class="bi bi-check-lg"></i> Simpan Material</button>
            </div>
        </form>
    </div>
</main>

<?php include __DIR__ . '/../layout/footer.php'; ?>