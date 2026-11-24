# Konteks Proyek: Sistem SCM & QR Inventory — PT Tri Arta Konstruksi

## 1. Tech Stack & Database
- **Backend:** PHP 8+ Native dengan koneksi **PDO** (`config/db.php`, tidak boleh diubah/dihapus).
- **Database:** PostgreSQL lokal (`db_scm_tri_arta`), skema 3NF ada di `database/database/db_scm_tri_arta.sql`.
- **Relasi Penting:** Tabel `staf` memakai foreign key `id_jabatan` yang berelasi ke `jabatan(id_jabatan, nama_jabatan)`. Tabel utama meliputi: `material`, `kemasan_qr`, `barang_keluar`, `detail_keluar`, `proyek`, `staf`, `jabatan`, `vendor`, `barang_masuk`, `detail_masuk`.
- **Frontend:** Bootstrap 5.3.3 + Bootstrap Icons + Google Font `Inter` + Custom CSS di `layout/header.php`.

## 2. Identitas Visual & Color Palette (Wajib Dipertahankan)
- **Layout:** Clean SaaS Inventory Dashboard (2-tier left sidebar: `icon-rail` + `nav-sidebar`, `saas-topbar`, 5-column `stat-box-card`, `dribbble-list` table dengan SVG semi-circle arc gauge persentase, `saas-switch`, dan pagination).
- **Palet Warna Resmi Logo PT Tri Arta Konstruksi:**
  - Deep Navy: `#062A4E` (`--ta-navy`)
  - Royal Blue: `#0D559B` (`--ta-blue`)
  - Construct Orange: `#F38218` (`--ta-orange`)
  - Forest Green: `#135E38` (`--ta-green`)
  - Slate Gray: `#617275` (`--ta-slate`)

## 3. Struktur Folder & Status File
- `config/db.php` — Koneksi PDO aktif (di-ignore Git).
- `layout/header.php` & `layout/footer.php` — Kerangka global UI & CSS variables.
- `index.php` — Dashboard utama & monitoring kemasan QR per section gudang.
- `barang/` — Modul CRUD Material (`index.php`, `create.php`, `edit.php`, `delete.php`).
- `transaksi_qr/` — Modul Pengeluaran Barang QR & Approval (`index.php`, `create.php`).

## 4. Aturan Coding di Proyek Ini
- Setiap file di dalam subfolder (`barang/`, `transaksi_qr/`) wajib memanggil koneksi dan layout menggunakan `__DIR__ . '/../config/db.php'` dan `__DIR__ . '/../layout/header.php'`.
- Jangan merusak class CSS global yang sudah ada di `layout/header.php` (`saas-topbar`, `dribbble-list`, `dribbble-table`, `btn-toolbar-primary`, `btn-toolbar-orange`, `badge-pill-soft`).
