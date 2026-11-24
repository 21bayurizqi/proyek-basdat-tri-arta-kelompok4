<?php
$currentUri   = $_SERVER['REQUEST_URI'] ?? '';
$countPending = isset($pdo) ? (int)$pdo->query("SELECT COUNT(*) FROM barang_keluar WHERE status_approval = 'Pending Approval'")->fetchColumn() : 0;
$countMat     = isset($pdo) ? (int)$pdo->query("SELECT COUNT(*) FROM material")->fetchColumn() : 0;
$countQrAktif = isset($pdo) ? (int)$pdo->query("SELECT COUNT(*) FROM kemasan_qr WHERE status_kemasan != 'Habis'")->fetchColumn() : 0;
$sidebarKats  = isset($pdo) ? $pdo->query("SELECT kategori, COUNT(*) AS jml FROM material GROUP BY kategori ORDER BY kategori ASC")->fetchAll() : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SCM & QR Inventory — PT Tri Arta Konstruksi</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --ta-navy: #062A4E;
            --ta-blue: #0D559B;
            --ta-orange: #F38218;
            --ta-green: #135E38;
            --ta-slate: #617275;
            --bg-main: #FFFFFF;
            --bg-sidebar: #FAFAFA;
            --bg-rail: #F4F5F7;
            --border-color: #EAECEF;
            --text-dark: #111827;
            --text-muted: #6B7280;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: var(--bg-main);
            color: var(--text-dark);
            font-size: 0.86rem;
            margin: 0;
        }
        .app-shell { display: flex; min-height: 100vh; }

        /* 1. Leftmost Workspace Icon Rail (Persis Referensi) */
        .icon-rail {
            width: 62px;
            background: var(--bg-rail);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem 0;
            gap: 0.65rem;
            flex-shrink: 0;
        }
        .rail-brand-box {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--ta-navy);
            color: #FFFFFF;
            font-weight: 800;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 2px 6px rgba(6, 42, 78, 0.2);
        }
        .rail-brand-box span { color: var(--ta-orange); }
        .rail-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ta-slate);
            text-decoration: none;
            position: relative;
            transition: all 0.15s;
        }
        .rail-icon:hover { border-color: var(--ta-blue); color: var(--ta-navy); }
        .rail-icon.active {
            background: var(--ta-orange);
            border-color: var(--ta-orange);
            color: #FFFFFF;
        }
        .rail-pill-badge {
            position: absolute;
            top: -5px;
            right: -6px;
            background: #EF4444;
            color: #fff;
            font-size: 0.62rem;
            font-weight: 700;
            padding: 1px 5px;
            border-radius: 999px;
            border: 1.5px solid #fff;
        }
        .rail-add {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            border: 1px dashed #CBD5E1;
            color: #94A3B8;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            margin-top: 4px;
        }
        .rail-add:hover { border-color: var(--ta-navy); color: var(--ta-navy); }

        /* 2. Secondary Navigation Sidebar */
        .nav-sidebar {
            width: 235px;
            background: var(--bg-sidebar);
            border-right: 1px solid var(--border-color);
            padding: 1.1rem 0.85rem;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .sidebar-top-title {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.3rem 0.5rem 0.85rem;
            font-weight: 600;
            color: var(--ta-navy);
            font-size: 0.9rem;
        }
        .nav-section-cap {
            font-size: 0.67rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #9CA3AF;
            padding: 0 0.6rem;
            margin: 1rem 0 0.35rem;
        }
        .menu-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.48rem 0.68rem;
            border-radius: 8px;
            color: #4B5563;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.83rem;
            margin-bottom: 2px;
            transition: all 0.12s;
        }
        .menu-row:hover { background: #F1F5F9; color: var(--ta-navy); }
        .menu-row.active {
            background: #FFFFFF;
            color: var(--ta-navy);
            font-weight: 600;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }
        .badge-pill-soft {
            font-size: 0.68rem;
            font-weight: 600;
            padding: 1px 7px;
            border-radius: 999px;
            background: #F1F5F9;
            color: #475569;
        }
        .badge-pill-red {
            font-size: 0.68rem;
            font-weight: 600;
            padding: 1px 7px;
            border-radius: 999px;
            background: #FEE2E2;
            color: #DC2626;
        }
        .cat-dot-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.36rem 0.68rem 0.36rem 1.2rem;
            font-size: 0.8rem;
            color: #6B7280;
            text-decoration: none;
            border-radius: 7px;
        }
        .cat-dot-link:hover { background: #F1F5F9; color: var(--ta-navy); }
        .dot-bullet {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 7px;
        }

        /* 3. Main Content & Header */
        .content-area { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .saas-topbar {
            padding: 1rem 1.75rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .saas-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--ta-navy);
            margin: 0;
            letter-spacing: -0.01em;
        }
        .top-search {
            position: relative;
            width: 260px;
            max-width: 100%;
        }
        .top-search input {
            width: 100%;
            background: #F9FAFB;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.42rem 0.8rem 0.42rem 2.1rem;
            font-size: 0.82rem;
        }
        .top-search input:focus { outline: none; background: #fff; border-color: var(--ta-blue); }
        .top-search i {
            position: absolute;
            left: 0.72rem;
            top: 50%;
            transform: translateY(-50%);
            color: #9CA3AF;
            font-size: 0.82rem;
        }
        .btn-toolbar-white {
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            color: var(--text-dark);
            border-radius: 8px;
            padding: 0.42rem 0.85rem;
            font-size: 0.82rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
        }
        .btn-toolbar-white:hover { background: #F9FAFB; color: var(--ta-navy); }
        .btn-toolbar-primary {
            background: var(--ta-navy);
            border: 1px solid var(--ta-navy);
            color: #FFFFFF;
            border-radius: 8px;
            padding: 0.42rem 0.95rem;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
        }
        .btn-toolbar-primary:hover { background: var(--ta-blue); border-color: var(--ta-blue); color: #fff; }
        .btn-toolbar-orange {
            background: var(--ta-orange);
            border: 1px solid var(--ta-orange);
            color: #FFFFFF;
            border-radius: 8px;
            padding: 0.42rem 0.95rem;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            text-decoration: none;
        }
        .btn-toolbar-orange:hover { opacity: 0.92; color: #fff; }

        /* Collapsible Statistic Card (Persis Referensi) */
        .stat-box-card {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem 1.35rem;
            margin-bottom: 1.25rem;
            background: #FFFFFF;
        }
        .stat-col-item { border-right: 1px solid var(--border-color); }
        .stat-col-item:last-child { border-right: none; }
        .stat-lbl { font-size: 0.74rem; color: var(--text-muted); margin-bottom: 4px; }
        .stat-val { font-size: 1.22rem; font-weight: 700; color: var(--ta-navy); }
        .stat-val small { font-size: 0.74rem; font-weight: 400; color: var(--text-muted); }

        /* Clean Borderless-Header Row List (Persis Dribbble Reference) */
        .dribbble-list {
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: #FFFFFF;
            overflow: hidden;
        }
        .dribbble-table { width: 100%; border-collapse: collapse; }
        .dribbble-table tr {
            border-bottom: 1px solid var(--border-color);
            transition: background 0.12s;
        }
        .dribbble-table tr:last-child { border-bottom: none; }
        .dribbble-table tr:hover { background: #FAFAFA; }
        .dribbble-table td { padding: 0.85rem 1.15rem; vertical-align: middle; }

        .prod-thumb {
            width: 44px;
            height: 44px;
            border-radius: 9px;
            background: #F4F5F7;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ta-navy);
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .col-micro-lbl {
            font-size: 0.72rem;
            color: var(--text-muted);
            margin-bottom: 2px;
        }
        .col-main-val {
            font-size: 0.84rem;
            font-weight: 600;
            color: var(--text-dark);
        }
        /* Toggle Switch Persis Visibility di Gambar */
        .saas-switch {
            width: 34px;
            height: 19px;
            border-radius: 999px;
            background: #E5E7EB;
            position: relative;
            display: inline-block;
            vertical-align: middle;
        }
        .saas-switch.on { background: var(--ta-navy); }
        .saas-switch::after {
            content: '';
            width: 13px;
            height: 13px;
            border-radius: 50%;
            background: #FFFFFF;
            position: absolute;
            top: 3px;
            left: 3px;
            transition: transform 0.15s;
        }
        .saas-switch.on::after { transform: translateX(15px); }

        .row-act-icon {
            color: #6B7280;
            padding: 4px 6px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.92rem;
        }
        .row-act-icon:hover { background: #F3F4F6; color: var(--ta-navy); }
        .row-act-icon.del:hover { background: #FEF2F2; color: #DC2626; }

        /* Pagination Footer Persis Gambar */
        .dribbble-pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 0.25rem 0.25rem;
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        @media (max-width: 991px) {
            .icon-rail, .nav-sidebar { display: none; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <!-- 1. Rail Ikon Workspace Kiri -->
    <aside class="icon-rail">
        <a href="/index.php" class="rail-brand-box" title="PT Tri Arta Konstruksi">T<span>A</span></a>
        <a href="/index.php" class="rail-icon <?= ($currentUri === '/' || (str_contains($currentUri, '/index.php') && !str_contains($currentUri, '/barang/') && !str_contains($currentUri, '/transaksi_qr/'))) ? 'active' : '' ?>" title="Dashboard & Rak QR">
            <i class="bi bi-speedometer2"></i>
        </a>
        <a href="/barang/index.php" class="rail-icon <?= str_contains($currentUri, '/barang/') ? 'active' : '' ?>" title="All Material List">
            <i class="bi bi-box-seam"></i>
        </a>
        <a href="/transaksi_qr/index.php" class="rail-icon <?= str_contains($currentUri, '/transaksi_qr/') ? 'active' : '' ?>" title="Transaksi Barang Keluar">
            <i class="bi bi-qr-code-scan"></i>
            <?php if ($countPending > 0): ?>
                <span class="rail-pill-badge"><?= $countPending ?></span>
            <?php endif; ?>
        </a>
        <a href="/barang/create.php" class="rail-add" title="Tambah Material Baru">
            <i class="bi bi-plus-lg"></i>
        </a>
    </aside>

    <!-- 2. Sidebar Menu & Kategori -->
    <aside class="nav-sidebar">
        <div class="sidebar-top-title">
            <?php if (file_exists(__DIR__ . '/../assets/logo-tri-arta.png')): ?>
                <img src="/assets/logo-tri-arta.png" alt="PT Tri Arta Konstruksi" style="max-height: 36px;">
            <?php else: ?>
                <span class="fw-bold" style="color: var(--ta-navy);">TRI <span style="color: var(--ta-orange);">ARTA</span></span>
                <span class="badge-pill-soft" style="font-size: 0.65rem;">SCM</span>
            <?php endif; ?>
        </div>

        <a href="/index.php" class="menu-row <?= ($currentUri === '/' || (str_contains($currentUri, '/index.php') && !str_contains($currentUri, '/barang/') && !str_contains($currentUri, '/transaksi_qr/'))) ? 'active' : '' ?>">
            <span><i class="bi bi-graph-up-arrow me-2"></i>Analytics & Rak QR</span>
        </a>
        <a href="/transaksi_qr/index.php" class="menu-row <?= str_contains($currentUri, '/transaksi_qr/') ? 'active' : '' ?>">
            <span><i class="bi bi-bell me-2"></i>Pending Approval</span>
            <?php if ($countPending > 0): ?>
                <span class="badge-pill-red"><?= $countPending ?></span>
            <?php endif; ?>
        </a>

        <div class="nav-section-cap">Inventory</div>
        <a href="/barang/index.php" class="menu-row <?= str_contains($currentUri, '/barang/') ? 'active' : '' ?>">
            <span><i class="bi bi-box-seam me-2"></i>All Material</span>
            <span class="badge-pill-soft"><?= $countMat ?></span>
        </a>
        <a href="/transaksi_qr/index.php" class="menu-row">
            <span><i class="bi bi-truck me-2"></i>Barang Keluar QR</span>
        </a>
        <a href="/index.php#rak-qr" class="menu-row">
            <span><i class="bi bi-qr-code me-2"></i>Kemasan QR Aktif</span>
            <span class="badge-pill-soft"><?= $countQrAktif ?></span>
        </a>

        <div class="nav-section-cap">Material Category</div>
        <?php
        $dotColors = ['#F38218', '#0D559B', '#135E38', '#062A4E', '#617275'];
        foreach ($sidebarKats as $idx => $sk):
            $dot = $dotColors[$idx % count($dotColors)];
        ?>
            <a href="/barang/index.php?kategori=<?= urlencode($sk['kategori']) ?>" class="cat-dot-link">
                <span><span class="dot-bullet" style="background: <?= $dot ?>;"></span><?= htmlspecialchars($sk['kategori']) ?></span>
                <span class="badge-pill-soft"><?= $sk['jml'] ?></span>
            </a>
        <?php endforeach; ?>

        <div class="mt-auto pt-3 border-top d-flex align-items-center justify-content-between text-muted" style="font-size: 0.75rem;">
            <span><i class="bi bi-database-check me-1" style="color: var(--ta-green);"></i> PostgreSQL</span>
            <span>Kelompok 4</span>
        </div>
    </aside>

    <!-- 3. Main Content -->
    <div class="content-area">
