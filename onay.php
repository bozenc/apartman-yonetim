<?php
/** İlk girişte / metin güncellendiğinde kullanım koşulları ve KVKK onayı */
require __DIR__ . '/includes/init.php';
$u = giris_zorunlu();

$bekleyen = yasal_bekleyenler((int)$u['id']);
$onaylar = kullanici_onaylari((int)$u['id']);
$rizaSor = wa_aktif() || ayar('sms_saglayici') === 'twilio' || !isset($onaylar['acik_riza']);

if (is_post()) {
    csrf_check();
    $eksik = array_filter($bekleyen, fn($a) => !post('onay_' . $a));
    if ($eksik) {
        flash('danger', 'Devam etmek için işaretli zorunlu metinleri onaylamanız gerekir.');
        redirect('onay.php');
    }
    foreach ($bekleyen as $a) onay_kaydet((int)$u['id'], $a);
    if ($rizaSor && ($onaylar['acik_riza']['durum'] ?? '') !== (post('acik_riza') ? 'onay' : 'geri_alindi')) {
        onay_kaydet((int)$u['id'], 'acik_riza', post('acik_riza') ? 'onay' : 'geri_alindi');
    }
    logla('Yasal metinler onaylandı', implode(', ', $bekleyen) . (post('acik_riza') ? ' + açık rıza' : ''));
    unset($_SESSION['yasal_tamam']);
    $donus = $_SESSION['donus'] ?? '';
    unset($_SESSION['donus']);
    if ($donus && $donus[0] === '/' && strpos($donus, '//') !== 0 && !str_contains($donus, 'onay.php')) { header('Location: ' . $donus); exit; }
    redirect('index.php');
}
if (!$bekleyen) redirect('index.php');

$guncelleme = (bool)array_filter($bekleyen, fn($a) => isset($onaylar[$a]));
$sadeBaslik = 'Onay';
$sadeSag = '<a href="' . url('logout.php?_csrf=' . csrf_token()) . '" class="btn btn-light btn-sm"><i class="bi bi-box-arrow-right me-1"></i>Çıkış</a>';
require __DIR__ . '/includes/sade_ust.php';
?>
<div class="card"><div class="card-body p-4 p-md-5">
    <h3 class="fw-bold mb-1"><?= $guncelleme ? 'Metinlerimiz güncellendi' : 'Hoş geldiniz, ' . e(explode(' ', $u['ad_soyad'])[0]) ?></h3>
    <p class="text-muted"><?= $guncelleme ? 'Devam etmeden önce güncellenen metinleri okuyup onaylamanızı rica ederiz.' : 'Sistemi kullanmaya başlamadan önce aşağıdaki metinleri okuyup onaylamanız gerekir.' ?></p>
    <?php foreach (flashes() as $f): ?><div class="alert alert-<?= e($f['tur']) ?>"><?= e($f['mesaj']) ?></div><?php endforeach; ?>
    <form method="post"><?= csrf_field() ?>
        <?php foreach ($bekleyen as $a): $m = yasal_metin($a); ?>
        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="fw-bold mb-0"><?= e($m['baslik']) ?> <span class="text-muted fw-normal small">· sürüm <?= (int)$m['surum'] ?></span></h6>
                <a href="<?= url('yasal.php?metin=' . $a) ?>" target="_blank" class="small"><i class="bi bi-box-arrow-up-right"></i> Tam sayfa</a>
            </div>
            <div class="yasal-kutu yasal-metin"><?= yasal_html(yasal_doldur($m['icerik'], $m['updated_at'])) ?></div>
            <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="onay_<?= $a ?>" value="1" id="o_<?= $a ?>" required>
                <label class="form-check-label fw-semibold" for="o_<?= $a ?>"><?= $a === 'kvkk' ? 'KVKK Aydınlatma Metni\'ni okudum, bilgilendirildim.' : e($m['baslik']) . '\'nı okudum ve kabul ediyorum.' ?> <span class="text-danger">*</span></label>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if ($rizaSor): $r = yasal_metin('acik_riza'); ?>
        <div class="p-3 rounded-3 mb-4" style="background:var(--brand-soft)">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="acik_riza" value="1" id="oRiza" <?= ($onaylar['acik_riza']['durum'] ?? '') === 'onay' ? 'checked' : '' ?>>
                <label class="form-check-label" for="oRiza"><strong>İsteğe bağlı:</strong> Bildirimlerin WhatsApp gibi sunucuları yurt dışında bulunan kanallarla gönderilebilmesi için
                    <a href="<?= url('yasal.php?metin=acik_riza') ?>" target="_blank">Açık Rıza Metni</a>'ni onaylıyorum.</label>
            </div>
            <div class="small text-muted mt-1 ms-4">Onaylamasanız da sistemi kullanabilirsiniz. Kararınızı Profilim › Gizlilik bölümünden istediğiniz an değiştirebilirsiniz.</div>
        </div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <span class="small text-muted"><i class="bi bi-shield-lock"></i> Onayınız tarih, IP ve tarayıcı bilgisiyle kayıt altına alınır.</span>
            <button class="btn btn-primary px-4"><i class="bi bi-check2-circle me-1"></i>Onayla ve devam et</button>
        </div>
    </form>
</div></div>
<?php require __DIR__ . '/includes/sade_alt.php'; ?>
