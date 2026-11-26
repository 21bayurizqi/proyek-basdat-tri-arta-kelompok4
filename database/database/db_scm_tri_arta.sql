-- ============================================================================
-- 1. CREATE TABLE (11 TABEL INTI SCM, QR INVENTORY & CRM LEADS PT TRI ARTA KONSTRUKSI)
-- ============================================================================

CREATE TABLE jabatan (
    id_jabatan VARCHAR(10) PRIMARY KEY,
    nama_jabatan VARCHAR(50) NOT NULL UNIQUE,
    role_akses VARCHAR(30) NOT NULL CHECK (role_akses IN ('Owner', 'Manajer Operasional', 'Admin Gudang', 'Pelaksana Proyek'))
);

CREATE TABLE staf (
    id_staf VARCHAR(10) PRIMARY KEY,
    id_jabatan VARCHAR(10) NOT NULL REFERENCES jabatan(id_jabatan) ON UPDATE CASCADE ON DELETE RESTRICT,
    nama VARCHAR(100) NOT NULL,
    kontak VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    username VARCHAR(50) UNIQUE,
    password_hash VARCHAR(255)
);

CREATE TABLE proyek (
    id_proyek VARCHAR(15) PRIMARY KEY,
    no_kontrak VARCHAR(50) NOT NULL UNIQUE,
    nama_proyek VARCHAR(150) NOT NULL,
    lokasi VARCHAR(150) NOT NULL,
    tgl_mulai DATE NOT NULL,
    tgl_target_selesai DATE NOT NULL CHECK (tgl_target_selesai >= tgl_mulai),
    status_proyek VARCHAR(25) NOT NULL DEFAULT 'Berjalan' CHECK (status_proyek IN ('Persiapan', 'Berjalan', 'Selesai', 'Ditunda'))
);

CREATE TABLE vendor (
    id_vendor VARCHAR(10) PRIMARY KEY,
    nama_vendor VARCHAR(100) NOT NULL,
    kontak_vendor VARCHAR(20) NOT NULL,
    alamat TEXT
);

CREATE TABLE material (
    id_material VARCHAR(10) PRIMARY KEY,
    nama_material VARCHAR(100) NOT NULL,
    kategori VARCHAR(50) NOT NULL,
    merek VARCHAR(50),
    spesifikasi_teknis TEXT,
    total_stok INT NOT NULL DEFAULT 0 CHECK (total_stok >= 0),
    batas_minimum INT NOT NULL DEFAULT 10 CHECK (batas_minimum >= 0),
    satuan VARCHAR(20) NOT NULL,
    gambar VARCHAR(255)
);

CREATE TABLE kemasan_qr (
    kode_qr VARCHAR(30) PRIMARY KEY,
    id_material VARCHAR(10) NOT NULL REFERENCES material(id_material) ON UPDATE CASCADE ON DELETE RESTRICT,
    section_gudang VARCHAR(50) NOT NULL,
    no_batch VARCHAR(30) NOT NULL,
    qty_awal INT NOT NULL CHECK (qty_awal > 0),
    qty_sisa INT NOT NULL CHECK (qty_sisa >= 0 AND qty_sisa <= qty_awal),
    tgl_masuk DATE NOT NULL DEFAULT CURRENT_DATE,
    status_kemasan VARCHAR(20) NOT NULL DEFAULT 'Tersedia' CHECK (status_kemasan IN ('Tersedia', 'Sebagian Terpakai', 'Habis'))
);

CREATE TABLE barang_masuk (
    id_masuk VARCHAR(15) PRIMARY KEY,
    id_vendor VARCHAR(10) NOT NULL REFERENCES vendor(id_vendor) ON UPDATE CASCADE ON DELETE RESTRICT,
    id_staf_penerima VARCHAR(10) NOT NULL REFERENCES staf(id_staf) ON UPDATE CASCADE ON DELETE RESTRICT,
    no_surat_jalan VARCHAR(50) NOT NULL UNIQUE,
    tgl_masuk DATE NOT NULL DEFAULT CURRENT_DATE,
    total_biaya_beli NUMERIC(15,2) NOT NULL DEFAULT 0 CHECK (total_biaya_beli >= 0),
    catatan TEXT
);

