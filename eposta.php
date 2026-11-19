<?php
/** E-posta ayarları ve gönderim kayıtları */
require __DIR__ . '/includes/init.php';
yetki_zorunlu('eposta.yonet');

if (is_post()) {
    csrf_check();
    $islem = post('islem');

    if ($islem === 'ayar') {
        $st = $pdo->prepare('INSERT INTO ayarlar (anahtar, deger) VALUES (?, ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)');
        $kaydet = fn($k, $v) => $st->execute([$k, (string)$v]);
        $kaydet('mail_aktif', post('mail_aktif') ? '1' : '0');
        $kaydet('mail_yontem', post('mail_yontem') === 'mail' ? 'mail' : 'smtp');
        $kaydet('smtp_host', trim((string)post('smtp_host')));
        $kaydet('smtp_port', (string)max(1, min(65535, (int)post('smtp_port', 587))));
        $kaydet('smtp_guvenlik', in_array(post('smtp_guvenlik'), ['tls', 'ssl', 'yok'], true) ? post('smtp_guvenlik') : 'tls');
        $kaydet('smtp_kullanici', trim((string)post('smtp_kullanici')));
        $sifre = (string)($_POST['smtp_sifre'] ?? '');
        if ($sifre !== '') $kaydet('smtp_sifre', smtp_sifre_sifrele($sifre));
        if (post('sifre_sil')) $kaydet('smtp_sifre', '');
        $gonderen = trim((string)post('mail_gonderen'));
        $kaydet('mail_gonderen', filter_var($gonderen, FILTER_VALIDATE_EMAIL) ? $gonderen : '');
        $kaydet('mail_gonderen_ad', trim((string)post('mail_gonderen_ad')));
        $url = rtrim(trim((string)post('site_url')), '/');
        $kaydet('site_url', preg_match('~^https?://~i', $url) ? $url : '');
        foreach (['mail_makbuz', 'mail_bildirim', 'mail_duyuru', 'mail_malik'] as $k) $kaydet($k, post($k) ? '1' : '0');
        ayar_sifirla();
        logla('E-posta ayarları güncellendi', (post('mail_aktif') ? 'açık' : 'kapalı') . ' · ' . post('smtp_host'));
        flash('success', 'E-posta ayarları kaydedildi.');
    }

    if ($islem === 'test') {
        $alici = trim((string)post('test_alici'));
        if (!filter_var($alici, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Geçerli bir test e-posta adresi girin.');
        } else {
            try {
                [$html, $metin] = eposta_sablon('Test e-postası', 'Merhaba,',
                    'Bu bir test e-postasıdır. Bu mesajı görüyorsanız ' . ayar('site_adi') . ' e-posta ayarları doğru çalışıyor.',
                    [['Gönderim zamanı', date('d.m.Y H:i:s')], ['Yöntem', ayar('mail_yontem') === 'mail' ? 'PHP mail()' : 'SMTP · ' . ayar('smtp_host') . ':' . ayar('smtp_port')]],
                    'Paneli Aç', site_kok_url() . '/index.php');
                eposta_hemen_gonder($alici, null, 'Test e-postası · ' . ayar('site_adi'), $html, $metin);
                logla('Test e-postası', $alici);
                flash('success', "Test e-postası $alici adresine gönderildi. Gelen kutunuzu (ve gereksiz klasörünü) kontrol edin.");
            } catch (Throwable $e) {
                flash('danger', 'Test e-postası gönderilemedi: ' . $e->getMessage());
            }
        }
    }

    if ($islem === 'isle') {
        $r = eposta_kuyrugu_isle(50);
        flash($r['hata'] ? 'warning' : 'success', $r['mesaj'] && !$r['gonderilen'] ? $r['mesaj'] : "{$r['gonderilen']} e-posta gönderildi" . ($r['hata'] ? ", {$r['hata']} başarısız" . ($r['mesaj'] ? ': ' . $r['mesaj'] : '') : '') . '.');
    }
    if ($islem === 'tekrar') {
        $pdo->exec("UPDATE eposta_kuyrugu SET durum = 'bekliyor', deneme = 0, sonraki_deneme = NULL WHERE durum = 'hata'" . (post('id') ? ' AND id = ' . (int)post('id') : ''));
        $GLOBALS['eposta_tetik'] = true;
        flash('success', 'Başarısız e-postalar yeniden gönderim sırasına alındı.');
    }
    if ($islem === 'iptal') {
        $pdo->prepare("UPDATE eposta_kuyrugu SET durum = 'iptal' WHERE id = ? AND durum IN ('bekliyor','hata')")->execute([(int)post('id')]);
        flash('success', 'E-posta iptal edildi.');
    }
    if ($islem === 'temizle') {
        $n = $pdo->exec("DELETE FROM eposta_kuyrugu WHERE durum IN ('gonderildi','iptal') AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        flash('success', "30 günden eski $n kayıt silindi.");
    }
    redirect('eposta.php' . (get('durum') ? '?durum=' . urlencode((string)get('durum')) : ''));
}

// Önizleme: kuyruktaki e-postanın HTML'i
if ($id = (int)get('onizle')) {
    $st = $pdo->prepare('SELECT html FROM eposta_kuyrugu WHERE id = ?');
    $st->execute([$id]);
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; img-src data: https:; style-src 'unsafe-inline'");
    echo $st->fetchColumn() ?: 'Bulunamadı';
    exit;
}

$durumF = in_array(get('durum'), ['bekliyor', 'gonderildi', 'hata', 'iptal'], true) ? get('durum') : '';
$sayilar = $pdo->query('SELECT durum, COUNT(*) FROM eposta_kuyrugu GROUP BY durum')->fetchAll(PDO::FETCH_KEY_PAIR);
$st = $pdo->prepare('SELECT id, alici, alici_ad, konu, tur, durum, deneme, hata, gonderim, created_at FROM eposta_kuyrugu' . ($durumF ? ' WHERE durum = ?' : '') . ' ORDER BY id DESC LIMIT 100');
$st->execute($durumF ? [$durumF] : []);
$kayitlar = $st->fetchAll();
$aliciSayisi = count(tum_sakin_alicilari());
$daireSayisi = (int)$pdo->query("SELECT COUNT(*) FROM daireler WHERE durum = 'dolu'")->fetchColumn();
$sifreVar = ayar('smtp_sifre') !== '';
$otoUrl = site_kok_url();
$yerel = preg_match('~//(localhost|127\.|192\.168\.|10\.)~', $otoUrl);

$sayfaBaslik = 'E-posta';
$sayfaAciklama = 'Sakinlere otomatik makbuz, ödeme bildirimi sonucu ve duyuru e-postaları';
require __DIR__ . '/includes/header.php';
$turAd = ['makbuz' => 'Makbuz', 'bildirim' => 'Bildirim', 'duyuru' => 'Duyuru', 'test' => 'Test', 'genel' => 'Genel'];
$durumRozet = ['bekliyor' => 'warning', 'gonderildi' => 'success', 'hata' => 'danger', 'iptal' => 'secondary'];
$durumAd = ['bekliyor' => 'Sırada', 'gonderildi' => 'Gönderildi', 'hata' => 'Başarısız', 'iptal' => 'İptal'];
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon soft-<?= eposta_aktif() ? 'success' : 'secondary' ?>"><i class="bi bi-<?= eposta_aktif() ? 'envelope-check' : 'envelope-slash' ?>"></i></div>
        <div><div class="stat-label">Durum</div><div class="stat-value fs-5"><?= eposta_aktif() ? 'Açık' : 'Kapalı' ?></div><div class="stat-sub"><?= ayar('mail_yontem') === 'mail' ? 'PHP mail()' : 'SMTP' ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon soft-primary"><i class="bi bi-people"></i></div>
        <div><div class="stat-label">E-postası Kayıtlı</div><div class="stat-value fs-5"><?= $aliciSayisi ?> adres</div><div class="stat-sub"><?= $daireSayisi ?> dolu daire</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon soft-success"><i class="bi bi-send-check"></i></div>
        <div><div class="stat-label">Gönderilen</div><div class="stat-value fs-5"><?= (int)($sayilar['gonderildi'] ?? 0) ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon soft-<?= !empty($sayilar['hata']) ? 'danger' : 'warning' ?>"><i class="bi bi-hourglass-split"></i></div>
        <div><div class="stat-label">Sırada / Başarısız</div><div class="stat-value fs-5"><?= (int)($sayilar['bekliyor'] ?? 0) ?> / <?= (int)($sayilar['hata'] ?? 0) ?></div></div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-xl-7">
        <form method="post" class="card h-100" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="islem" value="ayar">
            <div class="card-header"><h5><i class="bi bi-gear me-2"></i>Gönderim Ayarları</h5>
                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="mail_aktif" value="1" id="mAktif" <?= eposta_aktif() ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="mAktif">E-posta gönderimi açık</label></div></div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <span class="small text-muted me-1">Hazır ayar:</span>
                    <button type="button" class="btn btn-sm btn-light" data-saglayici="smtp.gmail.com|587|tls">Gmail</button>
                    <button type="button" class="btn btn-sm btn-light" data-saglayici="smtp.office365.com|587|tls">Outlook / Hotmail</button>
                    <button type="button" class="btn btn-sm btn-light" data-saglayici="smtp.yandex.com.tr|465|ssl">Yandex</button>
                    <button type="button" class="btn btn-sm btn-light" data-saglayici="mail.alanadiniz.com|465|ssl">cPanel / Hosting</button>
                </div>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Yöntem</label><select name="mail_yontem" class="form-select" id="mYontem">
                        <option value="smtp" <?= ayar('mail_yontem') !== 'mail' ? 'selected' : '' ?>>SMTP (önerilen)</option>
                        <option value="mail" <?= ayar('mail_yontem') === 'mail' ? 'selected' : '' ?>>PHP mail()</option></select></div>
                    <div class="col-md-8 smtp-alan"><label class="form-label">SMTP Sunucusu</label><input name="smtp_host" class="form-control" value="<?= e(ayar('smtp_host')) ?>" placeholder="smtp.gmail.com"></div>
                    <div class="col-md-3 smtp-alan"><label class="form-label">Port</label><input name="smtp_port" class="form-control" value="<?= e(ayar('smtp_port', '587')) ?>" inputmode="numeric"></div>
                    <div class="col-md-3 smtp-alan"><label class="form-label">Güvenlik</label><select name="smtp_guvenlik" class="form-select">
                        <?php foreach (['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', 'yok' => 'Yok (25)'] as $k => $v): ?><option value="<?= $k ?>" <?= ayar('smtp_guvenlik', 'tls') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6 smtp-alan"><label class="form-label">Kullanıcı Adı</label><input name="smtp_kullanici" class="form-control" value="<?= e(ayar('smtp_kullanici')) ?>" placeholder="yonetim@gmail.com" autocomplete="off"></div>
                    <div class="col-md-6 smtp-alan"><label class="form-label">Şifre <?php if ($sifreVar): ?><span class="badge-soft success ms-1">kayıtlı</span><?php endif; ?></label>
                        <input type="password" name="smtp_sifre" class="form-control" placeholder="<?= $sifreVar ? 'Değiştirmek için yazın' : 'Uygulama şifresi' ?>" autocomplete="new-password">
                        <?php if ($sifreVar): ?><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="sifre_sil" value="1" id="sSil"><label class="form-check-label small" for="sSil">Kayıtlı şifreyi sil</label></div><?php endif; ?></div>
                    <div class="col-md-6"><label class="form-label">Gönderen E-posta</label><input type="email" name="mail_gonderen" class="form-control" value="<?= e(ayar('mail_gonderen')) ?>" placeholder="Boşsa kullanıcı adı kullanılır"></div>
                    <div class="col-md-6"><label class="form-label">Gönderen Adı</label><input name="mail_gonderen_ad" class="form-control" value="<?= e(ayar('mail_gonderen_ad')) ?>" placeholder="<?= e(ayar('site_adi')) ?> Yönetimi"></div>
                    <div class="col-12"><label class="form-label">Site Adresi <span class="text-muted fw-normal">(e-postadaki bağlantılar bu adrese gider)</span></label>
                        <input name="site_url" class="form-control" value="<?= e(ayar('site_url')) ?>" placeholder="<?= e($otoUrl) ?>">
                        <div class="form-text">Boş bırakılırsa otomatik algılanır: <code><?= e($otoUrl) ?></code></div>
                        <?php if ($yerel): ?><div class="alert alert-warning small mt-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Site adresi yerel ağ adresi görünüyor. Sakinlerin bağlantıları evlerinden açabilmesi için sitenin internetten erişilebilir adresini (ör. <code>https://yesilvadi.com</code>) yazın.</div><?php endif; ?></div>
                </div>
                <hr>
                <h6 class="fw-bold mb-2">Otomatik E-postalar</h6>
                <div class="row g-2">
                    <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="mail_makbuz" value="1" id="o1" <?= ayar('mail_makbuz') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="o1">Tahsilat alınınca makbuz gönder</label></div></div>
                    <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="mail_bildirim" value="1" id="o2" <?= ayar('mail_bildirim') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="o2">Dekont onay / red sonucunu bildir</label></div></div>
                    <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="mail_duyuru" value="1" id="o3" <?= ayar('mail_duyuru') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="o3">Yeni duyuruları gönder (varsayılan işaretli)</label></div></div>
                    <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="mail_malik" value="1" id="o4" <?= ayar('mail_malik') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="o4">Kiracılı dairelerde malike de gönder (e-posta, SMS ve WhatsApp)</label></div></div>
                </div>
            </div>
            <div class="card-footer bg-transparent text-end p-3"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Kaydet</button></div>
        </form>
    </div>
    <div class="col-xl-5">
        <div class="card mb-3">
            <div class="card-header"><h5><i class="bi bi-send me-2"></i>Test E-postası</h5></div>
            <div class="card-body">
                <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="islem" value="test">
                    <input type="email" name="test_alici" class="form-control" value="<?= e(kullanici()['eposta'] ?? '') ?>" placeholder="ornek@gmail.com" required>
                    <button class="btn btn-primary text-nowrap">Gönder</button></form>
                <div class="form-text">Ayarları kaydettikten sonra deneyin. Test e-postası sıraya alınmadan hemen gönderilir ve hata varsa ayrıntısı gösterilir.</div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-lightbulb me-2"></i>Kurulum İpuçları</h5></div>
            <div class="card-body small">
                <p class="mb-2"><strong>Gmail:</strong> Google Hesabı › Güvenlik › 2 Adımlı Doğrulama'yı açın, ardından <em>Uygulama şifreleri</em> bölümünden 16 haneli şifre oluşturup "Şifre" alanına yazın. Normal Gmail şifreniz çalışmaz.</p>
                <p class="mb-2"><strong>Outlook / Hotmail:</strong> Hesap güvenliğinden uygulama şifresi oluşturun; sunucu <code>smtp.office365.com</code>, port 587, TLS.</p>
                <p class="mb-2"><strong>Hosting (cPanel / Plesk):</strong> Panelden <code>yonetim@alanadiniz.com</code> gibi bir e-posta hesabı açın; sunucu genellikle <code>mail.alanadiniz.com</code>, port 465 SSL.</p>
                <p class="mb-2"><strong>Gönderim zamanı (CLI Cron):</strong> E-postalar sıraya alınır ve sayfa yüklendikten sonra arka planda gönderilir. Hostingte düzenli gönderim için Cron'a şu komutu ekleyebilirsiniz:<br>
                    <code>*/5 * * * * php <?= e(__DIR__) ?>/cron_eposta.php</code></p>
                <p class="mb-0"><strong>Web-Cron (Paylaşımlı Hosting):</strong> CLI erişiminiz yoksa URL ile tetikleyebilirsiniz:<br>
                    <code><?= e(site_kok_url() . '/cron_eposta.php?key=' . cron_anahtari()) ?></code></p>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <ul class="nav nav-pills">
            <li class="nav-item"><a class="nav-link <?= $durumF === '' ? 'active' : '' ?>" href="?">Tümü</a></li>
            <?php foreach ($durumAd as $k => $v): ?>
                <li class="nav-item"><a class="nav-link <?= $durumF === $k ? 'active' : '' ?>" href="?durum=<?= $k ?>"><?= $v ?> <span class="badge rounded-pill bg-<?= $durumF === $k ? 'light text-dark' : $durumRozet[$k] ?>"><?= (int)($sayilar[$k] ?? 0) ?></span></a></li>
            <?php endforeach; ?>
        </ul>
        <div class="d-flex gap-2">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="islem" value="isle"><button class="btn btn-sm btn-primary" <?= eposta_aktif() ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i>Sıradakileri şimdi gönder</button></form>
            <?php if (!empty($sayilar['hata'])): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="islem" value="tekrar"><button class="btn btn-sm btn-soft-danger"><i class="bi bi-arrow-repeat me-1"></i>Başarısızları tekrar dene</button></form><?php endif; ?>
            <form method="post" data-confirm="30 günden eski gönderilmiş/iptal kayıtlar silinecek."><?= csrf_field() ?><input type="hidden" name="islem" value="temizle"><button class="btn btn-sm btn-light" title="Eski kayıtları temizle"><i class="bi bi-trash3"></i></button></form>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Tarih</th><th>Tür</th><th>Alıcı</th><th>Konu</th><th>Durum</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($kayitlar as $k): ?>
                <tr>
                    <td class="small text-nowrap"><?= tarih($k['created_at'], true) ?><?php if ($k['gonderim']): ?><div class="text-muted">↳ <?= tarih($k['gonderim'], true) ?></div><?php endif; ?></td>
                    <td><span class="badge-soft primary"><?= e($turAd[$k['tur']] ?? $k['tur']) ?></span></td>
                    <td class="small"><?= e(kisi($k['alici_ad'])) ?><div class="text-muted"><?= e(yetki('kisi.goruntule') ? $k['alici'] : preg_replace('/(?<=.).(?=[^@]*@)/u', '*', $k['alici'])) ?></div></td>
                    <td class="small" style="max-width:340px"><?= e($k['konu']) ?><?php if ($k['hata']): ?><div class="text-danger mt-1"><i class="bi bi-exclamation-circle"></i> <?= e($k['hata']) ?></div><?php endif; ?></td>
                    <td><span class="badge-soft <?= $durumRozet[$k['durum']] ?>"><?= $durumAd[$k['durum']] ?></span><?php if ($k['deneme'] > 1 || ($k['deneme'] && $k['durum'] !== 'gonderildi')): ?><div class="small text-muted"><?= (int)$k['deneme'] ?>. deneme</div><?php endif; ?></td>
                    <td class="table-actions">
                        <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#onizleModal" data-src="?onizle=<?= $k['id'] ?>" title="Önizle"><i class="bi bi-eye"></i></button>
                        <?php if ($k['durum'] === 'hata'): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="islem" value="tekrar"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button class="btn btn-sm btn-soft-primary" title="Tekrar dene"><i class="bi bi-arrow-repeat"></i></button></form><?php endif; ?>
                        <?php if (in_array($k['durum'], ['bekliyor', 'hata'], true)): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="islem" value="iptal"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button class="btn btn-sm btn-soft-danger" title="İptal"><i class="bi bi-x-lg"></i></button></form><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$kayitlar): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-envelope-open"></i><p class="mt-2 mb-0">Henüz e-posta kaydı yok.</p></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="onizleModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-envelope-open me-2"></i>E-posta Önizleme</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-0"><iframe id="onizleCerceve" sandbox style="width:100%;height:70vh;border:0;background:#f4f6fb"></iframe></div>
    </div></div>
</div>
<?php ob_start(); ?>
<script>
document.querySelectorAll('[data-saglayici]').forEach(b => b.addEventListener('click', function () {
    const [h, p, g] = this.dataset.saglayici.split('|');
    document.querySelector('[name=smtp_host]').value = h;
    document.querySelector('[name=smtp_port]').value = p;
    document.querySelector('[name=smtp_guvenlik]').value = g;
    document.getElementById('mYontem').value = 'smtp'; alanlar();
    document.querySelector('[name=smtp_kullanici]').focus();
}));
function alanlar() { const s = document.getElementById('mYontem').value === 'smtp'; document.querySelectorAll('.smtp-alan').forEach(e => e.style.display = s ? '' : 'none'); }
document.getElementById('mYontem').addEventListener('change', alanlar); alanlar();
document.getElementById('onizleModal').addEventListener('show.bs.modal', e => document.getElementById('onizleCerceve').src = e.relatedTarget.dataset.src);
</script>
<?php $sayfaJs = ob_get_clean(); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
