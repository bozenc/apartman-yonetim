<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('panel.goruntule');

if (is_post()) {
    csrf_check();
    $islem = post('islem');
    if ($islem === 'kaydet') {
        $id = (int)post('id');
        yetki_zorunlu($id ? 'duyuru.duzenle' : 'duyuru.ekle');
        $baslik = post('baslik');
        $icerik = post('icerik');
        $onemli = post('onemli') ? 1 : 0;
        if ($baslik === '' || $icerik === '') { flash('danger', 'Başlık ve içerik zorunludur.'); redirect('duyurular.php'); }
        if ($id) {
            $pdo->prepare('UPDATE duyurular SET baslik=?, icerik=?, onemli=? WHERE id=?')->execute([$baslik, $icerik, $onemli, $id]);
            flash('success', 'Duyuru güncellendi.');
        } else {
            $pdo->prepare('INSERT INTO duyurular (baslik, icerik, onemli, kullanici_id) VALUES (?,?,?,?)')->execute([$baslik, $icerik, $onemli, $_SESSION['uid']]);
            $ep = post('eposta') ? bildir_duyuru((int)$pdo->lastInsertId()) : ['eposta' => 0, 'mesaj' => 0];
            flash('success', 'Duyuru yayınlandı.' . bildirim_ozeti($ep));
        }
        logla('Duyuru kaydedildi', $baslik);
    }
    if ($islem === 'sil') {
        yetki_zorunlu('duyuru.sil');
        $pdo->prepare('DELETE FROM duyurular WHERE id = ?')->execute([(int)post('id')]);
        logla('Duyuru silindi', 'ID: ' . post('id'));
        flash('success', 'Duyuru silindi.');
    }
    redirect('duyurular.php');
}

$duyurular = $pdo->query('SELECT d.*, k.ad_soyad FROM duyurular d LEFT JOIN kullanicilar k ON k.id = d.kullanici_id ORDER BY d.onemli DESC, d.created_at DESC')->fetchAll();

$sayfaBaslik = 'Duyurular';
$sayfaAciklama = 'Site sakinlerine yönelik duyurular';
require __DIR__ . '/includes/header.php';
?>
<?php if (yetki('duyuru.ekle')): ?>
<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#duyuruModal" data-new data-title="Yeni Duyuru"><i class="bi bi-plus-lg me-1"></i>Yeni Duyuru</button>
</div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($duyurular as $d): ?>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <?php if ($d['onemli']): ?><span class="badge-soft danger mb-2"><i class="bi bi-exclamation-circle"></i> Önemli</span><?php endif; ?>
                        <h5 class="fw-bold mb-1"><a href="<?= url('duyuru.php?id=' . $d['id']) ?>" class="text-reset"><?= e($d['baslik']) ?></a></h5>
                        <div class="small text-muted"><i class="bi bi-person"></i> <?= e($d['ad_soyad'] ?? 'Yönetim') ?> · <i class="bi bi-clock"></i> <?= tarih($d['created_at'], true) ?></div>
                    </div>
                    <?php if (yetki_herhangi(['duyuru.duzenle', 'duyuru.sil'])): ?>
                    <div class="text-nowrap">
                        <?php if (yetki('duyuru.duzenle')): ?><button class="btn btn-sm btn-soft-primary" data-bs-toggle="modal" data-bs-target="#duyuruModal" data-title="Duyuruyu Düzenle"
                            data-edit='<?= e(json_encode(['id' => $d['id'], 'baslik' => $d['baslik'], 'icerik' => $d['icerik'], 'onemli' => $d['onemli']], JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-pencil"></i></button><?php endif; if (yetki('duyuru.sil')): ?>
                        <form method="post" class="d-inline" data-confirm="Duyuru silinecek."><?= csrf_field() ?><input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button class="btn btn-sm btn-soft-danger"><i class="bi bi-trash"></i></button></form><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <p class="mt-3 mb-0"><?= nl2br(e($d['icerik'])) ?></p>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$duyurular): ?><div class="col-12"><div class="card"><div class="empty-state"><i class="bi bi-megaphone"></i><p class="mt-2 mb-0">Henüz duyuru yok.</p></div></div></div><?php endif; ?>
</div>

<?php if (yetki_herhangi(['duyuru.ekle', 'duyuru.duzenle'])): ?>
<div class="modal fade" id="duyuruModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="kaydet"><input type="hidden" name="id">
                <div class="modal-header"><h5 class="modal-title">Yeni Duyuru</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Başlık</label><input name="baslik" class="form-control" required maxlength="150"></div>
                    <div class="mb-3"><label class="form-label">İçerik</label><textarea name="icerik" class="form-control" rows="6" required></textarea></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="onemli" value="1" id="onemli"><label class="form-check-label" for="onemli">Önemli olarak işaretle (en üstte gösterilir)</label></div>
                    <?php if (herhangi_bildirim_aktif()): ?><div class="form-check form-switch mt-2" id="epKutu"><input class="form-check-input" type="checkbox" name="eposta" value="1" id="epDuyuru" <?= ayar('mail_duyuru') === '1' || ayar('sms_duyuru') === '1' || ayar('wa_duyuru') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="epDuyuru"><i class="bi bi-bell"></i> Yayınlarken tüm sakinlere bildir <span class="text-muted small">(<?= e(implode(' / ', array_keys(array_filter(['e-posta' => eposta_aktif(), 'SMS' => sms_aktif(), 'WhatsApp' => wa_aktif()])))) ?> — ayarlarda duyuru için açık olanlar)</span></label>
                        <div class="form-text">Düzenlemede e-posta gönderilmez; gerekirse duyuru sayfasından elle gönderebilirsiniz.</div></div><?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-send me-1"></i>Yayınla</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
