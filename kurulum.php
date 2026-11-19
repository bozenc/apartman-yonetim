<?php
/**
 * Apartman Yönetim Sistemi - Kurulum Sihirbazı
 * Sistem kurulu değilse siteye ilk girişte otomatik açılır.
 * Kurulum tamamlanınca "kurulum.kilit" dosyası oluşturulur ve sihirbaz devre dışı kalır.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/guncelleme.php';

ini_set('display_errors', '0');
session_name('APTYS');
session_start();
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');

const SURUM = DB_SURUM;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function git(string $q = ''): void { header('Location: kurulum.php' . $q); exit; }

function baglan(string $host, string $port, string $user, string $pass, ?string $db = null): PDO
{
    $dsn = 'mysql:host=' . $host . ';port=' . $port . ($db !== null ? ';dbname=' . $db : '') . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

/* ------------------------------------------------------------------
   Kurulu mu? (kilit dosyası var ya da mevcut ayarlarla tam şema mevcut)
   ------------------------------------------------------------------ */
$kurulu = is_file(KURULUM_KILIT);
if (!$kurulu && is_file(__DIR__ . '/config.local.php')) {
    try {
        $kurulu = in_array(vt_durum(baglan(DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME)), ['v11', 'v12', 'v13', 'v14', 'v15'], true); // açılışta otomatik güncellenir
    } catch (Throwable $e) {}
}
$tamamlandi = !empty($_SESSION['kurulum_bitti']);

if ($tamamlandi && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['islem'] ?? '') === 'sihirbaz_sil') {
    if (is_file(KURULUM_KILIT)) {
        @unlink(__FILE__);
    }
    unset($_SESSION['kurulum_bitti']);
    header('Location: login.php');
    exit;
}

