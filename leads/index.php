<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/app_helpers.php';

ensureLeadsExtraColumns($pdo);
migrateLegacyWinContracts($pdo);

$activeStages   = ['New Lead', 'Opportunity', 'Quotation'];
$financeStages  = ['Quotation'];
$tahapanList    = ['New Lead', 'Opportunity', 'Quotation', 'Win', 'Loss'];
$sumberList     = ['WhatsApp', 'Kabar Burung', 'Relasi', 'Lainnya'];
$winIndicators  = getLeadWinIndicators();
$lossIndicators = getLeadLossIndicators();

// Download file jadwal .ics untuk Site Visit (kompatibel Apple Calendar macOS & Outlook)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_ics' && isset($_GET['id_lead'])) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $icsLeadId = (int)$_GET['id_lead'];
    $dateParam = trim((string)($_GET['date'] ?? ''));

    if ($icsLeadId <= 0) {
        http_response_code(400);
        exit('ID lead tidak valid.');
    }

    $stmtIcs = $pdo->prepare("
        SELECT l.id_lead, l.nama_klien, l.nama_proyek_prospek, l.lokasi_proyek, l.tgl_site_visit,
               s.nama AS nama_pic
        FROM leads l
        LEFT JOIN staf s ON l.id_staf_pic = s.id_staf
        WHERE l.id_lead = :id
        LIMIT 1
    ");
    $stmtIcs->execute([':id' => $icsLeadId]);
    $icsLead = $stmtIcs->fetch();

    if (!$icsLead) {
        http_response_code(404);
        exit('Prospek tidak ditemukan.');
    }

    $ymd = '';
    if ($dateParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
        $dtCheck = DateTime::createFromFormat('Y-m-d', $dateParam);
        if ($dtCheck && $dtCheck->format('Y-m-d') === $dateParam) {
            $ymd = $dateParam;
        }
    }
    if ($ymd === '' && !empty($icsLead['tgl_site_visit'])) {
        $ymd = substr((string)$icsLead['tgl_site_visit'], 0, 10);
    }
    if ($ymd === '') {
        $ymd = date('Y-m-d');
    }

    $dtStartObj = new DateTime($ymd);
    $dtEndObj   = (clone $dtStartObj)->modify('+1 day');
    $dtStart    = $dtStartObj->format('Ymd');
    $dtEnd      = $dtEndObj->format('Ymd');
    $dtStamp    = gmdate('Ymd\THis\Z');

    $proyekTitle = trim((string)($icsLead['nama_proyek_prospek'] ?? '')) !== ''
        ? trim((string)$icsLead['nama_proyek_prospek'])
        : trim((string)$icsLead['nama_klien']);
    $klienName = trim((string)$icsLead['nama_klien']);
    $lokasiTxt = trim((string)($icsLead['lokasi_proyek'] ?? ''));
    $picName   = trim((string)($icsLead['nama_pic'] ?? '')) !== '' ? trim((string)$icsLead['nama_pic']) : 'Belum ditentukan';

    $escapeIcs = static function (string $text): string {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(["\r\n", "\r", "\n"], '\n', $text);
        $text = str_replace([',', ';'], ['\,', '\;'], $text);
        return $text;
    };

    $summary = 'Site Visit: ' . $proyekTitle;
    $desc    = "Klien: {$klienName}\nPIC Sales: {$picName}" . ($lokasiTxt !== '' ? "\nLokasi: {$lokasiTxt}" : '') . "\nPT Tri Arta Konstruksi — CRM Pipeline";

    $icsLines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//PT Tri Arta Konstruksi//CRM Pipeline//ID',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:site-visit-lead-' . $icsLeadId . '@triartakonstruksi.co.id',
        'DTSTAMP:' . $dtStamp,
        'DTSTART;VALUE=DATE:' . $dtStart,
        'DTEND;VALUE=DATE:' . $dtEnd,
        'SUMMARY:' . $escapeIcs($summary),
        'LOCATION:' . $escapeIcs($lokasiTxt !== '' ? $lokasiTxt : 'Lokasi Proyek Klien'),
        'DESCRIPTION:' . $escapeIcs($desc),
        'END:VEVENT',
        'END:VCALENDAR',
    ];

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="site-visit-lead-' . $icsLeadId . '.ics"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo implode("\r\n", $icsLines) . "\r\n";
    exit;
}

