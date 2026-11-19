<?php
require __DIR__ . '/includes/init.php';
$u = giris_zorunlu();

if (is_post()) {
    csrf_check();
    $islem = post('islem');
    if ($islem === 'bilgi') {
        $eposta = post('eposta') ?: null;
        if ($eposta && !filter_var($eposta, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Geçerli bir e-posta girin.');
        } elseif (post('ad_soyad') === '') {
            flash('danger', 'Ad soyad boş olamaz.');
        } else {
            $pdo->prepare('UPDATE kullanicilar SET ad_soyad = ?, eposta = ?, telefon = ?, eposta_bildirim = ?, mesaj_bildirim = ? WHERE id = ?')
                ->execute([post('ad_soyad'), $eposta, post('telefon') ?: null, post('eposta_bildirim') ? 1 : 0, post('mesaj_bildirim') ? 1 : 0, $u['id']]);
            flash('success', 'Profil bilgileriniz güncellendi.');
        }
    }
    if ($islem === 'sifre') {
        $mevcut = (string)($_POST['mevcut'] ?? '');
        $yeni = (string)($_POST['yeni'] ?? '');
        $tekrar = (string)($_POST['tekrar'] ?? '');
        if (!password_verify($mevcut, $u['sifre'])) flash('danger', 'Mevcut şifre hatalı.');
        elseif (strlen($yeni) < 8) flash('danger', 'Yeni şifre en az 8 karakter olmalıdır.');
        elseif ($yeni !== $tekrar) flash('danger', 'Yeni şifreler eşleşmiyor.');
        else {
            $pdo->prepare('UPDATE kullanicilar SET sifre = ? WHERE id = ?')->execute([password_hash($yeni, PASSWORD_DEFAULT), $u['id']]);
            session_regenerate_id(true);
            logla('Şifre değiştirildi', $u['kullanici_adi']);
            flash('success', 'Şifreniz değiştirildi.');
        }
    }
    if ($islem === 'riza') {
        $durum = post('riza') === 'ver' ? 'onay' : 'geri_alindi';
        onay_kaydet((int)$u['id'], 'acik_riza', $durum);
        logla($durum === 'onay' ? 'Açık rıza verildi' : 'Açık rıza geri alındı', $u['kullanici_adi']);
        flash('success', $durum === 'onay' ? 'Açık rızanız kaydedildi.' : 'Açık rızanız geri alındı; size artık WhatsApp gibi yurt dışı sunuculu kanallardan bildirim gönderilmeyecek.');
    }
    redirect('profil.php' . ($islem === 'riza' ? '#gizlilik' : ''));
}

$onaylar = kullanici_onaylari((int)$u['id']);
$sayfaBaslik = 'Profilim';
$sayfaAciklama = 'Hesap bilgilerinizi ve şifrenizi yönetin';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card text-center"><div class="card-body py-4">
            <span class="avatar mx-auto" style="width:72px;height:72px;font-size:1.8rem;border-radius:18px"><?= e(mb_strtoupper(mb_substr($u['ad_soyad'], 0, 1))) ?></span>
            <h5 class="fw-bold mt-3 mb-0"><?= e($u['ad_soyad']) ?></h5>
            <div class="text-muted small">@<?= e($u['kullanici_adi']) ?></div>
            <span class="badge-soft <?= e($u['rol_renk']) ?> mt-2"><?= e($u['rol_adi']) ?></span>
            <hr>
            <div class="small text-muted">Son giriş: <?= $u['son_giris'] ? tarih($u['son_giris'], true) : '-' ?></div>
            <div class="small text-muted">Kayıt: <?= tarih($u['created_at']) ?></div>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><h5>Kişisel Bilgiler</h5></div>
            <div class="card-body">
                <form method="post" class="row g-3"><?= csrf_field() ?><input type="hidden" name="islem" value="bilgi">
                    <div class="col-md-6"><label class="form-label">Ad Soyad</label><input name="ad_soyad" class="form-control" value="<?= e($u['ad_soyad']) ?>" required></div>
                    <div class="col-md-6"><label class="form-label">Kullanıcı Adı</label><input class="form-control" value="<?= e($u['kullanici_adi']) ?>" disabled></div>
                    <div class="col-md-6"><label class="form-label">E-posta</label><input type="email" name="eposta" class="form-control" value="<?= e($u['eposta']) ?>"></div>
                    <div class="col-md-6"><label class="form-label">Telefon</label><input name="telefon" class="form-control" value="<?= e($u['telefon']) ?>"></div>
                    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="eposta_bildirim" value="1" id="epB" <?= (int)$u['eposta_bildirim'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="epB">Makbuz, ödeme bildirimi sonucu ve duyuruları e-posta ile almak istiyorum</label></div>
                        <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="mesaj_bildirim" value="1" id="msB" <?= (int)($u['mesaj_bildirim'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="msB">Aynı bildirimleri SMS / WhatsApp ile cep telefonuma almak istiyorum</label></div></div>
                    <div class="col-12 text-end"><button class="btn btn-primary">Kaydet</button></div>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h5>Şifre Değiştir</h5></div>
            <div class="card-body">
                <form method="post" class="row g-3"><?= csrf_field() ?><input type="hidden" name="islem" value="sifre">
                    <div class="col-md-4"><label class="form-label">Mevcut Şifre</label><input type="password" name="mevcut" class="form-control" required autocomplete="current-password"></div>
                    <div class="col-md-4"><label class="form-label">Yeni Şifre</label><input type="password" name="yeni" class="form-control" minlength="8" required autocomplete="new-password"></div>
                    <div class="col-md-4"><label class="form-label">Yeni Şifre (Tekrar)</label><input type="password" name="tekrar" class="form-control" minlength="8" required autocomplete="new-password"></div>
                    <div class="col-12 text-end"><button class="btn btn-primary"><i class="bi bi-key me-1"></i>Şifreyi Güncelle</button></div>
                </form>
            </div>
        </div>
        <div class="card mt-3" id="gizlilik">
            <div class="card-header"><h5><i class="bi bi-shield-lock me-2"></i>Gizlilik ve Onaylarım</h5>
                <a href="<?= url('veri_indir.php') ?>" class="btn btn-sm btn-soft-primary"><i class="bi bi-download me-1"></i>Verilerimi indir (JSON)</a></div>
            <div class="table-responsive"><table class="table mb-0">
                <thead><tr><th>Metin</th><th>Durum</th><th>Tarih</th><th></th></tr></thead>
                <tbody>
                <?php foreach (YASAL_SIRA as $a): if ($a === 'gizlilik' || $a === 'cerez') continue; $m = yasal_metin($a); $o = $onaylar[$a] ?? null; ?>
                    <tr>
                        <td class="fw-semibold"><?= e($m['baslik']) ?><div class="small text-muted fw-normal">Güncel sürüm <?= (int)$m['surum'] ?></div></td>
                        <td><?php if (!$o): ?><span class="badge-soft secondary">Yanıt yok</span>
                            <?php elseif ($o['durum'] === 'geri_alindi'): ?><span class="badge-soft warning">Rıza yok</span>
                            <?php elseif ((int)$o['surum'] < (int)$m['surum']): ?><span class="badge-soft warning">Eski sürüm onaylı (v<?= (int)$o['surum'] ?>)</span>
                            <?php else: ?><span class="badge-soft success"><i class="bi bi-check2"></i> <?= $a === 'kvkk' ? 'Okundu' : 'Onaylandı' ?> · v<?= (int)$o['surum'] ?></span><?php endif; ?></td>
                        <td class="small text-muted"><?= $o ? tarih($o['created_at'], true) : '-' ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= url('yasal.php?metin=' . $a) ?>" target="_blank" class="btn btn-sm btn-light">Oku</a>
                            <?php if ($a === 'acik_riza'): ?>
                            <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="islem" value="riza">
                                <?php if (($o['durum'] ?? '') === 'onay'): ?><button name="riza" value="geri" class="btn btn-sm btn-soft-danger">Rızamı geri al</button>
                                <?php else: ?><button name="riza" value="ver" class="btn btn-sm btn-soft-success">Rıza ver</button><?php endif; ?></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="card-body small text-muted border-top">
                KVKK md. 11 kapsamındaki haklarınız için (bilgi talebi, düzeltme, silme vb.) <strong><?= e(ayar('kvkk_eposta') ?: ayar('eposta')) ?></strong> adresine yazabilirsiniz.
                Ayrıntılar: <a href="<?= url('yasal.php?metin=kvkk') ?>">KVKK Aydınlatma Metni</a> · <a href="<?= url('yasal.php?metin=gizlilik') ?>">Gizlilik Politikası</a> · <a href="<?= url('yasal.php?metin=cerez') ?>">Çerez Politikası</a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
