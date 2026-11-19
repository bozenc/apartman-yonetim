<?php
/** SMS ve WhatsApp ayarları, şablonlar ve gönderim kayıtları */
require __DIR__ . '/includes/init.php';
yetki_zorunlu('mesaj.yonet');

$gizliAlanlar = ['netgsm_sifre', 'twilio_token', 'wa_meta_token'];

if (is_post()) {
    csrf_check();
    $islem = post('islem');
    $st = $pdo->prepare('INSERT INTO ayarlar (anahtar, deger) VALUES (?, ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)');
    $kaydet = fn($k, $v) => $st->execute([$k, (string)$v]);

    if ($islem === 'ayar') {
        foreach (['sms_aktif', 'wa_aktif', 'sms_turkce', 'sms_makbuz', 'sms_bildirim', 'sms_duyuru', 'wa_makbuz', 'wa_bildirim', 'wa_duyuru'] as $k) $kaydet($k, post($k) ? '1' : '0');
        $kaydet('sms_saglayici', in_array(post('sms_saglayici'), ['netgsm', 'twilio', 'ozel'], true) ? post('sms_saglayici') : 'netgsm');
        $kaydet('wa_saglayici', in_array(post('wa_saglayici'), ['meta', 'twilio', 'ozel'], true) ? post('wa_saglayici') : 'meta');
        $kaydet('wa_meta_tip', post('wa_meta_tip') === 'metin' ? 'metin' : 'sablon');
        foreach (['sms_baslik', 'netgsm_kullanici', 'twilio_sid', 'twilio_sms_no', 'twilio_wa_no', 'wa_meta_telefon_id', 'wa_meta_sablon', 'wa_meta_dil',
                  'ozel_sms_url', 'ozel_sms_govde', 'ozel_sms_tip', 'ozel_sms_basari', 'ozel_wa_url', 'ozel_wa_govde', 'ozel_wa_tip', 'ozel_wa_basari'] as $k) $kaydet($k, trim((string)post($k)));
        foreach (['ozel_sms_yontem', 'ozel_wa_yontem'] as $k) $kaydet($k, post($k) === 'GET' ? 'GET' : 'POST');
        foreach ($gizliAlanlar as $k) { $v = (string)($_POST[$k] ?? ''); if ($v !== '') $kaydet($k, gizli_sifrele($v)); }
        foreach (['makbuz', 'onay', 'red', 'duyuru'] as $t) { $v = trim((string)post('sablon_' . $t)); if ($v !== '') $kaydet('msj_sablon_' . $t, mb_substr($v, 0, 500)); }
        ayar_sifirla();
        logla('SMS/WhatsApp ayarları güncellendi', 'SMS ' . (post('sms_aktif') ? 'açık' : 'kapalı') . ' · WhatsApp ' . (post('wa_aktif') ? 'açık' : 'kapalı'));
        flash('success', 'SMS ve WhatsApp ayarları kaydedildi.');
    }

    if ($islem === 'test') {
        $kanal = post('kanal') === 'whatsapp' ? 'whatsapp' : 'sms';
        $tel = telefon_normal((string)post('test_tel'));
        if (!$tel) {
            flash('danger', 'Geçerli bir cep telefonu girin (ör. 0532 111 22 33).');
        } else {
            $metin = ayar('site_adi') . ': Bu bir test mesajıdır. ' . ($kanal === 'sms' ? 'SMS' : 'WhatsApp') . ' bildirimleri çalışıyor. ' . date('d.m.Y H:i');
            try {
                $id = $kanal === 'sms' ? sms_gonder_tek($tel, $metin) : wa_gonder_tek($tel, $metin);
                $pdo->prepare("INSERT INTO mesaj_kuyrugu (kanal, telefon, metin, tur, durum, deneme, saglayici_id, gonderim) VALUES (?,?,?,'test','gonderildi',1,?,NOW())")
                    ->execute([$kanal, $tel, $metin, mb_substr($id, 0, 100)]);
                logla('Test mesajı', $kanal . ' · ' . telefon_goster($tel));
                flash('success', 'Test ' . ($kanal === 'sms' ? 'SMS\'i' : 'WhatsApp mesajı') . ' ' . telefon_goster($tel) . ' numarasına gönderildi. Sağlayıcı numarası: ' . $id);
            } catch (Throwable $e) {
                flash('danger', 'Gönderilemedi: ' . $e->getMessage());
            }
        }
    }

    if ($islem === 'isle') {
        $r = mesaj_kuyrugu_isle(50);
        flash($r['hata'] ? 'warning' : 'success', "{$r['gonderilen']} mesaj gönderildi" . ($r['hata'] ? ", {$r['hata']} başarısız" . ($r['mesaj'] ? ': ' . $r['mesaj'] : '') : '') . '.');
    }
    if ($islem === 'tekrar') {
        $pdo->exec("UPDATE mesaj_kuyrugu SET durum = 'bekliyor', deneme = 0, sonraki_deneme = NULL WHERE durum = 'hata'" . (post('id') ? ' AND id = ' . (int)post('id') : ''));
        $GLOBALS['mesaj_tetik'] = true;
        flash('success', 'Başarısız mesajlar yeniden gönderim sırasına alındı.');
    }
    if ($islem === 'iptal') {
        $pdo->prepare("UPDATE mesaj_kuyrugu SET durum = 'iptal' WHERE id = ? AND durum IN ('bekliyor','hata')")->execute([(int)post('id')]);
        flash('success', 'Mesaj iptal edildi.');
    }
    redirect('mesajlar.php' . (get('durum') ? '?durum=' . urlencode((string)get('durum')) : ''));
}