if ($kurulu && !$tamamlandi) {
    $adim = 0;
} elseif ($tamamlandi) {
    $adim = 4;
} else {
    $k = &$_SESSION['kur'];
    $k = $k ?? ['adim' => 1];
    if (empty($_SESSION['kur_token'])) $_SESSION['kur_token'] = bin2hex(random_bytes(16));

    // Geri dönüş
    if (isset($_GET['adim']) && (int)$_GET['adim'] >= 1 && (int)$_GET['adim'] < $k['adim']) $k['adim'] = (int)$_GET['adim'];
    $adim = $k['adim'];
    $hata = '';

    /* ---------- Gereksinimler ---------- */
    if (!is_dir(__DIR__ . '/uploads/dekontlar')) {
        @mkdir(__DIR__ . '/uploads/dekontlar', 0755, true);
    }
    $klasorYazilabilir = is_writable(__DIR__);
    $gereksinim = [
        ['PHP sürümü 8.0 veya üzeri (mevcut: ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.0.0', '>='), true],
        ['PDO MySQL eklentisi (pdo_mysql)', extension_loaded('pdo_mysql'), true],
        ['mbstring eklentisi', extension_loaded('mbstring'), true],
        ['JSON eklentisi', function_exists('json_encode'), true],
        ['OpenSSL eklentisi (AES-256-GCM şifreleme ve güvenli SMTP için)', extension_loaded('openssl'), false],
        ['kurulum.sql dosyası okunabilir', is_readable(__DIR__ . '/kurulum.sql'), true],
        ['Uygulama klasörü yazılabilir (config.local.php için)', $klasorYazilabilir, false],
        ['uploads/dekontlar klasörü yazılabilir (dekont yükleme için)', is_writable(__DIR__ . '/uploads/dekontlar'), false],
    ];
    $gereksinimTamam = !in_array(false, array_map(fn($g) => $g[1] || !$g[2], $gereksinim), true);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['kur_token'], (string)($_POST['_t'] ?? ''))) git();

        /* ---------- 1 → 2 ---------- */
        if ($adim === 1 && $gereksinimTamam) { $k['adim'] = 2; git(); }

        /* ---------- 2: Veritabanı ---------- */
        if ($adim === 2) {
            $db = [
                'host' => trim($_POST['host'] ?? 'localhost') ?: 'localhost',
                'port' => trim($_POST['port'] ?? '3306') ?: '3306',
                'name' => trim($_POST['name'] ?? ''),
                'user' => trim($_POST['user'] ?? ''),
                'pass' => (string)($_POST['pass'] ?? ''),
            ];
            $k['db'] = $db;
            if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $db['name'])) {
                $hata = 'Veritabanı adı yalnızca harf, rakam, alt çizgi ve tire içerebilir.';
            } elseif ($db['user'] === '') {
                $hata = 'Veritabanı kullanıcı adı zorunludur.';
            } else {
                try {
                    $sunucu = baglan($db['host'], $db['port'], $db['user'], $db['pass']);
                    try {
                        $sunucu->exec('CREATE DATABASE IF NOT EXISTS `' . $db['name'] . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci');
                    } catch (PDOException $e) {
                        // Paylaşımlı hostinglerde yetki olmayabilir; veritabanı panelden açılmış olmalı
                    }
                    $pdo = baglan($db['host'], $db['port'], $db['user'], $db['pass'], $db['name']);
                    $k['durum'] = vt_durum($pdo);
                    $k['adim'] = 3;
                    git();
                } catch (PDOException $e) {
                    $m = $e->getMessage();
                    if (str_contains($m, '1045')) $hata = 'Kullanıcı adı veya şifre hatalı (MySQL erişimi reddedildi).';
                    elseif (str_contains($m, '1049') || str_contains($m, '1044')) $hata = '"' . $db['name'] . '" veritabanı bulunamadı ve oluşturulamadı. Hosting panelinizden (cPanel/Plesk) veritabanını oluşturup kullanıcıya yetki verin, ardından tekrar deneyin.';
                    elseif (str_contains($m, '2002') || str_contains($m, '2003') || str_contains($m, '2006')) $hata = 'Veritabanı sunucusuna ulaşılamadı. MySQL/MariaDB servisinin çalıştığından ve sunucu adresinin doğru olduğundan emin olun (XAMPP kullanıyorsanız kontrol panelinden MySQL\'i başlatın).';
                    else $hata = 'Bağlantı hatası: ' . $m;
                }
            }
        }

        /* ---------- 3: Site + yönetici + kurulum ---------- */
        if ($adim === 3) {
            $db = $k['db'];
            $durum = $k['durum'];
            $mod = $_POST['mod'] ?? ($durum === 'bos' ? 'yeni' : ($durum === 'v15' ? 'mevcut' : 'guncelle'));
            if ($durum === 'bos') $mod = 'yeni';
            $f = [
                'site_adi' => trim($_POST['site_adi'] ?? ''),
                'adres'    => trim($_POST['adres'] ?? ''),
                'telefon'  => trim($_POST['telefon'] ?? ''),
                'iban'     => trim($_POST['iban'] ?? ''),
                'aidat'    => trim($_POST['aidat'] ?? ''),
                'ad'       => trim($_POST['ad'] ?? ''),
                'kadi'     => mb_strtolower(trim($_POST['kadi'] ?? '')),
                'eposta'   => trim($_POST['eposta'] ?? ''),
                'ornek'    => !empty($_POST['ornek']),
            ];
            $k['form'] = $f; $k['mod'] = $mod;
            $sifre = (string)($_POST['sifre'] ?? '');

            if ($mod === 'yeni') {
                if ($f['site_adi'] === '') $hata = 'Site / apartman adı zorunludur.';
                elseif ($f['ad'] === '') $hata = 'Yönetici ad soyad zorunludur.';
                elseif (!preg_match('/^[a-z0-9._-]{3,60}$/', $f['kadi'])) $hata = 'Kullanıcı adı en az 3 karakter olmalı; yalnızca a-z, 0-9, nokta, alt çizgi ve tire içerebilir.';
                elseif ($f['eposta'] !== '' && !filter_var($f['eposta'], FILTER_VALIDATE_EMAIL)) $hata = 'Geçerli bir e-posta adresi girin.';
                elseif (strlen($sifre) < 8) $hata = 'Yönetici şifresi en az 8 karakter olmalıdır.';
                elseif ($sifre !== (string)($_POST['sifre2'] ?? '')) $hata = 'Şifreler eşleşmiyor.';
                elseif ($durum !== 'bos' && empty($_POST['onay'])) $hata = 'Mevcut tabloların silineceğini onaylamanız gerekiyor.';
            }

            if (!$hata) {
                try {
                    $pdo = baglan($db['host'], $db['port'], $db['user'], $db['pass'], $db['name']);

                    if ($mod === 'yeni') {
                        $sql = file_get_contents(__DIR__ . '/kurulum.sql');
                        if (!$f['ornek']) {
                            $p = strpos($sql, '-- Örnek veriler');
                            if ($p !== false) $sql = substr($sql, 0, $p);
                        }
                        sql_calistir($pdo, $sql);
                        $pdo->prepare('UPDATE kullanicilar SET ad_soyad = ?, kullanici_adi = ?, eposta = ?, sifre = ? WHERE id = 1')
                            ->execute([$f['ad'], $f['kadi'], $f['eposta'] ?: null, password_hash($sifre, PASSWORD_DEFAULT)]);
                        $ayar = $pdo->prepare('UPDATE ayarlar SET deger = ? WHERE anahtar = ?');
                        $ayar->execute([$f['site_adi'], 'site_adi']);
                        $ayar->execute([$f['adres'], 'adres']);
                        $ayar->execute([$f['telefon'], 'telefon']);
                        $ayar->execute([$f['eposta'], 'eposta']);
                        $ayar->execute([$f['iban'], 'iban']);
                        $aidat = (float)str_replace(',', '.', str_replace('.', '', $f['aidat']));
                        if ($aidat > 0) $ayar->execute([(string)$aidat, 'varsayilan_aidat']);
                        if (!empty($_SERVER['HTTP_HOST']) && !preg_match('/^(localhost|127\.|192\.168\.|10\.)/i', $_SERVER['HTTP_HOST'])) {
                            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
                            $algilananUrl = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . BASE_URL;
                            $ayar->execute([$algilananUrl, 'site_url']);
                        }
                    } elseif ($mod === 'guncelle') {
                        veritabani_guncelle($pdo);
                    }
                    // mod === 'mevcut': yalnızca yapılandırma yazılır

                    // Gizli anahtar (mevcut varsa koru, yoksa kriptografik üret)
                    $gizliAnahtar = (defined('GIZLI_ANAHTAR') && strlen((string)GIZLI_ANAHTAR) >= 32) ? GIZLI_ANAHTAR : '';
                    if ($gizliAnahtar === '') {
                        try {
                            $gizliAnahtar = (string)$pdo->query("SELECT deger FROM ayarlar WHERE anahtar = 'gizli_anahtar'")->fetchColumn();
                        } catch (Throwable $e) {}
                    }
                    if (strlen($gizliAnahtar) < 32) {
                        $gizliAnahtar = bin2hex(random_bytes(32));
                    }
                    $pdo->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('gizli_anahtar', ?) ON DUPLICATE KEY UPDATE deger = IF(deger = '' OR deger IS NULL, VALUES(deger), deger)")
                        ->execute([$gizliAnahtar]);

                    $pdo->prepare('INSERT INTO islem_log (kullanici_id, islem, detay, ip) VALUES (1, ?, ?, ?)')
                        ->execute(['Kurulum', 'Kurulum sihirbazı tamamlandı (v' . SURUM . ', ' . $mod . ')', $_SERVER['REMOTE_ADDR'] ?? null]);

                    // config.local.php
                    $icerik = "<?php\n// Kurulum sihirbazı tarafından oluşturuldu: " . date('d.m.Y H:i') . "\n"
                        . "define('DB_HOST', " . var_export($db['host'], true) . ");\n"
                        . "define('DB_PORT', " . var_export($db['port'], true) . ");\n"
                        . "define('DB_NAME', " . var_export($db['name'], true) . ");\n"
                        . "define('DB_USER', " . var_export($db['user'], true) . ");\n"
                        . "define('DB_PASS', " . var_export($db['pass'], true) . ");\n"
                        . "define('GIZLI_ANAHTAR', " . var_export($gizliAnahtar, true) . ");\n";
                    $yazildi = @file_put_contents(__DIR__ . '/config.local.php', $icerik) !== false;
                    $kilit = @file_put_contents(KURULUM_KILIT, 'Kuruldu: ' . date('c') . PHP_EOL) !== false;

                    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
                    $kokUrl = (!empty($_SERVER['HTTP_HOST']) ? (($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) : 'http://localhost') . BASE_URL;
                    $cronKey = substr(hash_hmac('sha256', 'apt_web_cron', $gizliAnahtar), 0, 24);

                    $_SESSION['kurulum_bitti'] = [
                        'mod' => $mod, 'kadi' => $mod === 'yeni' ? $f['kadi'] : null,
                        'config_yazildi' => $yazildi, 'config' => $yazildi ? null : $icerik, 'kilit' => $kilit,
                        'cron_cli' => '*/5 * * * * php ' . __DIR__ . '/cron_eposta.php >/dev/null 2>&1',
                        'cron_web' => '*/5 * * * * curl -fsS "' . $kokUrl . '/cron_eposta.php?key=' . $cronKey . '" >/dev/null 2>&1',
                    ];
                    unset($_SESSION['kur'], $_SESSION['kur_token']);
                    git();
                } catch (Throwable $e) {
                    $hata = 'Kurulum sırasında hata oluştu: ' . $e->getMessage();
                }
            }
        }
    }
}

