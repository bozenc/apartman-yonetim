<?php
/**
 * E-postadaki bağlantıların açıldığı sayfa.
 *  - Giriş yapmış ve yetkili kullanıcı → doğrudan panel içindeki detay sayfasına yönlendirilir.
 *  - Diğer durumda imzalı bağlantı, yalnızca ilgili kaydın detayını (salt okunur) gösterir.
 */
require __DIR__ . '/includes/init.php';
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$t = (string)get('t', '');
// SMS'teki kısa bağlantı: goster.php?k=Ab3dE9xY
if ($k = (string)get('k', '')) {
    $st = $pdo->prepare('SELECT token FROM kisa_linkler WHERE kod = ?');
    $st->execute([preg_replace('/[^A-Za-z0-9]/', '', $k)]);
    if ($t = (string)$st->fetchColumn()) $pdo->prepare('UPDATE kisa_linkler SET tiklama = tiklama + 1 WHERE kod = ?')->execute([$k]);
}
$tk = link_dogrula($t);
$u = kullanici();
$hata = null;
$veri = null;

if (!$tk) {
    $hata = 'Bu bağlantı geçersiz veya süresi dolmuş.';
} elseif ($tk['tur'] === 'odeme') {
    [$satirlar, $no] = makbuz_satirlari(null, $tk['ids']);
    $satirlar = array_values(array_filter($satirlar, fn($s) => (int)$s['daire_id'] === $tk['daire']));
    if (!$satirlar) $hata = 'Makbuz bulunamadı. Tahsilat iptal edilmiş olabilir.';
    else {
        $hedef = 'odeme_detay.php?id=' . implode(',', array_column($satirlar, 'id'));
        if ($u && makbuz_erisim($satirlar, $u)) redirect($hedef);
        $st = $pdo->prepare('SELECT COALESCE(SUM(tutar - odenen), 0) FROM borclar WHERE daire_id = ? AND tutar > odenen');
        $st->execute([$tk['daire']]);
        $veri = ['satirlar' => $satirlar, 'no' => $no, 'kalan' => (float)$st->fetchColumn(), 'hedef' => $hedef];
    }
} elseif ($tk['tur'] === 'duyuru') {
    $st = $pdo->prepare('SELECT d.*, k.ad_soyad FROM duyurular d LEFT JOIN kullanicilar k ON k.id = d.kullanici_id WHERE d.id = ?');
    $st->execute([$tk['ids'][0] ?? 0]);
    $du = $st->fetch();
    if (!$du) $hata = 'Duyuru bulunamadı veya kaldırılmış.';
    else {
        $hedef = 'duyuru.php?id=' . $du['id'];
        if ($u && yetki('panel.goruntule')) redirect($hedef);
        $veri = ['duyuru' => $du, 'hedef' => $hedef];
    }
} elseif ($tk['tur'] === 'bildirim') {
    $st = $pdo->prepare('SELECT ob.*, d.blok, d.daire_no FROM odeme_bildirimleri ob JOIN daireler d ON d.id = ob.daire_id WHERE ob.id = ? AND ob.daire_id = ?');
    $st->execute([$tk['ids'][0] ?? 0, $tk['daire']]);
    $bl = $st->fetch();
    if (!$bl) $hata = 'Bildirim bulunamadı.';
    else {
        $hedef = 'borclarim.php';
        if ($u && yetki('borc.kendi')) redirect($hedef);
        $acik = acik_borclar($pdo, (int)$bl['daire_id']);
        $veri = ['bildirim' => $bl, 'acik' => $acik, 'hedef' => $hedef];
    }
} elseif ($tk['tur'] === 'borc') {
    $st = $pdo->prepare('SELECT * FROM daireler WHERE id = ?');
    $st->execute([$tk['daire']]);
    $dr = $st->fetch();
    if (!$dr) $hata = 'Daire kaydı bulunamadı.';
    else {
        $hedef = 'borclarim.php';
        if ($u && yetki('borc.kendi')) redirect($hedef);
        $acik = acik_borclar($pdo, (int)$dr['id']);
        $veri = ['borc_daire' => $dr, 'acik' => $acik, 'hedef' => $hedef];
    }
} else {
    $hata = 'Bu bağlantı geçersiz.';
}

// Giriş sonrası ilgili sayfaya dönmek için
if (!empty($veri['hedef'])) $_SESSION['donus'] = url($veri['hedef']);
$siteAdi = ayar('site_adi', 'Apartman Yönetimi');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($siteAdi) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
    <style>
        .gst { max-width: 720px; margin: 0 auto; padding: 32px 16px 48px; }
        .gst-bas { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; }
        .gst-bas strong { font-size: 1.1rem; }
        .gst .card { border-radius: 18px; }
        .duyuru-metin { font-size: 1rem; line-height: 1.7; white-space: pre-line; }
    </style>
