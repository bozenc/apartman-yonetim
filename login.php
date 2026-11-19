<?php
require __DIR__ . '/includes/init.php';

if (kullanici()) redirect('index.php');

/** Tek kullanımlık (şifre değişince kendiliğinden geçersiz olan) şifre sıfırlama anahtarı */
function sifre_sifirlama_token(array $u, int $dakika = 30): string
{
    $son = time() + $dakika * 60;
    $ozet = substr(hash('sha256', (string)$u['sifre']), 0, 12);
    $yuk = $u['id'] . '|' . $ozet . '|' . $son;
    $imza = substr(b64url(hash_hmac('sha256', 'sifre_sifirla|' . $yuk, gizli_anahtar(), true)), 0, 24);
    return b64url($yuk) . '.' . $imza;
}

function sifre_sifirlama_dogrula(?string $t): ?array
{
    global $pdo;
    if (!$t || !str_contains($t, '.')) return null;
    [$yukB, $imza] = explode('.', $t, 2);
    $yuk = b64url_coz($yukB);
    $beklenen = substr(b64url(hash_hmac('sha256', 'sifre_sifirla|' . $yuk, gizli_anahtar(), true)), 0, 24);
    if (!hash_equals($beklenen, $imza)) return null;
    $p = explode('|', $yuk);
    if (count($p) !== 3 || (int)$p[2] < time()) return null;
    $st = $pdo->prepare('SELECT * FROM kullanicilar WHERE id = ? AND aktif = 1');
    $st->execute([(int)$p[0]]);
    $u = $st->fetch();
    if (!$u || !hash_equals(substr(hash('sha256', (string)$u['sifre']), 0, 12), $p[1])) return null;
    return $u;
}

$sifirlamaKullanici = isset($_GET['sifirla']) ? sifre_sifirlama_dogrula((string)$_GET['sifirla']) : null;
if (isset($_GET['sifirla']) && !$sifirlamaKullanici) {
    flash('danger', 'Şifre sıfırlama bağlantısı geçersiz veya süresi dolmuş.');
    redirect('login.php');
}