$durumF = in_array(get('durum'), ['bekliyor', 'gonderildi', 'hata', 'iptal'], true) ? get('durum') : '';
$sayilar = $pdo->query('SELECT durum, COUNT(*) FROM mesaj_kuyrugu GROUP BY durum')->fetchAll(PDO::FETCH_KEY_PAIR);
$kanalSay = $pdo->query("SELECT kanal, COUNT(*) FROM mesaj_kuyrugu WHERE durum = 'gonderildi' GROUP BY kanal")->fetchAll(PDO::FETCH_KEY_PAIR);
$st = $pdo->prepare('SELECT * FROM mesaj_kuyrugu' . ($durumF ? ' WHERE durum = ?' : '') . ' ORDER BY id DESC LIMIT 100');
$st->execute($durumF ? [$durumF] : []);
$kayitlar = $st->fetchAll();
$telSayi = 0;
foreach ($pdo->query('SELECT id FROM daireler')->fetchAll(PDO::FETCH_COLUMN) as $id) $telSayi += count(daire_telefonlari((int)$id, 'sms'));
$ornek = ['site' => ayar('site_adi'), 'daire' => 'A-5', 'tutar' => para(3500), 'tarih' => date('d.m.Y'), 'neden' => 'Dekont okunmuyor', 'baslik' => 'Su kesintisi – 5 Kasım', 'link' => site_kok_url() . '/goster.php?k=Ab3dE9xY'];
$durumRozet = ['bekliyor' => 'warning', 'gonderildi' => 'success', 'hata' => 'danger', 'iptal' => 'secondary'];
$durumAd = ['bekliyor' => 'Sırada', 'gonderildi' => 'Gönderildi', 'hata' => 'Başarısız', 'iptal' => 'İptal'];
$turAd = ['makbuz' => 'Makbuz', 'bildirim' => 'Bildirim', 'duyuru' => 'Duyuru', 'test' => 'Test', 'genel' => 'Genel'];
$kayitli = fn($k) => ayar($k) !== '' ? '<span class="badge-soft success ms-1">kayıtlı</span>' : '';