$adimlar = [1 => 'Gereksinimler', 2 => 'Veritabanı', 3 => 'Site & Yönetici', 4 => 'Tamamlandı'];
$f = $_SESSION['kur']['form'] ?? [];
$db = $_SESSION['kur']['db'] ?? ['host' => 'localhost', 'port' => '3306', 'name' => 'apartman_db', 'user' => 'root', 'pass' => ''];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Kurulum · Apartman Yönetim Sistemi</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        .setup { max-width: 760px; margin: 40px auto; padding: 0 16px; }
        .setup-head { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
        .steps { display: flex; gap: 8px; margin-bottom: 20px; }
        .step { flex: 1; display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 12px; background: var(--card);
            border: 1px solid var(--border); font-size: .8rem; font-weight: 600; color: var(--muted); }
        .step b { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; background: var(--secondary-soft); font-size: .75rem; flex-shrink: 0; }
        .step.aktif { border-color: var(--brand); color: var(--brand); }
        .step.aktif b { background: var(--brand); color: #fff; }
        .step.bitti { color: var(--success); }
        .step.bitti b { background: var(--success); color: #fff; }
        .req { display: flex; justify-content: space-between; align-items: center; padding: 11px 0; border-bottom: 1px solid var(--border); }
        .req:last-child { border-bottom: 0; }
        .mod-kart { border: 2px solid var(--border); border-radius: 12px; padding: 14px 16px; cursor: pointer; display: block; }
        .mod-kart:has(input:checked) { border-color: var(--brand); background: var(--brand-soft); }
        @media (max-width: 575.98px) { .step span { display: none; } .step { justify-content: center; } }
    </style>
</head>
<body>
<div class="setup">
    <div class="setup-head">
        <span class="brand-logo"><i class="bi bi-buildings-fill"></i></span>
        <div><h4 class="fw-bold mb-0">Apartman Yönetim Sistemi</h4><div class="text-muted small">Kurulum Sihirbazı · v<?= SURUM ?></div></div>
    </div>

<?php if ($adim === 0): ?>
    <div class="card"><div class="card-body p-4 text-center">
        <div class="stat-icon soft-success mx-auto" style="width:64px;height:64px;font-size:1.8rem"><i class="bi bi-check2-circle"></i></div>
        <h4 class="fw-bold mt-3">Sistem zaten kurulu</h4>
        <p class="text-muted">Güvenlik nedeniyle kurulum sihirbazı devre dışı bırakıldı.<br>
            Yeniden kurulum yapmanız gerekiyorsa sunucudaki <code>kurulum.kilit</code> dosyasını silin.</p>
        <a href="index.php" class="btn btn-primary px-4"><i class="bi bi-box-arrow-in-right me-1"></i>Giriş sayfasına git</a>
    </div></div>
<?php else: ?>

    <div class="steps">
        <?php foreach ($adimlar as $n => $ad): ?>
            <div class="step <?= $n < $adim ? 'bitti' : ($n === $adim ? 'aktif' : '') ?>"><b><?= $n < $adim ? '<i class="bi bi-check"></i>' : $n ?></b><span><?= $ad ?></span></div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($hata)): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($hata) ?></div><?php endif; ?>

    <?php if ($adim === 1): ?>
    <div class="card">
        <div class="card-header"><h5>1. Sunucu Gereksinimleri</h5></div>
        <div class="card-body">
            <p class="text-muted">Hoş geldiniz! Birkaç adımda kurulumu tamamlayacağız. Önce sunucunuzun uygunluğunu kontrol ediyoruz.</p>
            <?php foreach ($gereksinim as [$ad, $ok, $zorunlu]): ?>
                <div class="req"><span><?= h($ad) ?><?= $zorunlu ? '' : ' <span class="text-muted small">(önerilen)</span>' ?></span>
                    <?= $ok ? '<span class="badge-soft success"><i class="bi bi-check-lg"></i> Uygun</span>' : ($zorunlu ? '<span class="badge-soft danger"><i class="bi bi-x-lg"></i> Eksik</span>' : '<span class="badge-soft warning"><i class="bi bi-exclamation"></i> Yazılamıyor</span>') ?></div>
            <?php endforeach; ?>
            <?php if (!$klasorYazilabilir): ?><div class="alert alert-warning small mt-3 mb-0">Klasör yazılabilir değil. Kurulum yine de yapılabilir; son adımda <code>config.local.php</code> dosyasının içeriği gösterilecek ve bunu elle oluşturmanız gerekecek.</div><?php endif; ?>
        </div>
        <div class="card-footer bg-transparent d-flex justify-content-end p-3">
            <form method="post"><input type="hidden" name="_t" value="<?= h($_SESSION['kur_token']) ?>">
                <button class="btn btn-primary px-4" <?= $gereksinimTamam ? '' : 'disabled' ?>>Devam <i class="bi bi-arrow-right ms-1"></i></button></form>
        </div>
    </div>

    <?php elseif ($adim === 2): ?>
    <form method="post" class="card">
        <input type="hidden" name="_t" value="<?= h($_SESSION['kur_token']) ?>">
        <div class="card-header"><h5>2. Veritabanı Bağlantısı</h5></div>
        <div class="card-body">
            <p class="text-muted small">MySQL / MariaDB bilgilerinizi girin. Veritabanı yoksa (yetkiniz varsa) otomatik oluşturulur.</p>
            <div class="row g-3">
                <div class="col-8"><label class="form-label">Sunucu</label><input name="host" class="form-control" value="<?= h($db['host']) ?>" required></div>
                <div class="col-4"><label class="form-label">Port</label><input name="port" class="form-control" value="<?= h($db['port']) ?>" inputmode="numeric"></div>
                <div class="col-12"><label class="form-label">Veritabanı Adı</label><input name="name" class="form-control" value="<?= h($db['name']) ?>" required></div>
                <div class="col-md-6"><label class="form-label">Kullanıcı Adı</label><input name="user" class="form-control" value="<?= h($db['user']) ?>" required autocomplete="off"></div>
                <div class="col-md-6"><label class="form-label">Şifre</label><input type="password" name="pass" class="form-control" value="<?= h($db['pass']) ?>" autocomplete="new-password"></div>
            </div>
            <div class="alert alert-info small mt-3 mb-0">
                <strong>XAMPP / WAMP / Laragon:</strong> sunucu <code>localhost</code>, kullanıcı <code>root</code>, şifre genellikle boş.<br>
                <strong>Hosting (cPanel/Plesk):</strong> veritabanını ve kullanıcıyı panelden oluşturup buraya o bilgileri yazın (çoğunlukla <code>hesapadi_apartman</code> biçimindedir).
            </div>
        </div>
        <div class="card-footer bg-transparent d-flex justify-content-between p-3">
            <a href="?adim=1" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Geri</a>
            <button class="btn btn-primary px-4"><i class="bi bi-plug me-1"></i>Bağlantıyı Test Et ve Devam</button>
        </div>
    </form>

    <?php elseif ($adim === 3): $durum = $_SESSION['kur']['durum']; $mod = $_SESSION['kur']['mod'] ?? ($durum === 'bos' ? 'yeni' : ($durum === 'v15' ? 'mevcut' : 'guncelle')); ?>
    <form method="post" class="card" id="kurForm">
        <input type="hidden" name="_t" value="<?= h($_SESSION['kur_token']) ?>">
        <div class="card-header"><h5>3. Site Bilgileri ve Yönetici Hesabı</h5>
            <span class="badge-soft success"><i class="bi bi-database-check"></i> <?= h($db['name']) ?> bağlandı</span></div>
        <div class="card-body">
            <?php if ($durum !== 'bos'): ?>
            <div class="alert alert-warning small"><i class="bi bi-info-circle me-1"></i>Bu veritabanında <?= $durum === 'v15' ? 'mevcut bir kuruluma ait' : 'önceki bir sürüme (v1.' . substr($durum, 2) . ') ait' ?> tablolar bulundu. Ne yapmak istediğinizi seçin:</div>
            <div class="row g-2 mb-4">
                <div class="col-md-6"><label class="mod-kart"><input class="form-check-input me-2" type="radio" name="mod" value="<?= $durum === 'v15' ? 'mevcut' : 'guncelle' ?>" <?= $mod !== 'yeni' ? 'checked' : '' ?>>
                    <strong><?= $durum === 'v15' ? 'Mevcut kurulumu kullan' : 'Verileri koru ve v' . SURUM . ' sürümüne güncelle' ?></strong>
                    <div class="small text-muted mt-1">Önerilen. Daireler, aidatlar ve kullanıcılar korunur.</div></label></div>
                <div class="col-md-6"><label class="mod-kart"><input class="form-check-input me-2" type="radio" name="mod" value="yeni" <?= $mod === 'yeni' ? 'checked' : '' ?>>
                    <strong class="text-danger">Sıfırdan kur</strong>
                    <div class="small text-muted mt-1">Tüm tablolar silinir ve yeniden oluşturulur.</div></label></div>
            </div>
            <?php endif; ?>

            <div id="yeniAlanlar">
                <h6 class="fw-bold text-muted small text-uppercase mb-3">Site Bilgileri</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6"><label class="form-label">Site / Apartman Adı *</label><input name="site_adi" class="form-control" value="<?= h($f['site_adi'] ?? '') ?>" placeholder="ör. Yeşil Vadi Sitesi"></div>
                    <div class="col-md-6"><label class="form-label">Telefon</label><input name="telefon" class="form-control" value="<?= h($f['telefon'] ?? '') ?>"></div>
                    <div class="col-12"><label class="form-label">Adres</label><input name="adres" class="form-control" value="<?= h($f['adres'] ?? '') ?>"></div>
                    <div class="col-md-8"><label class="form-label">IBAN (sakinlere gösterilir)</label><input name="iban" class="form-control" value="<?= h($f['iban'] ?? '') ?>" placeholder="TR.."></div>
                    <div class="col-md-4"><label class="form-label">Aylık Aidat (₺)</label><input name="aidat" class="form-control" value="<?= h($f['aidat'] ?? '1500') ?>" inputmode="decimal"></div>
                </div>
                <h6 class="fw-bold text-muted small text-uppercase mb-3">Süper Yönetici Hesabı</h6>
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Ad Soyad *</label><input name="ad" class="form-control" value="<?= h($f['ad'] ?? '') ?>"></div>
                    <div class="col-md-6"><label class="form-label">E-posta</label><input type="email" name="eposta" class="form-control" value="<?= h($f['eposta'] ?? '') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Kullanıcı Adı *</label><input name="kadi" class="form-control" value="<?= h($f['kadi'] ?? 'admin') ?>" autocomplete="off"></div>
                    <div class="col-md-4"><label class="form-label">Şifre * <span class="text-muted fw-normal">(min. 8)</span></label><input type="password" name="sifre" class="form-control" autocomplete="new-password"></div>
                    <div class="col-md-4"><label class="form-label">Şifre (Tekrar) *</label><input type="password" name="sifre2" class="form-control" autocomplete="new-password"></div>
                    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="ornek" value="1" id="ornek" <?= !empty($f['ornek']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ornek">Deneme için örnek daireleri yükle <span class="text-muted small">(sonradan silinebilir)</span></label></div></div>
                    <?php if ($durum !== 'bos'): ?>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="onay" value="1" id="onay">
                        <label class="form-check-label text-danger fw-semibold" for="onay">Mevcut tüm verilerin kalıcı olarak silineceğini anlıyorum.</label></div></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-footer bg-transparent d-flex justify-content-between p-3">
            <a href="?adim=2" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Geri</a>
            <button class="btn btn-success px-4" id="kurBtn"><i class="bi bi-rocket-takeoff me-1"></i>Kurulumu Tamamla</button>
        </div>
    </form>
    <script>
    (function () {
        const alanlar = document.getElementById('yeniAlanlar');
        const radyo = document.querySelectorAll('input[name=mod]');
        function guncelle() {
            const sec = document.querySelector('input[name=mod]:checked');
            alanlar.style.display = (!sec || sec.value === 'yeni') ? '' : 'none';
        }
        radyo.forEach(r => r.addEventListener('change', guncelle)); guncelle();
        document.getElementById('kurForm').addEventListener('submit', function () {
            const b = document.getElementById('kurBtn'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Kuruluyor…';
        });
    })();
    </script>

    <?php elseif ($adim === 4): $s = $_SESSION['kurulum_bitti']; ?>
    <div class="card"><div class="card-body p-4">
        <div class="text-center">
            <div class="stat-icon soft-success mx-auto" style="width:72px;height:72px;font-size:2rem"><i class="bi bi-check2-circle"></i></div>
            <h3 class="fw-bold mt-3">Kurulum tamamlandı! 🎉</h3>
            <p class="text-muted"><?= $s['mod'] === 'guncelle' ? 'Verileriniz korunarak sistem v' . SURUM . ' sürümüne güncellendi.' : ($s['mod'] === 'mevcut' ? 'Mevcut kurulum bağlandı.' : 'Apartman yönetim sisteminiz kullanıma hazır.') ?></p>
        </div>
        <?php if (!$s['config_yazildi']): ?>
            <div class="alert alert-warning small"><strong>Önemli:</strong> Klasör yazılabilir olmadığı için yapılandırma dosyası oluşturulamadı.
                Uygulamanın ana klasöründe <code>config.local.php</code> adlı bir dosya oluşturup aşağıdaki içeriği yapıştırın:</div>
            <textarea class="form-control font-monospace small mb-3" rows="8" readonly onclick="this.select()"><?= h($s['config']) ?></textarea>
        <?php endif; ?>
        <?php if (!$s['kilit']): ?>
            <div class="alert alert-danger small"><i class="bi bi-shield-exclamation me-1"></i>Güvenlik için ana klasörde boş bir <code>kurulum.kilit</code> dosyası oluşturun veya <code>kurulum.php</code> dosyasını silin.</div>
        <?php endif; ?>
        <div class="p-3 rounded-3 soft-primary small mb-3">
            <strong>Sonraki adımlar:</strong>
            <ol class="mb-0 mt-1 ps-3">
                <?php if ($s['kadi']): ?><li><strong><?= h($s['kadi']) ?></strong> kullanıcı adı ve belirlediğiniz şifreyle giriş yapın.</li><?php endif; ?>
                <li><em>Daireler</em> sayfasından dairelerinizi ekleyin.</li>
                <li><em>Aidat Dönemleri</em> sayfasından ilk dönemi oluşturun.</li>
                <li><em>Kullanıcılar</em> sayfasından sakinleri ve yöneticileri ekleyip yetkilerini belirleyin.</li>
            </ol>
        </div>
        <?php if (!empty($s['cron_cli'])): ?>
        <div class="p-3 rounded-3 border small mb-4" style="background:var(--bg)">
            <strong><i class="bi bi-clock-history me-1"></i>Hosting Cron Görevi (İsteğe Bağlı – E-posta & SMS kuyruğu için):</strong>
            <div class="text-muted mt-1">cPanel / Plesk <em>Cron Jobs</em> ekranına aşağıdaki komutlardan birini (5 dakikada bir) ekleyebilirsiniz:</div>
            <div class="mt-2"><strong>CLI Cron:</strong><br><code class="user-select-all"><?= h($s['cron_cli']) ?></code></div>
            <div class="mt-1"><strong>Web Cron:</strong><br><code class="user-select-all"><?= h($s['cron_web']) ?></code></div>
        </div>
        <?php endif; ?>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            <a href="login.php" class="btn btn-primary btn-lg px-5"><i class="bi bi-box-arrow-in-right me-2"></i>Giriş Yap</a>
            <?php if ($s['kilit'] && $s['config_yazildi']): ?>
            <form method="post" onsubmit="return confirm('kurulum.php dosyası sunucudan kalıcı olarak silinecek ve giriş sayfasına yönlendirileceksiniz. Onaylıyor musunuz?')">
                <input type="hidden" name="islem" value="sihirbaz_sil">
                <button class="btn btn-outline-danger btn-lg"><i class="bi bi-shield-check me-1"></i>Sihirbazı Sil ve Giriş Yap</button>
            </form>
            <?php endif; ?>
        </div>
    </div></div>
    <?php endif; ?>
<?php endif; ?>
    <p class="text-center text-muted small mt-4">© <?= date('Y') ?> Apartman Yönetim Sistemi</p>
</div>
</body>
</html>