CREATE TABLE detail_masuk (
    id_detail_masuk VARCHAR(15) PRIMARY KEY,
    id_masuk VARCHAR(15) NOT NULL REFERENCES barang_masuk(id_masuk) ON UPDATE CASCADE ON DELETE CASCADE,
    id_material VARCHAR(10) NOT NULL REFERENCES material(id_material) ON UPDATE CASCADE ON DELETE RESTRICT,
    kode_qr VARCHAR(30) NOT NULL UNIQUE REFERENCES kemasan_qr(kode_qr) ON UPDATE CASCADE ON DELETE RESTRICT,
    qty_masuk INT NOT NULL CHECK (qty_masuk > 0),
    harga_satuan NUMERIC(15,2) NOT NULL CHECK (harga_satuan >= 0)
);

CREATE TABLE barang_keluar (
    id_keluar VARCHAR(15) PRIMARY KEY,
    id_proyek VARCHAR(15) NOT NULL REFERENCES proyek(id_proyek) ON UPDATE CASCADE ON DELETE RESTRICT,
    id_staf_pemohon VARCHAR(10) NOT NULL REFERENCES staf(id_staf) ON UPDATE CASCADE ON DELETE RESTRICT,
    id_staf_validator VARCHAR(10) REFERENCES staf(id_staf) ON UPDATE CASCADE ON DELETE RESTRICT,
    tgl_keluar DATE NOT NULL DEFAULT CURRENT_DATE,
    status_approval VARCHAR(20) NOT NULL DEFAULT 'Pending Approval' CHECK (status_approval IN ('Pending Approval', 'Approved', 'Rejected')),
    tujuan_keperluan TEXT NOT NULL,
    CONSTRAINT chk_approval_validator CHECK (
        (status_approval = 'Pending Approval' AND id_staf_validator IS NULL) OR
        (status_approval IN ('Approved', 'Rejected') AND id_staf_validator IS NOT NULL)
    )
);

CREATE TABLE detail_keluar (
    id_detail_keluar VARCHAR(15) PRIMARY KEY,
    id_keluar VARCHAR(15) NOT NULL REFERENCES barang_keluar(id_keluar) ON UPDATE CASCADE ON DELETE CASCADE,
    kode_qr VARCHAR(30) NOT NULL REFERENCES kemasan_qr(kode_qr) ON UPDATE CASCADE ON DELETE RESTRICT,
    qty_keluar INT NOT NULL CHECK (qty_keluar > 0),
    waktu_scan TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    keterangan TEXT
);