$createLeadError     = '';
$openTambahLeadModal = (($_GET['open'] ?? '') === 'tambah_lead');
$createLeadForm      = [
    'nama_klien'          => '',
    'nama_proyek_prospek' => '',
    'lokasi_proyek'       => '',
    'latitude'            => '',
    'longitude'           => '',
    'sumber_informasi'    => 'WhatsApp',
    'sumber_lainnya'      => '',
    'id_staf_pic'         => $_SESSION['id_staf'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 0. Auto-save indikator & tanggal Site Visit pada baris Opportunity (AJAX / fetch JSON)
    if (($_POST['action'] ?? '') === 'update_site_visit') {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        header('Content-Type: application/json; charset=utf-8');
        $idLead  = (int)($_POST['id_lead'] ?? 0);
        $rawFlag = strtolower(trim((string)($_POST['is_site_visit'] ?? '0')));
        $isVisit = in_array($rawFlag, ['1', 'true', 'on', 'yes'], true);
        $tglRaw  = trim((string)($_POST['tgl_site_visit'] ?? ''));

        if ($idLead <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'ID lead tidak valid.']);
            exit;
        }

        $tglFinal = null;
        if ($isVisit) {
            if ($tglRaw === '') {
                $tglFinal = date('Y-m-d');
            } else {
                $dt = DateTime::createFromFormat('Y-m-d', $tglRaw);
                if (!$dt || $dt->format('Y-m-d') !== $tglRaw) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Format tanggal Site Visit tidak valid (YYYY-MM-DD).']);
                    exit;
                }
                $tglFinal = $tglRaw;
            }
        }

        try {
            $stmtSv = $pdo->prepare("
                UPDATE leads
                SET is_site_visit  = :visit,
                    tgl_site_visit = :tgl
                WHERE id_lead = :id
                  AND tahapan_pipeline IN ('New Lead', 'Opportunity', 'Quotation')
            ");
            $stmtSv->bindValue(':visit', $isVisit, PDO::PARAM_BOOL);
            if ($tglFinal === null) {
                $stmtSv->bindValue(':tgl', null, PDO::PARAM_NULL);
            } else {
                $stmtSv->bindValue(':tgl', $tglFinal, PDO::PARAM_STR);
            }
            $stmtSv->bindValue(':id', $idLead, PDO::PARAM_INT);
            $stmtSv->execute();

            if ($stmtSv->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Prospek aktif tidak ditemukan.']);
                exit;
            }

            echo json_encode([
                'ok'             => true,
                'id_lead'        => $idLead,
                'is_site_visit'  => $isVisit,
                'tgl_site_visit' => $tglFinal,
                'formatted_date' => $tglFinal !== null ? formatIndoShortDate($tglFinal) : null,
            ]);
            exit;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Gagal menyimpan status Site Visit: ' . $e->getMessage()]);
            exit;
        }
    }

    // 1. Tambah Lead Baru (Tahapan selalu dikunci permanen di 'New Lead')
    if (($_POST['action'] ?? '') === 'create_lead') {
        $namaKlien         = trim((string)($_POST['nama_klien'] ?? ''));
        $namaProyekProspek = trim((string)($_POST['nama_proyek_prospek'] ?? ''));
        $lokasiProyek      = trim((string)($_POST['lokasi_proyek'] ?? ''));
        $latRaw            = trim((string)($_POST['latitude'] ?? ''));
        $lngRaw            = trim((string)($_POST['longitude'] ?? ''));
        $sumberInformasi   = trim((string)($_POST['sumber_informasi'] ?? ''));
        $sumberLainnya     = $sumberInformasi === 'Lainnya' ? trim((string)($_POST['sumber_lainnya'] ?? '')) : '';
        $idStafPic         = trim((string)($_POST['id_staf_pic'] ?? ''));
        $tahapanLocked     = 'New Lead';

        $createLeadForm = [
            'nama_klien'          => $namaKlien,
            'nama_proyek_prospek' => $namaProyekProspek,
            'lokasi_proyek'       => $lokasiProyek,
            'latitude'            => $latRaw,
            'longitude'           => $lngRaw,
            'sumber_informasi'    => $sumberInformasi !== '' ? $sumberInformasi : 'WhatsApp',
            'sumber_lainnya'      => $sumberLainnya,
            'id_staf_pic'         => $idStafPic,
        ];

        $latVal = null;
        $lngVal = null;
        if ($latRaw !== '' || $lngRaw !== '') {
            if (!is_numeric($latRaw) || !is_numeric($lngRaw)) {
                $createLeadError = 'Koordinat latitude / longitude tidak valid.';
            } else {
                $latNum = (float)$latRaw;
                $lngNum = (float)$lngRaw;
                if ($latNum < -90 || $latNum > 90 || $lngNum < -180 || $lngNum > 180) {
                    $createLeadError = 'Koordinat peta berada di luar rentang yang valid.';
                } else {
                    $latVal = number_format($latNum, 7, '.', '');
                    $lngVal = number_format($lngNum, 7, '.', '');
                }
            }
        }

        if ($createLeadError === '') {
            if ($namaKlien === '') {
                $createLeadError = 'Nama Klien wajib diisi.';
            } elseif ($namaProyekProspek === '') {
                $createLeadError = 'Nama Proyek Prospek wajib diisi.';
            } elseif (strlen($namaKlien) > 100 || strlen($namaProyekProspek) > 200) {
                $createLeadError = 'Panjang Nama Klien (maks. 100) atau Nama Proyek Prospek (maks. 200) melebihi batas.';
            } elseif ($lokasiProyek === '') {
                $createLeadError = 'Lokasi / Alamat Proyek wajib diisi.';
            } elseif (!in_array($sumberInformasi, $sumberList, true)) {
                $createLeadError = 'Sumber informasi tidak valid.';
            } elseif ($sumberInformasi === 'Lainnya' && $sumberLainnya === '') {
                $createLeadError = 'Keterangan Sumber Lainnya wajib diisi saat memilih sumber "Lainnya".';
            } else {
                try {
                    $stmtIns = $pdo->prepare("
                        INSERT INTO leads (
                            nama_klien, nama_proyek_prospek, lokasi_proyek, latitude, longitude,
                            sumber_informasi, sumber_lainnya, tahapan_pipeline, id_staf_pic
                        )
                        VALUES (
                            :klien, :proyek, :lokasi, :lat, :lng,
                            :sumber, :sumber_lain, :tahapan, :pic
                        )
                    ");
                    $stmtIns->execute([
                        ':klien'       => $namaKlien,
                        ':proyek'      => $namaProyekProspek,
                        ':lokasi'      => $lokasiProyek,
                        ':lat'         => $latVal,
                        ':lng'         => $lngVal,
                        ':sumber'      => $sumberInformasi,
                        ':sumber_lain' => ($sumberInformasi === 'Lainnya' && $sumberLainnya !== '') ? $sumberLainnya : null,
                        ':tahapan'     => $tahapanLocked,
                        ':pic'         => $idStafPic !== '' ? $idStafPic : null,
                    ]);
                    header('Location: /leads/index.php?tahapan=' . urlencode('New Lead') . '&msg=' . urlencode("Lead \"$namaProyekProspek\" ($namaKlien) berhasil ditambahkan ke tahap New Lead!"));
                    exit;
                } catch (Throwable $e) {
                    $createLeadError = 'Gagal menyimpan data lead: ' . $e->getMessage();
                }
            }
        }

        if ($createLeadError !== '') {
            $openTambahLeadModal = true;
        }
    }

    // 2. Edit Data Prospek Aktif + Kalkulator Nilai Proyek & Margin (wajib untuk Quotation)
    if (($_POST['action'] ?? '') === 'edit_lead' && isset($_POST['id_lead'])) {
        $idLead            = (int)$_POST['id_lead'];
        $namaProyekProspek = trim((string)($_POST['nama_proyek_prospek'] ?? ''));
        $namaKlien         = trim((string)($_POST['nama_klien'] ?? ''));
        $lokasiProyek      = trim((string)($_POST['lokasi_proyek'] ?? ''));
        $latRaw            = trim((string)($_POST['latitude'] ?? ''));
        $lngRaw            = trim((string)($_POST['longitude'] ?? ''));
        $sumberInformasi   = trim((string)($_POST['sumber_informasi'] ?? ''));
        $sumberLainnya     = $sumberInformasi === 'Lainnya' ? trim((string)($_POST['sumber_lainnya'] ?? '')) : '';
        $idStafPic         = trim((string)($_POST['id_staf'] ?? ($_POST['id_staf_pic'] ?? '')));
        $tahapanBaru       = trim((string)($_POST['tahapan_pipeline'] ?? ''));
        $nilaiProyekVal    = parseIdrCurrency($_POST['nilai_proyek'] ?? null);
        $keuntunganVal     = parseIdrCurrency($_POST['keuntungan_bersih'] ?? null);

        $redirectTab = in_array($tahapanBaru, $activeStages, true) ? ('?tahapan=' . urlencode($tahapanBaru) . '&') : '?';

        $latVal = null;
        $lngVal = null;
        if ($latRaw !== '' || $lngRaw !== '') {
            if (!is_numeric($latRaw) || !is_numeric($lngRaw)) {
                header('Location: /leads/index.php' . $redirectTab . 'err=' . urlencode('Koordinat latitude / longitude tidak valid.'));
                exit;
            }
            $latNum = (float)$latRaw;
            $lngNum = (float)$lngRaw;
            if ($latNum < -90 || $latNum > 90 || $lngNum < -180 || $lngNum > 180) {
                header('Location: /leads/index.php' . $redirectTab . 'err=' . urlencode('Koordinat peta berada di luar rentang yang valid.'));
                exit;
            }
            $latVal = number_format($latNum, 7, '.', '');
            $lngVal = number_format($lngNum, 7, '.', '');
        }

        if ($idLead <= 0 || $namaProyekProspek === '' || $namaKlien === '' || $lokasiProyek === '') {
            header('Location: /leads/index.php' . $redirectTab . 'err=' . urlencode('Nama Proyek Prospek, Nama Klien, dan Lokasi / Alamat Proyek wajib diisi.'));
            exit;
        }
        if (strlen($namaKlien) > 100 || strlen($namaProyekProspek) > 200) {
            header('Location: /leads/index.php' . $redirectTab . 'err=' . urlencode('Panjang Nama Klien (maks. 100) atau Nama Proyek Prospek (maks. 200) melebihi batas.'));
            exit;
        }
        if (!in_array($sumberInformasi, $sumberList, true)) {
            header('Location: /leads/index.php' . $redirectTab . 'err=' . urlencode('Sumber informasi tidak valid.'));
            exit;
        }
        if ($sumberInformasi === 'Lainnya' && $sumberLainnya === '') {
            header('Location: /leads/index.php' . $redirectTab . 'err=' . urlencode('Keterangan Sumber Lainnya wajib diisi saat memilih sumber "Lainnya".'));
            exit;
        }
        if (!in_array($tahapanBaru, $activeStages, true)) {
            header('Location: /leads/index.php?err=' . urlencode('Tahapan pipeline aktif tidak valid.'));
            exit;
        }

        $needsFinance = in_array($tahapanBaru, $financeStages, true);
        $marginPersen = null;

        if ($needsFinance) {
            if ($nilaiProyekVal === null || $nilaiProyekVal <= 0) {
                header('Location: /leads/index.php' . $redirectTab . 'err=' . urlencode("Total Nilai Project (IDR) wajib diisi lebih dari 0 untuk tahapan $tahapanBaru."));
                exit;
            }
            if ($keuntunganVal === null || $keuntunganVal < 0) {
                header('Location: /leads/index.php' . $redirectTab . 'err=' . urlencode("Total Keuntungan Bersih (IDR) wajib diisi untuk tahapan $tahapanBaru."));
                exit;
            }
            if ($keuntunganVal > $nilaiProyekVal) {
                header('Location: /leads/index.php' . $redirectTab . 'err=' . urlencode('Total Keuntungan Bersih tidak boleh melebihi Total Nilai Project.'));
                exit;
            }
            $marginPersen = round(($keuntunganVal / $nilaiProyekVal) * 100, 2);
        } elseif ($nilaiProyekVal !== null && $nilaiProyekVal > 0 && $keuntunganVal !== null && $keuntunganVal >= 0 && $keuntunganVal <= $nilaiProyekVal) {
            $marginPersen = round(($keuntunganVal / $nilaiProyekVal) * 100, 2);
        } else {
            $nilaiProyekVal = null;
            $keuntunganVal  = null;
            $marginPersen   = null;
        }

        try {
            $upd = $pdo->prepare("
                UPDATE leads
                SET nama_proyek_prospek = :proyek,
                    nama_klien          = :klien,
                    lokasi_proyek       = :lokasi,
                    latitude            = :lat,
                    longitude           = :lng,
                    sumber_informasi    = :sumber,
                    sumber_lainnya      = :sumber_lain,
                    id_staf_pic         = :pic,
                    tahapan_pipeline    = :tahapan,
                    nilai_proyek        = :nilai,
                    keuntungan_bersih   = :untung,
                    margin_persen       = :margin
                WHERE id_lead = :id
                  AND tahapan_pipeline IN ('New Lead', 'Opportunity', 'Quotation')
            ");
            $upd->execute([
                ':proyek'      => $namaProyekProspek,
                ':klien'       => $namaKlien,
                ':lokasi'      => $lokasiProyek,
                ':lat'         => $latVal,
                ':lng'         => $lngVal,
                ':sumber'      => $sumberInformasi,
                ':sumber_lain' => ($sumberInformasi === 'Lainnya' && $sumberLainnya !== '') ? $sumberLainnya : null,
                ':pic'         => $idStafPic !== '' ? $idStafPic : null,
                ':tahapan'     => $tahapanBaru,
                ':nilai'       => $nilaiProyekVal,
                ':untung'      => $keuntunganVal,
                ':margin'      => $marginPersen,
                ':id'          => $idLead,
            ]);

            if ($upd->rowCount() === 0) {
                header('Location: /leads/index.php?err=' . urlencode("Prospek aktif #$idLead tidak ditemukan atau sudah berstatus Win/Loss."));
                exit;
            }

            $marginMsg = $marginPersen !== null ? ' (Margin ' . number_format($marginPersen, 1) . '%)' : '';
            header('Location: /leads/index.php?tahapan=' . urlencode($tahapanBaru) . '&msg=' . urlencode("Data prospek \"$namaProyekProspek\" berhasil diperbarui ke tahap $tahapanBaru$marginMsg."));
            exit;
        } catch (Throwable $e) {
            header('Location: /leads/index.php?err=' . urlencode('Gagal memperbarui data prospek: ' . $e->getMessage()));
            exit;
        }
    }

    // 3. Update tahapan pipeline langsung (wajib cek nilai_proyek utk Quotation, & konfirmasi Win/Loss)
    if (isset($_POST['aksi_pipeline'], $_POST['id_lead'], $_POST['tahapan_baru'])) {
        $idLead           = (int)$_POST['id_lead'];
        $tahapanBaru      = trim((string)$_POST['tahapan_baru']);
        $indikatorPilihan = trim((string)($_POST['indikator_pilihan'] ?? ''));
        $indikatorKustom  = trim((string)($_POST['indikator_kustom'] ?? ''));
        $alasanHasil      = trim((string)($_POST['alasan_hasil'] ?? ($_POST['alasan_loss'] ?? '')));

        if ($idLead > 0 && in_array($tahapanBaru, $tahapanList, true)) {
            $isClosed = in_array($tahapanBaru, ['Win', 'Loss'], true);
            $indikatorFinal = $indikatorPilihan === 'Lainnya' ? $indikatorKustom : $indikatorPilihan;

            if ($isClosed && ($indikatorFinal === '' || $alasanHasil === '')) {
                header('Location: /leads/index.php?err=' . urlencode("Indikator statistik dan alasan lengkap wajib diisi untuk memindahkan lead ke $tahapanBaru."));
                exit;
            }
            if ($isClosed && strlen($indikatorFinal) > 120) {
                header('Location: /leads/index.php?err=' . urlencode('Teks indikator statistik maksimal 120 karakter.'));
                exit;
            }

            // Validasi server-side: pindah ke Quotation wajib sudah memiliki nilai_proyek & keuntungan_bersih
            if (in_array($tahapanBaru, $financeStages, true)) {
                $stmtFin = $pdo->prepare("SELECT nilai_proyek, keuntungan_bersih FROM leads WHERE id_lead = :id");
                $stmtFin->execute([':id' => $idLead]);
                $finRow = $stmtFin->fetch();
                if (!$finRow || $finRow['nilai_proyek'] === null || (float)$finRow['nilai_proyek'] <= 0 || $finRow['keuntungan_bersih'] === null) {
                    header('Location: /leads/index.php?err=' . urlencode("Mohon lengkapi Total Nilai Project & Total Keuntungan Bersih terlebih dahulu melalui tombol Edit sebelum memindahkan ke $tahapanBaru."));
                    exit;
                }
            }

            try {
                $pdo->beginTransaction();

                if ($isClosed) {
                    $upd = $pdo->prepare("
                        UPDATE leads
                        SET tahapan_pipeline = :tahapan,
                            indikator_hasil  = :indikator,
                            alasan_hasil     = :alasan_hasil,
                            alasan_loss      = :alasan_loss
                        WHERE id_lead = :id
                        RETURNING nama_klien, nama_proyek_prospek, lokasi_proyek
                    ");
                    $upd->execute([
                        ':tahapan'      => $tahapanBaru,
                        ':indikator'    => $indikatorFinal,
                        ':alasan_hasil' => $alasanHasil,
                        ':alasan_loss'  => $tahapanBaru === 'Loss' ? $alasanHasil : null,
                        ':id'           => $idLead,
                    ]);
                } else {
                    $upd = $pdo->prepare("
                        UPDATE leads
                        SET tahapan_pipeline = :tahapan
                        WHERE id_lead = :id
                        RETURNING nama_klien, nama_proyek_prospek, lokasi_proyek
                    ");
                    $upd->execute([
                        ':tahapan' => $tahapanBaru,
                        ':id'      => $idLead,
                    ]);
                }
                $leadRow = $upd->fetch();

                $newProyekId = null;
                if ($leadRow && $tahapanBaru === 'Win') {
                    $newProyekId = syncLeadWinToProyek(
                        $pdo,
                        $idLead,
                        (string)$leadRow['nama_klien'],
                        $leadRow['nama_proyek_prospek'] ?? null,
                        $leadRow['lokasi_proyek'] ?? null
                    );
                }

                $pdo->commit();
                if ($tahapanBaru === 'Win') {
                    $msg = "Prospek #$idLead resmi dipindahkan ke Proyek Win!";
                    if ($newProyekId !== null) {
                        $msg .= " Proyek baru ($newProyekId) otomatis terdaftar di Proyek Tujuan.";
                    }
                    header('Location: /leads/win.php?msg=' . urlencode($msg));
                    exit;
                }
                if ($tahapanBaru === 'Loss') {
                    header('Location: /leads/loss.php?msg=' . urlencode("Prospek #$idLead dipindahkan ke halaman evaluasi Proyek Loss."));
                    exit;
                }

                header('Location: /leads/index.php?tahapan=' . urlencode($tahapanBaru) . '&msg=' . urlencode("Tahapan prospek #$idLead diperbarui menjadi $tahapanBaru."));
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                header('Location: /leads/index.php?err=' . urlencode('Gagal memperbarui tahapan lead: ' . $e->getMessage()));
                exit;
            }
        }
    }
}

// Hitung jumlah prospek per tahapan untuk 4 bagian kartu tunggal CRM Pipeline Overview
$stageCounts = [
    'New Lead'    => 0,
    'Opportunity' => 0,
    'Quotation'   => 0,
    'Win'         => 0,
    'Loss'        => 0,
];
foreach ($pdo->query("SELECT tahapan_pipeline, COUNT(*) AS jml FROM leads GROUP BY tahapan_pipeline")->fetchAll() as $sr) {
    if (array_key_exists($sr['tahapan_pipeline'], $stageCounts)) {
        $stageCounts[$sr['tahapan_pipeline']] = (int)$sr['jml'];
    }
}

$cntNewLead         = $stageCounts['New Lead'];
$cntOpportunity     = $stageCounts['Opportunity'];
$cntQuotation       = $stageCounts['Quotation'];
$totalPipelineAktif = $cntNewLead + $cntOpportunity + $cntQuotation;
$winLeads           = $stageCounts['Win'];
$lossLeads          = $stageCounts['Loss'];
$closedLeads        = $winLeads + $lossLeads;
$winRate            = $closedLeads > 0 ? (int)round(($winLeads / $closedLeads) * 100) : 0;

// Parameter Filter & Pencarian — Default filter aktif adalah 'New Lead' (bagian Total Leads)
$keyword       = trim((string)($_GET['q'] ?? ''));
$explicitStage = trim((string)($_GET['tahapan'] ?? ''));
$selectedStage = '';
if ($explicitStage !== '' && in_array($explicitStage, $activeStages, true)) {
    $selectedStage = $explicitStage;
} elseif ($keyword === '') {
    $selectedStage = 'New Lead';
}

$hasActiveFilter = ($keyword !== '' || ($explicitStage !== '' && $explicitStage !== 'New Lead'));

// Query Prospek Aktif sesuai tab terpilih / pencarian
$activeWhere  = "WHERE l.tahapan_pipeline IN ('New Lead', 'Opportunity', 'Quotation')";
$activeParams = [];
if ($selectedStage !== '') {
    $activeWhere .= " AND l.tahapan_pipeline = :tahapan";
    $activeParams[':tahapan'] = $selectedStage;
}
if ($keyword !== '') {
    $activeWhere .= " AND (l.nama_klien ILIKE :kw OR COALESCE(l.nama_proyek_prospek, '') ILIKE :kw OR COALESCE(l.lokasi_proyek, '') ILIKE :kw OR COALESCE(l.sumber_informasi, '') ILIKE :kw OR COALESCE(l.sumber_lainnya, '') ILIKE :kw OR COALESCE(s.nama, '') ILIKE :kw)";
    $activeParams[':kw'] = "%$keyword%";
}

$stmtActive = $pdo->prepare("
    SELECT l.id_lead, l.nama_klien, l.nama_proyek_prospek, l.lokasi_proyek, l.latitude, l.longitude,
           l.sumber_informasi, l.sumber_lainnya, l.tahapan_pipeline,
           COALESCE(l.is_site_visit, FALSE) AS is_site_visit, l.tgl_site_visit,
           l.nilai_proyek, l.keuntungan_bersih, l.margin_persen,
           l.tanggal_masuk, l.id_staf_pic,
           s.nama AS nama_pic, j.nama_jabatan AS jabatan_pic
    FROM leads l
    LEFT JOIN staf s ON l.id_staf_pic = s.id_staf
    LEFT JOIN jabatan j ON s.id_jabatan = j.id_jabatan
    $activeWhere
    ORDER BY l.id_lead ASC
");
$stmtActive->execute($activeParams);
$activeLeadsList = $stmtActive->fetchAll();

// Tampilkan kolom Nilai & Margin Proyek pada tahap Quotation (atau jika ada hasil pencarian yg memiliki nilai)
$showFinanceCol = in_array($selectedStage, $financeStages, true);
if (!$showFinanceCol && $keyword !== '') {
    foreach ($activeLeadsList as $al) {
        if ($al['nilai_proyek'] !== null && (float)$al['nilai_proyek'] > 0) {
            $showFinanceCol = true;
            break;
        }
    }
}

$stafList = $pdo->query("
    SELECT s.id_staf, s.nama, j.nama_jabatan
    FROM staf s
    JOIN jabatan j ON s.id_jabatan = j.id_jabatan
    ORDER BY s.id_staf ASC
")->fetchAll();

$activeLeadsLookup = [];
foreach ($activeLeadsList as $al) {
    $proyekTitle = trim((string)($al['nama_proyek_prospek'] ?? '')) !== ''
        ? trim((string)$al['nama_proyek_prospek'])
        : $al['nama_klien'];
    $activeLeadsLookup[(int)$al['id_lead']] = [
        'id_lead'             => (int)$al['id_lead'],
        'nama_proyek_prospek' => $proyekTitle,
        'nama_klien'          => (string)$al['nama_klien'],
        'lokasi_proyek'       => trim((string)($al['lokasi_proyek'] ?? '')),
        'latitude'            => $al['latitude'] !== null ? (string)$al['latitude'] : '',
        'longitude'           => $al['longitude'] !== null ? (string)$al['longitude'] : '',
        'sumber_informasi'    => (string)($al['sumber_informasi'] ?? 'WhatsApp'),
        'sumber_lainnya'      => trim((string)($al['sumber_lainnya'] ?? '')),
        'id_staf_pic'         => (string)($al['id_staf_pic'] ?? ''),
        'tahapan_pipeline'    => (string)$al['tahapan_pipeline'],
        'is_site_visit'       => pgBool($al['is_site_visit'] ?? false),
        'tgl_site_visit'      => !empty($al['tgl_site_visit']) ? substr((string)$al['tgl_site_visit'], 0, 10) : '',
        'nilai_proyek'        => $al['nilai_proyek'] !== null ? (float)$al['nilai_proyek'] : null,
        'keuntungan_bersih'   => $al['keuntungan_bersih'] !== null ? (float)$al['keuntungan_bersih'] : null,
        'margin_persen'       => $al['margin_persen'] !== null ? (float)$al['margin_persen'] : null,
    ];
}

$stageTabTitle = match ($selectedStage) {
    'New Lead'    => 'Total Leads (New Lead)',
    'Opportunity' => 'Tahap Opportunity',
    'Quotation'   => 'Tahap Quotation',
    default       => 'Hasil Pencarian Pipeline Aktif',
};

$createGmapsQuery = ($createLeadForm['latitude'] !== '' && $createLeadForm['longitude'] !== '')
    ? ($createLeadForm['latitude'] . ',' . $createLeadForm['longitude'])
    : ($createLeadForm['lokasi_proyek'] !== '' ? $createLeadForm['lokasi_proyek'] : '-6.5971,106.8060');

include __DIR__ . '/../layout/header.php';
?>

<style>
    /* Kartu Tunggal CRM Pipeline Overview (4 Bagian Sejajar dalam 1 Kartu) */
    .pipeline-overview-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0;
    }
    @media (max-width: 991.98px) {
        .pipeline-overview-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }
    }
    .overview-tab-item {
        position: relative;
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: 0.9rem 1.15rem;
        border-radius: 12px;
        text-decoration: none;
        color: inherit;
        transition: background-color 0.16s ease, transform 0.14s ease, box-shadow 0.16s ease;
        border-right: 1px solid rgba(13, 85, 155, 0.1);
    }
    .overview-tab-item:last-child {
        border-right: none;
    }
    .overview-tab-item.is-clickable {
        cursor: pointer;
    }
    .overview-tab-item.is-clickable:hover {
        background: rgba(13, 85, 155, 0.06);
        color: inherit;
    }
    .overview-tab-item.is-active {
        background: linear-gradient(145deg, rgba(13, 85, 155, 0.1) 0%, rgba(243, 130, 24, 0.08) 100%);
        box-shadow: inset 0 -3px 0 var(--ta-orange);
    }
    .overview-tab-item.is-static {
        cursor: default;
        user-select: text;
    }
    @media (max-width: 991.98px) {
        .overview-tab-item {
            border-right: none;
            border: 1px solid rgba(13, 85, 155, 0.1);
        }
    }

    .leads-search-group {
        display: flex;
        align-items: center;
        gap: 8px;
        flex: 1 1 380px;
        max-width: 580px;
    }
    .leads-top-search {
        position: relative;
        min-width: 380px;
        width: 100%;
        max-width: 480px;
    }
    .leads-top-search input {
        width: 100% !important;
        padding-right: 36px !important;
    }
    .btn-search-clear {
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        width: 24px;
        height: 24px;
        border: none;
        border-radius: 999px;
        background: rgba(100, 116, 139, 0.14);
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        line-height: 1;
        cursor: pointer;
        padding: 0;
        transition: background-color 0.14s ease, color 0.14s ease;
    }
    .btn-search-clear:hover {
        background: rgba(220, 38, 38, 0.14);
        color: #DC2626;
    }
    @media (max-width: 767.98px) {
        .leads-search-group { flex: 1 1 100%; max-width: 100%; }
        .leads-top-search { min-width: 0; max-width: 100%; }
    }

    .active-empty-card {
        background: rgba(255, 255, 255, 0.94);
        backdrop-filter: blur(8px);
        border: 1px solid rgba(13, 85, 155, 0.16);
        border-radius: 18px;
        box-shadow: 0 6px 20px rgba(6, 42, 78, 0.06);
        padding: 2.5rem 1.5rem;
        text-align: center;
        margin-top: 1.25rem;
    }
    .btn-edit-active-lead {
        width: 32px;
        height: 32px;
        border-radius: 9px;
        border: 1px solid rgba(13, 85, 155, 0.22);
        background: rgba(239, 246, 255, 0.85);
        color: var(--ta-blue);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.86rem;
        cursor: pointer;
        transition: all 0.15s ease;
    }
    .btn-edit-active-lead:hover {
        background: var(--ta-navy);
        border-color: var(--ta-navy);
        color: #FFFFFF;
        transform: translateY(-1px);
    }

    /* Mini Visual Margin Bar pada Tabel Quotation */
    .table-margin-bar-track {
        width: 96px;
        height: 6px;
        border-radius: 999px;
        background: rgba(148, 163, 184, 0.25);
        overflow: hidden;
    }
    .table-margin-bar-fill {
        height: 100%;
        border-radius: 999px;
        transition: width 0.25s ease;
    }

    /* Kontrol Site Visit Opsional + Tanggal + Kalender pada Baris Tabel Opportunity */
    .sv-inline-control {
        margin-top: 7px;
        padding: 6px 9px;
        border-radius: 10px;
        background: rgba(248, 250, 252, 0.92);
        border: 1px solid rgba(13, 85, 155, 0.14);
        display: inline-flex;
        flex-direction: column;
        gap: 6px;
        min-width: 196px;
    }
    .sv-check-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
    }
    .sv-check-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--ta-navy);
        cursor: pointer;
        margin: 0;
        user-select: none;
    }
    .sv-check-label .form-check-input {
        margin: 0;
        width: 15px;
        height: 15px;
        cursor: pointer;
        border-color: rgba(13, 85, 155, 0.35);
    }
    .sv-check-label .form-check-input:checked {
        background-color: #059669;
        border-color: #059669;
    }
    .sv-save-pill {
        font-size: 10.5px;
        font-weight: 600;
        padding: 1px 6px;
        border-radius: 999px;
        opacity: 0;
        transition: opacity 0.18s ease;
    }
    .sv-save-pill.is-visible {
        opacity: 1;
    }
    .sv-save-pill.is-ok {
        background: #ECFDF5;
        color: #059669;
    }
    .sv-save-pill.is-err {
        background: #FEF2F2;
        color: #DC2626;
    }
    .sv-date-row {
        display: flex;
        align-items: center;
        gap: 5px;
        flex-wrap: nowrap;
    }
    .sv-date-input {
        font-size: 12px;
        font-weight: 500;
        color: var(--ta-navy);
        background: #FFFFFF;
        border: 1px solid rgba(13, 85, 155, 0.22);
        border-radius: 7px;
        padding: 3px 7px;
        height: 28px;
        width: 132px;
        outline: none;
    }
    .sv-date-input:focus {
        border-color: var(--ta-blue);
        box-shadow: 0 0 0 2px rgba(13, 85, 155, 0.12);
    }
    .sv-cal-actions {
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .sv-cal-btn {
        width: 28px;
        height: 28px;
        border-radius: 7px;
        border: 1px solid rgba(13, 85, 155, 0.2);
        background: #FFFFFF;
        color: var(--ta-blue);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.8rem;
        text-decoration: none;
        transition: all 0.15s ease;
        flex-shrink: 0;
    }
    .sv-cal-btn:hover {
        background: var(--ta-navy);
        border-color: var(--ta-navy);
        color: #FFFFFF;
        transform: translateY(-1px);
    }
    .sv-cal-btn.gcal-btn {
        color: #EA580C;
        border-color: rgba(243, 130, 24, 0.28);
        background: #FFFBF7;
    }
    .sv-cal-btn.gcal-btn:hover {
        background: #F38218;
        border-color: #F38218;
        color: #FFFFFF;
    }
    .quotation-sv-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        margin-top: 5px;
        padding: 2px 9px;
        border-radius: 999px;
        font-size: 11.5px;
        font-weight: 600;
        line-height: 1.35;
    }
    .quotation-sv-badge.is-done {
        background: #ECFDF5;
        color: #059669;
        border: 1px solid rgba(16, 185, 129, 0.26);
    }
    .quotation-sv-badge.is-none {
        background: #F1F5F9;
        color: #64748B;
        border: 1px solid rgba(100, 116, 139, 0.2);
    }

    /* Kalkulator Nilai Proyek & Indikator Margin Visual di Modal Edit */
    .finance-calc-box {
        background: linear-gradient(145deg, rgba(239, 246, 255, 0.88) 0%, rgba(255, 247, 237, 0.88) 100%);
        border: 1.5px solid rgba(13, 85, 155, 0.22);
        border-radius: 16px;
        padding: 1.15rem 1.25rem;
    }
    .margin-visual-card {
        background: rgba(255, 255, 255, 0.94);
        border: 1.5px solid rgba(13, 85, 155, 0.18);
        border-radius: 14px;
        padding: 1rem 1.15rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        box-shadow: 0 6px 18px rgba(6, 42, 78, 0.06);
    }
    .margin-radial-wrap {
        position: relative;
        width: 74px;
        height: 74px;
        flex-shrink: 0;
    }
    .margin-radial-wrap svg {
        transform: rotate(-90deg);
    }
    .margin-radial-center {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.86rem;
        font-weight: 800;
        color: var(--ta-navy);
    }
    .margin-progress-track {
        width: 100%;
        height: 9px;
        border-radius: 999px;
        background: #E2E8F0;
        overflow: hidden;
    }
    .margin-progress-fill {
        height: 100%;
        border-radius: 999px;
        transition: width 0.3s ease, background-color 0.25s ease;
    }
    .edit-lead-gmaps-frame {
        width: 100%;
        height: 220px;
        border-radius: 14px;
        border: 1px solid rgba(13, 85, 155, 0.16);
        box-shadow: 0 6px 18px rgba(6, 42, 78, 0.06);
        display: block;
        background: #E8F0FA;
    }
    .outcome-modal-content {
        border-radius: 22px !important;
        overflow: hidden;
    }
    .outcome-modal-banner {
        padding: 1.2rem 1.5rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-bottom: 1px solid rgba(13, 85, 155, 0.12);
    }
    .outcome-modal-banner.win-mode {
        background: linear-gradient(90deg, rgba(16, 185, 129, 0.2) 0%, rgba(236, 253, 245, 0.9) 100%);
    }
    .outcome-modal-banner.loss-mode {
        background: linear-gradient(90deg, rgba(244, 63, 94, 0.18) 0%, rgba(255, 241, 242, 0.9) 100%);
    }
    .locked-stage-pill {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.52rem 0.85rem;
        border-radius: 8px;
        background: rgba(241, 245, 249, 0.92);
        border: 1px solid rgba(13, 85, 155, 0.2);
        color: var(--ta-navy);
        font-weight: 600;
        font-size: 14px;
    }
