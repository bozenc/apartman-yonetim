<?php if (kullanici()): ?>
        </div><!-- /.content -->
        <footer class="app-footer">
            <span>© <?= date('Y') ?> <?= e(ayar('site_adi')) ?></span>
            <span class="yasal-alt"><?= yasal_linkler() ?></span>
            <span>Apartman Yönetim Sistemi v1.5</span>
        </footer>
    </main>
</div>

<!-- Ortak silme onay modalı -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center p-4">
                <div class="confirm-icon"><i class="bi bi-exclamation-triangle"></i></div>
                <h5 class="mt-3 mb-1">Emin misiniz?</h5>
                <p class="text-muted small mb-0" id="confirmText">Bu işlem geri alınamaz.</p>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-center">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Vazgeç</button>
                <button type="button" class="btn btn-danger" id="confirmOk">Evet, sil</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?= cerez_bandi() ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($grafikKullan)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.js"></script>
<?php endif; ?>
<script src="<?= url('assets/js/app.js') ?>"></script>
<?php if (!empty($sayfaJs)) echo $sayfaJs; ?>
</body>
</html>