</head>
<body>
<div class="gst">
    <div class="gst-bas">
        <span class="brand-logo"><i class="bi bi-buildings-fill"></i></span>
        <div><strong><?= e($siteAdi) ?></strong><div class="text-muted small"><?= e(ayar('adres')) ?></div></div>
    </div>

<?php if ($hata): ?>
    <div class="card"><div class="card-body p-4 text-center">
        <div class="stat-icon soft-warning mx-auto" style="width:60px;height:60px;font-size:1.6rem"><i class="bi bi-link-45deg"></i></div>
        <h4 class="fw-bold mt-3"><?= e($hata) ?></h4>
        <p class="text-muted">Güvenliğiniz için e-postadaki bağlantılar belirli bir süre geçerlidir. Panele giriş yaparak tüm kayıtlarınızı görebilirsiniz.</p>
        <a href="<?= url('login.php') ?>" class="btn btn-primary px-4"><i class="bi bi-box-arrow-in-right me-1"></i>Giriş Yap</a>
    </div></div>

<?php elseif (isset($veri['satirlar'])): $s = $veri['satirlar']; $ilk = $s[0]; $toplam = array_sum(array_column($s, 'tutar')); ?>
    <div class="card">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div><div class="text-muted small text-uppercase fw-bold">Tahsilat Makbuzu</div><h3 class="fw-bold mb-0"><?= e($veri['no']) ?></h3></div>
                <span class="badge-soft success fs-6"><i class="bi bi-check2-circle"></i> Ödendi</span>
            </div>
            <div class="p-3 rounded-3 soft-success text-center mb-4"><div class="small">Ödenen Tutar</div><div class="fs-2 fw-bold"><?= para($toplam) ?></div>
                <div class="small"><?= e(yaziyla($toplam)) ?></div></div>
            <div class="row g-3 mb-3">
                <div class="col-sm-6"><div class="text-muted small">Daire</div><div class="fw-semibold"><?= e($ilk['blok'] . ' Blok, No: ' . $ilk['daire_no']) ?></div></div>
                <div class="col-sm-6"><div class="text-muted small">Ödeme Tarihi</div><div class="fw-semibold"><?= tarih($ilk['tarih']) ?></div></div>
                <div class="col-sm-6"><div class="text-muted small">Ödeme Yöntemi</div><div class="fw-semibold"><?= e(odeme_yontemleri()[$ilk['yontem']] ?? $ilk['yontem']) ?></div></div>
                <div class="col-sm-6"><div class="text-muted small">Tahsil Eden</div><div class="fw-semibold"><?= e($ilk['alan'] ?: 'Yönetim') ?></div></div>
            </div>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Ödenen Kalem</th><th class="text-end">Tutar</th></tr></thead>
                <tbody><?php foreach ($s as $r): ?><tr><td><?= e($r['borc_aciklama']) ?><div class="small text-muted"><?= e($r['makbuz_no']) ?></div></td><td class="text-end fw-semibold"><?= para($r['tutar']) ?></td></tr><?php endforeach; ?></tbody>
                <tfoot><tr><th>Toplam</th><th class="text-end"><?= para($toplam) ?></th></tr></tfoot>
            </table></div>
            <div class="d-flex justify-content-between align-items-center p-3 rounded-3 mb-4" style="background:var(--bg)">
                <span class="text-muted">Dairenizin güncel kalan borcu</span><strong class="<?= $veri['kalan'] > 0 ? 'text-danger' : 'text-success' ?>"><?= para($veri['kalan']) ?></strong>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= url('makbuz_pdf.php?t=' . urlencode($t)) ?>" target="_blank" class="btn btn-danger"><i class="bi bi-file-earmark-pdf me-1"></i>PDF Makbuzu İndir</a>
                <a href="<?= url('login.php') ?>" class="btn btn-light"><i class="bi bi-box-arrow-in-right me-1"></i>Panele Giriş Yap</a>
            </div>
        </div>
    </div>

<?php elseif (isset($veri['duyuru'])): $du = $veri['duyuru']; ?>
    <div class="card">
        <div class="card-body p-4">
            <?php if ($du['onemli']): ?><span class="badge-soft danger mb-2"><i class="bi bi-exclamation-circle"></i> Önemli Duyuru</span><?php else: ?><span class="badge-soft primary mb-2"><i class="bi bi-megaphone"></i> Duyuru</span><?php endif; ?>
            <h3 class="fw-bold mt-2"><?= e($du['baslik']) ?></h3>
            <div class="text-muted small mb-4"><i class="bi bi-person"></i> <?= e($du['ad_soyad'] ?? 'Yönetim') ?> · <i class="bi bi-clock"></i> <?= tarih($du['created_at'], true) ?></div>
            <div class="duyuru-metin"><?= e($du['icerik']) ?></div>
            <hr class="my-4">
            <a href="<?= url('login.php') ?>" class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1"></i>Tüm duyurular için giriş yapın</a>
        </div>
    </div>