CREATE TABLE leads (
    id_lead SERIAL PRIMARY KEY,
    nama_klien VARCHAR(100) NOT NULL,
    sumber_informasi VARCHAR(50) CHECK (sumber_informasi IN ('WhatsApp', 'Kabar Burung', 'Relasi', 'Lainnya')),
    tahapan_pipeline VARCHAR(50) NOT NULL DEFAULT 'New Lead' CHECK (tahapan_pipeline IN ('New Lead', 'Opportunity', 'Site Visit', 'Quotation', 'Win', 'Loss')),
    alasan_loss TEXT,
    tanggal_masuk TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_staf_pic VARCHAR(10) REFERENCES staf(id_staf) ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE INDEX idx_kemasan_material_section ON kemasan_qr(id_material, section_gudang, status_kemasan);
CREATE INDEX idx_material_reorder ON material(total_stok, batas_minimum);
CREATE INDEX idx_barang_masuk_tgl ON barang_masuk(tgl_masuk DESC, id_vendor);
CREATE INDEX idx_barang_keluar_proyek_status ON barang_keluar(id_proyek, status_approval);
CREATE INDEX idx_detail_keluar_qr ON detail_keluar(kode_qr, waktu_scan DESC);
CREATE INDEX idx_leads_pipeline_tgl ON leads(tahapan_pipeline, tanggal_masuk DESC);

-- ============================================================================
-- 3. INSERT DATA CONTOH (SESUAI COMPANY PROFILE PT TRI ARTA KONSTRUKSI)
-- ============================================================================

INSERT INTO jabatan VALUES
('JBT-01', 'Direktur Utama', 'Owner'),
('JBT-02', 'Direktur Operasional', 'Manajer Operasional'),
('JBT-03', 'Direktur Sales & Marketing', 'Manajer Operasional'),
('JBT-04', 'Direktur Keuangan', 'Owner'),
('JBT-05', 'Kepala Logistik & Admin QR', 'Admin Gudang'),
('JBT-06', 'Site Engineer & QS Lapangan', 'Pelaksana Proyek');

-- Password default seluruh akun staf: triarta123 (bcrypt PASSWORD_DEFAULT)
INSERT INTO staf (id_staf, id_jabatan, nama, kontak, email, username, password_hash) VALUES
('STF-001', 'JBT-01', 'Ismail Sahid', '081389800851', 'ismail.sahid@triartakonstruksi.co.id', 'ismail.sahid', '$2y$12$tjSxtPlLZLLE6XIkK/BnOezHF0UhpH8O4r9HcstLbgKtJKWwMskxG'),
('STF-002', 'JBT-02', 'Taufan Kusuma Negara', '081389800852', 'taufan.kusuma@triartakonstruksi.co.id', 'taufan.kusuma', '$2y$12$tjSxtPlLZLLE6XIkK/BnOezHF0UhpH8O4r9HcstLbgKtJKWwMskxG'),
('STF-003', 'JBT-03', 'Dofa Mairika Perdana Putra', '081389800853', 'dofa.mairika@triartakonstruksi.co.id', 'dofa.mairika', '$2y$12$tjSxtPlLZLLE6XIkK/BnOezHF0UhpH8O4r9HcstLbgKtJKWwMskxG'),
('STF-004', 'JBT-04', 'Reza Fauzi Indra Utama', '081389800854', 'reza.fauzi@triartakonstruksi.co.id', 'reza.fauzi', '$2y$12$tjSxtPlLZLLE6XIkK/BnOezHF0UhpH8O4r9HcstLbgKtJKWwMskxG'),
('STF-005', 'JBT-05', 'Dimas Saputra', '081389800855', 'dimas.logistik@triartakonstruksi.co.id', 'dimas.logistik', '$2y$12$tjSxtPlLZLLE6XIkK/BnOezHF0UhpH8O4r9HcstLbgKtJKWwMskxG'),
('STF-006', 'JBT-06', 'Rizky Pratama', '081389800856', 'rizky.qs@triartakonstruksi.co.id', 'rizky.qs', '$2y$12$tjSxtPlLZLLE6XIkK/BnOezHF0UhpH8O4r9HcstLbgKtJKWwMskxG');

INSERT INTO proyek VALUES
('PRJ-2026-001', 'SPK/TAK/2024/06-001', 'Irigasi Lapangan Sepak Bola Stadion Sriwedari', 'Surakarta (Solo), Jawa Tengah', '2024-06-01', '2024-11-30', 'Selesai'),
('PRJ-2026-002', 'SPK/TAK/2025/02-004', 'Pembangunan Lapangan Padel & Bola Lorena Sport Hub', 'Bogor, Jawa Barat', '2025-02-10', '2025-06-10', 'Selesai'),
('PRJ-2026-003', 'SPK/TAK/2025/08-009', 'Pengerjaan Rubber Flooring Sedayu Indogolf', 'Pantai Indah Kapuk, Jakarta Utara', '2025-08-01', '2025-11-01', 'Selesai'),
('PRJ-2026-004', 'SPK/TAK/2025/11-011', 'Instalasi Sistem Irigasi Lapangan Golf Bogor', 'Kabupaten Bogor, Jawa Barat', '2025-11-15', '2026-11-15', 'Berjalan'),
('PRJ-2026-005', 'SPK/TAK/2026/03-012', 'Design & Build Sistem Irigasi Otomatis Plaza Marina Bay PIK 2', 'Pantai Indah Kapuk 2, Tangerang', '2026-03-01', '2026-06-30', 'Berjalan');

INSERT INTO vendor VALUES
('VND-001', 'PT Supreme Cable Manufacturing (SUCACO)', '02155667788', 'Jl. Daan Mogot KM 16, Jakarta Barat'),
('VND-002', 'PT Sumber Jaya Teknik Irigasi', '081288991122', 'Jl. Raya Bogor KM 28, Jakarta Timur'),
('VND-003', 'PT Agrindo Prima Niaga', '081122334455', 'Kawasan Industri Sentul Blok C4, Bogor'),
('VND-004', 'CV Mitra Pipa Nusantara', '081377665544', 'Jl. Sholeh Iskandar No. 45, Kota Bogor'),
('VND-005', 'PT Sintetis & Rubber Sport Indonesia', '02188990011', 'Kawasan Industri Jababeka, Cikarang');

INSERT INTO material (id_material, nama_material, kategori, merek, spesifikasi_teknis, total_stok, batas_minimum, satuan) VALUES
('MAT-001', 'Sprinkler Rotor', 'Irigasi', 'Rain Bird', 'Rain Bird 5004 Rotor Sprinkler', 26, 15, 'Unit'),
('MAT-002', 'Pipa PVC AW 2 Inch', 'Perpipaan', 'Wavin', 'Pipa PVC Wavin AW 2 inch panjang 4m', 50, 30, 'Batang'),
('MAT-003', 'Irrigation Controller', 'Kontrol & Otomasi', 'Rain Bird', 'ESP-TM2 8-Station Irrigation Controller', 4, 5, 'Unit'),
('MAT-004', 'Solenoid Electric Valve', 'Irigasi', 'Rain Bird', 'Rain Bird 100-HV Solenoid Electric Valve', 6, 10, 'Unit'),
('MAT-005', 'Fitting Elbow & Socket 2 Inch', 'Perpipaan', 'Rucika', 'Elbow & Socket PVC 2 inch', 60, 40, 'Pcs'),
('MAT-006', 'Kabel Listrik NYYHY 2x1.5mm', 'Elektrikal', 'Supreme', 'Kabel Listrik NYYHY 2x1.5mm (100m/roll)', 150, 200, 'Meter'),
('MAT-007', 'Waterproof Junction Box', 'Elektrikal', 'Schneider', 'Waterproof Junction Box & Konektor IP65', 13, 10, 'Set'),
('MAT-008', 'Selang Tubing LDPE 16mm', 'Irigasi', 'Netafim', 'Selang Tubing LDPE 16mm Drip Line (100m/roll)', 6, 8, 'Roll');

INSERT INTO kemasan_qr VALUES
('QR-SEC-A1-001', 'MAT-001', 'Section A1 - Rak Irigasi', 'BATCH-2026-01', 50, 26, '2026-04-01', 'Sebagian Terpakai'),
('QR-SEC-B1-001', 'MAT-002', 'Section B1 - Zona Pipa', 'BATCH-2026-01', 100, 50, '2026-04-01', 'Sebagian Terpakai'),
('QR-SEC-C1-001', 'MAT-003', 'Section C1 - Lemari Kontrol', 'BATCH-2026-02', 5, 4, '2026-04-03', 'Sebagian Terpakai'),
('QR-SEC-A1-002', 'MAT-004', 'Section A1 - Rak Irigasi', 'BATCH-2026-02', 12, 6, '2026-04-03', 'Sebagian Terpakai'),
('QR-SEC-B2-001', 'MAT-005', 'Section B2 - Rak Fitting', 'BATCH-2026-01', 100, 60, '2026-04-02', 'Sebagian Terpakai'),
('QR-SEC-D1-001', 'MAT-006', 'Section D1 - Zona Kabel Supreme', 'BATCH-2026-03', 300, 150, '2026-04-04', 'Sebagian Terpakai'),
('QR-SEC-D2-001', 'MAT-007', 'Section D2 - Rak Elektrikal', 'BATCH-2026-03', 25, 13, '2026-04-04', 'Sebagian Terpakai'),
('QR-SEC-A2-001', 'MAT-008', 'Section A2 - Rak Selang', 'BATCH-2026-04', 10, 6, '2026-04-06', 'Sebagian Terpakai');

INSERT INTO barang_masuk VALUES
('IN-2026-001', 'VND-003', 'STF-005', 'SJ/APN/2026/0401', '2026-04-01', 17500000, 'Restock awal sprinkler & selang irigasi'),
('IN-2026-002', 'VND-004', 'STF-005', 'SJ/MPN/2026/0402', '2026-04-02', 12400000, 'Pengadaan pipa PVC Wavin 2 inch dan fitting'),
('IN-2026-003', 'VND-002', 'STF-005', 'SJ/SJT/2026/0403', '2026-04-03', 26700000, 'Pengadaan controller Rain Bird & solenoid valve'),
('IN-2026-004', 'VND-001', 'STF-005', 'SJ/SUPREME/2026/0404', '2026-04-04', 3600000, 'Restock kabel listrik NYYHY 2x1.5mm PT Supreme'),
('IN-2026-005', 'VND-002', 'STF-005', 'SJ/SJT/2026/0405', '2026-04-05', 1250000, 'Restock junction box waterproof IP65');

INSERT INTO detail_masuk VALUES
('DIN-2026-001', 'IN-2026-001', 'MAT-001', 'QR-SEC-A1-001', 50, 350000),
('DIN-2026-002', 'IN-2026-002', 'MAT-002', 'QR-SEC-B1-001', 100, 95000),
('DIN-2026-003', 'IN-2026-002', 'MAT-005', 'QR-SEC-B2-001', 100, 29000),
('DIN-2026-004', 'IN-2026-003', 'MAT-003', 'QR-SEC-C1-001', 5, 4500000),
('DIN-2026-005', 'IN-2026-003', 'MAT-004', 'QR-SEC-A1-002', 12, 350000),
('DIN-2026-006', 'IN-2026-004', 'MAT-006', 'QR-SEC-D1-001', 300, 12000),
('DIN-2026-007', 'IN-2026-005', 'MAT-007', 'QR-SEC-D2-001', 25, 50000);

INSERT INTO barang_keluar VALUES
('OUT-2026-001', 'PRJ-2026-005', 'STF-006', 'STF-002', '2026-04-10', 'Approved', 'Instalasi Sistem Irigasi Otomatis Plaza Marina Bay PIK 2 (Agung Sedayu Group)'),
('OUT-2026-002', 'PRJ-2026-001', 'STF-006', 'STF-002', '2026-04-12', 'Approved', 'Pengiriman Fitting Pipa Tambahan Stadion Sriwedari Solo'),
('OUT-2026-003', 'PRJ-2026-002', 'STF-006', 'STF-002', '2026-04-15', 'Approved', 'Instalasi Jalur Kabel Supreme & Junction Box Lorena Sport Hub'),
('OUT-2026-004', 'PRJ-2026-005', 'STF-006', 'STF-002', '2026-04-18', 'Approved', 'Pemasangan Jalur Irigasi Tetes Taman Plaza Marina Bay PIK 2'),
('OUT-2026-005', 'PRJ-2026-004', 'STF-006', NULL, '2026-04-22', 'Pending Approval', 'Permintaan Material Irigasi Lapangan Golf Bogor');

INSERT INTO detail_keluar VALUES
('DOUT-2026-001', 'OUT-2026-001', 'QR-SEC-A1-001', 24, '2026-04-10 08:30:00', 'Scan keluar 24 unit sprinkler dari Section A1 untuk Marina Bay PIK 2'),
('DOUT-2026-002', 'OUT-2026-001', 'QR-SEC-B1-001', 50, '2026-04-10 08:35:00', 'Scan keluar 50 batang pipa PVC dari Section B1 untuk Marina Bay PIK 2'),
('DOUT-2026-003', 'OUT-2026-001', 'QR-SEC-C1-001', 1, '2026-04-10 08:40:00', 'Scan keluar 1 unit controller Rain Bird dari Section C1'),
('DOUT-2026-004', 'OUT-2026-001', 'QR-SEC-A1-002', 6, '2026-04-10 08:42:00', 'Scan keluar 6 unit solenoid valve dari Section A1'),
('DOUT-2026-005', 'OUT-2026-002', 'QR-SEC-B2-001', 40, '2026-04-12 09:15:00', 'Scan keluar 40 pcs fitting elbow PVC dari Section B2'),
('DOUT-2026-006', 'OUT-2026-003', 'QR-SEC-D1-001', 150, '2026-04-15 10:05:00', 'Scan keluar 150 meter kabel Supreme dari Section D1'),
('DOUT-2026-007', 'OUT-2026-003', 'QR-SEC-D2-001', 12, '2026-04-15 10:10:00', 'Scan keluar 12 set junction box dari Section D2'),
('DOUT-2026-008', 'OUT-2026-004', 'QR-SEC-A2-001', 4, '2026-04-18 11:00:00', 'Scan keluar 4 roll selang LDPE dari Section A2');

-- ============================================================================
-- QUERY MONITORING & ANALITIK REFERENSI
-- ============================================================================
-- QUERY 1: Monitoring Stok Gudang per Section & Kode QR (Tampilan Utama SCM Dashboard)
SELECT k.section_gudang, k.kode_qr, m.id_material, m.nama_material, m.merek,
       k.qty_awal, k.qty_sisa, m.satuan, k.status_kemasan
FROM kemasan_qr k
JOIN material m ON k.id_material = m.id_material
ORDER BY k.section_gudang ASC, k.kode_qr ASC;

-- QUERY 2: Alert Restock — Menampilkan Material yang Stok Fisiknya di Bawah Batas Minimum
SELECT m.id_material, m.nama_material, m.kategori, m.merek,
       m.total_stok, m.batas_minimum, m.satuan,
       (m.batas_minimum - m.total_stok) AS jumlah_harus_restock
FROM material m
WHERE m.total_stok < m.batas_minimum
ORDER BY jumlah_harus_restock DESC;

-- QUERY 3: Riwayat Scan Keluar-Masuk untuk Satu Kode QR / Dus Tertentu (Audit Trail QR)
SELECT k.kode_qr, k.section_gudang, m.nama_material,
       bm.tgl_masuk, v.nama_vendor AS pemasok_awal, dm.qty_masuk AS stok_masuk_awal,
       bk.tgl_keluar, p.nama_proyek AS dikirim_ke_proyek, dk.qty_keluar AS jumlah_diambil,
       dk.waktu_scan, k.qty_sisa AS sisa_saat_ini
FROM kemasan_qr k
JOIN material m ON k.id_material = m.id_material
JOIN detail_masuk dm ON k.kode_qr = dm.kode_qr
JOIN barang_masuk bm ON dm.id_masuk = bm.id_masuk
JOIN vendor v ON bm.id_vendor = v.id_vendor
LEFT JOIN detail_keluar dk ON k.kode_qr = dk.kode_qr
LEFT JOIN barang_keluar bk ON dk.id_keluar = bk.id_keluar
LEFT JOIN proyek p ON bk.id_proyek = p.id_proyek
WHERE k.kode_qr = 'QR-SEC-A1-001';

-- QUERY 4: Daftar Pengajuan Pengeluaran Barang yang Menunggu Approval Direktur Operasional
SELECT bk.id_keluar, p.no_kontrak, p.nama_proyek,
       s.nama AS pemohon, bk.tgl_keluar, bk.status_approval, bk.tujuan_keperluan
FROM barang_keluar bk
JOIN proyek p ON bk.id_proyek = p.id_proyek
JOIN staf s ON bk.id_staf_pemohon = s.id_staf
WHERE bk.status_approval = 'Pending Approval'
ORDER BY bk.tgl_keluar ASC;

-- QUERY 5: Rekapitulasi Pemakaian Material per Proyek Konstruksi PT Tri Arta Konstruksi
SELECT p.no_kontrak, p.nama_proyek, p.status_proyek,
       COUNT(DISTINCT bk.id_keluar) AS total_transaksi_keluar,
       COALESCE(SUM(dk.qty_keluar), 0) AS total_unit_material_terpakai
FROM proyek p
LEFT JOIN barang_keluar bk ON p.id_proyek = bk.id_proyek AND bk.status_approval = 'Approved'
LEFT JOIN detail_keluar dk ON bk.id_keluar = dk.id_keluar
GROUP BY p.no_kontrak, p.nama_proyek, p.status_proyek
ORDER BY total_unit_material_terpakai DESC;

-- ============================================================================
-- 4. MIGRASI AMAN AUTENTIKASI STAF (UNTUK DATABASE EKSISTING)
-- ============================================================================
ALTER TABLE staf ADD COLUMN IF NOT EXISTS username VARCHAR(50) UNIQUE;
ALTER TABLE staf ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255);

