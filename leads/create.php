<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
require_once __DIR__ . '/../config/db.php';

$sumberList  = ['WhatsApp', 'Kabar Burung', 'Relasi', 'Lainnya'];
$tahapanList = ['New Lead', 'Opportunity', 'Site Visit', 'Quotation', 'Win', 'Loss'];

$stafList = $pdo->query("
    SELECT s.id_staf, s.nama, j.nama_jabatan
    FROM staf s
    JOIN jabatan j ON s.id_jabatan = j.id_jabatan
    ORDER BY s.id_staf ASC
")->fetchAll();

$error = '';
$form = [
    'nama_klien'       => '',
    'sumber_informasi' => 'WhatsApp',
    'tahapan_pipeline' => 'New Lead',
    'alasan_loss'      => '',
    'id_staf_pic'      => $_SESSION['id_staf'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama_klien       = trim($_POST['nama_klien'] ?? '');
    $sumber_informasi = trim($_POST['sumber_informasi'] ?? '');
    $tahapan_pipeline = trim($_POST['tahapan_pipeline'] ?? 'New Lead');
    $alasan_loss      = trim($_POST['alasan_loss'] ?? '');
    $id_staf_pic      = trim($_POST['id_staf_pic'] ?? '');

    $form = [
        'nama_klien'       => $nama_klien,
        'sumber_informasi' => $sumber_informasi,
        'tahapan_pipeline' => $tahapan_pipeline,
        'alasan_loss'      => $alasan_loss,
        'id_staf_pic'      => $id_staf_pic,
    ];

    if ($nama_klien === '') {
        $error = 'Nama Klien / Proyek Prospek wajib diisi.';
    } elseif ($sumber_informasi !== '' && !in_array($sumber_informasi, $sumberList, true)) {
        $error = 'Sumber informasi tidak valid.';
    } elseif (!in_array($tahapan_pipeline, $tahapanList, true)) {
        $error = 'Tahapan pipeline tidak valid.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO leads (nama_klien, sumber_informasi, tahapan_pipeline, alasan_loss, id_staf_pic)
                VALUES (:klien, :sumber, :tahapan, :alasan, :pic)
            ");
            $stmt->execute([
                ':klien'   => $nama_klien,
                ':sumber'  => $sumber_informasi !== '' ? $sumber_informasi : null,
                ':tahapan' => $tahapan_pipeline,
                ':alasan'  => ($tahapan_pipeline === 'Loss' && $alasan_loss !== '') ? $alasan_loss : null,
                ':pic'     => $id_staf_pic !== '' ? $id_staf_pic : null,
            ]);
            header('Location: /leads/index.php?msg=' . urlencode("Lead \"$nama_klien\" berhasil ditambahkan ke pipeline CRM!"));
            exit;
        } catch (PDOException $e) {
            $error = 'Gagal menyimpan data lead: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../layout/header.php';
?>

<header class="saas-topbar">
    <div>
        <h1 class="saas-title">Tambah Lead Baru</h1>
        <div class="text-muted" style="font-size: 14px;">Catat prospek klien baru ke dalam CRM pipeline PT Tri Arta Konstruksi</div>
    </div>
    <a href="/leads/index.php" class="btn-toolbar-white">
        <i class="bi bi-arrow-left"></i> Kembali ke CRM Leads
    </a>
</header>

<main class="p-4">
    <div class="row g-0">
        <div class="col-12">
            <div class="dribbble-list w-100">
                <div class="px-4 py-3 d-flex align-items-center justify-content-between flex-wrap gap-2" style="background: linear-gradient(90deg, rgba(255, 241, 224, 0.82) 0%, rgba(226, 237, 249, 0.72) 100%); border-bottom: 1px solid rgba(243, 130, 24, 0.25);">
                    <div class="d-flex align-items-center gap-3">
                        <div class="prod-thumb" style="width: 42px; height: 42px; font-size: 1.1rem; background: rgba(255, 248, 240, 0.9); color: var(--ta-orange); border-color: rgba(243, 130, 24, 0.35);">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <div>
                            <div class="col-main-val" style="color: var(--ta-navy);">Form Registrasi Prospek CRM</div>
                            <div class="col-micro-lbl mb-0">Kolom bertanda <span style="color: var(--ta-orange); font-weight: 700;">*</span> wajib diisi</div>
                        </div>
                    </div>
                    <span class="badge-pill-soft" style="background: rgba(255, 248, 240, 0.92); color: var(--ta-orange); border: 1px solid rgba(243, 130, 24, 0.35);">CRM Pipeline</span>
                </div>

                <div class="p-4">
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger py-2 mb-3" style="font-size: 14px;">
                            <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                                Nama Klien / Proyek Prospek <span style="color: var(--ta-orange);">*</span>
                            </label>
                            <input type="text" name="nama_klien" class="form-control" style="font-size: 14.5px;" maxlength="100" placeholder="Contoh: Pengelola Lapangan Golf Sentul Selatan" value="<?= htmlspecialchars($form['nama_klien']) ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                                Sumber Informasi <span style="color: var(--ta-orange);">*</span>
                            </label>
                            <select name="sumber_informasi" class="form-select" style="font-size: 14.5px;" required>
                                <?php foreach ($sumberList as $sb): ?>
                                    <option value="<?= htmlspecialchars($sb) ?>" <?= $form['sumber_informasi'] === $sb ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sb) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                                Tahapan Pipeline <span style="color: var(--ta-orange);">*</span>
                            </label>
                            <select name="tahapan_pipeline" id="tahapanPipeline" class="form-select" style="font-size: 14.5px;" required>
                                <?php foreach ($tahapanList as $tp): ?>
                                    <option value="<?= htmlspecialchars($tp) ?>" <?= $form['tahapan_pipeline'] === $tp ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($tp) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">Staf PIC (Penanggung Jawab)</label>
                            <select name="id_staf_pic" class="form-select" style="font-size: 14.5px;">
                                <option value="">-- Pilih Staf PIC --</option>
                                <?php foreach ($stafList as $s): ?>
                                    <option value="<?= htmlspecialchars($s['id_staf']) ?>" <?= $form['id_staf_pic'] === $s['id_staf'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['nama'] . ' (' . $s['nama_jabatan'] . ')') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12" id="alasanLossWrap" style="<?= $form['tahapan_pipeline'] === 'Loss' ? '' : 'display: none;' ?>">
                            <label class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">Alasan Loss</label>
                            <textarea name="alasan_loss" rows="3" class="form-control" style="font-size: 14.5px;" placeholder="Catat alasan mengapa prospek tidak lanjut (harga, jadwal, kompetitor, dll.)"><?= htmlspecialchars($form['alasan_loss']) ?></textarea>
                        </div>

                        <div class="col-12 pt-3 mt-1 d-flex align-items-center justify-content-end gap-2" style="border-top: 1px solid rgba(13, 85, 155, 0.14);">
                            <a href="/leads/index.php" class="btn-toolbar-white">Batal</a>
                            <button type="submit" class="btn-toolbar-orange">
                                <i class="bi bi-check-lg"></i> Simpan Lead
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
    (function () {
        const select = document.getElementById('tahapanPipeline');
        const wrap = document.getElementById('alasanLossWrap');
        if (select && wrap) {
            select.addEventListener('change', function () {
                wrap.style.display = select.value === 'Loss' ? '' : 'none';
            });
        }
    })();
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>

