<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/app_helpers.php';
$authUser         = getAuthUser();
$isApproverUser   = isApproverAdmin();
$currentUri       = $_SERVER['REQUEST_URI'] ?? '';
$currentPath      = parse_url($currentUri, PHP_URL_PATH) ?: '/';
$isDashboard      = ($currentPath === '/' || $currentPath === '/index.php');
$isLeads          = str_starts_with($currentPath, '/leads/');
$isLeadsAnalytics = ($currentPath === '/leads/analytics.php');
$isLeadsIndex     = ($currentPath === '/leads/index.php' || $currentPath === '/leads/');
$isLeadsCreate    = ($currentPath === '/leads/create.php');
$isLeadsWin       = ($currentPath === '/leads/win.php');
$isLeadsLoss      = ($currentPath === '/leads/loss.php');
$isBarang         = str_starts_with($currentPath, '/barang/');
$isTransaksi      = str_starts_with($currentPath, '/transaksi_qr/');
$activeKategori   = ($isBarang && isset($_GET['kategori'])) ? trim((string)$_GET['kategori']) : '';

$countPending = isset($pdo) ? (int)$pdo->query("SELECT COUNT(*) FROM barang_keluar WHERE status_approval = 'Pending Approval'")->fetchColumn() : 0;
$countMat     = isset($pdo) ? (int)$pdo->query("SELECT COUNT(*) FROM material")->fetchColumn() : 0;
$sidebarKats  = isset($pdo) ? getKategoriMaterialWithCounts($pdo) : [];
$countLeadsAktif = 0;
$countLeadsWin   = 0;
$countLeadsLoss  = 0;
if (isset($pdo)) {
    try {
        $leadStatRow = $pdo->query("
            SELECT
                SUM(CASE WHEN tahapan_pipeline IN ('New Lead', 'Opportunity', 'Quotation') THEN 1 ELSE 0 END) AS jml_aktif,
                SUM(CASE WHEN tahapan_pipeline = 'Win' THEN 1 ELSE 0 END) AS jml_win,
                SUM(CASE WHEN tahapan_pipeline = 'Loss' THEN 1 ELSE 0 END) AS jml_loss
            FROM leads
        ")->fetch();
        if ($leadStatRow) {
            $countLeadsAktif = (int)($leadStatRow['jml_aktif'] ?? 0);
            $countLeadsWin   = (int)($leadStatRow['jml_win'] ?? 0);
            $countLeadsLoss  = (int)($leadStatRow['jml_loss'] ?? 0);
        }
    } catch (PDOException $e) {
        $countLeadsAktif = 0;
        $countLeadsWin   = 0;
        $countLeadsLoss  = 0;
    }
}

$pageTitle = match (true) {
    $isTransaksi      => 'Scan & Barang Keluar — PT Tri Arta Konstruksi',
    $isLeadsAnalytics => 'Analitik & Rangkuman CRM — PT Tri Arta Konstruksi',
    $isLeadsWin       => 'Proyek Win — PT Tri Arta Konstruksi',
    $isLeadsLoss      => 'Proyek Loss — PT Tri Arta Konstruksi',
    $isLeads          => 'Pipeline Leads CRM — PT Tri Arta Konstruksi',
    $isBarang         => 'All Material — PT Tri Arta Konstruksi',
    default           => 'Executive Summary — PT Tri Arta Konstruksi',
};

$authInitials = 'TA';
if ($authUser && !empty($authUser['nama'])) {
    $parts = preg_split('/\s+/', trim($authUser['nama']));
    $authInitials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

$sidebarLogoSrc = null;
if (file_exists(__DIR__ . '/../assets/logo-tri-arta.png')) {
    $sidebarLogoSrc = '/assets/logo-tri-arta.png';
} elseif (file_exists(__DIR__ . '/../assets/logo-tri-arta.jpg')) {
    $sidebarLogoSrc = '/assets/logo-tri-arta.jpg';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <link href="/assets/vendor/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/vendor/bootstrap-icons.min.css" rel="stylesheet">
    <script>
        (function () {
            try {
                if (window.innerWidth >= 992 && localStorage.getItem('ta_nav_sidebar_open_v2') === 'true') {
                    document.documentElement.classList.add('nav-sidebar-open', 'nav-sidebar-no-anim');
                }
            } catch (e) {}
        })();
    </script>
    <style>
        :root {
            --ta-navy: #062A4E;
            --ta-blue: #0D559B;
            --ta-orange: #F38218;
            --ta-green: #135E38;
            --ta-slate: #475569;
            --bg-canvas: linear-gradient(135deg, #C8DCF0 0%, #DCE8F6 52%, #F6DFC8 100%);
            --bg-card-glass: linear-gradient(145deg, rgba(240, 246, 253, 0.92) 0%, rgba(253, 245, 237, 0.92) 100%);
            --bg-sidebar: linear-gradient(180deg, #DFEAF6 0%, #ECF2FA 100%);
            --bg-rail: linear-gradient(180deg, #DFEAF6 0%, #ECF2FA 100%);
            --bg-topbar: linear-gradient(90deg, #E1ECF8 0%, #FAF0E6 100%);
            --border-color: #B8CCE0;
            --card-border: rgba(13, 85, 155, 0.16);
            --card-shadow: 0 6px 20px rgba(6, 42, 78, 0.06);
            --rail-width: 82px;
            --text-dark: #062A4E;
            --text-muted: #475569;
        }
        html, body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #C8DCF0 0%, #DCE8F6 52%, #F6DFC8 100%) fixed !important;
            color: var(--text-dark);
            font-size: 15px;
            line-height: 1.5;
            margin: 0;
            min-height: 100vh;
        }
        .app-shell { display: flex; min-height: 100vh; background: transparent !important; }

        /* 1. Leftmost Workspace Icon Rail (Always Visible & Sticky) */
        .icon-rail {
            width: var(--rail-width);
            height: 100vh;
            position: sticky;
            top: 0;
            align-self: flex-start;
            overflow: visible;
            z-index: 1055;
            background: linear-gradient(180deg, #DFEAF6 0%, #ECF2FA 100%) !important;
            border-right: 1px solid #B8CCE0;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 1rem 0;
            gap: 0.72rem;
            flex-shrink: 0;
        }
        .rail-toggle-btn {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            background: rgba(244, 248, 253, 0.82);
            border: 1px solid rgba(13, 85, 155, 0.18);
            color: var(--ta-navy);
            font-size: 1.38rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
            transition: all 0.15s ease;
        }
        .rail-toggle-btn:hover {
            border-color: var(--ta-navy);
            background: rgba(255, 255, 255, 0.92);
            color: var(--ta-navy);
        }
        html.nav-sidebar-open .rail-toggle-btn {
            background: var(--ta-navy);
            border-color: var(--ta-navy);
            color: #FFFFFF;
        }
        .rail-divider {
            width: 34px;
            height: 1px;
            background: #B8CCE0;
            margin: 0.1rem 0;
            flex-shrink: 0;
        }
        .rail-brand-box {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            background: var(--ta-navy);
            color: #FFFFFF;
            font-weight: 800;
            font-size: 0.98rem;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 2px 6px rgba(6, 42, 78, 0.22);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .rail-brand-box:hover { transform: translateY(-1px); color: #FFFFFF; }
        .rail-brand-box.active { box-shadow: 0 0 0 2.5px #DFEAF6, 0 0 0 4.5px var(--ta-navy); }
        .rail-brand-box span { color: var(--ta-orange); }
        .rail-icon {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            background: rgba(244, 248, 253, 0.84);
            border: 1px solid rgba(13, 85, 155, 0.16);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ta-slate);
            text-decoration: none;
            position: relative;
            transition: all 0.15s;
        }
        .rail-icon:hover { border-color: var(--ta-blue); color: var(--ta-navy); background: rgba(250, 246, 242, 0.95); }
        .rail-icon.rail-crm {
            background: rgba(255, 243, 230, 0.88);
            border-color: rgba(243, 130, 24, 0.35);
            color: var(--ta-orange);
        }
        .rail-icon.rail-crm:hover,
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
            font-size: 0.66rem;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 999px;
            border: 1.5px solid #DFEAF6;
        }
        .rail-add {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            background: rgba(244, 248, 253, 0.78);
            border: 1px dashed rgba(13, 85, 155, 0.35);
            color: var(--ta-slate);
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            margin-top: 2px;
            cursor: pointer;
            padding: 0;
            transition: all 0.15s;
        }
        .rail-add:hover,
        .rail-add.show { border-color: var(--ta-navy); color: var(--ta-navy); background: rgba(250, 244, 238, 0.92); }
        .rail-quick-menu {
            min-width: 215px;
            border-radius: 12px;
            padding: 0.45rem;
            font-size: 0.81rem;
            z-index: 1060;
        }
        .rail-quick-menu .dropdown-header {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--ta-slate);
            padding: 0.3rem 0.6rem;
        }
        .rail-quick-menu .dropdown-item {
            border-radius: 8px;
            padding: 0.48rem 0.65rem;
            font-weight: 500;
            color: var(--text-dark);
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }
        .rail-quick-menu .dropdown-item:hover { background: rgba(13, 85, 155, 0.08); color: var(--ta-navy); }
        .rail-bottom-group {
            margin-top: auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.6rem;
            padding-top: 0.75rem;
            border-top: 1px solid #B8CCE0;
            width: 50px;
        }
        .rail-user-avatar {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            background: var(--ta-navy);
            color: #FFFFFF;
            font-size: 0.86rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 2.5px solid var(--ta-orange);
            cursor: default;
            user-select: none;
            box-shadow: 0 2px 6px rgba(6, 42, 78, 0.18);
        }
        .rail-logout {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            background: rgba(244, 248, 253, 0.82);
            border: 1px solid rgba(13, 85, 155, 0.16);
            color: var(--ta-slate);
            font-size: 1.18rem;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: all 0.15s;
        }
        .rail-logout:hover { background: #FEF2F2; border-color: #FECACA; color: #DC2626; }
        .sidebar-user-card {
            background: linear-gradient(145deg, rgba(240, 246, 253, 0.92) 0%, rgba(253, 245, 237, 0.92) 100%);
            border: 1px solid rgba(13, 85, 155, 0.16);
            border-radius: 10px;
            padding: 0.6rem 0.65rem;
            display: flex;
            align-items: center;
            gap: 0.55rem;
            margin-bottom: 0;
        }
        .sidebar-brand-logo {
            width: 100%;
            max-width: 215px;
            height: auto;
            object-fit: contain;
            background: transparent;
            display: block;
            margin: 4px auto 8px;
        }
        .sidebar-user-avatar {
            width: 34px;
            height: 34px;
            border-radius: 9px;
            background: var(--ta-navy);
            color: #FFFFFF;
            font-size: 0.74rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border-bottom: 2px solid var(--ta-orange);
        }
        .sidebar-user-meta {
            min-width: 0;
            flex: 1;
        }
        .sidebar-user-name {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--ta-navy);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.3;
        }
        .sidebar-user-role {
            font-size: 12px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.3;
        }
        .sidebar-logout-btn {
            color: #6B7280;
            padding: 4px 6px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 1rem;
            flex-shrink: 0;
            transition: all 0.14s;
        }
        .sidebar-logout-btn:hover { background: #FEF2F2; color: #DC2626; }

        /* 2. Secondary Navigation Sidebar (Default Collapsed, Smooth Toggle) */
        .nav-sidebar {
            width: 0;
            height: 100vh;
            position: sticky;
            top: 0;
            align-self: flex-start;
            overflow: hidden;
            background: linear-gradient(180deg, #DFEAF6 0%, #ECF2FA 100%) !important;
            border-right: 0 solid #B8CCE0;
            padding: 0;
            opacity: 0;
            pointer-events: none;
            flex-shrink: 0;
            transition: width 0.25s cubic-bezier(0.4, 0, 0.2, 1),
                        opacity 0.2s ease,
                        border-right-width 0.2s ease;
        }
        .nav-sidebar-inner {
            width: 255px;
            min-height: 100%;
            padding: 1rem 0.85rem;
            display: flex;
            flex-direction: column;
        }
        html.nav-sidebar-open .nav-sidebar {
            width: 255px;
            border-right-width: 1px;
            opacity: 1;
            overflow-y: auto;
            pointer-events: auto;
        }
        html.nav-sidebar-no-anim .nav-sidebar,
        html.nav-sidebar-no-anim .nav-sidebar-backdrop {
            transition: none !important;
        }
        .nav-sidebar-backdrop {
            display: none;
        }
        .sidebar-top-title {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.3rem 0.5rem 0.85rem;
            font-weight: 600;
            color: var(--ta-navy);
            font-size: 1rem;
        }
        .nav-section-cap {
            font-size: 12.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.055em;
            color: #475569;
            padding: 0 0.6rem;
            margin: 0.9rem 0 0.35rem;
        }
        .nav-acc-group {
            margin-top: 0.55rem;
        }
        .nav-acc-btn {
            width: 100%;
            background: transparent;
            border: none;
            border-radius: 8px;
            padding: 0.45rem 0.6rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
            font-size: 12.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.055em;
            color: #475569;
            cursor: pointer;
            transition: background 0.14s ease, color 0.14s ease;
        }
        .nav-acc-btn:hover {
            background: rgba(13, 85, 155, 0.07);
            color: var(--ta-navy);
        }
        .nav-acc-caret {
            font-size: 11px;
            color: #64748B;
            transition: transform 0.22s ease, color 0.15s ease;
            transform: rotate(0deg);
        }
        .nav-acc-btn[aria-expanded="true"] .nav-acc-caret {
            transform: rotate(180deg);
            color: var(--ta-navy);
        }
        .nav-acc-list {
            padding-top: 0.2rem;
        }
        .menu-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 0.54rem 0.72rem;
            border-radius: 9px;
            color: #1E293B;
            text-decoration: none;
            font-weight: 500;
            font-size: 14.5px;
            margin-bottom: 3px;
            transition: all 0.12s;
        }
        .menu-row > i {
            flex-shrink: 0;
            margin-top: 2px;
            line-height: 1.25;
        }
        .nav-label-text {
            flex: 1;
            min-width: 0;
            line-height: 1.35;
        }
        .menu-row > .badge-pill-soft,
        .menu-row > .badge-pill-red,
        .cat-dot-link > .badge-pill-soft {
            flex-shrink: 0;
            margin-top: 1px;
        }
        .menu-row:hover { background: rgba(255, 255, 255, 0.55); color: var(--ta-navy); }
        .menu-row.active {
            background: linear-gradient(135deg, rgba(13, 85, 155, 0.14) 0%, rgba(244, 249, 255, 0.94) 100%);
            color: var(--ta-blue);
            font-weight: 600;
            border: 1px solid rgba(13, 85, 155, 0.28);
            box-shadow: 0 2px 8px rgba(13, 85, 155, 0.08);
        }
        .nav-sub-acc-group {
            margin-top: 2px;
            padding-left: 8px;
        }
        .nav-sub-acc-btn {
            width: 100%;
            background: transparent;
            border: none;
            border-radius: 8px;
            padding: 0.45rem 0.65rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            font-size: 13.5px;
            font-weight: 600;
            color: #334155;
            cursor: pointer;
            transition: background 0.14s ease, color 0.14s ease;
        }
        .nav-sub-acc-btn:hover {
            background: rgba(13, 85, 155, 0.07);
            color: var(--ta-navy);
        }
        .nav-sub-caret {
            font-size: 10.5px;
            color: #64748B;
            flex-shrink: 0;
            transition: transform 0.22s ease, color 0.15s ease;
            transform: rotate(0deg);
        }
        .nav-sub-acc-btn[aria-expanded="true"] .nav-sub-caret {
            transform: rotate(90deg);
            color: var(--ta-blue);
        }
        .nav-sub-list {
            margin-left: 14px;
            padding-left: 10px;
            border-left: 1.5px solid rgba(13, 85, 155, 0.14);
            margin-top: 3px;
            margin-bottom: 4px;
        }
        .badge-pill-soft {
            font-size: 12.5px;
            font-weight: 600;
            padding: 2px 9px;
            border-radius: 999px;
            background: #F1F5F9;
            color: #475569;
        }
        .badge-pill-red {
            font-size: 12.5px;
            font-weight: 600;
            padding: 2px 9px;
            border-radius: 999px;
            background: #FEE2E2;
            color: #DC2626;
        }
        .cat-dot-row {
            display: flex;
            align-items: center;
            gap: 4px;
            margin-bottom: 2px;
        }
        .cat-dot-link {
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: flex-start;
            gap: 9px;
            padding: 0.4rem 0.55rem;
            font-size: 13.5px;
            font-weight: 500;
            color: #334155;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.12s ease;
        }
        .cat-dot-link:hover { background: rgba(255, 255, 255, 0.55); color: var(--ta-navy); }
        .cat-dot-link.active {
            background: linear-gradient(135deg, rgba(13, 85, 155, 0.13) 0%, rgba(244, 249, 255, 0.92) 100%);
            color: var(--ta-blue);
            font-weight: 600;
            border: 1px solid rgba(13, 85, 155, 0.24);
        }
        .cat-del-btn {
            border: none;
            background: transparent;
            color: #94A3B8;
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            padding: 0;
            flex-shrink: 0;
            cursor: pointer;
            transition: all 0.14s ease;
        }
        .cat-del-btn:hover {
            background: #FEE2E2;
            color: #DC2626;
        }
        .cat-add-trigger {
            width: 100%;
            margin-top: 6px;
            padding: 0.38rem 0.6rem;
            border-radius: 8px;
            border: 1px dashed rgba(13, 85, 155, 0.35);
            background: rgba(240, 246, 253, 0.65);
            color: var(--ta-blue);
            font-size: 12.5px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            cursor: pointer;
            transition: all 0.14s ease;
        }
        .cat-add-trigger:hover {
            background: rgba(13, 85, 155, 0.12);
            border-color: var(--ta-blue);
            color: var(--ta-navy);
        }
        .dot-bullet {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
            margin-top: 6px;
        }

        /* 3. Main Content & Header */
        .content-area,
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
            min-height: 100vh;
            background: transparent !important;
        }
        .content-area > main {
            background: transparent !important;
        }
        .saas-topbar {
            background: linear-gradient(90deg, #E1ECF8 0%, #FAF0E6 100%) !important;
            padding: 1rem 1.75rem;
            border-bottom: 1px solid #B8CCE0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .saas-title {
            font-size: 1.65rem;
            font-weight: 700;
            color: var(--ta-navy);
            margin: 0;
            letter-spacing: -0.015em;
        }
        .top-search {
            position: relative;
            width: 280px;
            max-width: 100%;
        }
        .top-search input {
            width: 100%;
            background: linear-gradient(145deg, rgba(240, 246, 253, 0.92) 0%, rgba(253, 245, 237, 0.92) 100%);
            border: 1px solid rgba(13, 85, 155, 0.2);
            border-radius: 8px;
            padding: 0.48rem 0.85rem 0.48rem 2.25rem;
            font-size: 14px;
            color: var(--ta-navy);
        }
        .top-search input:focus { outline: none; background: rgba(246, 250, 255, 0.98); border-color: var(--ta-blue); }
        .top-search i {
            position: absolute;
            left: 0.78rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--ta-slate);
            font-size: 14px;
        }
        .btn-toolbar-white {
            background: linear-gradient(145deg, rgba(240, 246, 253, 0.92) 0%, rgba(253, 245, 237, 0.92) 100%);
            border: 1px solid rgba(13, 85, 155, 0.2);
            color: var(--ta-navy);
            border-radius: 8px;
            padding: 0.48rem 0.95rem;
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            text-decoration: none;
            transition: all 0.15s ease;
        }
        .btn-toolbar-white:hover { background: linear-gradient(145deg, #E3EFFB 0%, #FCECDD 100%); border-color: var(--ta-blue); color: var(--ta-navy); }
        .btn-toolbar-primary {
            background: var(--ta-navy);
            border: 1px solid var(--ta-navy);
            color: #FFFFFF;
            border-radius: 8px;
            padding: 0.48rem 1.05rem;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            text-decoration: none;
        }
        .btn-toolbar-primary:hover { background: var(--ta-blue); border-color: var(--ta-blue); color: #fff; }
        .btn-toolbar-orange {
            background: var(--ta-orange);
            border: 1px solid var(--ta-orange);
            color: #FFFFFF;
            border-radius: 8px;
            padding: 0.48rem 1.05rem;
            font-size: 14px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            text-decoration: none;
        }
        .btn-toolbar-orange:hover { opacity: 0.92; color: #fff; }

        /* Soft Frosted Gradient Surface untuk Kartu, Tabel, Form & Modal */
        .stat-box-card,
        .analytics-card,
        .dribbble-list,
        .scanner-stage-card,
        .card,
        .modal-content {
            background: linear-gradient(145deg, rgba(240, 246, 253, 0.92) 0%, rgba(253, 245, 237, 0.92) 100%) !important;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(13, 85, 155, 0.16) !important;
            box-shadow: 0 6px 20px rgba(6, 42, 78, 0.06) !important;
        }

        /* Backdrop Kaca Buram & Standar Pop-Up Modal Besar Estetik (Near-Full Content Width) */
        .modal-backdrop.show {
            background-color: rgba(6, 42, 78, 0.42) !important;
            opacity: 1 !important;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        .ta-large-modal .modal-dialog {
            max-width: min(92vw, 1140px);
            width: 100%;
            margin: 1.5rem auto;
        }
        .ta-large-modal .modal-content {
            max-height: 90vh;
            border-radius: 24px !important;
            border: 1px solid rgba(13, 85, 155, 0.18) !important;
            box-shadow: 0 28px 70px rgba(6, 42, 78, 0.24) !important;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }
        .ta-lm-accent {
            height: 4px;
            width: 100%;
            flex-shrink: 0;
        }
        .ta-lm-accent.theme-orange {
            background: linear-gradient(90deg, #F38218 0%, #FB923C 55%, #0D559B 100%);
        }
        .ta-lm-accent.theme-navy {
            background: linear-gradient(90deg, #062A4E 0%, #0D559B 55%, #F38218 100%);
        }
        .ta-lm-form {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
            overflow: hidden;
        }
        .ta-lm-header {
            padding: 1.15rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-shrink: 0;
            border-bottom: 1px solid rgba(13, 85, 155, 0.14);
            background: linear-gradient(90deg, rgba(235, 244, 253, 0.92) 0%, rgba(254, 244, 233, 0.92) 100%);
        }
        .ta-lm-header-badge {
            width: 44px;
            height: 44px;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .ta-lm-header-badge.theme-orange {
            background: linear-gradient(135deg, rgba(243, 130, 24, 0.18), rgba(255, 247, 237, 0.95));
            color: var(--ta-orange);
            border: 1px solid rgba(243, 130, 24, 0.3);
        }
        .ta-lm-header-badge.theme-navy {
            background: linear-gradient(135deg, rgba(6, 42, 78, 0.14), rgba(239, 246, 255, 0.95));
            color: var(--ta-navy);
            border: 1px solid rgba(13, 85, 155, 0.25);
        }
        .ta-lm-close {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid rgba(13, 85, 155, 0.16);
            background: rgba(255, 255, 255, 0.85);
            color: var(--ta-navy);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.15s ease;
        }
        .ta-lm-close:hover {
            background: var(--ta-navy);
            border-color: var(--ta-navy);
            color: #FFFFFF;
            transform: rotate(90deg);
        }
        .ta-lm-body {
            padding: 1.5rem 1.75rem;
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
        }
        .ta-lm-footer {
            padding: 1rem 1.75rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-shrink: 0;
            border-top: 1px solid rgba(13, 85, 155, 0.14);
            background: linear-gradient(90deg, rgba(238, 245, 253, 0.95) 0%, rgba(253, 246, 238, 0.95) 100%);
        }
        .ta-lm-panel {
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.72) 0%, rgba(238, 246, 254, 0.65) 100%);
            border: 1px solid rgba(13, 85, 155, 0.15);
            border-radius: 16px;
            padding: 1.15rem 1.25rem;
        }
        @media (max-width: 767.98px) {
            .ta-large-modal .modal-dialog {
                max-width: 96vw;
                margin: 0.75rem auto;
            }
            .ta-lm-header, .ta-lm-body, .ta-lm-footer {
                padding-left: 1.15rem;
                padding-right: 1.15rem;
            }
        }
        .form-control,
        .form-select {
            background: linear-gradient(145deg, rgba(240, 246, 253, 0.92) 0%, rgba(253, 245, 237, 0.92) 100%) !important;
            backdrop-filter: blur(6px);
            border: 1px solid rgba(13, 85, 155, 0.2) !important;
            color: var(--ta-navy) !important;
        }
        .form-control:focus,
        .form-select:focus {
            background: linear-gradient(145deg, rgba(246, 250, 255, 0.98) 0%, rgba(255, 249, 242, 0.98) 100%) !important;
            border-color: var(--ta-blue) !important;
            box-shadow: 0 0 0 3px rgba(13, 85, 155, 0.14) !important;
        }
        .form-control[readonly],
        .form-control:disabled {
            background: linear-gradient(145deg, rgba(220, 233, 246, 0.82) 0%, rgba(241, 234, 226, 0.82) 100%) !important;
            color: var(--ta-navy) !important;
        }
        .dropdown-menu {
            background: linear-gradient(145deg, rgba(240, 246, 253, 0.96) 0%, rgba(253, 245, 237, 0.96) 100%) !important;
            backdrop-filter: blur(8px);
            border: 1px solid rgba(13, 85, 155, 0.16) !important;
            box-shadow: 0 12px 28px rgba(6, 42, 78, 0.12) !important;
        }

        /* Collapsible Statistic Card (Persis Referensi) */
        .stat-box-card {
            border-radius: 12px;
            padding: 1.15rem 1.45rem;
            margin-bottom: 1.25rem;
        }
        .stat-col-item { border-right: 1px solid rgba(13, 85, 155, 0.14); }
        .stat-col-item:last-child { border-right: none; }
        .stat-lbl { font-size: 14px; font-weight: 500; color: var(--text-muted); margin-bottom: 5px; }
        .stat-val { font-size: 1.45rem; font-weight: 700; color: var(--ta-navy); }
        .stat-val small { font-size: 13px; font-weight: 500; color: var(--text-muted); }

        /* Clean Borderless-Header Row List (Persis Dribbble Reference) */
        .dribbble-list {
            border-radius: 12px;
            overflow: hidden;
        }
        .dribbble-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .dribbble-table th { font-size: 13px !important; font-weight: 700; }
        .dribbble-table tr {
            border-bottom: 1px solid rgba(13, 85, 155, 0.12);
            transition: background 0.12s;
        }
        .dribbble-table tr:last-child { border-bottom: none; }
        .dribbble-table tr:hover { background: rgba(13, 85, 155, 0.045); }
        .dribbble-table td { padding: 0.92rem 1.2rem; vertical-align: middle; }

        .prod-thumb {
            width: 44px;
            height: 44px;
            border-radius: 9px;
            background: linear-gradient(145deg, rgba(223, 234, 246, 0.85) 0%, rgba(250, 238, 224, 0.85) 100%);
            border: 1px solid rgba(13, 85, 155, 0.16);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--ta-navy);
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .col-micro-lbl {
            font-size: 13px;
            color: var(--text-muted);
            margin-bottom: 3px;
        }
        .col-main-val {
            font-size: 14.5px;
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

        /* Global Alert Banner & Presisi Tombol Close (×) */
        .alert:not(.d-none),
        .alert-dismissible:not(.d-none) {
            display: flex !important;
            align-items: center !important;
            justify-content: space-between !important;
            gap: 16px;
            padding: 14px 20px !important;
            border-radius: 14px !important;
            line-height: 1.45;
        }
        .alert-dismissible .btn-close {
            position: static !important;
            padding: 0 !important;
            margin: 0 !important;
            width: 28px;
            height: 28px;
            flex-shrink: 0;
            border-radius: 8px;
            opacity: 0.65;
            transition: opacity 0.2s ease, background-color 0.2s ease;
        }
        .alert-dismissible .btn-close:hover {
            opacity: 1;
            background-color: rgba(6, 42, 78, 0.08);
        }
        .alert-dismissible .btn-close:focus {
            box-shadow: 0 0 0 3px rgba(13, 85, 155, 0.16);
        }

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
            :root {
                --rail-width: 72px;
            }
            .icon-rail {
                width: var(--rail-width);
            }
            .nav-sidebar {
                position: fixed;
                top: 0;
                left: var(--rail-width);
                width: 275px;
                max-width: calc(100vw - var(--rail-width));
                height: 100vh;
                z-index: 1050;
                border-right: 1px solid var(--border-color);
                box-shadow: 12px 0 36px rgba(6, 42, 78, 0.22);
                transform: translateX(-110%);
                opacity: 0;
                overflow-y: auto;
                pointer-events: none;
                transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.2s ease;
            }
            .nav-sidebar-inner {
                width: 100%;
            }
            html.nav-sidebar-open .nav-sidebar {
                width: 275px;
                transform: translateX(0);
                opacity: 1;
                pointer-events: auto;
            }
            .nav-sidebar-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(6, 42, 78, 0.45);
                backdrop-filter: blur(2px);
                z-index: 1045;
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.22s ease;
            }
            html.nav-sidebar-open .nav-sidebar-backdrop {
                opacity: 1;
                pointer-events: auto;
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <!-- Backdrop Overlay (Mobile/Tablet Drawer) -->
    <div id="navSidebarBackdrop" class="nav-sidebar-backdrop" aria-hidden="true"></div>

    <!-- 1. Rail Ikon Workspace Kiri (Always Visible & Sticky) -->
    <aside class="icon-rail">
        <button type="button" id="sidebarToggleBtn" class="rail-toggle-btn" title="Buka / Tutup Menu Sidebar" aria-label="Buka atau tutup menu sidebar" aria-controls="navSidebarPanel" aria-expanded="false">
            <i class="bi bi-list"></i>
        </button>
        <div class="rail-divider"></div>

        <a href="/index.php" class="rail-brand-box <?= $isDashboard ? 'active' : '' ?>" title="Dashboard Utama (Executive Summary)">T<span>A</span></a>
        <a href="/leads/analytics.php" class="rail-icon rail-crm <?= $isLeads ? 'active' : '' ?>" title="Analitik &amp; Rangkuman CRM (Leads)">
            <i class="bi bi-speedometer2"></i>
        </a>
        <a href="/barang/index.php" class="rail-icon <?= $isBarang ? 'active' : '' ?>" title="Modul SCM / Warehouse">
            <i class="bi bi-box-seam"></i>
        </a>
        <a href="/transaksi_qr/index.php" class="rail-icon <?= $isTransaksi ? 'active' : '' ?>" title="Scan &amp; Barang Keluar">
            <i class="bi bi-qr-code-scan"></i>
            <?php if ($countPending > 0): ?>
                <span class="rail-pill-badge"><?= $countPending ?></span>
            <?php endif; ?>
        </a>
        <div class="dropend">
            <button type="button" class="rail-add" data-bs-toggle="dropdown" aria-expanded="false" title="Aksi Cepat">
                <i class="bi bi-plus-lg"></i>
            </button>
            <ul class="dropdown-menu rail-quick-menu ms-2">
                <li><h6 class="dropdown-header">Aksi Cepat</h6></li>
                <li>
                    <a class="dropdown-item" href="/leads/create.php">
                        <i class="bi bi-person-plus-fill" style="color: var(--ta-orange);"></i>
                        <span>Tambah Lead Baru</span>
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="/transaksi_qr/create.php">
                        <i class="bi bi-qr-code-scan" style="color: var(--ta-blue);"></i>
                        <span>Scan &amp; Barang Keluar</span>
                    </a>
                </li>
            </ul>
        </div>

        <?php if ($authUser): ?>
            <div class="rail-bottom-group">
                <div class="rail-user-avatar" title="<?= htmlspecialchars($authUser['nama'] . ' — ' . $authUser['nama_jabatan']) ?>">
                    <?= htmlspecialchars($authInitials) ?>
                </div>
                <a href="/logout.php" class="rail-logout" title="Logout (<?= htmlspecialchars($authUser['nama']) ?>)">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </aside>

    <!-- 2. Sidebar Menu & Kategori (Collapsible Panel / Mobile Drawer) -->
    <aside id="navSidebarPanel" class="nav-sidebar" aria-label="Navigasi Sub-Menu">
        <div class="nav-sidebar-inner">
            <div class="sidebar-top-title justify-content-center px-0 pt-1 pb-2 mb-2 border-bottom">
                <?php if ($sidebarLogoSrc !== null): ?>
                    <a href="/index.php" class="d-block w-100 text-center text-decoration-none">
                        <img src="<?= htmlspecialchars($sidebarLogoSrc) ?>" alt="PT Tri Arta Konstruksi" class="sidebar-brand-logo">
                    </a>
                <?php else: ?>
                    <span class="fw-bold" style="color: var(--ta-navy);">TRI <span style="color: var(--ta-orange);">ARTA</span></span>
                    <span class="badge-pill-soft" style="font-size: 0.65rem;">SCM</span>
                <?php endif; ?>
            </div>

            <!-- Menu Berdiri Sendiri (Standalone) -->
            <a href="/index.php" class="menu-row mt-1 <?= $isDashboard ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2"></i>
                <span class="nav-label-text">Executive Summary</span>
            </a>
            <a href="/transaksi_qr/index.php" class="menu-row mb-1 <?= $isTransaksi ? 'active' : '' ?>">
                <i class="bi bi-qr-code-scan"></i>
                <span class="nav-label-text">Scan &amp; Barang Keluar</span>
                <?php if ($countPending > 0): ?>
                    <span class="badge-pill-red"><?= $countPending ?></span>
                <?php endif; ?>
            </a>

            <!-- Bab 1: CRM & SALES (Level 1 Accordion) -->
            <div class="nav-acc-group">
                <button type="button" class="nav-acc-btn" data-acc-toggle="1" data-acc-target="#accCrmSales" data-acc-key="crm" aria-expanded="true" aria-controls="accCrmSales">
                    <span>CRM &amp; Sales</span>
                    <i class="bi bi-caret-up-fill nav-acc-caret"></i>
                </button>
                <div id="accCrmSales" class="collapse show nav-acc-list" data-acc-key="crm">
                    <a href="/leads/analytics.php" class="menu-row <?= $isLeadsAnalytics ? 'active' : '' ?>">
                        <i class="bi bi-bar-chart-line" style="color: var(--ta-blue);"></i>
                        <span class="nav-label-text">Analitik &amp; Rangkuman CRM</span>
                    </a>
                    <a href="/leads/index.php" class="menu-row <?= ($isLeadsIndex || $isLeadsCreate) ? 'active' : '' ?>">
                        <i class="bi bi-speedometer2" style="color: var(--ta-orange);"></i>
                        <span class="nav-label-text">Pipeline Leads</span>
                        <span class="badge-pill-soft" style="background: #FFF7ED; color: var(--ta-orange);"><?= $countLeadsAktif ?></span>
                    </a>
                    <a href="/leads/win.php" class="menu-row <?= $isLeadsWin ? 'active' : '' ?>">
                        <i class="bi bi-trophy-fill" style="color: #059669;"></i>
                        <span class="nav-label-text">Proyek Win</span>
                        <span class="badge-pill-soft" style="background: #ECFDF5; color: #059669;"><?= $countLeadsWin ?></span>
                    </a>
                    <a href="/leads/loss.php" class="menu-row <?= $isLeadsLoss ? 'active' : '' ?>">
                        <i class="bi bi-x-octagon-fill" style="color: #DC2626;"></i>
                        <span class="nav-label-text">Proyek Loss</span>
                        <span class="badge-pill-red"><?= $countLeadsLoss ?></span>
                    </a>
                </div>
            </div>

            <!-- Bab 2: INVENTORY & SCM (Level 1 Accordion + Nested Sub-Bab Material Category) -->
            <div class="nav-acc-group">
                <button type="button" class="nav-acc-btn" data-acc-toggle="1" data-acc-target="#accInventoryScm" data-acc-key="scm" aria-expanded="true" aria-controls="accInventoryScm">
                    <span>Inventory &amp; SCM</span>
                    <i class="bi bi-caret-up-fill nav-acc-caret"></i>
                </button>
                <div id="accInventoryScm" class="collapse show nav-acc-list" data-acc-key="scm" data-force-open="<?= $activeKategori !== '' ? '1' : '0' ?>">
                    <a href="/barang/index.php" class="menu-row <?= ($isBarang && $activeKategori === '') ? 'active' : '' ?>">
                        <i class="bi bi-box-seam"></i>
                        <span class="nav-label-text">All Material</span>
                        <span class="badge-pill-soft"><?= $countMat ?></span>
                    </a>

                    <!-- Sub-Bab: Material Category (Level 2 Nested Accordion Dinamis) -->
                    <div class="nav-sub-acc-group">
                        <button type="button" class="nav-sub-acc-btn" data-acc-toggle="1" data-acc-target="#accMaterialCat" data-acc-key="cat" aria-expanded="true" aria-controls="accMaterialCat">
                            <span class="d-flex align-items-center gap-2 min-w-0">
                                <i class="bi bi-tags" style="color: var(--ta-blue); flex-shrink: 0;"></i>
                                <span class="nav-label-text text-start">Material Category</span>
                            </span>
                            <i class="bi bi-caret-right-fill nav-sub-caret"></i>
                        </button>
                        <div id="accMaterialCat" class="collapse show nav-sub-list" data-acc-key="cat" data-force-open="<?= $activeKategori !== '' ? '1' : '0' ?>">
                            <?php foreach ($sidebarKats as $sk):
                                $katId       = (int)$sk['id_kategori'];
                                $katName     = (string)$sk['nama_kategori'];
                                $katJml      = (int)$sk['jml_material'];
                                $dot         = !empty($sk['warna_dot']) ? $sk['warna_dot'] : '#0D559B';
                                $isActiveKat = ($activeKategori !== '' && $activeKategori === $katName);
                            ?>
                                <div class="cat-dot-row">
                                    <a href="/barang/index.php?kategori=<?= urlencode($katName) ?>" class="cat-dot-link <?= $isActiveKat ? 'active' : '' ?>">
                                        <span class="dot-bullet" style="background: <?= htmlspecialchars($dot) ?>;"></span>
                                        <span class="nav-label-text"><?= htmlspecialchars($katName) ?></span>
                                        <span class="badge-pill-soft"><?= $katJml ?></span>
                                    </a>
                                    <form method="POST" action="/barang/kategori_action.php" class="d-inline m-0"
                                          onsubmit="return confirm('Hapus kategori <?= htmlspecialchars($katName, ENT_QUOTES) ?>?');">
                                        <input type="hidden" name="aksi" value="hapus">
                                        <input type="hidden" name="id_kategori" value="<?= $katId ?>">
                                        <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentPath) ?>">
                                        <button type="submit" class="cat-del-btn" title="Hapus Kategori <?= htmlspecialchars($katName) ?>">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php endforeach; ?>

                            <button type="button" class="cat-add-trigger" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
                                <i class="bi bi-plus-lg"></i> Tambah Kategori
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-auto pt-3 border-top">
                <?php if ($authUser): ?>
                    <div class="sidebar-user-card" title="<?= htmlspecialchars($authUser['nama'] . ' — ' . $authUser['nama_jabatan'] . ' (' . $authUser['role_akses'] . ')') ?>">
                        <div class="sidebar-user-avatar"><?= htmlspecialchars($authInitials) ?></div>
                        <div class="sidebar-user-meta">
                            <div class="sidebar-user-name"><?= htmlspecialchars($authUser['nama']) ?></div>
                            <div class="sidebar-user-role"><?= htmlspecialchars($authUser['nama_jabatan']) ?></div>
                        </div>
                        <a href="/logout.php" class="sidebar-logout-btn" title="Logout">
                            <i class="bi bi-box-arrow-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </aside>
    <script>
    // Eksekusi instan tepat setelah sidebar agar tombol toggle & accordion responsif 0ms tanpa menunggu CDN
    (function () {
        const STORAGE_KEY = 'ta_nav_sidebar_open_v2';
        const ACC_KEY     = 'ta_nav_accordion_state_v3';
        const root        = document.documentElement;
        const toggleBtn   = document.getElementById('sidebarToggleBtn');

        requestAnimationFrame(function () {
            root.classList.remove('nav-sidebar-no-anim');
        });

        function syncAria() {
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-expanded', root.classList.contains('nav-sidebar-open') ? 'true' : 'false');
            }
        }

        function setSidebarOpen(isOpen, persistDesktop) {
            root.classList.toggle('nav-sidebar-open', isOpen);
            syncAria();
            if (persistDesktop && window.innerWidth >= 992) {
                try {
                    localStorage.setItem(STORAGE_KEY, isOpen ? 'true' : 'false');
                } catch (e) {}
            }
            setTimeout(function () {
                window.dispatchEvent(new Event('resize'));
            }, 270);
        }

        // Pulihkan state nested accordion dari localStorage secara langsung
        let accState = {};
        try {
            accState = JSON.parse(localStorage.getItem(ACC_KEY) || '{}') || {};
        } catch (e) {
            accState = {};
        }

        function applyAccordionState(panel, btn, isOpen) {
            panel.classList.toggle('show', isOpen);
            btn.classList.toggle('collapsed', !isOpen);
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        document.querySelectorAll('.nav-acc-list[data-acc-key], .nav-sub-list[data-acc-key]').forEach(function (panel) {
            const key       = panel.getAttribute('data-acc-key');
            const forceOpen = panel.getAttribute('data-force-open') === '1';
            const btn       = document.querySelector('[data-acc-toggle="1"][data-acc-key="' + key + '"]');
            if (!key || !btn) return;
            const shouldOpen = forceOpen || accState[key] !== false;
            applyAccordionState(panel, btn, shouldOpen);
        });

        // Event delegation global agar klik tombol sidebar & accordion langsung aktif seketika
        document.addEventListener('click', function (e) {
            const sbToggle = e.target.closest('#sidebarToggleBtn');
            if (sbToggle) {
                e.preventDefault();
                setSidebarOpen(!root.classList.contains('nav-sidebar-open'), true);
                return;
            }

            if (e.target.closest('#navSidebarBackdrop')) {
                setSidebarOpen(false, false);
                return;
            }

            const accBtn = e.target.closest('[data-acc-toggle="1"]');
            if (accBtn) {
                e.preventDefault();
                e.stopPropagation();
                const targetSel = accBtn.getAttribute('data-acc-target');
                const key       = accBtn.getAttribute('data-acc-key');
                const panel     = targetSel ? document.querySelector(targetSel) : null;
                if (!panel) return;
                const nextOpen = !panel.classList.contains('show');
                applyAccordionState(panel, accBtn, nextOpen);
                if (key) {
                    accState[key] = nextOpen;
                    try { localStorage.setItem(ACC_KEY, JSON.stringify(accState)); } catch (err) {}
                }
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && root.classList.contains('nav-sidebar-open') && window.innerWidth < 992) {
                setSidebarOpen(false, false);
            }
        });

        syncAria();
    })();
    </script>

    <!-- 3. Main Content -->
    <div class="content-area">
