<?php
/** KVKK, gizlilik, çerez, sözleşme ve açık rıza metinlerinin yönetimi + onay kayıtları */
require __DIR__ . '/includes/init.php';
yetki_zorunlu('yasal.yonet');

$aktifMetin = in_array(get('metin'), YASAL_SIRA, true) ? get('metin') : 'kvkk';

if (is_post()) {
    csrf_check();
    $islem = post('islem');
    if ($islem === 'metin') {
        $a = in_array(post('anahtar'), YASAL_SIRA, true) ? post('anahtar') : null;
        $baslik = trim((string)post('baslik'));
        $icerik = trim(str_replace("\r\n", "\n", (string)($_POST['icerik'] ?? '')));
        if (!$a || $baslik === '' || $icerik === '') {
            flash('danger', 'Başlık ve metin boş olamaz.');
        } else {
            yasal_metin($a); // yoksa oluşturur
            $yeni = post('yeni_surum') ? 1 : 0;
            $pdo->prepare('UPDATE yasal_metinler SET baslik = ?, icerik = ?, surum = surum + ? WHERE anahtar = ?')->execute([mb_substr($baslik, 0, 150), $icerik, $yeni, $a]);
            logla('Yasal metin güncellendi', $baslik . ($yeni ? ' (yeni sürüm yayımlandı)' : ' (düzeltme)'));
            flash('success', $yeni
                ? "\"$baslik\" yeni sürüm olarak yayımlandı." . (in_array($a, YASAL_ZORUNLU, true) ? ' Kullanıcılardan bir sonraki girişlerinde yeniden onay istenecek.' : '')
                : "\"$baslik\" kaydedildi (sürüm değişmedi).");
        }
        redirect('yasal_yonet.php?metin=' . $a);
    }
    if ($islem === 'varsayilan') {
        $a = in_array(post('anahtar'), YASAL_SIRA, true) ? post('anahtar') : 'kvkk';
        $v = yasal_varsayilan()[$a];
        yasal_metin($a);
        $pdo->prepare('UPDATE yasal_metinler SET baslik = ?, icerik = ?, surum = surum + 1 WHERE anahtar = ?')->execute([$v[0], $v[1], $a]);
        logla('Yasal metin varsayılana döndürüldü', $v[0]);
        flash('success', "\"{$v[0]}\" varsayılan metne döndürüldü ve yeni sürüm olarak yayımlandı.");
        redirect('yasal_yonet.php?metin=' . $a);
    }
    if ($islem === 'ayar') {
        $st = $pdo->prepare('INSERT INTO ayarlar (anahtar, deger) VALUES (?, ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)');
        foreach (['yasal_onay_zorunlu', 'cerez_bildirimi'] as $k) $st->execute([$k, post($k) ? '1' : '0']);
        foreach (['kvkk_sorumlu', 'kvkk_eposta', 'kvkk_kep'] as $k) $st->execute([$k, trim((string)post($k))]);
        $st->execute(['log_saklama_gun', (string)max(30, min(3650, (int)post('log_saklama_gun', 730)))]);
        ayar_sifirla();
        logla('KVKK ayarları güncellendi');
        flash('success', 'KVKK ve gizlilik ayarları kaydedildi.');
        redirect('yasal_yonet.php?metin=' . $aktifMetin . '#ayarlar');
    }
}

$metinler = [];
foreach (YASAL_SIRA as $a) $metinler[$a] = yasal_metin($a);
$m = $metinler[$aktifMetin];

// Onay istatistikleri: aktif kullanıcıların güncel sürümü onaylama durumu
$aktifKullanici = (int)$pdo->query('SELECT COUNT(*) FROM kullanicilar WHERE aktif = 1')->fetchColumn();
$istatistik = [];
foreach (['sozlesme', 'kvkk', 'acik_riza'] as $a) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM kullanicilar k JOIN onaylar o ON o.id = (SELECT MAX(id) FROM onaylar WHERE kullanici_id = k.id AND metin = ?)
                         WHERE k.aktif = 1 AND o.durum = 'onay' AND o.surum >= ?");
    $st->execute([$a, $a === 'acik_riza' ? 1 : (int)$metinler[$a]['surum']]);
    $istatistik[$a] = (int)$st->fetchColumn();
}
$filtre = in_array(get('kayit'), YASAL_SIRA, true) ? get('kayit') : '';
$st = $pdo->prepare('SELECT o.*, k.ad_soyad, k.kullanici_adi FROM onaylar o JOIN kullanicilar k ON k.id = o.kullanici_id' . ($filtre ? ' WHERE o.metin = ?' : '') . ' ORDER BY o.id DESC LIMIT 200');
$st->execute($filtre ? [$filtre] : []);
$kayitlar = $st->fetchAll();

