<?php
/**
 * Helper bersama untuk Kategori Material Dinamis & Sinkronisasi Leads Win -> Proyek.
 */

/**
 * Normalisasi kode warna hex ke format #RRGGBB huruf besar.
 */
function normalizeHexColor(?string $hex): ?string
{
    $clean = strtoupper(trim((string)$hex));
    return preg_match('/^#[0-9A-F]{6}$/', $clean) ? $clean : null;
}

/**
 * Konversi #RRGGBB ke [r, g, b] (0..255).
 */
function hexColorToRgb(string $hex): array
{
    return [
        hexdec(substr($hex, 1, 2)),
        hexdec(substr($hex, 3, 2)),
        hexdec(substr($hex, 5, 2)),
    ];
}

/**
 * Hitung Hue (0..360) dari komponen RGB.
 */
function rgbToHueDegrees(int $r, int $g, int $b): float
{
    $rf = $r / 255.0;
    $gf = $g / 255.0;
    $bf = $b / 255.0;
    $max = max($rf, $gf, $bf);
    $min = min($rf, $gf, $bf);
    $delta = $max - $min;

    if ($delta < 0.0001) {
        return 0.0;
    }

    if ($max === $rf) {
        $hue = 60.0 * fmod((($gf - $bf) / $delta), 6.0);
    } elseif ($max === $gf) {
        $hue = 60.0 * ((($bf - $rf) / $delta) + 2.0);
    } else {
        $hue = 60.0 * ((($rf - $gf) / $delta) + 4.0);
    }

    return $hue < 0.0 ? $hue + 360.0 : $hue;
}

/**
 * Konversi HSL (h: 0..360, s: 0..100, l: 0..100) ke #RRGGBB huruf besar.
 */
function hslToHexColor(float $h, float $s, float $l): string
{
    $h = fmod($h, 360.0);
    if ($h < 0.0) {
        $h += 360.0;
    }
    $sf = max(0.0, min(100.0, $s)) / 100.0;
    $lf = max(0.0, min(100.0, $l)) / 100.0;

    $c = (1.0 - abs(2.0 * $lf - 1.0)) * $sf;
    $x = $c * (1.0 - abs(fmod($h / 60.0, 2.0) - 1.0));
    $m = $lf - ($c / 2.0);

    if ($h < 60.0) {
        [$r1, $g1, $b1] = [$c, $x, 0.0];
    } elseif ($h < 120.0) {
        [$r1, $g1, $b1] = [$x, $c, 0.0];
    } elseif ($h < 180.0) {
        [$r1, $g1, $b1] = [0.0, $c, $x];
    } elseif ($h < 240.0) {
        [$r1, $g1, $b1] = [0.0, $x, $c];
    } elseif ($h < 300.0) {
        [$r1, $g1, $b1] = [$x, 0.0, $c];
    } else {
        [$r1, $g1, $b1] = [$c, 0.0, $x];
    }

    $r = (int)round(($r1 + $m) * 255.0);
    $g = (int)round(($g1 + $m) * 255.0);
    $b = (int)round(($b1 + $m) * 255.0);

    return sprintf('#%02X%02X%02X', max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)));
}

/**
 * Cek apakah kandidat warna sama atau terlalu mirip (jarak hue & RGB terlalu dekat)
 * dengan daftar warna yang sudah digunakan di kategori_material.
 */
function isColorTooSimilar(string $candidateHex, array $usedHexes, float $minHueDiff = 18.0, float $minRgbDist = 60.0): bool
{
    [$cr, $cg, $cb] = hexColorToRgb($candidateHex);
    $cHue = rgbToHueDegrees($cr, $cg, $cb);

    foreach ($usedHexes as $usedHex) {
        if ($candidateHex === $usedHex) {
            return true;
        }
        [$ur, $ug, $ub] = hexColorToRgb($usedHex);
        $rgbDist = sqrt((($cr - $ur) ** 2) + (($cg - $ug) ** 2) + (($cb - $ub) ** 2));
        if ($rgbDist < 42.0) {
            return true;
        }

        $uHue = rgbToHueDegrees($ur, $ug, $ub);
        $rawHueDiff = abs($cHue - $uHue);
        $hueDiff = min($rawHueDiff, 360.0 - $rawHueDiff);

        if ($hueDiff < $minHueDiff && $rgbDist < $minRgbDist) {
            return true;
        }
    }

    return false;
}