</style>

<header class="saas-topbar">
    <div class="d-flex align-items-center gap-3 flex-wrap flex-grow-1">
        <div>
            <h1 class="saas-title">CRM Leads &amp; Pipeline Proyek</h1>
            <div class="text-muted" style="font-size: 0.85rem;">Kelola tahapan prospek aktif dari New Lead hingga Quotation dengan kalkulasi margin proyek</div>
        </div>
        <div class="leads-search-group">
            <form method="GET" class="top-search leads-top-search" id="leadsSearchForm">
                <i class="bi bi-search"></i>
                <input type="text" id="leadsSearchInput" name="q" placeholder="Cari proyek, klien, lokasi, sumber..." value="<?= htmlspecialchars($keyword) ?>">
                <button type="button"
                        id="btnClearLeadsSearch"
                        class="btn-search-clear <?= $keyword === '' ? 'd-none' : '' ?>"
                        title="Hapus teks pencarian"
                        aria-label="Hapus teks pencarian">&times;</button>
                <?php if ($explicitStage !== ''): ?><input type="hidden" name="tahapan" value="<?= htmlspecialchars($explicitStage) ?>"><?php endif; ?>
            </form>
            <?php if ($hasActiveFilter): ?>
                <a href="/leads/index.php" class="btn-toolbar-white flex-shrink-0" title="Kembali ke tampilan awal (Total Leads)">
                    <i class="bi bi-arrow-left"></i> Reset
                </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <button type="button" class="btn-toolbar-orange" data-bs-toggle="modal" data-bs-target="#modalTambahLead">
            <i class="bi bi-plus-lg"></i> Tambah Lead Baru
        </button>
    </div>