$sayfaBaslik = 'SMS & WhatsApp';
$sayfaAciklama = 'Makbuz, ödeme bildirimi sonucu ve duyuruları sakinlerin cep telefonuna gönderin';
require __DIR__ . '/includes/header.php';
$sec = fn($k, $v) => ayar($k) === $v ? 'selected' : '';
$isaret = fn($k) => ayar($k) === '1' ? 'checked' : '';
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon soft-<?= sms_aktif() ? 'success' : 'secondary' ?>"><i class="bi bi-chat-text"></i></div>
        <div><div class="stat-label">SMS</div><div class="stat-value fs-5"><?= sms_aktif() ? 'Açık' : 'Kapalı' ?></div><div class="stat-sub"><?= ['netgsm' => 'Netgsm', 'twilio' => 'Twilio', 'ozel' => 'Özel HTTP'][ayar('sms_saglayici', 'netgsm')] ?? '' ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon soft-<?= wa_aktif() ? 'success' : 'secondary' ?>"><i class="bi bi-whatsapp"></i></div>
        <div><div class="stat-label">WhatsApp</div><div class="stat-value fs-5"><?= wa_aktif() ? 'Açık' : 'Kapalı' ?></div><div class="stat-sub"><?= ['meta' => 'Meta Cloud API', 'twilio' => 'Twilio', 'ozel' => 'Özel HTTP'][ayar('wa_saglayici', 'meta')] ?? '' ?></div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon soft-primary"><i class="bi bi-phone"></i></div>
        <div><div class="stat-label">Geçerli Cep Telefonu</div><div class="stat-value fs-5"><?= $telSayi ?> numara</div><div class="stat-sub">izinli dairelerde</div></div></div></div>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon soft-success"><i class="bi bi-send-check"></i></div>
        <div><div class="stat-label">Gönderilen</div><div class="stat-value fs-5"><?= (int)($kanalSay['sms'] ?? 0) ?> SMS · <?= (int)($kanalSay['whatsapp'] ?? 0) ?> WA</div><div class="stat-sub"><?= (int)($sayilar['hata'] ?? 0) ?> başarısız</div></div></div></div>
</div>