/**
 * Memilih warna indikator dot secara otomatis, kontras lintas spektrum,
 * anti-sama dan anti-mirip berdasarkan warna yang sudah terdaftar di kategori_material.
 */
function pickDistinctCategoryColor(PDO $pdo): string
{
    $rows = $pdo->query("SELECT warna_dot FROM kategori_material")->fetchAll(PDO::FETCH_COLUMN);
    $usedHexes = [];
    foreach ($rows as $rawHex) {
        $norm = normalizeHexColor($rawHex);
        if ($norm !== null && !in_array($norm, $usedHexes, true)) {
            $usedHexes[] = $norm;
        }
    }

    // Daftar palet warna kontras lintas spektrum (urutan prioritas)
    $contrastPalette = [
        '#10B981', // 1. Emerald Green
        '#EC4899', // 2. Pink / Magenta
        '#EF4444', // 3. Crimson Red
        '#EAB308', // 4. Gold / Kuning Hangat
        '#6366F1', // 5. Indigo
        '#F97316', // 6. Tangerine
        '#14B8A6', // 7. Tosca
        '#D946EF', // 8. Fuchsia
        '#84CC16', // 9. Lime
        '#F43F5E', // 10. Rose
    ];

    // Tahap 1: Cari warna pertama di palet yang belum dipakai & tidak terlalu mirip dengan warna eksisting
    foreach ($contrastPalette as $hex) {
        if (!in_array($hex, $usedHexes, true) && !isColorTooSimilar($hex, $usedHexes, 18.0, 65.0)) {
            return $hex;
        }
    }

    // Tahap 2: Cadangan otomatis menggunakan rumus Golden Angle (hue = fmod(total_kategori * 137.508, 360), S=75%, L=48%)
    $totalKategori = count($rows);
    $bestFallback  = null;
    $bestMinDist   = -1.0;

    for ($step = 0; $step < 72; $step++) {
        $n   = $totalKategori + $step;
        $hue = fmod($n * 137.508, 360.0);
        $hex = hslToHexColor($hue, 75.0, 48.0);

        if (in_array($hex, $usedHexes, true)) {
            continue;
        }

        if (!isColorTooSimilar($hex, $usedHexes, 16.0, 55.0)) {
            return $hex;
        }

        // Simpan kandidat paling jauh bila daftar kategori sudah sangat padat
        [$cr, $cg, $cb] = hexColorToRgb($hex);
        $minDist = INF;
        foreach ($usedHexes as $uHex) {
            [$ur, $ug, $ub] = hexColorToRgb($uHex);
            $d = sqrt((($cr - $ur) ** 2) + (($cg - $ug) ** 2) + (($cb - $ub) ** 2));
            if ($d < $minDist) {
                $minDist = $d;
            }
        }
        if ($minDist > $bestMinDist) {
            $bestMinDist  = $minDist;
            $bestFallback = $hex;
        }
    }

    return $bestFallback ?? hslToHexColor(fmod(($totalKategori + 1) * 137.508, 360.0), 75.0, 48.0);
}

/**
 * Path file penanda (lock file) migrasi skema otomatis agar DDL/migrasi hanya berjalan 1 kali.
 */
function getSchemaLockFilePath(): string
{
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ta_schema_v5.lock';
}

/**
 * Jalankan seluruh migrasi skema aman tepat 1 kali hingga file penanda ta_schema_v5.lock terbentuk.
 */
function ensureSchemaMigrationsOnce(PDO $pdo): void
{
    static $doneInProcess = false;
    static $running       = false;

    if ($doneInProcess || $running) {
        return;
    }

    $lockFile = getSchemaLockFilePath();
    if (is_file($lockFile)) {
        $doneInProcess = true;
        return;
    }

    $running = true;
    try {
        _runKategoriMaterialMigration($pdo);
        _runMaterialFotoDataMigration($pdo);
        _runLeadsExtraColumnsMigration($pdo);
        _runProyekLeadColumnMigration($pdo);
        _runLegacyWinContractsMigration($pdo);
        @file_put_contents($lockFile, 'migrated_at=' . date('c') . "\n", LOCK_EX);
        $doneInProcess = true;
    } finally {
        $running = false;
    }
}