<?php elseif (isset($veri['bildirim'])): $bl = $veri['bildirim']; ?>
    <div class="card">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div><div class="text-muted small text-uppercase fw-bold">Ödeme Bildirimi</div><h4 class="fw-bold mb-0"><?= e($bl['blok'] . ' Blok, No: ' . $bl['daire_no']) ?></h4></div>
                <?= ['bekliyor' => '<span class="badge-soft warning fs-6">Onay bekliyor</span>', 'onaylandi' => '<span class="badge-soft success fs-6">Onaylandı</span>', 'reddedildi' => '<span class="badge-soft danger fs-6">Onaylanmadı</span>'][$bl['durum']] ?>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-sm-6"><div class="text-muted small">Bildirilen Tutar</div><div class="fw-semibold"><?= para($bl['tutar']) ?></div></div>
                <div class="col-sm-6"><div class="text-muted small">Ödeme Tarihi</div><div class="fw-semibold"><?= tarih($bl['tarih']) ?></div></div>
            </div>
            <?php if ($bl['durum'] === 'reddedildi'): ?>
                <div class="alert alert-danger"><strong>Nedeni:</strong> <?= e($bl['red_neden']) ?></div>
            <?php endif; ?>
            <?php if ($veri['acik']): ?>
                <h6 class="fw-bold mt-4">Açık Borçlarınız</h6>
                <table class="table"><tbody>
                    <?php foreach ($veri['acik'] as $b): ?><tr><td><?= e($b['aciklama']) ?><div class="small text-muted">Son ödeme <?= tarih($b['son_odeme']) ?></div></td><td class="text-end fw-semibold"><?= para($b['tutar'] - $b['odenen']) ?></td></tr><?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <div class="p-3 rounded-3 soft-primary small mb-4"><i class="bi bi-bank me-1"></i>Ödeme için IBAN: <strong><?= e(ayar('iban')) ?></strong></div>
            <a href="<?= url('login.php') ?>" class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1"></i>Giriş yapıp dekontu yeniden yükleyin</a>
        </div>
    </div>
<?php elseif (isset($veri['borc_daire'])): $dr = $veri['borc_daire']; $topBorc = array_sum(array_map(fn($b) => $b['tutar'] - $b['odenen'], $veri['acik'])); ?>
    <div class="card">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div><div class="text-muted small text-uppercase fw-bold">Güncel Borç Durumu</div><h4 class="fw-bold mb-0"><?= e($dr['blok'] . ' Blok, No: ' . $dr['daire_no']) ?></h4></div>
                <span class="badge-soft <?= $topBorc > 0 ? 'warning' : 'success' ?> fs-6"><?= $topBorc > 0 ? para($topBorc) : 'Borç Yok' ?></span>
            </div>
            <?php if ($veri['acik']): ?>
                <table class="table">
                    <thead><tr><th>Açıklama</th><th>Son Ödeme</th><th class="text-end">Kalan Tutar</th></tr></thead>
                    <tbody>
                    <?php foreach ($veri['acik'] as $b): ?>
                        <tr>
                            <td><?= e($b['aciklama']) ?></td>
                            <td><?= tarih($b['son_odeme']) ?> <?php if ($b['son_odeme'] < date('Y-m-d')): ?><span class="badge-soft danger">Gecikmiş</span><?php endif; ?></td>
                            <td class="text-end fw-semibold"><?= para($b['tutar'] - $b['odenen']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th colspan="2">Toplam Kalan</th><th class="text-end fs-5 text-danger"><?= para($topBorc) ?></th></tr></tfoot>
                </table>
            <?php else: ?>
                <div class="alert alert-success">Dairenize ait ödenmemiş borç bulunmamaktadır. Teşekkür ederiz.</div>
            <?php endif; ?>
            <?php if (ayar('iban')): ?>
                <div class="p-3 rounded-3 soft-primary small mb-4"><i class="bi bi-bank me-1"></i>Ödeme için IBAN: <strong><?= e(ayar('iban')) ?></strong> (Açıklamaya <strong><?= e($dr['blok'] . '-' . $dr['daire_no']) ?></strong> yazınız)</div>
            <?php endif; ?>
            <a href="<?= url('login.php') ?>" class="btn btn-primary"><i class="bi bi-cloud-arrow-up me-1"></i>Panele giriş yapıp dekont yükleyin</a>
        </div>
    </div>
<?php endif; ?>
    <p class="text-center text-muted small mt-4 mb-1">© <?= date('Y') ?> <?= e($siteAdi) ?> · Apartman Yönetim Sistemi</p>
    <p class="text-center yasal-alt"><?= yasal_linkler() ?></p>
</div>
<?= cerez_bandi() ?>
<script src="<?= url('assets/js/app.js') ?>"></script>
</body>
</html>