$sayfaBaslik = 'Yasal Metinler & KVKK';
$sayfaAciklama = 'Aydınlatma metni, gizlilik, çerez, kullanım koşulları, açık rıza ve onay kayıtları';
require __DIR__ . '/includes/header.php';
?>
<div class="alert alert-warning d-flex gap-2 align-items-start"><i class="bi bi-exclamation-triangle mt-1"></i>
    <div class="small">Hazır metinler, apartman/site yönetimleri için <strong>genel bir taslaktır</strong>. Yayına almadan önce yönetim planınıza, kullandığınız hizmet sağlayıcılara ve
        güncel mevzuata göre bir hukukçuya kontrol ettirmeniz önerilir. Metinlerdeki <code>{site_adi}</code>, <code>{adres}</code> gibi alanlar Ayarlar ve aşağıdaki KVKK bilgilerinden otomatik doldurulur.</div></div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon soft-primary"><i class="bi bi-people"></i></div>
        <div><div class="stat-label">Aktif Kullanıcı</div><div class="stat-value fs-5"><?= $aktifKullanici ?></div></div></div></div>
    <?php foreach (['sozlesme' => ['Kullanım Koşulları', 'bi-file-earmark-check', 'success'], 'kvkk' => ['KVKK Aydınlatma', 'bi-shield-check', 'info'], 'acik_riza' => ['Açık Rıza', 'bi-hand-thumbs-up', 'warning']] as $a => [$ad, $ik, $renk]): ?>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon soft-<?= $renk ?>"><i class="bi <?= $ik ?>"></i></div>
        <div><div class="stat-label"><?= $ad ?></div><div class="stat-value fs-5"><?= $istatistik[$a] ?> / <?= $aktifKullanici ?></div>
            <div class="stat-sub"><?= $a === 'acik_riza' ? 'rıza veren (isteğe bağlı)' : 'v' . (int)$metinler[$a]['surum'] . ' onaylı' ?></div></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card mb-3">
    <div class="card-header">
        <ul class="nav nav-pills">
            <?php foreach ($metinler as $a => $x): ?><li class="nav-item"><a class="nav-link <?= $a === $aktifMetin ? 'active' : '' ?>" href="?metin=<?= $a ?>"><?= e($x['baslik']) ?></a></li><?php endforeach; ?>
        </ul>
        <a href="<?= url('yasal.php?metin=' . $aktifMetin) ?>" target="_blank" class="btn btn-sm btn-light"><i class="bi bi-box-arrow-up-right me-1"></i>Yayındaki sayfa</a>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-xl-6">
                <form method="post" id="metinForm"><?= csrf_field() ?><input type="hidden" name="islem" value="metin"><input type="hidden" name="anahtar" value="<?= $aktifMetin ?>">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted">Sürüm <strong><?= (int)$m['surum'] ?></strong> · son değişiklik <?= tarih($m['updated_at'], true) ?>
                            <?= in_array($aktifMetin, YASAL_ZORUNLU, true) ? ' · <span class="badge-soft danger">girişte onay zorunlu</span>' : ($aktifMetin === 'acik_riza' ? ' · <span class="badge-soft warning">isteğe bağlı rıza</span>' : ' · <span class="badge-soft secondary">bilgilendirme</span>') ?></span>
                    </div>
                    <div class="mb-2"><label class="form-label">Başlık</label><input name="baslik" class="form-control" value="<?= e($m['baslik']) ?>" maxlength="150" required></div>
                    <label class="form-label">Metin</label>
                    <textarea name="icerik" id="icerik" class="form-control font-monospace" rows="22" style="font-size:.8rem" required><?= e($m['icerik']) ?></textarea>
                    <div class="form-text mb-3">Biçim: <code>## Başlık</code>, <code>- madde</code>, <code>**kalın**</code>, paragraflar arasında boş satır. Alanlar: <code>{site_adi}</code> <code>{adres}</code> <code>{eposta}</code> <code>{telefon}</code> <code>{vergi_no}</code> <code>{kvkk_sorumlu}</code> <code>{kvkk_eposta}</code> <code>{kvkk_kep}</code> <code>{log_saklama_gun}</code> <code>{tarih}</code></div>
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-light"><i class="bi bi-save me-1"></i>Kaydet (düzeltme)</button>
                        <button name="yeni_surum" value="1" class="btn btn-primary"><i class="bi bi-megaphone me-1"></i>Yeni sürüm olarak yayımla</button>
                    </div>
                    <div class="form-text"><?= in_array($aktifMetin, YASAL_ZORUNLU, true) ? 'Yeni sürüm yayımlandığında tüm kullanıcılardan bir sonraki girişte yeniden onay istenir. Yazım düzeltmeleri için "Kaydet" yeterlidir.' : 'Yeni sürüm, metindeki sürüm ve yürürlük tarihini günceller.' ?></div>
                </form>
                <form method="post" class="mt-3" data-confirm="Metin, sistemle gelen varsayılan taslağa döndürülecek ve yeni sürüm olarak yayımlanacak."><?= csrf_field() ?><input type="hidden" name="islem" value="varsayilan"><input type="hidden" name="anahtar" value="<?= $aktifMetin ?>">
                    <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-arrow-counterclockwise"></i> Varsayılan taslağa dön</button></form>
            </div>
            <div class="col-xl-6">
                <div class="small text-muted fw-bold text-uppercase mb-2">Önizleme</div>
                <div class="yasal-kutu yasal-metin" style="max-height:640px"><h4 class="fw-bold"><?= e($m['baslik']) ?></h4><?= yasal_html(yasal_doldur($m['icerik'], $m['updated_at'])) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-5" id="ayarlar">
        <div class="card h-100"><div class="card-header"><h5><i class="bi bi-sliders me-2"></i>KVKK Ayarları</h5></div>
            <div class="card-body">
                <form method="post" class="row g-3"><?= csrf_field() ?><input type="hidden" name="islem" value="ayar">
                    <div class="col-12"><label class="form-label">Veri Sorumlusu Unvanı</label><input name="kvkk_sorumlu" class="form-control" value="<?= e(ayar('kvkk_sorumlu')) ?>" placeholder="<?= e(ayar('site_adi')) ?> Yönetimi"></div>
                    <div class="col-md-6"><label class="form-label">KVKK Başvuru E-postası</label><input type="email" name="kvkk_eposta" class="form-control" value="<?= e(ayar('kvkk_eposta')) ?>" placeholder="<?= e(ayar('eposta')) ?>"></div>
                    <div class="col-md-6"><label class="form-label">KEP Adresi</label><input name="kvkk_kep" class="form-control" value="<?= e(ayar('kvkk_kep')) ?>" placeholder="yonetim@hs01.kep.tr"></div>
                    <div class="col-md-6"><label class="form-label">İşlem kayıtlarını sakla (gün)</label><input type="number" min="30" max="3650" name="log_saklama_gun" class="form-control" value="<?= e(ayar('log_saklama_gun', '730')) ?>">
                        <div class="form-text">Süresi dolan işlem kayıtları ve 365 günden eski bildirim kayıtları her gün otomatik silinir.</div></div>
                    <div class="col-md-6 d-flex flex-column gap-2 justify-content-center">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="yasal_onay_zorunlu" value="1" id="yOz" <?= ayar('yasal_onay_zorunlu', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="yOz">Girişte sözleşme + KVKK onayı iste</label></div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="cerez_bildirimi" value="1" id="cB" <?= ayar('cerez_bildirimi', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="cB">Çerez bildirimini göster</label></div>
                    </div>
                    <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Kaydet</button></div>
                </form>
            </div></div>
    </div>
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header"><h5><i class="bi bi-journal-check me-2"></i>Onay Kayıtları</h5>
                <form class="d-flex gap-2"><input type="hidden" name="metin" value="<?= $aktifMetin ?>">
                    <select name="kayit" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">Tüm metinler</option>
                        <?php foreach ($metinler as $a => $x): ?><option value="<?= $a ?>" <?= $filtre === $a ? 'selected' : '' ?>><?= e($x['baslik']) ?></option><?php endforeach; ?></select></form></div>
            <div class="table-responsive" style="max-height:420px">
                <table class="table table-sm">
                    <thead><tr><th>Tarih</th><th>Kullanıcı</th><th>Metin</th><th>İşlem</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($kayitlar as $k): ?>
                        <tr>
                            <td class="small text-nowrap"><?= tarih($k['created_at'], true) ?></td>
                            <td class="small"><?= e($k['ad_soyad']) ?><div class="text-muted">@<?= e($k['kullanici_adi']) ?></div></td>
                            <td class="small"><?= e($metinler[$k['metin']]['baslik'] ?? $k['metin']) ?> <span class="text-muted">v<?= (int)$k['surum'] ?></span></td>
                            <td><?= $k['durum'] === 'onay' ? '<span class="badge-soft success">Onay</span>' : '<span class="badge-soft warning">Geri alındı</span>' ?></td>
                            <td class="small text-muted" title="<?= e($k['tarayici']) ?>"><?= e($k['ip']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$kayitlar): ?><tr><td colspan="5"><div class="empty-state py-4"><i class="bi bi-journal"></i><p class="mt-2 mb-0">Henüz onay kaydı yok.</p></div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