/**
 * Migrasi aman kolom `material.foto_data TEXT` untuk cadangan foto Base64 di Render Free Tier.
 */
function _runMaterialFotoDataMigration(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $pdo->exec("ALTER TABLE material ADD COLUMN IF NOT EXISTS gambar VARCHAR(255)");
    $pdo->exec("ALTER TABLE material ADD COLUMN IF NOT EXISTS foto_data TEXT");

    // Backfill aman 1 kali untuk foto material lokal yang sudah ada di disk namun belum memiliki foto_data
    $rows = $pdo->query("
        SELECT id_material, gambar
        FROM material
        WHERE gambar IS NOT NULL
          AND TRIM(gambar) <> ''
          AND (foto_data IS NULL OR TRIM(foto_data) = '')
    ")->fetchAll();

    if (count($rows) > 0) {
        $projectRoot = dirname(__DIR__);
        $upd = $pdo->prepare("UPDATE material SET foto_data = :fd WHERE id_material = :id");
        foreach ($rows as $r) {
            $rel  = ltrim((string)$r['gambar'], '/');
            $full = $projectRoot . '/' . $rel;
            if ($rel !== '' && is_file($full) && is_readable($full)) {
                $dataUri = compressImageToBase64DataUri($full, 800, 75);
                if ($dataUri !== null) {
                    $upd->execute([':fd' => $dataUri, ':id' => $r['id_material']]);
                }
            }
        }
    }

    $ran = true;
}

/**
 * Kompresi gambar menggunakan PHP GD ke lebar maksimal $maxWidth px (JPEG kualitas $jpegQuality%)
 * dan kembalikan sebagai Base64 Data URI (`data:image/jpeg;base64,...`).
 */
function compressImageToBase64DataUri(string $filePath, int $maxWidth = 800, int $jpegQuality = 75): ?string
{
    if (!is_file($filePath) || !is_readable($filePath)) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($filePath);

    if (function_exists('imagecreatetruecolor') && function_exists('imagejpeg')) {
        $srcImg = match ($mime) {
            'image/jpeg', 'image/jpg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($filePath) : false,
            'image/png'               => function_exists('imagecreatefrompng') ? @imagecreatefrompng($filePath) : false,
            'image/webp'              => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : false,
            default                   => false,
        };

        if ($srcImg !== false) {
            $origW = imagesx($srcImg);
            $origH = imagesy($srcImg);
            if ($origW > 0 && $origH > 0) {
                if ($origW > $maxWidth) {
                    $newW = $maxWidth;
                    $newH = max(1, (int)round($origH * ($maxWidth / $origW)));
                } else {
                    $newW = $origW;
                    $newH = $origH;
                }

                $dstImg = imagecreatetruecolor($newW, $newH);
                if ($dstImg !== false) {
                    // Latar putih bersih agar transparansi PNG/WEBP tidak menjadi hitam pada JPEG
                    $white = imagecolorallocate($dstImg, 255, 255, 255);
                    imagefill($dstImg, 0, 0, $white);
                    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

                    ob_start();
                    $ok = imagejpeg($dstImg, null, max(30, min(95, $jpegQuality)));
                    $jpegBytes = ob_get_clean();

                    unset($dstImg, $srcImg);

                    if ($ok && is_string($jpegBytes) && $jpegBytes !== '') {
                        return 'data:image/jpeg;base64,' . base64_encode($jpegBytes);
                    }
                } else {
                    unset($srcImg);
                }
            } else {
                unset($srcImg);
            }
        }
    }

    // Fallback aman jika ekstensi GD belum aktif di lingkungan lokal tertentu
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) && filesize($filePath) <= 2 * 1024 * 1024) {
        $raw = @file_get_contents($filePath);
        if (is_string($raw) && $raw !== '') {
            return 'data:' . $mime . ';base64,' . base64_encode($raw);
        }
    }

    return null;
}