<form method="post" autocomplete="off">
<?= csrf_field() ?><input type="hidden" name="islem" value="ayar">
<div class="row g-3 mb-3">
    <!-- SMS -->
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header"><h5><i class="bi bi-chat-text me-2"></i>SMS</h5>
                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="sms_aktif" value="1" id="smsA" <?= $isaret('sms_aktif') ?>><label class="form-check-label fw-semibold" for="smsA">SMS gönderimi açık</label></div></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Sağlayıcı</label><select name="sms_saglayici" class="form-select saglayici" data-grup="sms">
                        <option value="netgsm" <?= $sec('sms_saglayici', 'netgsm') ?>>Netgsm</option><option value="twilio" <?= $sec('sms_saglayici', 'twilio') ?>>Twilio</option><option value="ozel" <?= $sec('sms_saglayici', 'ozel') ?>>Özel HTTP (diğer firmalar)</option></select></div>
                    <div class="col-md-6"><label class="form-label">SMS Başlığı (gönderici adı)</label><input name="sms_baslik" class="form-control" value="<?= e(ayar('sms_baslik')) ?>" placeholder="YESILVADI" maxlength="11"></div>
                    <div class="col-md-6 sms-netgsm"><label class="form-label">Netgsm Kullanıcı Kodu</label><input name="netgsm_kullanici" class="form-control" value="<?= e(ayar('netgsm_kullanici')) ?>" placeholder="850xxxxxxx"></div>
                    <div class="col-md-6 sms-netgsm"><label class="form-label">Netgsm API Şifresi <?= $kayitli('netgsm_sifre') ?></label><input type="password" name="netgsm_sifre" class="form-control" placeholder="Değiştirmek için yazın" autocomplete="new-password"></div>
                    <div class="col-md-6 sms-twilio wa-twilio-ortak"><label class="form-label">Twilio Account SID</label><input name="twilio_sid" class="form-control" value="<?= e(ayar('twilio_sid')) ?>" placeholder="ACxxxxxxxx"></div>
                    <div class="col-md-6 sms-twilio wa-twilio-ortak"><label class="form-label">Twilio Auth Token <?= $kayitli('twilio_token') ?></label><input type="password" name="twilio_token" class="form-control" placeholder="Değiştirmek için yazın" autocomplete="new-password"></div>
                    <div class="col-md-6 sms-twilio"><label class="form-label">Twilio SMS Numarası</label><input name="twilio_sms_no" class="form-control" value="<?= e(ayar('twilio_sms_no')) ?>" placeholder="+1415…"></div>
                    <div class="col-12 sms-ozel"><label class="form-label">İstek Adresi</label><input name="ozel_sms_url" class="form-control font-monospace small" value="<?= e(ayar('ozel_sms_url')) ?>" placeholder="https://api.firma.com/sms?user=..&pass=..&to={telefon}&text={mesaj}"></div>
                    <div class="col-md-4 sms-ozel"><label class="form-label">Yöntem</label><select name="ozel_sms_yontem" class="form-select"><option <?= $sec('ozel_sms_yontem', 'GET') ?>>GET</option><option <?= $sec('ozel_sms_yontem', 'POST') ?>>POST</option></select></div>
                    <div class="col-md-8 sms-ozel"><label class="form-label">İçerik Türü (POST)</label><input name="ozel_sms_tip" class="form-control" value="<?= e(ayar('ozel_sms_tip', 'application/json')) ?>"></div>
                    <div class="col-12 sms-ozel"><label class="form-label">POST Gövdesi</label><textarea name="ozel_sms_govde" class="form-control font-monospace small" rows="2" placeholder='{"to":"{telefon}","message":"{mesaj}"}'><?= e(ayar('ozel_sms_govde')) ?></textarea></div>
                    <div class="col-12 sms-ozel"><label class="form-label">Başarılı yanıtta geçen ifade <span class="text-muted fw-normal">(boşsa HTTP 2xx yeterli)</span></label><input name="ozel_sms_basari" class="form-control" value="<?= e(ayar('ozel_sms_basari')) ?>"></div>
                    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="sms_turkce" value="1" id="smsTr" <?= $isaret('sms_turkce') ?>><label class="form-check-label" for="smsTr">Türkçe karakterli gönder <span class="text-muted small">(kapalıyken ç→c, ş→s yapılır; mesaj başına daha çok karakter sığar)</span></label></div></div>
                </div>
                <hr><h6 class="fw-bold mb-2">SMS ile gönderilecekler</h6>
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="sms_makbuz" value="1" id="s1" <?= $isaret('sms_makbuz') ?>><label class="form-check-label" for="s1">Tahsilat makbuzu</label></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="sms_bildirim" value="1" id="s2" <?= $isaret('sms_bildirim') ?>><label class="form-check-label" for="s2">Dekont onay / red</label></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="sms_duyuru" value="1" id="s3" <?= $isaret('sms_duyuru') ?>><label class="form-check-label" for="s3">Duyurular <span class="text-muted small">(tüm sakinlere)</span></label></div>
                </div>
            </div>
        </div>
    </div>
    <!-- WhatsApp -->
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header"><h5><i class="bi bi-whatsapp me-2 text-success"></i>WhatsApp</h5>
                <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="wa_aktif" value="1" id="waA" <?= $isaret('wa_aktif') ?>><label class="form-check-label fw-semibold" for="waA">WhatsApp gönderimi açık</label></div></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Sağlayıcı</label><select name="wa_saglayici" class="form-select saglayici" data-grup="wa">
                        <option value="meta" <?= $sec('wa_saglayici', 'meta') ?>>Meta Cloud API (resmi)</option><option value="twilio" <?= $sec('wa_saglayici', 'twilio') ?>>Twilio WhatsApp</option><option value="ozel" <?= $sec('wa_saglayici', 'ozel') ?>>Özel HTTP</option></select></div>
                    <div class="col-md-6 wa-meta"><label class="form-label">Mesaj Türü</label><select name="wa_meta_tip" class="form-select">
                        <option value="sablon" <?= $sec('wa_meta_tip', 'sablon') ?>>Onaylı şablon (önerilen)</option><option value="metin" <?= $sec('wa_meta_tip', 'metin') ?>>Serbest metin (yalnızca 24 saat penceresi)</option></select></div>
                    <div class="col-md-6 wa-meta"><label class="form-label">Telefon Numarası Kimliği</label><input name="wa_meta_telefon_id" class="form-control" value="<?= e(ayar('wa_meta_telefon_id')) ?>" placeholder="1234567890123"></div>
                    <div class="col-md-6 wa-meta"><label class="form-label">Kalıcı Erişim Anahtarı <?= $kayitli('wa_meta_token') ?></label><input type="password" name="wa_meta_token" class="form-control" placeholder="EAAG…" autocomplete="new-password"></div>
                    <div class="col-md-8 wa-meta"><label class="form-label">Şablon Adı</label><input name="wa_meta_sablon" class="form-control" value="<?= e(ayar('wa_meta_sablon')) ?>" placeholder="apartman_bildirim"></div>
                    <div class="col-md-4 wa-meta"><label class="form-label">Dil Kodu</label><input name="wa_meta_dil" class="form-control" value="<?= e(ayar('wa_meta_dil', 'tr')) ?>"></div>
                    <div class="col-12 wa-meta"><div class="alert alert-info small mb-0"><i class="bi bi-info-circle me-1"></i>Meta, işletmenin başlattığı mesajlarda onaylı şablon ister. Meta Business › WhatsApp › Mesaj Şablonları'ndan <strong>Yardımcı (Utility)</strong> kategorisinde, gövdesi örneğin <code>Merhaba, {{1}}</code> olan bir şablon oluşturun; sistem mesaj metnini <code>{{1}}</code> yerine yazar.</div></div>
                    <div class="col-md-6 wa-twilio"><label class="form-label">Twilio WhatsApp Numarası</label><input name="twilio_wa_no" class="form-control" value="<?= e(ayar('twilio_wa_no')) ?>" placeholder="+1415…"></div>
                    <div class="col-12 wa-twilio"><div class="form-text">Account SID ve Auth Token, SMS bölümündeki Twilio alanlarıyla ortaktır (SMS sağlayıcısı Twilio değilse de oradan girin).</div></div>
                    <div class="col-12 wa-ozel"><label class="form-label">İstek Adresi</label><input name="ozel_wa_url" class="form-control font-monospace small" value="<?= e(ayar('ozel_wa_url')) ?>" placeholder="https://api.firma.com/whatsapp/send"></div>
                    <div class="col-md-4 wa-ozel"><label class="form-label">Yöntem</label><select name="ozel_wa_yontem" class="form-select"><option <?= $sec('ozel_wa_yontem', 'GET') ?>>GET</option><option <?= $sec('ozel_wa_yontem', 'POST') ?>>POST</option></select></div>
                    <div class="col-md-8 wa-ozel"><label class="form-label">İçerik Türü (POST)</label><input name="ozel_wa_tip" class="form-control" value="<?= e(ayar('ozel_wa_tip', 'application/json')) ?>"></div>
                    <div class="col-12 wa-ozel"><label class="form-label">POST Gövdesi</label><textarea name="ozel_wa_govde" class="form-control font-monospace small" rows="2" placeholder='{"phone":"{telefon}","text":"{mesaj}"}'><?= e(ayar('ozel_wa_govde')) ?></textarea></div>
                    <div class="col-12 wa-ozel"><label class="form-label">Başarılı yanıtta geçen ifade</label><input name="ozel_wa_basari" class="form-control" value="<?= e(ayar('ozel_wa_basari')) ?>"></div>
                </div>
                <hr><h6 class="fw-bold mb-2">WhatsApp ile gönderilecekler</h6>
                <div class="d-flex flex-wrap gap-3">
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="wa_makbuz" value="1" id="w1" <?= $isaret('wa_makbuz') ?>><label class="form-check-label" for="w1">Tahsilat makbuzu</label></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="wa_bildirim" value="1" id="w2" <?= $isaret('wa_bildirim') ?>><label class="form-check-label" for="w2">Dekont onay / red</label></div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="wa_duyuru" value="1" id="w3" <?= $isaret('wa_duyuru') ?>><label class="form-check-label" for="w3">Duyurular</label></div>
                </div>
                <div class="form-text mt-2">API kullanmadan da her makbuz ve duyuru sayfasındaki <i class="bi bi-whatsapp"></i> düğmesiyle WhatsApp'tan elle paylaşabilirsiniz (ücretsiz).</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><h5><i class="bi bi-chat-square-text me-2"></i>Mesaj Şablonları <span class="text-muted fw-normal small">(SMS ve WhatsApp için ortak)</span></h5>
        <span class="small text-muted">Kullanılabilir: <code>{site}</code> <code>{daire}</code> <code>{tutar}</code> <code>{tarih}</code> <code>{neden}</code> <code>{baslik}</code> <code>{link}</code></span></div>
    <div class="card-body">
        <div class="row g-3">
            <?php foreach (['makbuz' => 'Tahsilat makbuzu', 'onay' => 'Dekont onaylandı', 'red' => 'Dekont reddedildi', 'duyuru' => 'Duyuru'] as $t => $ad): $ornekMetin = mesaj_sablon($t, $ornek); ?>
            <div class="col-lg-6">
                <label class="form-label"><?= $ad ?></label>
                <textarea name="sablon_<?= $t ?>" class="form-control sablon" rows="2" maxlength="500"><?= e(ayar('msj_sablon_' . $t)) ?></textarea>
                <div class="form-text">Örnek: <span class="text-body"><?= e(ayar('sms_turkce') === '1' ? $ornekMetin : ascii_tr($ornekMetin)) ?></span> · <strong><?= mb_strlen($ornekMetin) ?> karakter ≈ <?= sms_adet(ayar('sms_turkce') === '1' ? $ornekMetin : ascii_tr($ornekMetin)) ?> SMS</strong></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="small text-muted mt-3"><i class="bi bi-link-45deg"></i> SMS'lerde maliyeti düşürmek için kısa bağlantı kullanılır (ör. <code><?= e(site_kok_url()) ?>/goster.php?k=Ab3dE9xY</code>); bağlantı, şifre sormadan ilgili makbuzu veya duyuruyu açar.</div>
    </div>
    <div class="card-footer bg-transparent text-end p-3"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Ayarları Kaydet</button></div>
