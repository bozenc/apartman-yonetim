<?php
/**
 * Apartman Yönetim Sistemi — Hosting Dağıtım Paketi Oluşturucu (Build Script)
 *
 * Kullanım:
 *   php deploy/paketle.php
 *
 * Çıktı:
 *   deploy/apartman-paket.zip   (Hosting'e yüklenecek temiz uygulama arşivi)
 *   deploy/installer.php        (Tek dosyalık web kurucu)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Bu betik yalnızca komut satırından çalıştırılabilir.\n");
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "[HATA] PHP ZipArchive eklentisi yüklü değil.\n");
    exit(1);
}

$kok = dirname(__DIR__);
$ciktiZip = __DIR__ . '/apartman-paket.zip';
if (is_file($ciktiZip)) {
    @unlink($ciktiZip);
}

$zip = new ZipArchive();
if ($zip->open($ciktiZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "[HATA] ZIP dosyası oluşturulamadı: $ciktiZip\n");
    exit(1);
}

// Pakete dahil edilmeyecek yerel/geçici dosya ve klasörler
$haricTam = [
    'config.local.php',
    'kurulum.kilit',
    '.git',
    '.env',
    'deploy',
];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($kok, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$eklenen = 0;
foreach ($it as $dosya) {
    $tamYol = str_replace('\\', '/', $dosya->getPathname());
    $goreli = ltrim(substr($tamYol, strlen($kok)), '/');
    $ilkParca = explode('/', $goreli)[0];

    if (in_array($goreli, $haricTam, true) || in_array($ilkParca, $haricTam, true)) {
        continue;
    }
    // Yüklenmiş gerçek dekont dosyalarını pakete dahil etme (.htaccess hariç)
    if (str_starts_with($goreli, 'uploads/dekontlar/') && basename($goreli) !== '.htaccess') {
        continue;
    }

    if ($dosya->isDir()) {
        $zip->addEmptyDir($goreli);
    } else {
        $zip->addFile($dosya->getPathname(), $goreli);
        $eklenen++;
    }
}

$zip->addEmptyDir('uploads/dekontlar');
$zip->close();

echo "[OK] Dağıtım paketi hazırlandı: $ciktiZip ($eklenen dosya, " . round(filesize($ciktiZip) / 1024, 1) . " KB)\n";
echo "Hosting kurulumu için 'deploy/apartman-paket.zip' ve 'deploy/installer.php' dosyalarını sunucuya yükleyip tarayıcıda installer.php'yi açın.\n";
