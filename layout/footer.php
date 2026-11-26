        <footer class="mt-auto py-3 px-4 border-top d-flex justify-content-between align-items-center flex-shrink-0" style="font-size: 0.78rem; color: var(--text-muted); background: linear-gradient(90deg, #E1ECF8 0%, #FAF0E6 100%) !important; border-color: #B8CCE0 !important;">
            <span>&copy; 2026 <strong>PT Tri Arta Konstruksi</strong> — SCM &amp; CRM Management</span>
            <span>Internal Portal</span>
        </footer>
    </div><!-- /.content-area -->
</div><!-- /.app-shell -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="https://unpkg.com/html5-qrcode"></script>
<script>
(function () {
    const STORAGE_KEY = 'ta_nav_sidebar_open_v2';
    const root        = document.documentElement;
    const toggleBtn   = document.getElementById('sidebarToggleBtn');
    const backdrop    = document.getElementById('navSidebarBackdrop');

    // Aktifkan animasi kembali setelah render awal
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
        // Trigger resize agar Chart.js menyesuaikan lebar area konten secara halus
        setTimeout(function () {
            window.dispatchEvent(new Event('resize'));
        }, 270);
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            const nextState = !root.classList.contains('nav-sidebar-open');
            setSidebarOpen(nextState, true);
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            setSidebarOpen(false, false);
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && root.classList.contains('nav-sidebar-open') && window.innerWidth < 992) {
            setSidebarOpen(false, false);
        }
    });

    // Simpan & pulihkan status buka/tutup tiap grup accordion sidebar di localStorage
    const ACC_KEY = 'ta_nav_accordion_state_v1';
    let accState = {};
    try {
        accState = JSON.parse(localStorage.getItem(ACC_KEY) || '{}') || {};
    } catch (e) {
        accState = {};
    }

    document.querySelectorAll('.nav-acc-list[data-acc-key]').forEach(function (panel) {
        const key = panel.getAttribute('data-acc-key');
        const btn = document.querySelector('.nav-acc-btn[data-acc-key="' + key + '"]');
        if (!key || !btn) return;

        if (accState[key] === false) {
            panel.classList.remove('show');
            btn.classList.add('collapsed');
            btn.setAttribute('aria-expanded', 'false');
        } else {
            panel.classList.add('show');
            btn.classList.remove('collapsed');
            btn.setAttribute('aria-expanded', 'true');
        }

        panel.addEventListener('shown.bs.collapse', function () {
            btn.setAttribute('aria-expanded', 'true');
            accState[key] = true;
            try { localStorage.setItem(ACC_KEY, JSON.stringify(accState)); } catch (e) {}
        });
        panel.addEventListener('hidden.bs.collapse', function () {
            btn.setAttribute('aria-expanded', 'false');
            accState[key] = false;
            try { localStorage.setItem(ACC_KEY, JSON.stringify(accState)); } catch (e) {}
        });
    });

    syncAria();
})();
</script>
</body>
</html>