</div>
</form>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card h-100"><div class="card-header"><h5><i class="bi bi-send me-2"></i>Test Mesajı</h5></div>
            <div class="card-body">
                <form method="post" class="row g-2"><?= csrf_field() ?><input type="hidden" name="islem" value="test">
                    <div class="col-12"><input name="test_tel" class="form-control" value="<?= e(kullanici()['telefon'] ?? '') ?>" placeholder="0532 111 22 33" required></div>
                    <div class="col-6"><button name="kanal" value="sms" class="btn btn-primary w-100" <?= sms_aktif() ? '' : 'disabled' ?>><i class="bi bi-chat-text me-1"></i>SMS gönder</button></div>
                    <div class="col-6"><button name="kanal" value="whatsapp" class="btn btn-success w-100" <?= wa_aktif() ? '' : 'disabled' ?>><i class="bi bi-whatsapp me-1"></i>WhatsApp gönder</button></div>
                </form>
                <div class="form-text">Ayarları kaydedip kanalı açtıktan sonra deneyin. Test mesajı sıraya alınmadan hemen gönderilir ve sağlayıcının hata mesajı gösterilir.</div>
            </div></div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100"><div class="card-header"><h5><i class="bi bi-shield-check me-2"></i>Yasal Notlar</h5></div>
            <div class="card-body small">
                <p class="mb-2"><strong>İYS:</strong> Makbuz, ödeme sonucu ve yönetim duyuruları <em>bilgilendirme</em> amaçlı iletilerdir; ticari elektronik ileti onayı (İYS) gerektirmez. Sağlayıcınızda bu mesajları "bilgilendirme" olarak gönderin; reklam/kampanya içeriği eklemeyin.</p>
                <p class="mb-2"><strong>KVKK:</strong> Telefon numaraları aidat tahsili ve yönetimle iletişim amacıyla işlenir. WhatsApp ve Twilio sunucuları yurt dışında olduğundan bu kanallar için sakinlerden <a href="<?= url('yasal.php?metin=acik_riza') ?>" target="_blank">açık rıza</a> alınması önerilir; rızasını geri alan kullanıcılara WhatsApp gönderilmez.</p>
                <p class="mb-0"><strong>Gönderim izni:</strong> Daire kartındaki "SMS / WhatsApp bildirimi" kutusu kapatılırsa o daireye mesaj gitmez; sakin de Profilim sayfasından kapatabilir.</p>
            </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <ul class="nav nav-pills">
            <li class="nav-item"><a class="nav-link <?= $durumF === '' ? 'active' : '' ?>" href="?">Tümü</a></li>
            <?php foreach ($durumAd as $k => $v): ?><li class="nav-item"><a class="nav-link <?= $durumF === $k ? 'active' : '' ?>" href="?durum=<?= $k ?>"><?= $v ?> <span class="badge rounded-pill bg-<?= $durumF === $k ? 'light text-dark' : $durumRozet[$k] ?>"><?= (int)($sayilar[$k] ?? 0) ?></span></a></li><?php endforeach; ?>
        </ul>
        <div class="d-flex gap-2">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="islem" value="isle"><button class="btn btn-sm btn-primary" <?= sms_aktif() || wa_aktif() ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i>Sıradakileri şimdi gönder</button></form>
            <?php if (!empty($sayilar['hata'])): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="islem" value="tekrar"><button class="btn btn-sm btn-soft-danger"><i class="bi bi-arrow-repeat me-1"></i>Başarısızları tekrar dene</button></form><?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Tarih</th><th>Kanal</th><th>Tür</th><th>Alıcı</th><th>Mesaj</th><th>Durum</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($kayitlar as $k): ?>
                <tr>
                    <td class="small text-nowrap"><?= tarih($k['created_at'], true) ?><?php if ($k['gonderim']): ?><div class="text-muted">↳ <?= tarih($k['gonderim'], true) ?></div><?php endif; ?></td>
                    <td><?= $k['kanal'] === 'sms' ? '<span class="badge-soft primary"><i class="bi bi-chat-text"></i> SMS</span>' : '<span class="badge-soft success"><i class="bi bi-whatsapp"></i> WhatsApp</span>' ?></td>
                    <td><span class="badge-soft secondary"><?= e($turAd[$k['tur']] ?? $k['tur']) ?></span></td>
                    <td class="small text-nowrap"><?= e(kisi($k['alici_ad'])) ?><div class="text-muted"><?= e(yetki('kisi.goruntule') ? telefon_goster($k['telefon']) : substr(telefon_goster($k['telefon']), 0, 5) . ' ••• •• ••') ?></div></td>
                    <td class="small" style="max-width:380px"><?= e($k['metin']) ?><?php if ($k['hata']): ?><div class="text-danger mt-1"><i class="bi bi-exclamation-circle"></i> <?= e($k['hata']) ?></div><?php endif; ?></td>
                    <td><span class="badge-soft <?= $durumRozet[$k['durum']] ?>"><?= $durumAd[$k['durum']] ?></span><?php if ($k['saglayici_id'] && $k['durum'] === 'gonderildi'): ?><div class="small text-muted text-truncate" style="max-width:120px" title="<?= e($k['saglayici_id']) ?>"><?= e($k['saglayici_id']) ?></div><?php endif; ?></td>
                    <td class="table-actions">
                        <?php if ($k['durum'] === 'hata'): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="islem" value="tekrar"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button class="btn btn-sm btn-soft-primary" title="Tekrar dene"><i class="bi bi-arrow-repeat"></i></button></form><?php endif; ?>
                        <?php if (in_array($k['durum'], ['bekliyor', 'hata'], true)): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="islem" value="iptal"><input type="hidden" name="id" value="<?= $k['id'] ?>"><button class="btn btn-sm btn-soft-danger" title="İptal"><i class="bi bi-x-lg"></i></button></form><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$kayitlar): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-chat-dots"></i><p class="mt-2 mb-0">Henüz SMS / WhatsApp kaydı yok.</p></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php ob_start(); ?>
<script>
(function () {
    function goster() {
        const sms = document.querySelector('[name=sms_saglayici]').value, wa = document.querySelector('[name=wa_saglayici]').value;
        document.querySelectorAll('.sms-netgsm').forEach(e => e.style.display = sms === 'netgsm' ? '' : 'none');
        document.querySelectorAll('.sms-twilio').forEach(e => e.style.display = (sms === 'twilio' || (e.classList.contains('wa-twilio-ortak') && wa === 'twilio')) ? '' : 'none');
        document.querySelectorAll('.sms-ozel').forEach(e => e.style.display = sms === 'ozel' ? '' : 'none');
        document.querySelectorAll('.wa-meta').forEach(e => e.style.display = wa === 'meta' ? '' : 'none');
        document.querySelectorAll('.wa-twilio').forEach(e => e.style.display = wa === 'twilio' ? '' : 'none');
        document.querySelectorAll('.wa-ozel').forEach(e => e.style.display = wa === 'ozel' ? '' : 'none');
    }
    document.querySelectorAll('.saglayici').forEach(s => s.addEventListener('change', goster)); goster();
})();
</script>
<?php $sayfaJs = ob_get_clean(); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
