<?php
/**
 * Helper sinkronisasi otomatis tabel `material` <-> `kemasan_qr` (PostgreSQL).
 */

function getDefaultSectionGudang(string $kategori): string
{
    return match (trim($kategori)) {
        'Perpipaan'         => 'Section B1 - Zona Pipa',
        'Irigasi'           => 'Section A1 - Rak Irigasi',
        'Elektrikal'        => 'Section D1 - Rak Elektrikal',
        'Kontrol & Otomasi' => 'Section C1 - Lemari Kontrol',
        default             => 'Section G1 - Gudang Utama',
    };
}

function createInitialKemasanQr(
    PDO $pdo,
    string $idMaterial,
    string $kategori,
    int $qtyStok,
    ?string $sectionGudang = null
): ?string {
    if ($qtyStok <= 0 || trim($idMaterial) === '') {
        return null;
    }

    $idMaterial = strtoupper(trim($idMaterial));
    $section    = trim((string)$sectionGudang) !== ''
        ? substr(trim((string)$sectionGudang), 0, 50)
        : getDefaultSectionGudang($kategori);

    // Cari nomor urut berikutnya untuk kode QR format QR-MAT-XXX-01
    $stmtSeq = $pdo->prepare("
        SELECT COUNT(*)
        FROM kemasan_qr
        WHERE id_material = :id_mat
    ");
    $stmtSeq->execute([':id_mat' => $idMaterial]);
    $seq = (int)$stmtSeq->fetchColumn() + 1;

    $stmtExists = $pdo->prepare("SELECT 1 FROM kemasan_qr WHERE kode_qr = :qr LIMIT 1");
    do {
        $kodeQr = sprintf('QR-%s-%02d', $idMaterial, $seq);
        $stmtExists->execute([':qr' => $kodeQr]);
        $exists = (bool)$stmtExists->fetchColumn();
        if ($exists) {
            $seq++;
        }
    } while ($exists);

    $noBatch = sprintf('BATCH-%s-AUTO', date('Y'));

    $stmtIns = $pdo->prepare("
        INSERT INTO kemasan_qr (kode_qr, id_material, section_gudang, no_batch, qty_awal, qty_sisa, tgl_masuk, status_kemasan)
        VALUES (:qr, :id_mat, :section, :batch, :awal, :sisa, CURRENT_DATE, 'Tersedia')
        ON CONFLICT (kode_qr) DO NOTHING
    ");
    $stmtIns->execute([
        ':qr'      => $kodeQr,
        ':id_mat'  => $idMaterial,
        ':section' => $section,
        ':batch'   => $noBatch,
        ':awal'    => $qtyStok,
        ':sisa'    => $qtyStok,
    ]);

    return $kodeQr;
}

/**
 * Sinkronisasi otomatis: buatkan 1 entri `kemasan_qr` awal untuk setiap `material`
 * yang memiliki `total_stok > 0` namun belum memiliki satu pun baris di `kemasan_qr`.
 */
function syncMissingKemasanQr(PDO $pdo): int
{
    $missingRows = $pdo->query("
        SELECT m.id_material, m.kategori, m.total_stok
        FROM material m
        LEFT JOIN kemasan_qr k ON m.id_material = k.id_material
        WHERE m.total_stok > 0
          AND k.kode_qr IS NULL
        ORDER BY m.id_material ASC
    ")->fetchAll();

    if (count($missingRows) === 0) {
        return 0;
    }

    $created = 0;
    $manageTx = !$pdo->inTransaction();
    try {
        if ($manageTx) {
            $pdo->beginTransaction();
        }
        foreach ($missingRows as $row) {
            $qr = createInitialKemasanQr(
                $pdo,
                $row['id_material'],
                $row['kategori'],
                (int)$row['total_stok']
            );
            if ($qr !== null) {
                $created++;
            }
        }
        if ($manageTx) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($manageTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    return $created;
}

