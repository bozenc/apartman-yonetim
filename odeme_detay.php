<?php
/**
 * Tahsilat / makbuz detayı (panel içi)
 *   odeme_detay.php?id=12[,13…]   veya   odeme_detay.php?bildirim=5
 */
require __DIR__ . '/includes/init.php';
$u = giris_zorunlu();

[$satirlar, $no] = ($bid = (int)get('bildirim')) ? makbuz_satirlari($bid) : makbuz_satirlari(null, explode(',', (string)get('id')));
if (!makbuz_erisim($satirlar, $u)) {
    http_response_code(404);
    $sayfaBaslik = 'Makbuz bulunamadı';
    require __DIR__ . '/includes/header.php';
    echo '<div class="card"><div class="empty-state"><i class="bi bi-receipt"></i><h5 class="mt-3">Makbuz bulunamadı veya görüntüleme yetkiniz yok</h5>'
       . '<a href="' . url('index.php') . '" class="btn btn-primary mt-2">Ana sayfa</a></div></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

/* Makbuzu e-posta / SMS / WhatsApp ile yeniden gönder */
if (is_post() && in_array(post('islem'), ['eposta', 'mesaj'], true)) {
    csrf_check();
    $GLOBALS['eposta_zorla'] = $GLOBALS['mesaj_zorla'] = true;
    $ids = array_map('intval', array_column($satirlar, 'id'));
    if (post('islem') === 'eposta') {
        yetki_zorunlu('eposta.yonet');
        $n = eposta_makbuz($ids);
        flash($n ? 'success' : 'warning', $n ? "Makbuz e-postası $n alıcıya gönderilmek üzere sıraya alındı." : 'Gönderilemedi: e-posta kapalı veya dairede kayıtlı e-posta adresi yok.');
    } else {
        yetki_zorunlu('mesaj.yonet');
        $n = mesaj_makbuz($ids);
        flash($n ? 'success' : 'warning', $n ? "Makbuz $n SMS/WhatsApp mesajı olarak sıraya alındı." : 'Gönderilemedi: SMS/WhatsApp kapalı veya dairede geçerli cep telefonu yok.');
    }
    redirect('odeme_detay.php?id=' . implode(',', array_column($satirlar, 'id')));
}

$ilk = $satirlar[0];
$toplam = array_sum(array_column($satirlar, 'tutar'));
$sahibi = (int)$ilk['sahip'] === (int)$u['id'];
$st = $pdo->prepare('SELECT COALESCE(SUM(tutar - odenen), 0) FROM borclar WHERE daire_id = ? AND tutar > odenen');
$st->execute([$ilk['daire_id']]);
$kalan = (float)$st->fetchColumn();
$idler = implode(',', array_column($satirlar, 'id'));
$pdfUrl = $ilk['bildirim_id'] && count(array_unique(array_column($satirlar, 'bildirim_id'))) === 1
    ? url('makbuz_pdf.php?bildirim=' . $ilk['bildirim_id']) : url('makbuz_pdf.php?id=' . $idler);
$alicilar = daire_alicilari((int)$ilk['daire_id']);

$sayfaBaslik = 'Makbuz ' . $no;
$sayfaAciklama = $ilk['blok'] . ' Blok, No: ' . $ilk['daire_no'] . ' · ' . tarih($ilk['tarih']);
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
                    <div><div class="text-muted small text-uppercase fw-bold">Tahsilat Makbuzu</div><h3 class="fw-bold mb-0"><?= e($no) ?></h3></div>
                    <span class="badge-soft success fs-6"><i class="bi bi-check2-circle"></i> Ödendi</span>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-md-3"><div class="text-muted small">Daire</div><div class="fw-semibold"><?= e($ilk['blok'] . '-' . $ilk['daire_no']) ?></div></div>
                    <div class="col-sm-6 col-md-3"><div class="text-muted small">Ödeyen</div><div class="fw-semibold"><?= e($sahibi ? $ilk['odeyen'] : kisi($ilk['odeyen'])) ?></div></div>
                    <div class="col-sm-6 col-md-3"><div class="text-muted small">Tarih</div><div class="fw-semibold"><?= tarih($ilk['tarih']) ?></div></div>
                    <div class="col-sm-6 col-md-3"><div class="text-muted small">Yöntem</div><div class="fw-semibold"><?= e(odeme_yontemleri()[$ilk['yontem']] ?? '') ?></div></div>
                </div>
                <div class="table-responsive"><table class="table">
                    <thead><tr><th>Makbuz</th><th>Ödenen Kalem</th><th>Son Ödeme</th><th class="text-end">Tutar</th></tr></thead>
                    <tbody><?php foreach ($satirlar as $r): ?>
                        <tr><td><code><?= e($r['makbuz_no']) ?></code></td><td><?= e($r['borc_aciklama']) ?></td><td><?= tarih($r['son_odeme']) ?></td><td class="text-end fw-semibold"><?= para($r['tutar']) ?></td></tr>
                    <?php endforeach; ?></tbody>
                    <tfoot><tr><th colspan="3">Toplam</th><th class="text-end fs-5 text-success"><?= para($toplam) ?></th></tr></tfoot>
                </table></div>
                <div class="small text-muted mt-2">Yalnız: <strong><?= e(yaziyla($toplam)) ?></strong></div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body d-grid gap-2">
            <a href="<?= $pdfUrl ?>" target="_blank" class="btn btn-danger"><i class="bi bi-file-earmark-pdf me-1"></i>PDF Makbuz</a>
            <?php if (count($satirlar) === 1): ?><a href="<?= url('makbuz.php?id=' . $ilk['id']) ?>" target="_blank" class="btn btn-light"><i class="bi bi-printer me-1"></i>Yazdır</a><?php endif; ?>
            <?php if ($ilk['dekont']): ?><a href="<?= dekont_url('o', (int)$ilk['id']) ?>" target="_blank" class="btn btn-light"><i class="bi bi-paperclip me-1"></i>Dekontu Görüntüle</a><?php endif; ?>
            <?php if (yetki('eposta.yonet')): ?>
            <form method="post" class="d-grid"><?= csrf_field() ?><input type="hidden" name="islem" value="eposta">
                <button class="btn btn-soft-primary" <?= eposta_aktif() && $alicilar ? '' : 'disabled' ?>><i class="bi bi-envelope me-1"></i>Makbuzu E-postayla Gönder</button></form>
            <div class="small text-muted"><?php if (!eposta_aktif()): ?>E-posta gönderimi kapalı.<?php elseif (!$alicilar): ?>Bu dairede kayıtlı e-posta yok.<?php else: ?>Alıcı: <?= e(implode(', ', array_keys($alicilar))) ?><?php endif; ?></div>
            <?php endif; ?>
            <?php if (yetki('mesaj.yonet')): $tel = daire_telefonlari((int)$ilk['daire_id'], 'sms'); ?>
            <form method="post" class="d-grid"><?= csrf_field() ?><input type="hidden" name="islem" value="mesaj">
                <button class="btn btn-soft-success" <?= (sms_aktif() || wa_aktif()) && $tel ? '' : 'disabled' ?>><i class="bi bi-chat-dots me-1"></i>SMS / WhatsApp ile Gönder</button></form>
            <?php $waMetin = ayar('site_adi') . ': ' . $ilk['blok'] . '-' . $ilk['daire_no'] . ' için ' . tarih($ilk['tarih']) . ' tarihli ' . para($toplam) . ' ödemeniz alınmıştır. Makbuz: ' . imzali_link('odeme', $idler, (int)$ilk['daire_id'], 180); ?>
            <a href="<?= e(wa_me(array_key_first($tel) ? (string)array_key_first($tel) : null, $waMetin)) ?>" target="_blank" rel="noopener" class="btn btn-light"><i class="bi bi-whatsapp text-success me-1"></i>WhatsApp'ta aç (elle)</a>
            <div class="small text-muted">"WhatsApp'ta aç" telefonunuzdaki/bilgisayarınızdaki WhatsApp'ı makbuz bağlantısı yazılı olarak açar; API gerekmez.</div>
            <?php endif; ?>
        </div></div>
        <div class="card"><div class="card-body">
            <div class="d-flex justify-content-between"><span class="text-muted">Dairenin kalan borcu</span><strong class="<?= $kalan > 0 ? 'text-danger' : 'text-success' ?>"><?= para($kalan) ?></strong></div>
            <?php if ($ilk['bildirim_id']): ?><div class="small text-muted mt-2"><i class="bi bi-receipt-cutoff"></i> Sakinin ödeme bildirimi (#<?= (int)$ilk['bildirim_id'] ?>) onaylanarak oluşturuldu.</div><?php endif; ?>
            <div class="small text-muted mt-2">Tahsil eden: <?= e($ilk['alan'] ?: '-') ?></div>
            <div class="small text-muted">Doğrulama kodu: <strong><?= e(dogrulama_kodu($no, $toplam, $ilk['tarih'])) ?></strong></div>
        </div></div>
        <a href="<?= url(yetki('borc.kendi') && $sahibi ? 'borclarim.php' : 'odemeler.php') ?>" class="btn btn-link mt-2"><i class="bi bi-arrow-left"></i> Geri</a>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
