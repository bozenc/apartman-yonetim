<?php
/**
 * Apartman Yönetim Sistemi — Tek Dosyalık Hosting Otomatik Kurucu (Web Installer)
 *
 * KULLANIM:
 * 1. "deploy/paketle.php" ile oluşturulan "apartman-paket.zip" dosyasını ve bu "installer.php"
 *    dosyasını hostinginizin web kök dizinine (public_html / httpdocs) yükleyin.
 * 2. Tarayıcıdan https://alanadiniz.com/installer.php adresini açın.
 * 3. Bu betik ZIP paketini çıkartır, klasör/dosya izinlerini ayarlar, kurulum arşivini ve
 *    kendisini temizleyip otomatik olarak kurulum sihirbazına (kurulum.php) yönlendirir.
 */

ini_set('display_errors', '0');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Robots-Tag: noindex, nofollow');

session_name('APTYS_INST');
session_start();
if (empty($_SESSION['inst_token'])) {
    $_SESSION['inst_token'] = bin2hex(random_bytes(16));
}

$hedefKlasor = __DIR__;
$varsayilanZip = 'apartman-paket.zip';

// Klasördeki mevcut .zip arşivlerini bul
$zipler = array_values(array_map('basename', glob($hedefKlasor . '/*.zip') ?: []));
$seciliZip = in_array($varsayilanZip, $zipler, true) ? $varsayilanZip : ($zipler[0] ?? $varsayilanZip);

$kontroller = [
    ['PHP 8.0+ (Mevcut: ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.0.0', '>='), true],
    ['ZipArchive eklentisi (zip)', class_exists('ZipArchive'), true],
    ['PDO MySQL eklentisi (pdo_mysql)', extension_loaded('pdo_mysql'), true],
    ['mbstring eklentisi', extension_loaded('mbstring'), true],
    ['OpenSSL eklentisi', extension_loaded('openssl'), false],
    ['Hedef dizin yazılabilir (' . $hedefKlasor . ')', is_writable($hedefKlasor), true],
    ['Kurulum paketi (.zip) mevcut', !empty($zipler), true],
];
$hazir = !in_array(false, array_map(fn($k) => $k[1] || !$k[2], $kontroller), true);

$hata = '';
$basarili = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $hazir) {
    if (!hash_equals($_SESSION['inst_token'], (string)($_POST['_token'] ?? ''))) {
        $hata = 'Oturum doğrulaması başarısız oldu. Sayfayı yenileyip tekrar deneyin.';
    } else {
        $zipAd = basename((string)($_POST['zip_dosya'] ?? $seciliZip));
        $zipYol = $hedefKlasor . '/' . $zipAd;
        $temizle = !empty($_POST['temizle']);

        if (!is_file($zipYol) || !in_array($zipAd, $zipler, true)) {
            $hata = 'Seçilen ZIP paketi bulunamadı.';
        } else {
            $zip = new ZipArchive();
            if ($zip->open($zipYol) !== true) {
                $hata = 'ZIP arşivi açılamadı veya dosya bozuk.';
            } else {
                // Güvenlik kontrolü: Zip Slip (Path Traversal) önlemi
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $isim = $zip->getNameIndex($i);
                    if (str_contains($isim, '../') || str_contains($isim, '..\\') || str_starts_with($isim, '/')) {
                        $zip->close();
                        $hata = 'Güvenlik hatası: Arşiv içinde geçersiz dosya yolu tespit edildi.';
                        break;
                    }
                }

                if ($hata === '') {
                    if (!$zip->extractTo($hedefKlasor)) {
                        $hata = 'Dosyalar hedef klasöre çıkartılamadı. Disk kotasını ve klasör izinlerini kontrol edin.';
                    }
                    $zip->close();
                }

                if ($hata === '') {
                    // Dizinleri ve izinleri hazırla
                    $dekontKlasor = $hedefKlasor . '/uploads/dekontlar';
                    if (!is_dir($dekontKlasor)) {
                        @mkdir($dekontKlasor, 0755, true);
                    }
                    @chmod($hedefKlasor . '/uploads', 0755);
                    @chmod($dekontKlasor, 0755);
                    @chmod($hedefKlasor . '/includes', 0755);

                    if ($temizle) {
                        @unlink($zipYol);
                        @unlink(__FILE__);
                    }
                    header('Location: kurulum.php');
                    exit;
                }
            }
        }
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Hosting Otomatik Kurucu · Apartman Yönetim Sistemi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6fb; font-family: system-ui, -apple-system, sans-serif; color: #0f172a; }
        .wrap { max-width: 640px; margin: 48px auto; padding: 0 16px; }
        .card { border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 4px 20px rgba(15,23,42,.04); }
        .req { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: .92rem; }
        .req:last-child { border-bottom: 0; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="card-body p-4">
            <h4 class="fw-bold mb-1">Apartman Yönetim Sistemi</h4>
            <p class="text-muted small mb-4">Hosting Otomatik Paket Açıcı ve Kurulum Hazırlayıcı</p>

            <?php if ($hata): ?>
                <div class="alert alert-danger small"><?= h($hata) ?></div>
            <?php endif; ?>

            <h6 class="fw-bold small text-uppercase text-muted mb-2">Sunucu Kontrolü</h6>
            <div class="mb-4">
                <?php foreach ($kontroller as [$etiket, $ok, $zorunlu]): ?>
                    <div class="req">
                        <span><?= h($etiket) ?></span>
                        <?php if ($ok): ?>
                            <span class="badge bg-success-subtle text-success">Hazır</span>
                        <?php elseif ($zorunlu): ?>
                            <span class="badge bg-danger-subtle text-danger">Eksik</span>
                        <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning">Önerilen</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($zipler)): ?>
                <div class="alert alert-warning small">
                    Bu klasörde (<code><?= h($hedefKlasor) ?></code>) henüz bir <code>.zip</code> kurulum paketi bulunamadı.<br>
                    Lütfen <code>apartman-paket.zip</code> dosyasını <code>installer.php</code> ile aynı klasöre yükleyip sayfayı yenileyin.
                </div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="_token" value="<?= h($_SESSION['inst_token']) ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Kurulum Paketi</label>
                        <select name="zip_dosya" class="form-select">
                            <?php foreach ($zipler as $z): ?>
                                <option value="<?= h($z) ?>" <?= $z === $seciliZip ? 'selected' : '' ?>><?= h($z) ?> (<?= round(filesize($hedefKlasor . '/' . $z) / 1024) ?> KB)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="temizle" value="1" id="temizle" checked>
                        <label class="form-check-label small" for="temizle">
                            Dosyalar çıkartıldıktan sonra <code>.zip</code> arşivini ve <code>installer.php</code> dosyasını güvenlik için otomatik sil
                        </label>
                    </div>
                    <button class="btn btn-primary w-100 py-2 fw-semibold" <?= $hazir ? '' : 'disabled' ?>>
                        Paketi Çıkart ve Kurulum Sihirbazını Başlat →
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
