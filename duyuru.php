<?php
/** Tek duyurunun detay sayfası (e-postadaki bağlantılar buraya yönlenir) */
require __DIR__ . '/includes/init.php';
yetki_zorunlu('panel.goruntule');

$st = $pdo->prepare('SELECT d.*, k.ad_soyad FROM duyurular d LEFT JOIN kullanicilar k ON k.id = d.kullanici_id WHERE d.id = ?');
$st->execute([(int)get('id')]);
$du = $st->fetch();

if ($du && is_post() && post('islem') === 'eposta') {
    csrf_check();
    if (!yetki_herhangi(['eposta.yonet', 'mesaj.yonet'])) yetki_zorunlu('eposta.yonet');
    $r = ['eposta' => yetki('eposta.yonet') ? eposta_duyuru((int)$du['id']) : 0, 'mesaj' => yetki('mesaj.yonet') ? mesaj_duyuru((int)$du['id'], true) : 0];
    logla('Duyuru bildirimi', $du['baslik'] . " · {$r['eposta']} e-posta, {$r['mesaj']} mesaj");
    flash($r['eposta'] + $r['mesaj'] ? 'success' : 'warning', $r['eposta'] + $r['mesaj'] ? trim(bildirim_ozeti($r)) : 'Gönderilemedi: bildirim kanalları kapalı veya kayıtlı iletişim bilgisi yok.');
    redirect('duyuru.php?id=' . $du['id']);
}

$digerleri = $pdo->query('SELECT id, baslik, onemli, created_at FROM duyurular ORDER BY created_at DESC LIMIT 8')->fetchAll();
$sayfaBaslik = $du ? 'Duyuru' : 'Duyuru bulunamadı';
$sayfaAciklama = $du ? tarih($du['created_at'], true) : '';
require __DIR__ . '/includes/header.php';
?>
<?php if (!$du): ?>
    <div class="card"><div class="empty-state"><i class="bi bi-megaphone"></i><h5 class="mt-3">Duyuru bulunamadı veya kaldırılmış</h5><a href="<?= url('duyurular.php') ?>" class="btn btn-primary mt-2">Tüm duyurular</a></div></div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card"><div class="card-body p-4">
            <?php if ($du['onemli']): ?><span class="badge-soft danger mb-2"><i class="bi bi-exclamation-circle"></i> Önemli</span><?php endif; ?>
            <h3 class="fw-bold mt-1"><?= e($du['baslik']) ?></h3>
            <div class="text-muted small mb-4"><i class="bi bi-person"></i> <?= e($du['ad_soyad'] ?? 'Yönetim') ?> · <i class="bi bi-clock"></i> <?= tarih($du['created_at'], true) ?></div>
            <div style="font-size:1rem;line-height:1.75"><?= nl2br(e($du['icerik'])) ?></div>
            <?php if (yetki_herhangi(['eposta.yonet', 'mesaj.yonet'])): ?>
            <hr class="my-4">
            <div class="d-flex flex-wrap gap-2 align-items-center">
            <form method="post" data-confirm="Bu duyuru, açık olan kanallardan (e-posta / SMS / WhatsApp) bütün sakinlere gönderilecek."><?= csrf_field() ?><input type="hidden" name="islem" value="eposta">
                <button class="btn btn-soft-primary" <?= herhangi_bildirim_aktif() ? '' : 'disabled' ?>><i class="bi bi-bell me-1"></i>Sakinlere bildir (<?= e(implode(' / ', array_keys(array_filter(['e-posta' => eposta_aktif(), 'SMS' => sms_aktif(), 'WhatsApp' => wa_aktif()]))) ?: 'kanal kapalı') ?>)</button></form>
            <a class="btn btn-light" target="_blank" rel="noopener" href="<?= e(wa_me(null, ayar('site_adi') . ' duyuru: ' . $du['baslik'] . "\n\n" . mb_strimwidth($du['icerik'], 0, 600, '…') . "\n\nDetay: " . imzali_link('duyuru', (string)$du['id'], 0, 120))) ?>"><i class="bi bi-whatsapp text-success me-1"></i>WhatsApp grubunda paylaş</a>
            </div>
            <?php endif; ?>
        </div></div>
    </div>
    <div class="col-lg-4">
        <div class="card"><div class="card-header"><h5>Son Duyurular</h5><a href="<?= url('duyurular.php') ?>" class="small">Tümü</a></div>
            <div class="list-group list-group-flush">
                <?php foreach ($digerleri as $d): ?>
                    <a href="<?= url('duyuru.php?id=' . $d['id']) ?>" class="list-group-item list-group-item-action <?= $d['id'] == $du['id'] ? 'active' : '' ?>" style="background:transparent;border-color:var(--border);<?= $d['id'] == $du['id'] ? 'color:var(--brand);font-weight:700' : 'color:var(--text)' ?>">
                        <?php if ($d['onemli']): ?><i class="bi bi-exclamation-circle text-danger me-1"></i><?php endif; ?><?= e($d['baslik']) ?>
                        <div class="small text-muted"><?= tarih($d['created_at']) ?></div></a>
                <?php endforeach; ?>
            </div></div>
    </div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