if (is_post()) {
    csrf_check();
    $islem = post('islem', 'giris');

    if ($islem === 'unuttum') {
        $aranan = trim((string)post('hesap', ''));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $st = $pdo->prepare("SELECT COUNT(*) FROM islem_log WHERE islem = 'Şifre Sıfırlama İsteği' AND ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() >= 3) {
            flash('warning', 'Çok fazla şifre sıfırlama isteği gönderildi. Lütfen 15 dakika sonra tekrar deneyin.');
            redirect('login.php');
        }
        $st = $pdo->prepare('SELECT * FROM kullanicilar WHERE (kullanici_adi = ? OR eposta = ?) AND aktif = 1 LIMIT 1');
        $st->execute([$aranan, $aranan]);
        $hedef = $st->fetch();
        logla('Şifre Sıfırlama İsteği', $aranan);
        if ($hedef) {
            $link = site_kok_url() . '/login.php?sifirla=' . urlencode(sifre_sifirlama_token($hedef, 30));
            $gonderildi = 0;
            if (eposta_aktif() && $hedef['eposta'] && filter_var($hedef['eposta'], FILTER_VALIDATE_EMAIL)) {
                $konu = 'Şifre Sıfırlama · ' . ayar('site_adi');
                $gonderildi += eposta_kuyruga([$hedef['eposta'] => $hedef['ad_soyad']], $konu, fn($ad) => eposta_sablon(
                    'Şifre Sıfırlama İsteği', selamla($ad),
                    'Hesabınız için şifre sıfırlama talebinde bulunuldu. Yeni şifrenizi belirlemek için aşağıdaki düğmeye tıklayın (30 dakika geçerlidir).',
                    [['Kullanıcı Adı', $hedef['kullanici_adi']], ['Geçerlilik', '30 dakika (tek kullanımlık)']],
                    'Yeni Şifre Belirle', $link, 'Bu isteği siz yapmadıysanız bu e-postayı dikkate almayınız.', '', '#4f46e5'
                ), 'genel', (int)$hedef['id']);
            }
            if ((sms_aktif() || wa_aktif()) && ($tel = telefon_normal($hedef['telefon'] ?? ''))) {
                $metin = ayar('site_adi') . ': Şifrenizi sıfırlamak için bağlantı (30 dk geçerli): ' . $link;
                if (sms_aktif()) $gonderildi += mesaj_kuyruga('sms', [$tel => $hedef['ad_soyad']], $metin, 'genel', (int)$hedef['id']);
                elseif (wa_aktif()) $gonderildi += mesaj_kuyruga('whatsapp', [$tel => $hedef['ad_soyad']], $metin, 'genel', (int)$hedef['id']);
            }
        }
        flash('info', 'Hesabınızda kayıtlı e-posta veya telefon numarası varsa şifre sıfırlama bağlantısı gönderilmiştir.');
        redirect('login.php');
    }

    if ($islem === 'yeni_sifre' && $sifirlamaKullanici) {
        $s1 = (string)($_POST['yeni_sifre'] ?? '');
        $s2 = (string)($_POST['yeni_sifre2'] ?? '');
        if (strlen($s1) < 8) {
            flash('danger', 'Yeni şifre en az 8 karakter olmalıdır.');
            redirect('login.php?sifirla=' . urlencode((string)$_GET['sifirla']));
        }
        if ($s1 !== $s2) {
            flash('danger', 'Şifreler eşleşmiyor.');
            redirect('login.php?sifirla=' . urlencode((string)$_GET['sifirla']));
        }
        $pdo->prepare('UPDATE kullanicilar SET sifre = ? WHERE id = ?')->execute([password_hash($s1, PASSWORD_DEFAULT), $sifirlamaKullanici['id']]);
        logla('Şifre sıfırlandı', $sifirlamaKullanici['kullanici_adi']);
        flash('success', 'Şifreniz başarıyla güncellendi. Yeni şifrenizle giriş yapabilirsiniz.');
        redirect('login.php');
    }

    if (giris_yap((string)post('kullanici_adi', ''), (string)($_POST['sifre'] ?? ''))) {
        $donus = $_SESSION['donus'] ?? '';
        unset($_SESSION['donus']);
        // Yalnızca site içi yönlendirmeye izin ver
        if ($donus && $donus[0] === '/' && strpos($donus, '//') !== 0) {
            header('Location: ' . $donus);
            exit;
        }
        redirect('index.php');
    }
    redirect('login.php');
}
$siteAdi = ayar('site_adi', 'Apartman Yönetimi');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giriş · <?= e($siteAdi) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
    <link rel="manifest" href="<?= url('manifest.json') ?>">
    <meta name="theme-color" content="#4f46e5">
    <script>try{if((localStorage.getItem('tema')||sessionStorage.getItem('tema'))==='dark')document.documentElement.setAttribute('data-bs-theme','dark')}catch(e){}</script>