/**
 * Resolve sumber gambar material: prioritaskan file lokal di disk bila ada,
 * lalu fallback otomatis ke Base64 Data URI `foto_data` dari PostgreSQL (tahan reset container Render).
 */
function resolveMaterialImageSrc(?string $gambarPath, ?string $fotoData, string $projectRoot): string
{
    $clean = trim(ltrim((string)($gambarPath ?? ''), '/'));
    if ($clean !== '' && is_file(rtrim($projectRoot, '/') . '/' . $clean)) {
        return '/' . $clean;
    }
    $cleanData = trim((string)($fotoData ?? ''));
    if ($cleanData !== '' && str_starts_with($cleanData, 'data:image/')) {
        return $cleanData;
    }
    return '';
}

function ensureKategoriMaterialTable(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    if (is_file(getSchemaLockFilePath())) {
        $ensured = true;
        return;
    }
    ensureSchemaMigrationsOnce($pdo);
    $ensured = true;
}

function _runKategoriMaterialMigration(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kategori_material (
            id_kategori SERIAL PRIMARY KEY,
            nama_kategori VARCHAR(100) NOT NULL UNIQUE,
            warna_dot VARCHAR(20) NOT NULL DEFAULT '#0D559B'
        )
    ");

    // 4 kategori bawaan dengan 4 warna berbeda kontras
    $defaults = [
        ['Elektrikal', '#F26522'],        // Oranye
        ['Irigasi', '#0D559B'],           // Royal Blue
        ['Kontrol & Otomasi', '#8B5CF6'], // Ungu
        ['Perpipaan', '#06B6D4'],         // Cyan / Teal
    ];
    $ins = $pdo->prepare("
        INSERT INTO kategori_material (nama_kategori, warna_dot)
        VALUES (:nama, :warna)
        ON CONFLICT (nama_kategori) DO NOTHING
    ");
    foreach ($defaults as [$nama, $warna]) {
        $ins->execute([':nama' => $nama, ':warna' => $warna]);
    }

    // Pastikan kategori tambahan yang sudah ada di tabel material ikut terdaftar dengan warna otomatis kontras
    $missingKats = $pdo->query("
        SELECT DISTINCT TRIM(m.kategori) AS nama_kat
        FROM material m
        WHERE m.kategori IS NOT NULL
          AND TRIM(m.kategori) <> ''
          AND NOT EXISTS (
              SELECT 1 FROM kategori_material km
              WHERE LOWER(TRIM(km.nama_kategori)) = LOWER(TRIM(m.kategori))
          )
        ORDER BY nama_kat ASC
    ")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($missingKats as $namaKat) {
        $autoColor = pickDistinctCategoryColor($pdo);
        $ins->execute([':nama' => $namaKat, ':warna' => $autoColor]);
    }

    $ran = true;
}

function getKategoriMaterialWithCounts(PDO $pdo): array
{
    ensureKategoriMaterialTable($pdo);

    return $pdo->query("
        SELECT km.id_kategori, km.nama_kategori, km.warna_dot,
               COUNT(m.id_material) AS jml_material
        FROM kategori_material km
        LEFT JOIN material m ON m.kategori = km.nama_kategori
        GROUP BY km.id_kategori, km.nama_kategori, km.warna_dot
        ORDER BY km.nama_kategori ASC
    ")->fetchAll();
}

function getKategoriMaterialNames(PDO $pdo): array
{
    ensureKategoriMaterialTable($pdo);

    return $pdo->query("
        SELECT nama_kategori
        FROM kategori_material
        ORDER BY nama_kategori ASC
    ")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Daftar indikator statistik standar untuk keputusan proyek Win & Loss.
 */
function getLeadWinIndicators(): array
{
    return [
        'Harga Kompetitif',
        'Kualitas & Spesifikasi Cocok',
        'Relasi / Klien Percaya',
        'Respon & Survei Cepat',
    ];
}

function getLeadLossIndicators(): array
{
    return [
        'Keduluan Kompetitor',
        'Harga Kalah Bersaing',
        'Proyek Batal / Ditunda',
        'Spesifikasi Tidak Cocok',
    ];
}

/**
 * Helper format & kalkulasi finansial proyek (IDR & Margin %).
 */
function parseIdrCurrency(?string $raw): ?float
{
    $clean = trim((string)$raw);
    if ($clean === '') {
        return null;
    }
    // Hapus "Rp", spasi, dan pemisah ribuan titik; dukung koma desimal bila ada
    $clean = preg_replace('/[^0-9,\.]/', '', $clean);
    if ($clean === '' || $clean === null) {
        return null;
    }
    // Jika format Indonesia "150.000.000" atau "150.000.000,50"
    if (str_contains($clean, ',')) {
        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);
    } elseif (substr_count($clean, '.') > 1 || preg_match('/\.\d{3}$/', $clean)) {
        $clean = str_replace('.', '', $clean);
    }
    if (!is_numeric($clean)) {
        return null;
    }
    return round((float)$clean, 2);
}

function formatRupiah(?float $amount, bool $compact = false): string
{
    if ($amount === null) {
        return '-';
    }
    if ($compact) {
        $abs = abs($amount);
        if ($abs >= 1_000_000_000) {
            return 'Rp ' . number_format($amount / 1_000_000_000, 2, ',', '.') . ' M';
        }
        if ($abs >= 1_000_000) {
            return 'Rp ' . number_format($amount / 1_000_000, 1, ',', '.') . ' Jt';
        }
    }
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function getMarginHealthMeta(?float $marginPct): array
{
    if ($marginPct === null) {
        return [
            'label'  => 'Belum Dihitung',
            'color'  => '#64748B',
            'bg'     => '#F1F5F9',
            'border' => 'rgba(100, 116, 139, 0.22)',
        ];
    }
    if ($marginPct >= 30.0) {
        return [
            'label'  => 'Margin Tinggi',
            'color'  => '#059669',
            'bg'     => '#ECFDF5',
            'border' => 'rgba(16, 185, 129, 0.3)',
        ];
    }
    if ($marginPct >= 15.0) {
        return [
            'label'  => 'Margin Sehat',
            'color'  => '#0D559B',
            'bg'     => '#EFF6FF',
            'border' => 'rgba(13, 85, 155, 0.28)',
        ];
    }
    return [
        'label'  => 'Margin Tipis',
        'color'  => '#EA580C',
        'bg'     => '#FFF7ED',
        'border' => 'rgba(243, 130, 24, 0.32)',
    ];
}

/**
 * Pastikan seluruh kolom tambahan CRM Leads tersedia di PostgreSQL tanpa menghapus data lama.
 */
function ensureLeadsExtraColumns(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    if (is_file(getSchemaLockFilePath())) {
        $ensured = true;
        return;
    }
    ensureSchemaMigrationsOnce($pdo);
    $ensured = true;
}

function _runLeadsExtraColumnsMigration(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }

    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS nama_proyek_prospek VARCHAR(200)");
    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS sumber_lainnya VARCHAR(100)");
    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS lokasi_proyek TEXT");
    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS latitude DECIMAL(10, 7)");
    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS longitude DECIMAL(10, 7)");
    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS nilai_proyek NUMERIC(15, 2)");
    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS keuntungan_bersih NUMERIC(15, 2)");
    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS margin_persen NUMERIC(5, 2)");
    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS is_site_visit BOOLEAN DEFAULT FALSE");
    $pdo->exec("ALTER TABLE leads ALTER COLUMN is_site_visit SET DEFAULT FALSE");
    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS tgl_site_visit DATE DEFAULT NULL");
    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS indikator_hasil VARCHAR(120)");
    $pdo->exec("ALTER TABLE leads ADD COLUMN IF NOT EXISTS alasan_hasil TEXT");
    $pdo->exec("UPDATE leads SET is_site_visit = FALSE WHERE is_site_visit IS NULL");
    $pdo->exec("
        UPDATE leads
        SET tahapan_pipeline = 'Opportunity',
            is_site_visit    = TRUE
        WHERE tahapan_pipeline = 'Site Visit'
    ");
    $pdo->exec("
        DO $$
        DECLARE
            c_name TEXT;
        BEGIN
            FOR c_name IN
                SELECT conname
                FROM pg_constraint
                WHERE conrelid = 'leads'::regclass
                  AND contype = 'c'
                  AND pg_get_constraintdef(oid) ILIKE '%tahapan_pipeline%'
                  AND pg_get_constraintdef(oid) ILIKE '%Site Visit%'
            LOOP
                EXECUTE format('ALTER TABLE leads DROP CONSTRAINT IF EXISTS %I', c_name);
            END LOOP;

            IF NOT EXISTS (
                SELECT 1
                FROM pg_constraint
                WHERE conrelid = 'leads'::regclass
                  AND contype = 'c'
                  AND conname = 'leads_tahapan_pipeline_check'
            ) THEN
                ALTER TABLE leads
                    ADD CONSTRAINT leads_tahapan_pipeline_check
                    CHECK (tahapan_pipeline IN ('New Lead', 'Opportunity', 'Quotation', 'Win', 'Loss'));
            END IF;
        END $$;
    ");
    $pdo->exec("
        UPDATE leads
        SET alasan_hasil = alasan_loss
        WHERE alasan_hasil IS NULL
          AND alasan_loss IS NOT NULL
          AND TRIM(alasan_loss) <> ''
    ");
    $ran = true;
}

/**
 * Konversi nilai boolean PostgreSQL/PDO ('t'/'f', true/false, 1/0, null) secara aman ke bool PHP.
 */
function pgBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_string($value)) {
        $v = strtolower(trim($value));
        if ($v === 't' || $v === 'true' || $v === '1' || $v === 'yes' || $v === 'on') {
            return true;
        }
        if ($v === 'f' || $v === 'false' || $v === '0' || $v === 'no' || $v === 'off' || $v === '') {
            return false;
        }
    }
    return filter_var($value ?? false, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Format tanggal YYYY-MM-DD menjadi 'DD MMM YYYY' (Bahasa Indonesia ringkas).
 */
function formatIndoShortDate(?string $ymd): string
{
    $clean = trim((string)$ymd);
    if ($clean === '') {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', substr($clean, 0, 10));
    if (!$dt) {
        return $clean;
    }
    $months = [
        '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
        '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu',
        '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des',
    ];
    $m = $months[$dt->format('m')] ?? $dt->format('M');
    return $dt->format('d') . ' ' . $m . ' ' . $dt->format('Y');
}

/**
 * Pastikan kolom pelacak lead sumber pada tabel `proyek` tersedia untuk cegah duplikasi.
 */
function ensureProyekLeadColumn(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    if (is_file(getSchemaLockFilePath())) {
        $ensured = true;
        return;
    }
    ensureSchemaMigrationsOnce($pdo);
    $ensured = true;
}

function _runProyekLeadColumnMigration(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $pdo->exec("ALTER TABLE proyek ADD COLUMN IF NOT EXISTS id_lead_sumber INT");
    $ran = true;
}

/**
 * Generate nomor kontrak resmi berikutnya: SPK/TAK/{YYYY}/{MM}-{NNN}
 * Melanjutkan 3 digit nomor urut terbesar dari seluruh no_kontrak di tabel proyek.
 */
function generateNextProyekContract(PDO $pdo): string
{
    $maxSeq = (int)$pdo->query("
        SELECT COALESCE(MAX(CAST(SUBSTRING(no_kontrak FROM '([0-9]{3})$') AS INTEGER)), 0)
        FROM proyek
        WHERE no_kontrak ~ '[0-9]{3}$'
    ")->fetchColumn();

    $nextSeq = $maxSeq + 1;
    $tahun   = date('Y');
    $bulan   = date('m');
    $kontrak = sprintf('SPK/TAK/%s/%s-%03d', $tahun, $bulan, $nextSeq);

    $stmtDup = $pdo->prepare("SELECT 1 FROM proyek WHERE no_kontrak = :nk LIMIT 1");
    while (true) {
        $stmtDup->execute([':nk' => $kontrak]);
        if (!$stmtDup->fetchColumn()) {
            break;
        }
        $nextSeq++;
        $kontrak = sprintf('SPK/TAK/%s/%s-%03d', $tahun, $bulan, $nextSeq);
    }

    return $kontrak;
}

/**
 * Generate id_proyek berurutan dengan format PRY-NNN
 * Melanjutkan nomor urut terbesar dari PRY-xxx (atau PRJ-YYYY-xxx awal) di tabel proyek.
 */
function generateNextProyekId(PDO $pdo): string
{
    $maxNum = (int)$pdo->query("
        SELECT GREATEST(
            COALESCE(MAX(CAST(SUBSTRING(id_proyek FROM '([0-9]+)$') AS INTEGER)) FILTER (WHERE id_proyek ~ '^PRY-[0-9]+$'), 0),
            COALESCE(MAX(CAST(SUBSTRING(id_proyek FROM '([0-9]+)$') AS INTEGER)) FILTER (WHERE id_proyek ~ '^PRJ-[0-9]{4}-[0-9]+$' AND no_kontrak NOT LIKE 'SPK/TAK/WIN-%'), 0)
        )
        FROM proyek
    ")->fetchColumn();

    $nextSeq  = $maxNum + 1;
    $idProyek = sprintf('PRY-%03d', $nextSeq);

    $stmtDup = $pdo->prepare("SELECT 1 FROM proyek WHERE id_proyek = :id LIMIT 1");
    while (true) {
        $stmtDup->execute([':id' => $idProyek]);
        if (!$stmtDup->fetchColumn()) {
            break;
        }
        $nextSeq++;
        $idProyek = sprintf('PRY-%03d', $nextSeq);
    }

    return $idProyek;
}

/**
 * Migrasi aman satu kali: mendeteksi baris proyek lama dengan no_kontrak LIKE 'SPK/TAK/WIN-%',
 * memperbarui no_kontrak ke format resmi SPK/TAK/YYYY/MM-NNN dan merapikan id_proyek ke PRY-NNN.
 */
function migrateLegacyWinContracts(PDO $pdo): void
{
    static $migrated = false;
    if ($migrated) {
        return;
    }
    if (is_file(getSchemaLockFilePath())) {
        $migrated = true;
        return;
    }
    ensureSchemaMigrationsOnce($pdo);
    $migrated = true;
}

function _runLegacyWinContractsMigration(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }

    _runProyekLeadColumnMigration($pdo);

    $legacyRows = $pdo->query("
        SELECT id_proyek, no_kontrak
        FROM proyek
        WHERE no_kontrak LIKE 'SPK/TAK/WIN-%'
        ORDER BY id_proyek ASC
    ")->fetchAll();

    if (count($legacyRows) > 0) {
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            $upd = $pdo->prepare("
                UPDATE proyek
                SET id_proyek      = :new_id,
                    no_kontrak     = :new_kontrak,
                    id_lead_sumber = COALESCE(id_lead_sumber, :lead_id)
                WHERE id_proyek = :old_id
            ");
            foreach ($legacyRows as $row) {
                $leadId = null;
                if (preg_match('/^SPK\/TAK\/WIN-(\d+)$/', (string)$row['no_kontrak'], $m)) {
                    $leadId = (int)$m[1];
                }
                $newKontrak = generateNextProyekContract($pdo);
                $newId      = preg_match('/^PRY-[0-9]+$/', (string)$row['id_proyek'])
                    ? (string)$row['id_proyek']
                    : generateNextProyekId($pdo);

                $upd->execute([
                    ':new_id'      => $newId,
                    ':new_kontrak' => $newKontrak,
                    ':lead_id'     => $leadId,
                    ':old_id'      => $row['id_proyek'],
                ]);
            }
            if ($ownTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    $ran = true;
}

/**
 * Sinkronisasi otomatis Lead berstatus 'Win' ke tabel `proyek` (status 'Berjalan').
 * Menggunakan nomor kontrak resmi SPK/TAK/YYYY/MM-NNN dan id_proyek berurutan PRY-NNN,
 * serta menyalin `lokasi_proyek` ke kolom `proyek.lokasi`.
 * Mengembalikan id_proyek yang baru dibuat atau null jika sudah terdaftar.
 */
function syncLeadWinToProyek(
    PDO $pdo,
    int $idLead,
    string $namaKlien,
    ?string $namaProyekProspek = null,
    ?string $lokasiProyek = null
): ?string {
    ensureProyekLeadColumn($pdo);
    migrateLegacyWinContracts($pdo);

    $cleanKlien  = trim($namaKlien);
    $cleanProyek = trim((string)($namaProyekProspek ?? ''));
    $cleanLokasi = trim((string)($lokasiProyek ?? ''));

    if ($cleanProyek !== '' && $cleanKlien !== '' && stripos($cleanProyek, $cleanKlien) === false) {
        $namaProyek = $cleanProyek . ' - ' . $cleanKlien;
    } elseif ($cleanProyek !== '') {
        $namaProyek = $cleanProyek;
    } else {
        $namaProyek = $cleanKlien;
    }

    if ($idLead <= 0 || $namaProyek === '') {
        return null;
    }

    $namaProyek = function_exists('mb_substr')
        ? mb_substr($namaProyek, 0, 150, 'UTF-8')
        : substr($namaProyek, 0, 150);

    $lokasiFinal = $cleanLokasi !== '' ? $cleanLokasi : 'Lokasi Proyek Klien';
    $lokasiFinal = function_exists('mb_substr')
        ? mb_substr($lokasiFinal, 0, 150, 'UTF-8')
        : substr($lokasiFinal, 0, 150);

    // Cek duplikasi aman berdasarkan id_lead_sumber, nama_proyek, atau nama_klien
    $stmtCheck = $pdo->prepare("
        SELECT id_proyek, lokasi, id_lead_sumber
        FROM proyek
        WHERE id_lead_sumber = :lead_id
           OR LOWER(TRIM(nama_proyek)) = LOWER(TRIM(:nama))
           OR (:klien <> '' AND LOWER(TRIM(nama_proyek)) = LOWER(TRIM(:klien)))
        LIMIT 1
    ");
    $stmtCheck->execute([
        ':lead_id' => $idLead,
        ':nama'    => $namaProyek,
        ':klien'   => $cleanKlien,
    ]);
    $existing = $stmtCheck->fetch();
    if ($existing) {
        $needLocUpd  = ($cleanLokasi !== '' && trim((string)$existing['lokasi']) === 'Lokasi Proyek Klien');
        $needLeadUpd = ($existing['id_lead_sumber'] === null);
        if ($needLocUpd || $needLeadUpd) {
            $upd = $pdo->prepare("
                UPDATE proyek
                SET lokasi         = CASE WHEN :upd_loc THEN :lok ELSE lokasi END,
                    id_lead_sumber = COALESCE(id_lead_sumber, :lead_id)
                WHERE id_proyek = :id
            ");
            $upd->bindValue(':upd_loc', $needLocUpd, PDO::PARAM_BOOL);
            $upd->bindValue(':lok', $lokasiFinal);
            $upd->bindValue(':lead_id', $idLead, PDO::PARAM_INT);
            $upd->bindValue(':id', $existing['id_proyek']);
            $upd->execute();
        }
        return null;
    }

    $idProyek  = generateNextProyekId($pdo);
    $noKontrak = generateNextProyekContract($pdo);

    $stmtIns = $pdo->prepare("
        INSERT INTO proyek (id_proyek, no_kontrak, nama_proyek, lokasi, tgl_mulai, tgl_target_selesai, status_proyek, id_lead_sumber)
        VALUES (:id, :kontrak, :nama, :lokasi, CURRENT_DATE, (CURRENT_DATE + INTERVAL '90 days')::date, 'Berjalan', :lead_id)
    ");
    $stmtIns->execute([
        ':id'      => $idProyek,
        ':kontrak' => $noKontrak,
        ':nama'    => $namaProyek,
        ':lokasi'  => $lokasiFinal,
        ':lead_id' => $idLead,
    ]);

    return $idProyek;
}

