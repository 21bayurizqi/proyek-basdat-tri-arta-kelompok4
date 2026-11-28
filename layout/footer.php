        <footer class="mt-auto py-3 px-4 border-top d-flex justify-content-between align-items-center flex-shrink-0" style="font-size: 0.78rem; color: var(--text-muted); background: linear-gradient(90deg, #E1ECF8 0%, #FAF0E6 100%) !important; border-color: #B8CCE0 !important;">
            <span>&copy; 2026 <strong>PT Tri Arta Konstruksi</strong> — SCM &amp; CRM Management</span>
            <span>Internal Portal</span>
        </footer>
    </div><!-- /.content-area -->
</div><!-- /.app-shell -->

<!-- Modal Tambah Material Category Dinamis (Global dari Sidebar) -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content rounded-4 overflow-hidden">
            <div class="px-4 pt-4 pb-2 d-flex align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="prod-thumb" style="width: 38px; height: 38px; font-size: 1rem; color: var(--ta-blue);">
                        <i class="bi bi-tags-fill"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold" id="addCategoryModalLabel" style="color: var(--ta-navy); font-size: 1.05rem;">Tambah Material Category</h5>
                        <div class="col-micro-lbl mb-0">Kategori baru langsung tersedia di sidebar &amp; form material</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <form method="POST" action="/barang/kategori_action.php">
                <input type="hidden" name="aksi" value="tambah">
                <input type="hidden" name="return_to" value="<?= htmlspecialchars($currentPath ?? '/barang/index.php') ?>">
                <div class="modal-body px-4 pt-3 pb-3">
                    <div>
                        <label for="inputNamaKategoriBaru" class="form-label fw-semibold" style="font-size: 14px; color: var(--ta-navy);">
                            Nama Kategori <span style="color: var(--ta-orange);">*</span>
                        </label>
                        <input type="text" id="inputNamaKategoriBaru" name="nama_kategori" class="form-control" maxlength="100" placeholder="Contoh: Pompa & Aksesoris" required>
                    </div>
                </div>
                <div class="px-4 pb-4 pt-2 d-flex justify-content-end gap-2">
                    <button type="button" class="btn-toolbar-white" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-toolbar-primary">
                        <i class="bi bi-check-lg"></i> Simpan Kategori
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="/assets/vendor/bootstrap.bundle.min.js"></script>
<script src="/assets/vendor/qrcode.min.js"></script>
<script src="/assets/vendor/html5-qrcode.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Auto-dismiss banner notifikasi (.alert) setelah 10 detik dengan animasi fade-out halus
    document.querySelectorAll('.alert:not(.d-none)').forEach(function (el) {
        let removeTimer = null;
        let dismissed   = false;

        function cancelTimers() {
            dismissed = true;
            if (autoTimer) {
                clearTimeout(autoTimer);
                autoTimer = null;
            }
            if (removeTimer) {
                clearTimeout(removeTimer);
                removeTimer = null;
            }
        }

        let autoTimer = setTimeout(function () {
            if (dismissed || !document.body.contains(el)) return;
            el.style.transition = 'opacity 0.45s ease, transform 0.45s ease';
            requestAnimationFrame(function () {
                if (dismissed || !document.body.contains(el)) return;
                el.style.opacity   = '0';
                el.style.transform = 'translateY(-6px)';
            });
            removeTimer = setTimeout(function () {
                if (dismissed || !document.body.contains(el)) return;
                try {
                    if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                        bootstrap.Alert.getOrCreateInstance(el).close();
                    } else {
                        el.remove();
                    }
                } catch (err) {
                    if (document.body.contains(el)) el.remove();
                }
            }, 450);
        }, 10000);

        el.addEventListener('close.bs.alert', cancelTimers);
        el.addEventListener('closed.bs.alert', cancelTimers);
        const closeBtn = el.querySelector('.btn-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', cancelTimers);
        }
    });
});
</script>
</body>
</html>