</head>
<body>
<div class="auth-wrap">
    <section class="auth-hero">
        <div class="d-flex align-items-center gap-3">
            <span class="brand-logo"><i class="bi bi-buildings-fill"></i></span>
            <strong class="fs-5"><?= e($siteAdi) ?></strong>
        </div>
        <div>
            <h2>Apartmanınızı tek<br>panelden yönetin.</h2>
            <p class="mt-3">Aidat takibi, tahsilat, gelir-gider kayıtları, raporlar ve rol tabanlı kullanıcı yönetimi — hepsi bir arada.</p>
            <div class="auth-feature"><i class="bi bi-calendar2-check"></i> Toplu aidat borçlandırma ve tahsilat</div>
            <div class="auth-feature"><i class="bi bi-graph-up-arrow"></i> Anlık gelir-gider ve kasa raporları</div>
            <div class="auth-feature"><i class="bi bi-shield-check"></i> Esnek rol ve yetki yönetimi</div>
        </div>
        <small class="text-secondary">© <?= date('Y') ?> Apartman Yönetim Sistemi</small>
    </section>

    <section class="auth-form">
        <div class="auth-box">
            <?php if ($sifirlamaKullanici): ?>
                <h3 class="mb-1">Yeni Şifre Belirle 🔑</h3>
                <p class="text-muted mb-4"><strong>@<?= e($sifirlamaKullanici['kullanici_adi']) ?></strong> hesabınız için yeni şifrenizi girin.</p>
                <?php foreach (flashes() as $f): ?>
                    <div class="alert alert-<?= e($f['tur']) ?>"><?= e($f['mesaj']) ?></div>
                <?php endforeach; ?>
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?><input type="hidden" name="islem" value="yeni_sifre">
                    <div class="mb-3">
                        <label class="form-label">Yeni Şifre (en az 8 karakter)</label>
                        <input type="password" name="yeni_sifre" class="form-control" minlength="8" required autofocus autocomplete="new-password">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Yeni Şifre (Tekrar)</label>
                        <input type="password" name="yeni_sifre2" class="form-control" minlength="8" required autocomplete="new-password">
                    </div>
                    <button class="btn btn-primary w-100 py-2" style="height:48px"><i class="bi bi-check2-circle me-2"></i>Şifremi Güncelle</button>
                    <div class="text-center mt-3"><a href="<?= url('login.php') ?>" class="small">Giriş ekranına dön</a></div>
                </form>
            <?php else: ?>
                <h3 class="mb-1">Hoş geldiniz 👋</h3>
                <p class="text-muted mb-4">Devam etmek için hesabınıza giriş yapın.</p>

                <?php foreach (flashes() as $f): ?>
                    <div class="alert alert-<?= e($f['tur']) ?>"><?= e($f['mesaj']) ?></div>
                <?php endforeach; ?>

                <form method="post" autocomplete="on">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Kullanıcı adı veya e-posta</label>
                        <div class="input-icon">
                            <i class="bi bi-person"></i>
                            <input type="text" name="kullanici_adi" class="form-control" required autofocus>
                        </div>
                    </div>
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <label class="form-label">Şifre</label>
                            <?php if (herhangi_bildirim_aktif()): ?>
                                <a href="#" class="small text-muted mb-1" data-bs-toggle="modal" data-bs-target="#unuttumModal">Şifremi unuttum</a>
                            <?php endif; ?>
                        </div>
                        <div class="input-icon">
                            <i class="bi bi-lock"></i>
                            <input type="password" name="sifre" class="form-control" required>
                        </div>
                    </div>
                    <button class="btn btn-primary w-100 py-2" style="height:48px"><i class="bi bi-box-arrow-in-right me-2"></i>Giriş Yap</button>
                </form>
            <?php endif; ?>
            <p class="small text-muted mt-4 mb-0">Giriş yaparak <a href="<?= url('yasal.php?metin=sozlesme') ?>">Kullanım Koşulları</a>'nı kabul etmiş ve
                <a href="<?= url('yasal.php?metin=kvkk') ?>">KVKK Aydınlatma Metni</a>'ni okumuş olursunuz.</p>
            <div class="yasal-alt mt-3"><?= yasal_linkler() ?></div>
        </div>
    </section>
</div>
<?php if (herhangi_bildirim_aktif() && !$sifirlamaKullanici): ?>
<div class="modal fade" id="unuttumModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="unuttum">
                <div class="modal-header"><h6 class="modal-title fw-bold">Şifre Sıfırlama</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="small text-muted">Kullanıcı adınızı veya kayıtlı e-posta adresinizi girin; sıfırlama bağlantısını iletelim.</p>
                    <input type="text" name="hesap" class="form-control" placeholder="Kullanıcı adı veya e-posta" required>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary btn-sm">Gönder</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?= cerez_bandi() ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= url('assets/js/app.js') ?>"></script>
</body>
</html>
