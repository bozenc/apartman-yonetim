<?php
require __DIR__ . '/includes/init.php';
$u = giris_zorunlu();

$st = $pdo->prepare('SELECT o.*, b.aciklama AS borc_aciklama, b.tutar AS borc_tutar, b.odenen AS borc_odenen,
        d.blok, d.daire_no, d.kullanici_id AS daire_kullanici, COALESCE(d.sakin_adi, d.malik_adi) AS ad, k.ad_soyad AS alan
    FROM odemeler o JOIN borclar b ON b.id = o.borc_id JOIN daireler d ON d.id = o.daire_id
    LEFT JOIN kullanicilar k ON k.id = o.kullanici_id WHERE o.id = ?');
$st->execute([(int)get('id')]);
$o = $st->fetch();

$izin = $o && (yetki_herhangi(['odeme.goruntule', 'aidat.goruntule']) || (yetki('borc.kendi') && (int)$o['daire_kullanici'] === (int)$u['id']));
if (!$izin) {
    http_response_code(404);
    die('Makbuz bulunamadı.');
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Makbuz <?= e($o['makbuz_no']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
</head>
<body style="background:#f4f6fb">
<div class="text-center mt-4 no-print">
    <a class="btn btn-danger" href="<?= url('makbuz_pdf.php?id=' . (int)$o['id']) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>PDF İndir</a>
    <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Yazdır</button>
    <?php if (!empty($o['dekont'])): ?><a class="btn btn-light" target="_blank" href="<?= dekont_url('o', (int)$o['id']) ?>"><i class="bi bi-paperclip me-1"></i>Dekont</a><?php endif; ?>
    <button class="btn btn-light" onclick="history.length > 1 ? history.back() : window.close()">Kapat</button>
</div>
<div class="receipt">
    <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1"><?= e(ayar('site_adi')) ?></h4>
            <div class="small text-secondary"><?= e(ayar('adres')) ?><br><?= e(ayar('telefon')) ?> · <?= e(ayar('eposta')) ?></div>
        </div>
        <div class="text-end">
            <div class="text-uppercase small fw-bold text-secondary">Tahsilat Makbuzu</div>
            <div class="fs-5 fw-bold"><?= e($o['makbuz_no']) ?></div>
            <div class="small"><?= tarih($o['tarih']) ?></div>
        </div>
    </div>

    <table class="table table-borderless mb-4" style="--bs-table-bg:transparent">
        <tr><td class="text-secondary" style="width:180px">Daire</td><td class="fw-semibold"><?= e($o['blok']) ?> Blok, No: <?= e($o['daire_no']) ?></td></tr>
        <tr><td class="text-secondary">Ödeyen</td><td class="fw-semibold"><?= e(kisi($o['ad']) ?: '-') ?></td></tr>
        <tr><td class="text-secondary">Açıklama</td><td><?= e($o['borc_aciklama']) ?><?= $o['aciklama'] ? ' — ' . e($o['aciklama']) : '' ?></td></tr>
        <tr><td class="text-secondary">Ödeme Yöntemi</td><td><?= e(odeme_yontemleri()[$o['yontem']] ?? $o['yontem']) ?></td></tr>
        <tr><td class="text-secondary">Borç Kalan</td><td><?= para(max(0, $o['borc_tutar'] - $o['borc_odenen'])) ?></td></tr>
    </table>

    <div class="p-3 rounded-3 d-flex justify-content-between align-items-center" style="background:#eef2ff">
        <div><div class="small text-secondary">Yalnız</div><div class="fw-semibold"><?= e(yaziyla((float)$o['tutar'])) ?></div></div>
        <div class="fs-3 fw-bold" style="color:#4f46e5"><?= para($o['tutar']) ?></div>
    </div>

    <div class="d-flex justify-content-between mt-5 pt-4 small">
        <div class="text-center" style="width:200px"><div class="border-top pt-2">Teslim Eden</div></div>
        <div class="text-center" style="width:200px"><div class="border-top pt-2">Teslim Alan<br><strong><?= e($o['alan'] ?? '') ?></strong></div></div>
    </div>
    <div class="text-center small text-secondary mt-4 pt-3 border-top">Doğrulama kodu: <strong><?= e(dogrulama_kodu((string)$o['makbuz_no'], (float)$o['tutar'], $o['tarih'])) ?></strong></div>
</div>
</body>
</html>