UPDATE staf
SET username = SPLIT_PART(email, '@', 1)
WHERE username IS NULL OR username = '';

UPDATE staf
SET password_hash = '$2y$12$tjSxtPlLZLLE6XIkK/BnOezHF0UhpH8O4r9HcstLbgKtJKWwMskxG'
WHERE password_hash IS NULL OR password_hash = '';

-- ============================================================================
-- 5. MIGRASI AMAN TABEL CRM LEADS (UNTUK DATABASE EKSISTING)
-- ============================================================================
CREATE TABLE IF NOT EXISTS leads (
    id_lead SERIAL PRIMARY KEY,
    nama_klien VARCHAR(100) NOT NULL,
    sumber_informasi VARCHAR(50) CHECK (sumber_informasi IN ('WhatsApp', 'Kabar Burung', 'Relasi', 'Lainnya')),
    tahapan_pipeline VARCHAR(50) NOT NULL DEFAULT 'New Lead' CHECK (tahapan_pipeline IN ('New Lead', 'Opportunity', 'Site Visit', 'Quotation', 'Win', 'Loss')),
    alasan_loss TEXT,
    tanggal_masuk TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    id_staf_pic VARCHAR(10) REFERENCES staf(id_staf) ON UPDATE CASCADE ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_leads_pipeline_tgl ON leads(tahapan_pipeline, tanggal_masuk DESC);

-- ============================================================================
-- 6. MIGRASI AMAN KOLOM GAMBAR MATERIAL (UNTUK DATABASE EKSISTING)
-- ============================================================================
ALTER TABLE material ADD COLUMN IF NOT EXISTS gambar VARCHAR(255);