</header>

<main class="p-4">
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
            <span><?= htmlspecialchars($_GET['msg']) ?></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['err'])): ?>
        <div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
            <span><?= htmlspecialchars($_GET['err']) ?></span>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- KARTU TUNGGAL CRM PIPELINE OVERVIEW (4 BAGIAN SEJAJAR: 3 TAB INTERAKTIF + 1 STATIS WIN RATE) -->
    <div class="stat-box-card">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <span class="fw-semibold" style="font-size: 0.95rem; color: var(--ta-navy);">CRM Pipeline Overview</span>
            <div class="d-flex align-items-center gap-2">
                <a href="/leads/win.php" class="badge-pill-soft text-decoration-none" style="background: #ECFDF5; color: #059669;">
                    <i class="bi bi-trophy-fill me-1"></i><?= $winLeads ?> Proyek Win
                </a>
                <a href="/leads/loss.php" class="badge-pill-red text-decoration-none">
                    <i class="bi bi-x-octagon-fill me-1"></i><?= $lossLeads ?> Proyek Loss
                </a>
            </div>
        </div>

        <div class="pipeline-overview-grid">
            <!-- 1. Total Leads (New Lead — Default Aktif) -->
            <a href="/leads/index.php?tahapan=<?= urlencode('New Lead') ?><?= $keyword !== '' ? '&q=' . urlencode($keyword) : '' ?>"
               class="overview-tab-item is-clickable <?= $selectedStage === 'New Lead' ? 'is-active' : '' ?>"
               data-overview-stage="New Lead">
                <div class="stat-lbl">Total Leads</div>
                <div class="stat-val" style="color: var(--ta-navy);"><?= $cntNewLead ?> <small>New Lead</small></div>
            </a>

            <!-- 2. Opportunity -->
            <a href="/leads/index.php?tahapan=<?= urlencode('Opportunity') ?><?= $keyword !== '' ? '&q=' . urlencode($keyword) : '' ?>"
               class="overview-tab-item is-clickable <?= $selectedStage === 'Opportunity' ? 'is-active' : '' ?>"
               data-overview-stage="Opportunity">
                <div class="stat-lbl">Opportunity</div>
                <div class="stat-val" style="color: var(--ta-blue);"><?= $cntOpportunity ?> <small>Prospek</small></div>
            </a>

            <!-- 3. Quotation -->
            <a href="/leads/index.php?tahapan=<?= urlencode('Quotation') ?><?= $keyword !== '' ? '&q=' . urlencode($keyword) : '' ?>"
               class="overview-tab-item is-clickable <?= $selectedStage === 'Quotation' ? 'is-active' : '' ?>"
               data-overview-stage="Quotation">
                <div class="stat-lbl">Quotation</div>
                <div class="stat-val" style="color: var(--ta-orange);"><?= $cntQuotation ?> <small>Penawaran</small></div>
            </a>

            <!-- 4. Win Rate (Statis / Tidak Bisa Diklik) -->
            <div class="overview-tab-item is-static" aria-label="Win Rate Statis">
                <div class="stat-lbl">Win Rate</div>
                <div class="stat-val" style="color: var(--ta-green);"><?= $winRate ?>% <small>(<?= $winLeads ?>/<?= $closedLeads ?>)</small></div>
            </div>
        </div>
    </div>

    <!-- KONTEN TABEL PIPELINE AKTIF SESUAI TAB OVERVIEW -->
    <?php if (count($activeLeadsList) === 0 && $keyword === ''): ?>
        <div class="active-empty-card">
            <div class="prod-thumb mx-auto mb-3" style="width: 56px; height: 56px; font-size: 1.5rem; background: #FFF7ED; color: var(--ta-orange); border-color: rgba(243, 130, 24, 0.28);">
                <i class="bi bi-funnel"></i>
            </div>
            <div class="fw-bold mb-1" style="font-size: 1.06rem; color: var(--ta-navy);">
                Belum ada prospek pada tahap <?= htmlspecialchars($selectedStage ?: 'Pipeline Aktif') ?>
            </div>
            <div class="col-micro-lbl mb-3">
                <?php if ($selectedStage === 'New Lead'): ?>
                    Tambahkan prospek baru agar langsung tercatat pada tahap New Lead.
                <?php else: ?>
                    Pindahkan prospek dari tahap sebelumnya atau tambahkan prospek baru untuk memulai pipeline.
                <?php endif; ?>
            </div>
            <button type="button" class="btn-toolbar-orange" data-bs-toggle="modal" data-bs-target="#modalTambahLead">
                <i class="bi bi-plus-lg"></i> Tambah Lead Baru
            </button>
        </div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-2 mt-4 flex-wrap gap-2">
            <span class="fw-bold" style="color: var(--ta-navy); font-size: 1rem;">
                <i class="bi bi-kanban me-1" style="color: var(--ta-blue);"></i><?= htmlspecialchars($stageTabTitle) ?>
            </span>
            <span class="text-muted" style="font-size: 13.5px;"><?= count($activeLeadsList) ?> Prospek Ditampilkan</span>
        </div>

        <div class="dribbble-list">
            <div class="table-responsive">
                <table class="dribbble-table">
                    <thead>
                        <tr style="background: rgba(223, 234, 246, 0.58); border-bottom: 1px solid rgba(13, 85, 155, 0.14); font-size: 0.74rem; color: var(--text-muted); text-transform: uppercase;">
                            <th class="py-2 px-3">Proyek Prospek &amp; Klien</th>
                            <th class="py-2 px-3">Sumber Informasi</th>
                            <th class="py-2 px-3">Staf PIC</th>
                            <?php if ($showFinanceCol): ?>
                                <th class="py-2 px-3">Nilai &amp; Margin Proyek</th>
                            <?php endif; ?>
                            <th class="py-2 px-3">Tahapan</th>
                            <th class="py-2 px-3 text-end">Aksi &amp; Update Tahapan</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($activeLeadsList) === 0): ?>
                            <tr>
                                <td colspan="<?= $showFinanceCol ? 6 : 5 ?>" class="text-center py-4">
                                    <div class="col-main-val mb-1" style="color: var(--ta-navy);">Tidak ada prospek yang cocok dengan pencarian "<?= htmlspecialchars($keyword) ?>"</div>
                                    <a href="/leads/index.php" class="btn-toolbar-white mt-2"><i class="bi bi-arrow-left"></i> Reset Pencarian</a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($activeLeadsList as $l):
                                $stageStyle = match ($l['tahapan_pipeline']) {
                                    'Quotation'   => 'background: #FFF7ED; color: #F38218;',
                                    'Opportunity' => 'background: #EEF2FF; color: #062A4E;',
                                    default       => 'background: #F1F5F9; color: #475569;',
                                };
                                $proyekProspek  = trim((string)($l['nama_proyek_prospek'] ?? ''));
                                $judulUtama     = $proyekProspek !== '' ? $proyekProspek : $l['nama_klien'];
                                $tampilSubKlien = $proyekProspek !== '';
                                $sumberLabel    = $l['sumber_informasi'] ?? '-';
                                if ($sumberLabel === 'Lainnya' && !empty(trim((string)($l['sumber_lainnya'] ?? '')))) {
                                    $sumberLabel = 'Lainnya (' . trim((string)$l['sumber_lainnya']) . ')';
                                }
                                $lokasiRingkas = trim((string)($l['lokasi_proyek'] ?? ''));
                                $isSiteVisit   = pgBool($l['is_site_visit'] ?? false);
                                $tglSiteVisit  = !empty($l['tgl_site_visit']) ? substr((string)$l['tgl_site_visit'], 0, 10) : '';
                                $tglSvLabel    = $tglSiteVisit !== '' ? formatIndoShortDate($tglSiteVisit) : '';
                                $nilaiProyek   = $l['nilai_proyek'] !== null ? (float)$l['nilai_proyek'] : null;
                                $keuntungan    = $l['keuntungan_bersih'] !== null ? (float)$l['keuntungan_bersih'] : null;
                                $marginPct     = $l['margin_persen'] !== null
                                    ? (float)$l['margin_persen']
                                    : (($nilaiProyek && $nilaiProyek > 0 && $keuntungan !== null) ? round(($keuntungan / $nilaiProyek) * 100, 2) : null);
                                $marginMeta    = getMarginHealthMeta($marginPct);
                                $hasFinance    = ($nilaiProyek !== null && $nilaiProyek > 0 && $keuntungan !== null);
                                $picNamaClean  = trim((string)($l['nama_pic'] ?? '')) !== '' ? trim((string)$l['nama_pic']) : 'Belum ditentukan';
                                $icsHref       = '/leads/index.php?action=download_ics&id_lead=' . (int)$l['id_lead'] . ($tglSiteVisit !== '' ? '&date=' . urlencode($tglSiteVisit) : '');
                                $gcalHref      = '#';
                                if ($tglSiteVisit !== '') {
                                    $gcStart = str_replace('-', '', $tglSiteVisit);
                                    $gcEnd   = date('Ymd', strtotime($tglSiteVisit . ' +1 day'));
                                    $gcDetails = "Klien: {$l['nama_klien']} | PIC Sales: {$picNamaClean}" . ($lokasiRingkas !== '' ? " | Lokasi: {$lokasiRingkas}" : '');
                                    $gcalHref = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
                                        . '&text=' . rawurlencode('Site Visit: ' . $judulUtama)
                                        . '&dates=' . rawurlencode($gcStart . '/' . $gcEnd)
                                        . '&location=' . rawurlencode($lokasiRingkas)
                                        . '&details=' . rawurlencode($gcDetails);
                                }
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="prod-thumb" style="width: 38px; height: 38px; font-size: 0.95rem;"><i class="bi bi-building"></i></div>
                                        <div>
                                            <div class="col-main-val" style="color: var(--ta-navy);"><?= htmlspecialchars($judulUtama) ?></div>
                                            <?php if ($tampilSubKlien): ?>
                                                <div class="col-micro-lbl mb-0" style="color: var(--ta-blue); font-weight: 600;">
                                                    <i class="bi bi-person-badge me-1"></i><?= htmlspecialchars($l['nama_klien']) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="col-micro-lbl mb-0">
                                                #<?= (int)$l['id_lead'] ?> &bull; <?= htmlspecialchars(date('d M Y, H:i', strtotime($l['tanggal_masuk']))) ?>
                                                <?php if ($lokasiRingkas !== ''): ?>
                                                    &bull; <i class="bi bi-geo-alt-fill" style="color: var(--ta-orange);"></i> <?= htmlspecialchars(mb_strimwidth($lokasiRingkas, 0, 50, '...')) ?>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($l['tahapan_pipeline'] === 'Quotation'): ?>
                                                <?php if ($isSiteVisit): ?>
                                                    <span class="quotation-sv-badge is-done">
                                                        <i class="bi bi-check-lg"></i>Site Visit<?= $tglSvLabel !== '' ? ' (' . htmlspecialchars($tglSvLabel) . ')' : '' ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="quotation-sv-badge is-none">
                                                        Tanpa Site Visit
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge-pill-soft"><?= htmlspecialchars($sumberLabel) ?></span>
                                </td>
                                <td>
                                    <div class="col-main-val" style="font-size: 0.85rem;"><?= htmlspecialchars($l['nama_pic'] ?? 'Belum ditentukan') ?></div>
                                    <?php if (!empty($l['jabatan_pic'])): ?>
                                        <div class="col-micro-lbl mb-0"><?= htmlspecialchars($l['jabatan_pic']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <?php if ($showFinanceCol): ?>
                                    <td style="min-width: 195px;">
                                        <?php if ($hasFinance): ?>
                                            <div class="col-main-val" style="color: var(--ta-navy); font-size: 0.88rem;">
                                                <?= htmlspecialchars(formatRupiah($nilaiProyek)) ?>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 mt-1">
                                                <div class="table-margin-bar-track">
                                                    <div class="table-margin-bar-fill" style="width: <?= max(4, min(100, (int)round((float)$marginPct))) ?>%; background: <?= $marginMeta['color'] ?>;"></div>
                                                </div>
                                                <span class="badge-pill-soft" style="background: <?= $marginMeta['bg'] ?>; color: <?= $marginMeta['color'] ?>; border: 1px solid <?= $marginMeta['border'] ?>; font-size: 11.5px; padding: 1px 8px;">
                                                    <?= number_format((float)$marginPct, 1) ?>%
                                                </span>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge-pill-soft" style="background: #FFF7ED; color: #EA580C; font-size: 11.5px;">Belum diisi</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <div>
                                        <span class="badge-pill-soft" style="<?= $stageStyle ?>">
                                            <?= htmlspecialchars($l['tahapan_pipeline']) ?>
                                        </span>
                                    </div>
                                    <?php if ($selectedStage === 'Opportunity' && $l['tahapan_pipeline'] === 'Opportunity'): ?>
                                        <div class="sv-inline-control"
                                             data-lead-id="<?= (int)$l['id_lead'] ?>"
                                             data-proyek="<?= htmlspecialchars($judulUtama, ENT_QUOTES) ?>"
                                             data-klien="<?= htmlspecialchars($l['nama_klien'], ENT_QUOTES) ?>"
                                             data-lokasi="<?= htmlspecialchars($lokasiRingkas, ENT_QUOTES) ?>"
                                             data-pic="<?= htmlspecialchars($picNamaClean, ENT_QUOTES) ?>">
                                            <div class="sv-check-row">
                                                <label class="sv-check-label">
                                                    <input type="checkbox"
                                                           class="form-check-input sv-checkbox"
                                                           <?= $isSiteVisit ? 'checked' : '' ?>>
                                                    <span>Site Visit</span>
                                                </label>
                                                <span class="sv-save-pill" aria-live="polite"></span>
                                            </div>
                                            <div class="sv-date-row <?= $isSiteVisit ? '' : 'd-none' ?>">
                                                <input type="date"
                                                       class="sv-date-input"
                                                       value="<?= htmlspecialchars($tglSiteVisit) ?>"
                                                       aria-label="Tanggal Site Visit">
                                                <div class="sv-cal-actions <?= ($isSiteVisit && $tglSiteVisit !== '') ? '' : 'd-none' ?>">
                                                    <a href="<?= htmlspecialchars($icsHref) ?>"
                                                       class="sv-cal-btn sv-ics-link"
                                                       title="Simpan ke Kalender (.ics — Apple Calendar / Outlook)"
                                                       aria-label="Unduh jadwal Site Visit (.ics)">
                                                        <i class="bi bi-calendar2-plus"></i>
                                                    </a>
                                                    <a href="<?= htmlspecialchars($gcalHref) ?>"
                                                       target="_blank"
                                                       rel="noopener noreferrer"
                                                       class="sv-cal-btn gcal-btn sv-gcal-link"
                                                       title="Simpan ke Google Calendar"
                                                       aria-label="Buka jadwal Site Visit di Google Calendar">
                                                        <i class="bi bi-google"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center gap-1 flex-nowrap">
                                        <button type="button"
                                                class="btn-edit-active-lead"
                                                data-edit-lead-id="<?= (int)$l['id_lead'] ?>"
                                                title="Edit Prospek &amp; Estimasi Nilai Proyek">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST"
                                              class="d-inline-flex align-items-center gap-1 pipeline-stage-form"
                                              data-lead-id="<?= (int)$l['id_lead'] ?>"
                                              data-lead-proyek="<?= htmlspecialchars($judulUtama, ENT_QUOTES) ?>"
                                              data-lead-klien="<?= htmlspecialchars($l['nama_klien'], ENT_QUOTES) ?>"
                                              data-has-finance="<?= $hasFinance ? '1' : '0' ?>">
                                            <input type="hidden" name="aksi_pipeline" value="1">
                                            <input type="hidden" name="id_lead" value="<?= (int)$l['id_lead'] ?>">
                                            <select name="tahapan_baru" class="form-select form-select-sm" style="width: 138px; font-size: 0.8rem; border-color: var(--border-color);">
                                                <?php foreach ($tahapanList as $st): ?>
                                                    <option value="<?= htmlspecialchars($st) ?>" <?= $l['tahapan_pipeline'] === $st ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($st) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-toolbar-primary" style="padding: 0.32rem 0.72rem; font-size: 0.78rem;">
                                                Simpan
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</main>

<!-- MODAL BESAR ESTETIK: TAMBAH LEAD BARU (#modalTambahLead — Tahap Dikunci Permanen di New Lead) -->
<div class="modal fade ta-large-modal" id="modalTambahLead" tabindex="-1" aria-labelledby="modalTambahLeadLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="ta-lm-accent theme-orange"></div>

            <form method="POST" id="formTambahLeadModal" class="ta-lm-form">
                <input type="hidden" name="action" value="create_lead">
                <input type="hidden" name="tahapan" value="New Lead">
                <input type="hidden" name="tahapan_pipeline" value="New Lead">
                <input type="hidden" id="createLeadLat" name="latitude" value="<?= htmlspecialchars($createLeadForm['latitude']) ?>">
                <input type="hidden" id="createLeadLng" name="longitude" value="<?= htmlspecialchars($createLeadForm['longitude']) ?>">

                <div class="ta-lm-header">
                    <div class="d-flex align-items-center gap-3 min-w-0">
                        <div class="ta-lm-header-badge theme-orange">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <div class="min-w-0">
                            <h5 class="mb-0 fw-bold" id="modalTambahLeadLabel" style="font-size: 1.15rem; color: var(--ta-navy);">Tambah Lead Baru</h5>
                            <div class="col-micro-lbl mb-0">Setiap prospek baru masuk otomatis ke tahap awal New Lead</div>
                        </div>
                    </div>
                    <button type="button" class="ta-lm-close" data-bs-dismiss="modal" aria-label="Tutup">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="ta-lm-body">
                    <?php if ($createLeadError !== ''): ?>
                        <div class="alert alert-danger py-2 mb-3" style="font-size: 14px;">
                            <span><i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($createLeadError) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="row g-4 align-items-start">
                        <div class="col-lg-6">
                            <div class="ta-lm-panel">
                                <div class="fw-bold mb-3 d-flex align-items-center gap-2" style="font-size: 0.94rem; color: var(--ta-navy);">
                                    <i class="bi bi-person-vcard" style="color: var(--ta-orange);"></i>
                                    <span>Data Prospek &amp; Penanggung Jawab</span>
                                </div>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label for="createLeadKlien" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Nama Klien <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <input type="text" id="createLeadKlien" name="nama_klien" class="form-control" maxlength="100" placeholder="Contoh: PT Agung Sedayu Group / Pengelola Stadion" value="<?= htmlspecialchars($createLeadForm['nama_klien']) ?>" required>
                                    </div>

                                    <div class="col-12">
                                        <label for="createLeadProyek" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Nama Proyek Prospek <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <input type="text" id="createLeadProyek" name="nama_proyek_prospek" class="form-control" maxlength="200" placeholder="Contoh: Instalasi Irigasi Lapangan Golf Sentul Selatan" value="<?= htmlspecialchars($createLeadForm['nama_proyek_prospek']) ?>" required>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="createLeadSumber" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Sumber Informasi <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <select id="createLeadSumber" name="sumber_informasi" class="form-select" required>
                                            <?php foreach ($sumberList as $sb): ?>
                                                <option value="<?= htmlspecialchars($sb) ?>" <?= $createLeadForm['sumber_informasi'] === $sb ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($sb) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Tahapan Pipeline
                                        </label>
                                        <div class="locked-stage-pill" title="Tahapan awal terkunci pada New Lead">
                                            <span><i class="bi bi-lock-fill me-1" style="color: var(--ta-blue);"></i>New Lead</span>
                                            <span class="badge-pill-soft" style="background: #EFF6FF; color: var(--ta-blue); font-size: 11px;">Tahap Awal</span>
                                        </div>
                                    </div>

                                    <div class="col-12 <?= $createLeadForm['sumber_informasi'] === 'Lainnya' ? '' : 'd-none' ?>" id="createLeadSumberLainWrap">
                                        <label for="createLeadSumberLain" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Keterangan Sumber Lainnya <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <input type="text" id="createLeadSumberLain" name="sumber_lainnya" class="form-control" maxlength="100" placeholder="Contoh: Instagram, Tender LPSE, Pameran Konstruksi" value="<?= htmlspecialchars($createLeadForm['sumber_lainnya']) ?>" <?= $createLeadForm['sumber_informasi'] === 'Lainnya' ? 'required' : '' ?>>
                                    </div>

                                    <div class="col-12">
                                        <label for="createLeadPic" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">Staf PIC (Penanggung Jawab)</label>
                                        <select id="createLeadPic" name="id_staf_pic" class="form-select">
                                            <option value="">-- Pilih Staf PIC --</option>
                                            <?php foreach ($stafList as $s): ?>
                                                <option value="<?= htmlspecialchars($s['id_staf']) ?>" <?= $createLeadForm['id_staf_pic'] === $s['id_staf'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($s['nama'] . ' (' . $s['nama_jabatan'] . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="ta-lm-panel">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                                    <div class="fw-bold d-flex align-items-center gap-2" style="font-size: 0.94rem; color: var(--ta-navy);">
                                        <i class="bi bi-geo-alt-fill" style="color: var(--ta-blue);"></i>
                                        <span>Lokasi &amp; Peta Google Maps</span>
                                    </div>
                                    <span id="createCoordPill" class="badge-pill-soft" style="background: rgba(255, 255, 255, 0.95); border: 1px solid rgba(13, 85, 155, 0.2); color: var(--ta-navy); font-size: 12px;">
                                        <?= ($createLeadForm['latitude'] !== '' && $createLeadForm['longitude'] !== '')
                                            ? 'Lat: ' . htmlspecialchars($createLeadForm['latitude']) . ', Lng: ' . htmlspecialchars($createLeadForm['longitude'])
                                            : 'Default: Bogor (-6.5971, 106.8060)' ?>
                                    </span>
                                </div>

                                <div class="mb-2">
                                    <label for="createLeadLokasi" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                        Lokasi / Alamat Proyek <span style="color: var(--ta-orange);">*</span>
                                    </label>
                                    <input type="text" id="createLeadLokasi" name="lokasi_proyek" class="form-control mb-2" placeholder="Ketik alamat, koordinat (-6.xxx, 106.xxx), atau tautan Google Maps" value="<?= htmlspecialchars($createLeadForm['lokasi_proyek']) ?>" required>
                                    <div class="d-flex gap-2 flex-wrap">
                                        <button type="button" id="btnCreateSearchGmaps" class="btn-toolbar-primary" style="font-size: 13px; padding: 0.4rem 0.85rem;">
                                            <i class="bi bi-search"></i> Cari di Google Maps
                                        </button>
                                        <button type="button" id="btnCreateUseGps" class="btn-toolbar-white" style="font-size: 13px; padding: 0.4rem 0.85rem;">
                                            <i class="bi bi-cursor-fill" style="color: var(--ta-orange);"></i> Gunakan Titik GPS Saya
                                        </button>
                                    </div>
                                </div>

                                <div class="position-relative mt-3">
                                    <a id="btnCreateOpenGmaps"
                                       href="https://www.google.com/maps/search/?api=1&amp;query=<?= urlencode($createGmapsQuery) ?>"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="btn-toolbar-white"
                                       style="position: absolute; top: 10px; right: 10px; z-index: 5; font-size: 12px; padding: 5px 11px; border-radius: 999px; box-shadow: 0 6px 14px rgba(6, 42, 78, 0.14);">
                                        <i class="bi bi-box-arrow-up-right" style="color: var(--ta-orange);"></i> Buka di Google Maps
                                    </a>
                                    <iframe id="createLeadGmapsIframe"
                                            title="Pratinjau Google Maps Tambah Lead"
                                            data-default-src="https://maps.google.com/maps?q=<?= urlencode($createGmapsQuery) ?>&amp;t=m&amp;z=15&amp;ie=UTF8&amp;iwloc=&amp;output=embed"
                                            style="width: 100%; height: 300px; border-radius: 16px; border: 1px solid rgba(13, 85, 155, 0.16); box-shadow: 0 8px 22px rgba(6, 42, 78, 0.06); display: block; background: #E8F0FA;"
                                            loading="lazy"
                                            referrerpolicy="no-referrer-when-downgrade"
                                            allowfullscreen></iframe>
                                </div>
                                <div id="createGmapsHint" class="col-micro-lbl mt-2 mb-0">
                                    <i class="bi bi-info-circle me-1"></i>Tekan Enter pada kolom alamat atau klik <strong>Cari di Google Maps</strong> untuk memindahkan peta.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ta-lm-footer">
                    <span class="col-micro-lbl mb-0 d-none d-sm-inline">Kolom bertanda <strong style="color: var(--ta-orange);">*</strong> wajib diisi</span>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <button type="button" class="btn-toolbar-white" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-toolbar-orange">
                            <i class="bi bi-check-lg"></i> Simpan Lead
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL EDIT PROSPEK AKTIF + KALKULATOR NILAI PROYEK & MARGIN (#editActiveLeadModal) -->
<div class="modal fade ta-large-modal" id="editActiveLeadModal" tabindex="-1" aria-labelledby="editActiveLeadModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="ta-lm-accent theme-navy"></div>

            <form method="POST" id="editActiveLeadForm" class="ta-lm-form">
                <input type="hidden" name="action" value="edit_lead">
                <input type="hidden" name="id_lead" id="editLeadId" value="">
                <input type="hidden" name="latitude" id="editLeadLat" value="">
                <input type="hidden" name="longitude" id="editLeadLng" value="">

                <div class="ta-lm-header">
                    <div class="d-flex align-items-center gap-3 min-w-0">
                        <div class="ta-lm-header-badge theme-navy">
                            <i class="bi bi-pencil-square"></i>
                        </div>
                        <div class="min-w-0">
                            <h5 class="mb-0 fw-bold" id="editActiveLeadModalLabel" style="font-size: 1.12rem; color: var(--ta-navy);">Edit Prospek Aktif</h5>
                            <div id="editLeadSubLabel" class="col-micro-lbl mb-0">Perbarui detail prospek, lokasi peta, dan kalkulasi nilai proyek</div>
                        </div>
                    </div>
                    <button type="button" class="ta-lm-close" data-bs-dismiss="modal" aria-label="Tutup">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="ta-lm-body">
                    <div id="editFinanceNotice" class="alert alert-warning py-2 mb-3 d-none" style="font-size: 13.5px;">
                        <span><i class="bi bi-calculator-fill me-2"></i>Untuk memindahkan prospek ke tahap <strong id="editFinanceNoticeStage">Quotation</strong>, silakan lengkapi <strong>Total Nilai Project (IDR)</strong> dan <strong>Total Keuntungan Bersih (IDR)</strong> terlebih dahulu.</span>
                    </div>

                    <div class="row g-4 align-items-start">
                        <!-- KOLOM KIRI: Info Prospek + Kalkulator Finansial -->
                        <div class="col-lg-6">
                            <div class="ta-lm-panel mb-3">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="editLeadProyek" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Nama Proyek Prospek <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <input type="text" id="editLeadProyek" name="nama_proyek_prospek" class="form-control" maxlength="200" required>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="editLeadKlien" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Nama Klien <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <input type="text" id="editLeadKlien" name="nama_klien" class="form-control" maxlength="100" required>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="editLeadSumber" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Sumber Informasi <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <select id="editLeadSumber" name="sumber_informasi" class="form-select" required>
                                            <?php foreach ($sumberList as $sb): ?>
                                                <option value="<?= htmlspecialchars($sb) ?>"><?= htmlspecialchars($sb) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="editLeadTahapan" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Tahapan Pipeline <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <select id="editLeadTahapan" name="tahapan_pipeline" class="form-select" required>
                                            <?php foreach ($activeStages as $st): ?>
                                                <option value="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="col-12 d-none" id="editLeadSumberLainnyaWrap">
                                        <label for="editLeadSumberLainnya" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                            Keterangan Sumber Lainnya <span style="color: var(--ta-orange);">*</span>
                                        </label>
                                        <input type="text" id="editLeadSumberLainnya" name="sumber_lainnya" class="form-control" maxlength="100" placeholder="Contoh: Instagram, Tender LPSE, Pameran Konstruksi">
                                    </div>

                                    <div class="col-12">
                                        <label for="editLeadPic" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">Staf PIC (Penanggung Jawab)</label>
                                        <select id="editLeadPic" name="id_staf" class="form-select">
                                            <option value="">-- Pilih Staf PIC --</option>
                                            <?php foreach ($stafList as $s): ?>
                                                <option value="<?= htmlspecialchars($s['id_staf']) ?>">
                                                    <?= htmlspecialchars($s['nama'] . ' (' . $s['nama_jabatan'] . ')') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- KALKULATOR NILAI PROYEK, KEUNTUNGAN BERSIH & INDIKATOR MARGIN VISUAL (WAJIB DI QUOTATION) -->
                            <div id="financeCalcSection" class="finance-calc-box d-none">
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <div class="fw-bold d-flex align-items-center gap-2" style="font-size: 0.94rem; color: var(--ta-navy);">
                                        <i class="bi bi-cash-coin" style="color: var(--ta-orange);"></i>
                                        <span>Estimasi Nilai &amp; Margin Proyek</span>
                                    </div>
                                    <span class="badge-pill-soft" style="background: #FFF7ED; color: var(--ta-orange); font-size: 11px;">Wajib Quotation</span>
                                </div>

                                <!-- Step 1: Total Nilai Project (IDR) -->
                                <div class="mb-3">
                                    <label for="editNilaiProyek" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                        Total Nilai Project (IDR) <span style="color: var(--ta-orange);">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text fw-bold bg-white" style="color: var(--ta-navy); border-color: rgba(13, 85, 155, 0.22);">Rp</span>
                                        <input type="text" id="editNilaiProyek" name="nilai_proyek" inputmode="numeric" class="form-control" placeholder="Contoh: 150.000.000" autocomplete="off">
                                    </div>
                                </div>

                                <!-- Step 2: Total Keuntungan Bersih (IDR) — Muncul Dinamis Setelah Nilai Project > 0 -->
                                <div class="mb-3 d-none" id="keuntunganBersihWrap">
                                    <label for="editKeuntunganBersih" class="form-label fw-semibold" style="font-size: 13.5px; color: var(--ta-navy);">
                                        Total Keuntungan Bersih (IDR) <span style="color: var(--ta-orange);">*</span>
                                    </label>
                                    <div class="input-group">
                                        <span class="input-group-text fw-bold bg-white" style="color: var(--ta-green); border-color: rgba(13, 85, 155, 0.22);">Rp</span>
                                        <input type="text" id="editKeuntunganBersih" name="keuntungan_bersih" inputmode="numeric" class="form-control" placeholder="Contoh: 36.750.000" autocomplete="off">
                                    </div>
                                    <div id="keuntunganExceedWarn" class="text-danger mt-1 d-none" style="font-size: 12.5px; font-weight: 600;">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Keuntungan bersih tidak boleh melebihi Total Nilai Project.
                                    </div>
                                </div>

                                <!-- Step 3: Kartu Indikator Margin Visual — Muncul Dinamis Setelah Keduanya Terisi -->
                                <div id="marginVisualCard" class="margin-visual-card d-none">
                                    <div class="margin-radial-wrap">
                                        <svg width="74" height="74" viewBox="0 0 74 74">
                                            <circle cx="37" cy="37" r="30" fill="none" stroke="#E2E8F0" stroke-width="7"></circle>
                                            <circle id="marginRadialArc" cx="37" cy="37" r="30" fill="none" stroke="#0D559B" stroke-width="7" stroke-linecap="round"
                                                    stroke-dasharray="188.5" stroke-dashoffset="188.5" style="transition: stroke-dashoffset 0.3s ease, stroke 0.25s ease;"></circle>
                                        </svg>
                                        <div class="margin-radial-center" id="marginRadialText">0%</div>
                                    </div>
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                                            <span class="fw-bold" style="font-size: 0.9rem; color: var(--ta-navy);">Indikator Margin Bersih</span>
                                            <span id="marginHealthBadge" class="badge-pill-soft" style="font-size: 11.5px;">Margin Sehat</span>
                                        </div>
                                        <div class="margin-progress-track mb-2">
                                            <div id="marginBarFill" class="margin-progress-fill" style="width: 0%; background: #0D559B;"></div>
                                        </div>
                                        <div id="marginSummaryLine" class="col-micro-lbl mb-0" style="font-size: 12px;">
                                            Estimasi margin keuntungan dari total nilai kontrak proyek
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- KOLOM KANAN: Lokasi & Pratinjau Google Maps -->
                        <div class="col-lg-6">
                            <div class="ta-lm-panel">
                                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                    <label for="editLeadLokasi" class="form-label fw-semibold mb-0" style="font-size: 13.5px; color: var(--ta-navy);">
                                        Lokasi / Alamat Proyek <span style="color: var(--ta-orange);">*</span>
                                    </label>
                                    <span id="editLeadCoordBadge" class="badge-pill-soft" style="background: #EFF6FF; color: var(--ta-blue); font-size: 11.5px;">
                                        Koordinat belum dikunci
                                    </span>
                                </div>
                                <div class="d-flex gap-2 flex-wrap mb-2">
                                    <div class="input-group flex-grow-1" style="min-width: 220px;">
                                        <input type="text" id="editLeadLokasi" name="lokasi_proyek" class="form-control" placeholder="Alamat proyek, koordinat, atau tautan Google Maps" required>
                                        <button type="button" id="btnEditSearchGmaps" class="btn-toolbar-primary" style="border-radius: 0 8px 8px 0;">
                                            <i class="bi bi-geo-alt"></i> Cari
                                        </button>
                                    </div>
                                    <button type="button" id="btnEditUseGps" class="btn-toolbar-white">
                                        <i class="bi bi-cursor-fill" style="color: var(--ta-orange);"></i> GPS
                                    </button>
                                </div>
                                <iframe id="editLeadGmapsIframe"
                                        class="edit-lead-gmaps-frame"
                                        title="Pratinjau Google Maps Edit Prospek"
                                        data-default-src="https://maps.google.com/maps?q=-6.5971,106.8060&amp;t=m&amp;z=15&amp;ie=UTF8&amp;iwloc=&amp;output=embed"
                                        loading="lazy"
                                        referrerpolicy="no-referrer-when-downgrade"
                                        allowfullscreen></iframe>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ta-lm-footer">
                    <span class="col-micro-lbl mb-0 d-none d-sm-inline">Kolom bertanda <strong style="color: var(--ta-orange);">*</strong> wajib diisi</span>
                    <div class="d-flex align-items-center gap-2 ms-auto">
                        <button type="button" class="btn-toolbar-white" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" id="btnSubmitEditLead" class="btn-toolbar-primary">
                            <i class="bi bi-check-lg"></i> Simpan Perubahan
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- POP-UP KONFIRMASI INDIKATOR STATISTIK WIN / LOSS (#outcomeConfirmModal) -->
<div class="modal fade" id="outcomeConfirmModal" tabindex="-1" aria-labelledby="outcomeConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 500px;">
        <div class="modal-content outcome-modal-content">
            <div id="outcomeModalBanner" class="outcome-modal-banner win-mode">
                <div class="d-flex align-items-center gap-2">
                    <div id="outcomeModalIcon" class="prod-thumb" style="width: 38px; height: 38px; font-size: 1.05rem;">
                        <i class="bi bi-trophy-fill"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold" id="outcomeConfirmModalLabel" style="font-size: 1.05rem; color: var(--ta-navy);">Konfirmasi Status Proyek</h5>
                        <div id="outcomeModalProjectSub" class="col-micro-lbl mb-0">Nama Proyek — Klien</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>

            <form method="POST" id="outcomeConfirmForm">
                <input type="hidden" name="aksi_pipeline" value="1">
                <input type="hidden" name="id_lead" id="outcomeLeadId" value="">
                <input type="hidden" name="tahapan_baru" id="outcomeTargetStage" value="">

                <div class="modal-body px-4 pt-3 pb-3">
                    <div class="mb-3">
                        <label for="outcomeIndicatorSelect" class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                            Indikator Statistik <span style="color: var(--ta-orange);">*</span>
                        </label>
                        <select id="outcomeIndicatorSelect" name="indikator_pilihan" class="form-select" style="font-size: 14.5px;" required></select>
                    </div>

                    <div class="mb-3 d-none" id="outcomeCustomIndicatorWrap">
                        <label for="outcomeCustomIndicatorInput" class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                            Tulis Indikator Baru <span style="color: var(--ta-orange);">*</span>
                        </label>
                        <input type="text" id="outcomeCustomIndicatorInput" name="indikator_kustom" class="form-control" style="font-size: 14.5px;" maxlength="120" placeholder="Tulis kategori indikator baru untuk grafik CRM...">
                    </div>

                    <div>
                        <label for="outcomeAlasanInput" class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                            Alasan Lengkap <span style="color: var(--ta-orange);">*</span>
                        </label>
                        <textarea id="outcomeAlasanInput" name="alasan_hasil" rows="4" class="form-control" style="font-size: 14.5px;" placeholder="Tuliskan detail kronologi atau faktor utama kenapa proyek ini Win / Loss..." required></textarea>
                    </div>
                </div>

                <div class="px-4 pb-4 pt-2 d-flex justify-content-end gap-2">
                    <button type="button" class="btn-toolbar-white" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" id="outcomeSubmitBtn" class="btn-toolbar-primary">
                        <i class="bi bi-check-lg"></i> Konfirmasi &amp; Simpan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', function () {
    const winIndicators     = <?= json_encode($winIndicators, JSON_UNESCAPED_UNICODE) ?>;
    const lossIndicators    = <?= json_encode($lossIndicators, JSON_UNESCAPED_UNICODE) ?>;
    const activeLeadsLookup = <?= json_encode($activeLeadsLookup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    // Helper format pemisah ribuan Rupiah
    function digitsOnly(str) {
        return String(str || '').replace(/\D+/g, '');
    }
    function formatIdrDigits(val) {
        const d = digitsOnly(val);
        if (!d) return '';
        return d.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }
    function parseIdrInput(val) {
        const d = digitsOnly(val);
        return d ? parseInt(d, 10) : 0;
    }

    // 1. Tombol hapus teks (×) pada search bar
    const searchInput = document.getElementById('leadsSearchInput');
    const clearBtn    = document.getElementById('btnClearLeadsSearch');
    const hadQuery    = <?= json_encode($keyword !== '') ?>;
    const explicitSt  = <?= json_encode($explicitStage) ?>;

    if (searchInput && clearBtn) {
        function syncClearBtn() {
            clearBtn.classList.toggle('d-none', searchInput.value.trim() === '');
        }
        searchInput.addEventListener('input', syncClearBtn);
        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            syncClearBtn();
            if (hadQuery) {
                window.location.href = explicitSt !== ''
                    ? '/leads/index.php?tahapan=' + encodeURIComponent(explicitSt)
                    : '/leads/index.php';
            } else {
                searchInput.focus();
            }
        });
    }

    // 2. Modal Edit Prospek Aktif + Kalkulator Nilai Proyek & Margin Visual
    const editLeadModalEl = document.getElementById('editActiveLeadModal');
    let openEditLeadModalFn = null;

    if (editLeadModalEl && typeof bootstrap !== 'undefined') {
        const editLeadModal    = new bootstrap.Modal(editLeadModalEl);
        const editForm         = document.getElementById('editActiveLeadForm');
        const inpEditId        = document.getElementById('editLeadId');
        const inpEditProyek    = document.getElementById('editLeadProyek');
        const inpEditKlien     = document.getElementById('editLeadKlien');
        const inpEditLokasi    = document.getElementById('editLeadLokasi');
        const inpEditLat       = document.getElementById('editLeadLat');
        const inpEditLng       = document.getElementById('editLeadLng');
        const badgeEditCoord   = document.getElementById('editLeadCoordBadge');
        const iframeEditMaps   = document.getElementById('editLeadGmapsIframe');
        const btnEditSearch    = document.getElementById('btnEditSearchGmaps');
        const btnEditGps       = document.getElementById('btnEditUseGps');
        const selEditSumber    = document.getElementById('editLeadSumber');
        const wrapEditLain     = document.getElementById('editLeadSumberLainnyaWrap');
        const inpEditLain      = document.getElementById('editLeadSumberLainnya');
        const selEditPic       = document.getElementById('editLeadPic');
        const selEditTahapan   = document.getElementById('editLeadTahapan');
        const lblEditSub       = document.getElementById('editLeadSubLabel');
        const noticeFin        = document.getElementById('editFinanceNotice');
        const noticeFinStage   = document.getElementById('editFinanceNoticeStage');

        // Elemen Kalkulator Finansial & Indikator Margin Visual
        const finSection       = document.getElementById('financeCalcSection');
        const inpNilai         = document.getElementById('editNilaiProyek');
        const wrapUntung       = document.getElementById('keuntunganBersihWrap');
        const inpUntung        = document.getElementById('editKeuntunganBersih');
        const warnExceed       = document.getElementById('keuntunganExceedWarn');
        const marginCard       = document.getElementById('marginVisualCard');
        const marginArc        = document.getElementById('marginRadialArc');
        const marginRadialText = document.getElementById('marginRadialText');
        const marginBadge      = document.getElementById('marginHealthBadge');
        const marginBarFill    = document.getElementById('marginBarFill');
        const marginSummary    = document.getElementById('marginSummaryLine');
        const btnSubmitEdit    = document.getElementById('btnSubmitEditLead');

        function updateMarginCalculator() {
            const stage = selEditTahapan ? selEditTahapan.value : '';
            const needsFin = (stage === 'Quotation');

            finSection.classList.toggle('d-none', !needsFin);
            inpNilai.required = needsFin;

            if (!needsFin) {
                wrapUntung.classList.add('d-none');
                inpUntung.required = false;
                marginCard.classList.add('d-none');
                warnExceed.classList.add('d-none');
                if (btnSubmitEdit) btnSubmitEdit.disabled = false;
                return;
            }

            const nilaiVal  = parseIdrInput(inpNilai.value);
            const hasNilai  = nilaiVal > 0;
            wrapUntung.classList.toggle('d-none', !hasNilai);
            inpUntung.required = hasNilai;

            if (!hasNilai) {
                marginCard.classList.add('d-none');
                warnExceed.classList.add('d-none');
                if (btnSubmitEdit) btnSubmitEdit.disabled = false;
                return;
            }

            const untungDigits = digitsOnly(inpUntung.value);
            if (untungDigits === '') {
                marginCard.classList.add('d-none');
                warnExceed.classList.add('d-none');
                if (btnSubmitEdit) btnSubmitEdit.disabled = false;
                return;
            }

            const untungVal = parseInt(untungDigits, 10);
            if (untungVal > nilaiVal) {
                warnExceed.classList.remove('d-none');
                marginCard.classList.add('d-none');
                if (btnSubmitEdit) btnSubmitEdit.disabled = true;
                return;
            }

            warnExceed.classList.add('d-none');
            if (btnSubmitEdit) btnSubmitEdit.disabled = false;

            const pct = (untungVal / nilaiVal) * 100;
            const pctStr = pct.toFixed(1) + '%';
            const clampedPct = Math.max(0, Math.min(100, pct));

            let color = '#EA580C';
            let bg    = '#FFF7ED';
            let label = 'Margin Tipis';
            if (pct >= 30) {
                color = '#059669';
                bg    = '#ECFDF5';
                label = 'Margin Tinggi';
            } else if (pct >= 15) {
                color = '#0D559B';
                bg    = '#EFF6FF';
                label = 'Margin Sehat';
            }

            const circumference = 188.5;
            const offset = circumference - (circumference * clampedPct / 100);
            if (marginArc) {
                marginArc.setAttribute('stroke', color);
                marginArc.setAttribute('stroke-dashoffset', offset.toFixed(2));
            }
            if (marginRadialText) {
                marginRadialText.textContent = pctStr;
                marginRadialText.style.color = color;
            }
            if (marginBadge) {
                marginBadge.textContent = label;
                marginBadge.style.background = bg;
                marginBadge.style.color = color;
            }
            if (marginBarFill) {
                marginBarFill.style.width = Math.max(3, clampedPct).toFixed(1) + '%';
                marginBarFill.style.backgroundColor = color;
            }
            if (marginSummary) {
                marginSummary.innerHTML = `Laba bersih <strong>Rp ${formatIdrDigits(untungVal)}</strong> dari nilai proyek <strong>Rp ${formatIdrDigits(nilaiVal)}</strong>`;
            }
            marginCard.classList.remove('d-none');
        }

        function attachIdrMask(el) {
            if (!el) return;
            el.addEventListener('input', function () {
                el.value = formatIdrDigits(el.value);
                updateMarginCalculator();
            });
        }
        attachIdrMask(inpNilai);
        attachIdrMask(inpUntung);

        if (selEditTahapan) {
            selEditTahapan.addEventListener('change', function () {
                updateMarginCalculator();
            });
        }

        if (editForm) {
            editForm.addEventListener('submit', function (e) {
                const stage = selEditTahapan.value;
                if (stage === 'Quotation') {
                    const n = parseIdrInput(inpNilai.value);
                    const u = parseIdrInput(inpUntung.value);
                    if (n <= 0 || digitsOnly(inpUntung.value) === '' || u > n) {
                        e.preventDefault();
                        updateMarginCalculator();
                        if (n <= 0) inpNilai.focus(); else inpUntung.focus();
                    }
                }
            });
        }

        function parseCoordsText(raw) {
            const text = String(raw || '').trim();
            if (!text) return null;
            let m = text.match(/^\s*(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$/);
            if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
            m = text.match(/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/);
            if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
            m = text.match(/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/);
            if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
            m = text.match(/[?&](?:q|query|ll)=(-?\d{1,2}\.\d+)(?:,|%2C)(-?\d{1,3}\.\d+)/i);
            if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
            return null;
        }

        function updateEditGmaps(query, coords, immediate) {
            const q   = String(query || '').trim() || '-6.5971,106.8060';
            const url = `https://maps.google.com/maps?q=${encodeURIComponent(q)}&t=m&z=15&ie=UTF8&iwloc=&output=embed`;
            if (iframeEditMaps) {
                iframeEditMaps.dataset.pendingSrc = url;
                if (immediate || editLeadModalEl.classList.contains('show')) {
                    iframeEditMaps.src = url;
                    iframeEditMaps.dataset.loaded = '1';
                }
            }
            if (coords && Number.isFinite(coords.lat) && Number.isFinite(coords.lng)) {
                inpEditLat.value = coords.lat.toFixed(7);
                inpEditLng.value = coords.lng.toFixed(7);
                if (badgeEditCoord) badgeEditCoord.textContent = `Lat: ${inpEditLat.value}, Lng: ${inpEditLng.value}`;
            } else {
                inpEditLat.value = '';
                inpEditLng.value = '';
                if (badgeEditCoord) badgeEditCoord.textContent = q === '-6.5971,106.8060' ? 'Default: Bogor' : 'Alamat Google Maps';
            }
        }

        editLeadModalEl.addEventListener('shown.bs.modal', function () {
            if (!iframeEditMaps) return;
            const targetUrl = iframeEditMaps.dataset.pendingSrc || iframeEditMaps.getAttribute('data-default-src');
            if (targetUrl && iframeEditMaps.getAttribute('src') !== targetUrl) {
                iframeEditMaps.src = targetUrl;
                iframeEditMaps.dataset.loaded = '1';
            }
        });

        function syncEditSumberLainnya(clearWhenHidden) {
            const isLain = selEditSumber.value === 'Lainnya';
            wrapEditLain.classList.toggle('d-none', !isLain);
            inpEditLain.required = isLain;
            if (!isLain && clearWhenHidden) inpEditLain.value = '';
        }
        selEditSumber.addEventListener('change', function () {
            syncEditSumberLainnya(true);
            if (selEditSumber.value === 'Lainnya') setTimeout(function () { inpEditLain.focus(); }, 100);
        });

        function applyEditLocationSearch() {
            const raw = inpEditLokasi.value.trim();
            if (!raw) { updateEditGmaps('-6.5971,106.8060', null, true); return; }
            const coords = parseCoordsText(raw);
            if (coords && coords.lat >= -90 && coords.lat <= 90 && coords.lng >= -180 && coords.lng <= 180) {
                updateEditGmaps(`${coords.lat.toFixed(7)},${coords.lng.toFixed(7)}`, coords, true);
                return;
            }
            updateEditGmaps(raw, null, true);
        }
        if (btnEditSearch) btnEditSearch.addEventListener('click', applyEditLocationSearch);
        if (inpEditLokasi) {
            inpEditLokasi.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); applyEditLocationSearch(); }
            });
        }
        if (btnEditGps) {
            btnEditGps.addEventListener('click', function () {
                if (!navigator.geolocation) return;
                navigator.geolocation.getCurrentPosition(function (pos) {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    if (inpEditLokasi.value.trim() === '') {
                        inpEditLokasi.value = `Titik GPS (${lat.toFixed(6)}, ${lng.toFixed(6)})`;
                    }
                    updateEditGmaps(`${lat.toFixed(7)},${lng.toFixed(7)}`, { lat: lat, lng: lng });
                }, function () {}, { enableHighAccuracy: true, timeout: 10000 });
            });
        }

        openEditLeadModalFn = function (leadId, forceTargetStage, showFinancePrompt) {
            const item = activeLeadsLookup[leadId];
            if (!item) return;

            inpEditId.value      = String(item.id_lead);
            inpEditProyek.value  = item.nama_proyek_prospek;
            inpEditKlien.value   = item.nama_klien;
            inpEditLokasi.value  = item.lokasi_proyek;
            selEditSumber.value  = item.sumber_informasi || 'WhatsApp';
            inpEditLain.value    = item.sumber_lainnya || '';
            selEditPic.value     = item.id_staf_pic || '';
            selEditTahapan.value = forceTargetStage || item.tahapan_pipeline || 'New Lead';
            inpNilai.value       = item.nilai_proyek !== null ? formatIdrDigits(Math.round(item.nilai_proyek)) : '';
            inpUntung.value      = item.keuntungan_bersih !== null ? formatIdrDigits(Math.round(item.keuntungan_bersih)) : '';
            if (lblEditSub) lblEditSub.textContent = `Lead #${item.id_lead} — ${item.nama_proyek_prospek}`;

            if (noticeFin) {
                noticeFin.classList.toggle('d-none', !showFinancePrompt);
                if (showFinancePrompt && noticeFinStage) {
                    noticeFinStage.textContent = forceTargetStage || 'Quotation';
                }
            }

            syncEditSumberLainnya(false);
            updateMarginCalculator();

            if (item.latitude !== '' && item.longitude !== '') {
                updateEditGmaps(`${item.latitude},${item.longitude}`, {
                    lat: parseFloat(item.latitude),
                    lng: parseFloat(item.longitude)
                });
            } else if (item.lokasi_proyek !== '') {
                updateEditGmaps(item.lokasi_proyek, null);
            } else {
                updateEditGmaps('-6.5971,106.8060', null);
            }

            editLeadModal.show();
            if (showFinancePrompt) {
                setTimeout(function () { inpNilai.focus(); }, 280);
            }
        };

        document.querySelectorAll('.btn-edit-active-lead').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openEditLeadModalFn(btn.getAttribute('data-edit-lead-id'), null, false);
            });
        });
    }

    // 3. Modal Konfirmasi Win / Loss & Validasi Wajib Nilai Proyek saat pindah ke Quotation
    const confirmModalEl = document.getElementById('outcomeConfirmModal');
    if (confirmModalEl && typeof bootstrap !== 'undefined') {
        const confirmModal = new bootstrap.Modal(confirmModalEl);
        const elBanner     = document.getElementById('outcomeModalBanner');
        const elIcon       = document.getElementById('outcomeModalIcon');
        const elTitle      = document.getElementById('outcomeConfirmModalLabel');
        const elSub        = document.getElementById('outcomeModalProjectSub');
        const inpLeadId    = document.getElementById('outcomeLeadId');
        const inpStage     = document.getElementById('outcomeTargetStage');
        const selIndikator = document.getElementById('outcomeIndicatorSelect');
        const wrapCustom   = document.getElementById('outcomeCustomIndicatorWrap');
        const inpCustom    = document.getElementById('outcomeCustomIndicatorInput');
        const txtAlasan    = document.getElementById('outcomeAlasanInput');
        const btnSubmit    = document.getElementById('outcomeSubmitBtn');

        function syncCustomField() {
            const isOther = selIndikator.value === 'Lainnya';
            wrapCustom.classList.toggle('d-none', !isOther);
            inpCustom.required = isOther;
            if (!isOther) inpCustom.value = '';
        }
        selIndikator.addEventListener('change', function () {
            syncCustomField();
            if (selIndikator.value === 'Lainnya') setTimeout(function () { inpCustom.focus(); }, 100);
        });

        function openOutcomeConfirm(leadId, proyek, klien, stage) {
            const isWin   = stage === 'Win';
            const presets = isWin ? winIndicators : lossIndicators;
            elBanner.classList.toggle('win-mode', isWin);
            elBanner.classList.toggle('loss-mode', !isWin);
            elIcon.innerHTML = isWin ? '<i class="bi bi-trophy-fill"></i>' : '<i class="bi bi-x-octagon-fill"></i>';
            elIcon.style.background = isWin ? '#ECFDF5' : '#FFF1F2';
            elIcon.style.color      = isWin ? '#059669' : '#E11D48';
            elTitle.textContent = isWin ? 'Konfirmasi Proyek Win' : 'Konfirmasi Proyek Loss';
            elSub.textContent   = `${proyek} — ${klien}`;
            inpLeadId.value     = String(leadId);
            inpStage.value      = stage;

            selIndikator.innerHTML = '<option value="">-- Pilih Indikator Statistik --</option>';
            presets.forEach(function (item) {
                const o = document.createElement('option');
                o.value = item;
                o.textContent = item;
                selIndikator.appendChild(o);
            });
            const optOther = document.createElement('option');
            optOther.value = 'Lainnya';
            optOther.textContent = 'Lainnya (Tulis Indikator Baru)';
            selIndikator.appendChild(optOther);

            selIndikator.value = '';
            inpCustom.value = '';
            txtAlasan.value = '';
            syncCustomField();

            btnSubmit.style.background = isWin ? '#059669' : '#E11D48';
            btnSubmit.innerHTML = isWin
                ? '<i class="bi bi-trophy-fill"></i> Simpan sebagai Win'
                : '<i class="bi bi-x-octagon-fill"></i> Simpan sebagai Loss';
            confirmModal.show();
        }

        document.querySelectorAll('.pipeline-stage-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                const sel = form.querySelector('select[name="tahapan_baru"]');
                if (!sel) return;
                const target     = sel.value;
                const leadId     = form.getAttribute('data-lead-id');
                const hasFinance = form.getAttribute('data-has-finance') === '1';

                // Wajib isi Nilai Project & Keuntungan Bersih saat pindah ke Quotation
                if (target === 'Quotation' && !hasFinance && openEditLeadModalFn) {
                    e.preventDefault();
                    openEditLeadModalFn(leadId, target, true);
                    return;
                }

                // Konfirmasi Indikator Statistik & Alasan saat pindah ke Win / Loss
                if (target === 'Win' || target === 'Loss') {
                    e.preventDefault();
                    openOutcomeConfirm(
                        leadId,
                        form.getAttribute('data-lead-proyek') || '',
                        form.getAttribute('data-lead-klien') || '',
                        target
                    );
                }
            });
        });
    }

    // 4. Modal Besar: Tambah Lead Baru (#modalTambahLead)
    const createModalEl = document.getElementById('modalTambahLead');
    if (createModalEl && typeof bootstrap !== 'undefined') {
        const createModal    = new bootstrap.Modal(createModalEl);
        const selCreateSmb   = document.getElementById('createLeadSumber');
        const wrapCreateLain = document.getElementById('createLeadSumberLainWrap');
        const inpCreateLain  = document.getElementById('createLeadSumberLain');
        const inpCreateLok   = document.getElementById('createLeadLokasi');
        const inpCreateLat   = document.getElementById('createLeadLat');
        const inpCreateLng   = document.getElementById('createLeadLng');
        const pillCreateCrd  = document.getElementById('createCoordPill');
        const btnCreateSrch  = document.getElementById('btnCreateSearchGmaps');
        const btnCreateGps   = document.getElementById('btnCreateUseGps');
        const btnCreateOpen  = document.getElementById('btnCreateOpenGmaps');
        const iframeCreate   = document.getElementById('createLeadGmapsIframe');
        const hintCreate     = document.getElementById('createGmapsHint');

        function extractGmapsCoords(raw) {
            const text = String(raw || '').trim();
            if (!text) return null;
            let m = text.match(/^\s*(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)\s*$/);
            if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
            m = text.match(/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/);
            if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
            m = text.match(/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/);
            if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
            m = text.match(/[?&](?:q|query|ll)=(-?\d{1,2}\.\d+)(?:,|%2C)(-?\d{1,3}\.\d+)/i);
            if (m) return { lat: parseFloat(m[1]), lng: parseFloat(m[2]) };
            return null;
        }
        function extractGmapsPlace(raw) {
            const text = String(raw || '').trim();
            const mp = text.match(/\/maps\/place\/([^\/?#]+)/i);
            if (mp) return decodeURIComponent(mp[1].replace(/\+/g, ' '));
            const mq = text.match(/[?&](?:q|query)=([^&]+)/i);
            if (mq) return decodeURIComponent(mq[1].replace(/\+/g, ' '));
            return null;
        }
        function updateCreateGmaps(query, coords, hint) {
            const cleanQ = String(query || '').trim() || '-6.5971,106.8060';
            const url    = `https://maps.google.com/maps?q=${encodeURIComponent(cleanQ)}&t=m&z=15&ie=UTF8&iwloc=&output=embed`;
            if (iframeCreate) {
                iframeCreate.src = url;
                iframeCreate.dataset.loaded = '1';
            }
            if (btnCreateOpen) btnCreateOpen.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(cleanQ)}`;
            if (coords && Number.isFinite(coords.lat) && Number.isFinite(coords.lng)) {
                inpCreateLat.value = coords.lat.toFixed(7);
                inpCreateLng.value = coords.lng.toFixed(7);
                if (pillCreateCrd) pillCreateCrd.textContent = `Lat: ${inpCreateLat.value}, Lng: ${inpCreateLng.value}`;
            } else {
                inpCreateLat.value = '';
                inpCreateLng.value = '';
                if (pillCreateCrd) pillCreateCrd.textContent = cleanQ === '-6.5971,106.8060' ? 'Default: Bogor (-6.5971, 106.8060)' : `Peta: ${cleanQ.slice(0, 32)}${cleanQ.length > 32 ? '...' : ''}`;
            }
            if (hintCreate && hint) hintCreate.innerHTML = hint;
        }
        createModalEl.addEventListener('shown.bs.modal', function () {
            if (!iframeCreate || iframeCreate.dataset.loaded === '1') return;
            const defUrl = iframeCreate.getAttribute('data-default-src');
            if (defUrl) {
                iframeCreate.src = defUrl;
                iframeCreate.dataset.loaded = '1';
            }
        });
        function syncCreateSumber(clearHidden) {
            if (!selCreateSmb || !wrapCreateLain || !inpCreateLain) return;
            const isLain = selCreateSmb.value === 'Lainnya';
            wrapCreateLain.classList.toggle('d-none', !isLain);
            inpCreateLain.required = isLain;
            if (!isLain && clearHidden) inpCreateLain.value = '';
        }
        if (selCreateSmb) {
            syncCreateSumber(false);
            selCreateSmb.addEventListener('change', function () {
                syncCreateSumber(true);
                if (selCreateSmb.value === 'Lainnya') setTimeout(function () { inpCreateLain.focus(); }, 100);
            });
        }
        function applyCreateSearch() {
            const raw = inpCreateLok ? inpCreateLok.value.trim() : '';
            if (!raw) { updateCreateGmaps('-6.5971,106.8060', null, '<i class="bi bi-info-circle me-1"></i>Menampilkan peta default Bogor.'); return; }
            const coords = extractGmapsCoords(raw);
            if (coords && coords.lat >= -90 && coords.lat <= 90 && coords.lng >= -180 && coords.lng <= 180) {
                updateCreateGmaps(`${coords.lat.toFixed(7)},${coords.lng.toFixed(7)}`, coords, '<i class="bi bi-check-circle-fill me-1" style="color: var(--ta-green);"></i>Koordinat berhasil dikunci &amp; peta dipindahkan.');
                return;
            }
            const place = extractGmapsPlace(raw);
            if (place) {
                inpCreateLok.value = place;
                updateCreateGmaps(place, null, '<i class="bi bi-check-circle-fill me-1" style="color: var(--ta-green);"></i>Tautan Google Maps terbaca &amp; peta dipindahkan.');
                return;
            }
            updateCreateGmaps(raw, null, '<i class="bi bi-check-circle-fill me-1" style="color: var(--ta-green);"></i>Pratinjau Google Maps dipindahkan ke alamat yang dicari.');
        }
        if (btnCreateSrch) btnCreateSrch.addEventListener('click', applyCreateSearch);
        if (inpCreateLok) {
            inpCreateLok.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); applyCreateSearch(); }
            });
            inpCreateLok.addEventListener('paste', function () {
                setTimeout(function () {
                    const val = inpCreateLok.value.trim();
                    if (extractGmapsCoords(val) || /google\.com\/maps|maps\.app\.goo\.gl/i.test(val)) applyCreateSearch();
                }, 60);
            });
        }
        if (btnCreateGps) {
            btnCreateGps.addEventListener('click', function () {
                if (!navigator.geolocation) return;
                navigator.geolocation.getCurrentPosition(function (pos) {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    if (inpCreateLok && inpCreateLok.value.trim() === '') {
                        inpCreateLok.value = `Titik GPS (${lat.toFixed(6)}, ${lng.toFixed(6)})`;
                    }
                    updateCreateGmaps(`${lat.toFixed(7)},${lng.toFixed(7)}`, { lat: lat, lng: lng }, '<i class="bi bi-check-circle-fill me-1" style="color: var(--ta-green);"></i>Titik GPS dikunci pada peta.');
                }, function () {}, { enableHighAccuracy: true, timeout: 10000 });
            });
        }

        const shouldAutoOpenTambah = <?= json_encode($openTambahLeadModal) ?>;
        if (shouldAutoOpenTambah) {
            createModal.show();
            if (window.history && window.history.replaceState) {
                const url = new URL(window.location.href);
                if (url.searchParams.has('open')) {
                    url.searchParams.delete('open');
                    window.history.replaceState({}, '', url.pathname + url.search);
                }
            }
        }
    }

    // 5. Kontrol Checkbox Site Visit Opsional + Tanggal + Integrasi Kalender pada Baris Opportunity
    function getTodayLocalYmd() {
        const now = new Date();
        const y = now.getFullYear();
        const m = String(now.getMonth() + 1).padStart(2, '0');
        const d = String(now.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function nextDayYmdCompact(ymd) {
        const parts = String(ymd || '').split('-').map(Number);
        if (parts.length !== 3 || parts.some(isNaN)) return '';
        const dt = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2] + 1));
        const y = dt.getUTCFullYear();
        const m = String(dt.getUTCMonth() + 1).padStart(2, '0');
        const d = String(dt.getUTCDate()).padStart(2, '0');
        return `${y}${m}${d}`;
    }

    function buildGoogleCalUrl(proyek, klien, lokasi, pic, ymd) {
        const startCompact = String(ymd || '').replace(/-/g, '');
        const endCompact   = nextDayYmdCompact(ymd);
        if (!startCompact || !endCompact) return '#';
        const details = `Klien: ${klien} | PIC Sales: ${pic}` + (lokasi ? ` | Lokasi: ${lokasi}` : '');
        const params = new URLSearchParams({
            action: 'TEMPLATE',
            text: `Site Visit: ${proyek}`,
            dates: `${startCompact}/${endCompact}`,
            location: lokasi || '',
            details: details
        });
        return `https://calendar.google.com/calendar/render?${params.toString()}`;
    }

    document.querySelectorAll('.sv-inline-control').forEach(function (box) {
        const leadId     = box.getAttribute('data-lead-id');
        const proyek     = box.getAttribute('data-proyek') || '';
        const klien      = box.getAttribute('data-klien') || '';
        const lokasi     = box.getAttribute('data-lokasi') || '';
        const pic        = box.getAttribute('data-pic') || 'Belum ditentukan';
        const chk        = box.querySelector('.sv-checkbox');
        const dateRow    = box.querySelector('.sv-date-row');
        const dateInp    = box.querySelector('.sv-date-input');
        const calActions = box.querySelector('.sv-cal-actions');
        const icsLink    = box.querySelector('.sv-ics-link');
        const gcalLink   = box.querySelector('.sv-gcal-link');
        const pill       = box.querySelector('.sv-save-pill');
        let hideTimer    = null;

        function flashPill(text, isErr) {
            if (!pill) return;
            if (hideTimer) clearTimeout(hideTimer);
            pill.textContent = text;
            pill.classList.remove('is-ok', 'is-err');
            pill.classList.add('is-visible', isErr ? 'is-err' : 'is-ok');
            hideTimer = setTimeout(function () {
                pill.classList.remove('is-visible');
            }, 2200);
        }

        function syncCalendarLinks(ymd) {
            const hasDate = Boolean(chk && chk.checked && ymd);
            if (calActions) calActions.classList.toggle('d-none', !hasDate);
            if (!hasDate) return;
            if (icsLink) {
                icsLink.href = `/leads/index.php?action=download_ics&id_lead=${encodeURIComponent(leadId)}&date=${encodeURIComponent(ymd)}`;
            }
            if (gcalLink) {
                gcalLink.href = buildGoogleCalUrl(proyek, klien, lokasi, pic, ymd);
            }
        }

        async function persistSiteVisit(isChecked, ymd) {
            const fd = new FormData();
            fd.append('action', 'update_site_visit');
            fd.append('id_lead', leadId);
            fd.append('is_site_visit', isChecked ? '1' : '0');
            fd.append('tgl_site_visit', isChecked ? (ymd || '') : '');
            try {
                const res = await fetch('/leads/index.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (!res.ok || !data.ok) {
                    flashPill('Gagal', true);
                    return;
                }
                if (activeLeadsLookup[leadId]) {
                    activeLeadsLookup[leadId].is_site_visit = Boolean(data.is_site_visit);
                    activeLeadsLookup[leadId].tgl_site_visit = data.tgl_site_visit || '';
                }
                if (isChecked && data.tgl_site_visit && dateInp && dateInp.value !== data.tgl_site_visit) {
                    dateInp.value = data.tgl_site_visit;
                    syncCalendarLinks(data.tgl_site_visit);
                }
                flashPill('Tersimpan', false);
            } catch (err) {
                flashPill('Gagal', true);
            }
        }

        if (chk) {
            chk.addEventListener('change', function () {
                if (chk.checked) {
                    if (dateInp && !dateInp.value) {
                        dateInp.value = getTodayLocalYmd();
                    }
                    if (dateRow) dateRow.classList.remove('d-none');
                    syncCalendarLinks(dateInp ? dateInp.value : '');
                    persistSiteVisit(true, dateInp ? dateInp.value : '');
                } else {
                    if (dateRow) dateRow.classList.add('d-none');
                    if (dateInp) dateInp.value = '';
                    syncCalendarLinks('');
                    persistSiteVisit(false, '');
                }
            });
        }

        if (dateInp) {
            dateInp.addEventListener('change', function () {
                if (!chk || !chk.checked) return;
                const val = dateInp.value.trim();
                syncCalendarLinks(val);
                if (val) {
                    persistSiteVisit(true, val);
                }
            });
        }
    });
});
</script>

<?php include __DIR__ . '/../layout/footer.php'; ?>
