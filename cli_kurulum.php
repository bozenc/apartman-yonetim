<?php
/**
 * Apartman Yönetim Sistemi — Komut Satırı (CLI / SSH / CI-CD) Otomatik Kurulum Betiği
 *
 * Kullanım örneği (Sıfırdan kurulum):
 *   php cli_kurulum.php \
 *     --db-host=localhost --db-port=3306 --db-name=apartman_db --db-user=root --db-pass="Sifre" \
 *     --site-adi="Yeşil Vadi Sitesi" --site-url="https://yesilvadi.com" \
 *     --admin-ad="Sistem Yöneticisi" --admin-user=admin --admin-pass="GucluSifre123!" --admin-email="yonetim@yesilvadi.com" \
 *     --aidat=1500 --iban="TR00..." --ornek=0
 *
 * Mevcut veritabanını güncelleme / bağlama:
 *   php cli_kurulum.php --db-host=localhost --db-name=apartman_db --db-user=root --db-pass="Sifre" --mod=guncelle
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Bu betik yalnızca komut satırından (CLI) çalıştırılabilir.\n");
}

require __DIR__ . '/config.php';
require __DIR__ . '/includes/guncelleme.php';

$opts = getopt('', [
    'db-host::',
    'db-port::',
    'db-name:',
    'db-user:',
    'db-pass::',
    'mod::',
    'site-adi::',
    'site-url::',
    'adres::',
    'telefon::',
    'iban::',
    'aidat::',
    'admin-ad::',
    'admin-user::',
    'admin-pass::',
    'admin-email::',
    'ornek::',
    'force',
    'help',
]);

if (isset($opts['help']) || empty($opts['db-name']) || empty($opts['db-user'])) {
    echo <<<HELP
Apartman Yönetim Sistemi — CLI Otomatik Kurulum (v1.5)

Zorunlu Parametreler:
  --db-name=AD          MySQL veritabanı adı
  --db-user=KULLANICI   MySQL kullanıcı adı

İsteğe Bağlı Parametreler:
  --db-host=HOST        MySQL sunucusu (varsayılan: localhost)
  --db-port=PORT        MySQL portu (varsayılan: 3306)
  --db-pass=SIFRE       MySQL şifresi (varsayılan: boş)
  --mod=MOD             yeni | guncelle | mevcut (varsayılan: otomatik algılar)
  --site-adi=AD         Site / Apartman adı (varsayılan: Apartman Yönetimi)
  --site-url=URL        Site tam adresi, ör. https://apartman.com
  --adres=ADRES         Site açık adresi
  --telefon=TEL         Yönetim telefonu
  --iban=IBAN           Banka IBAN numarası
  --aidat=TUTAR         Varsayılan aylık aidat (varsayılan: 1500)
  --admin-ad=AD         Süper Yönetici ad soyad (varsayılan: Sistem Yöneticisi)
  --admin-user=KADI     Süper Yönetici kullanıcı adı (varsayılan: admin)
  --admin-pass=SIFRE    Süper Yönetici şifresi (en az 8 karakter; verilmezse rastgele üretilir)
  --admin-email=EPOSTA  Süper Yönetici e-posta adresi
  --ornek=1             Örnek daire verilerini yükle (0 veya 1, varsayılan: 0)
  --force               Kurulum kilidi (kurulum.kilit) varsa bile üzerine yaz

HELP;
    exit(isset($opts['help']) ? 0 : 1);
}

if (is_file(KURULUM_KILIT) && !isset($opts['force'])) {
    fwrite(STDERR, "[HATA] Sistem zaten kurulu (kurulum.kilit mevcut). Üzerine yazmak için --force ekleyin.\n");
    exit(1);
}

$dbHost = $opts['db-host'] ?? 'localhost';
$dbPort = $opts['db-port'] ?? '3306';
$dbName = $opts['db-name'];
$dbUser = $opts['db-user'];
$dbPass = $opts['db-pass'] ?? '';

if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $dbName)) {
    fwrite(STDERR, "[HATA] Geçersiz veritabanı adı: $dbName\n");
    exit(1);
}

// 1. Klasör izinleri ve dizin hazırlığı
$dekontKlasor = __DIR__ . '/uploads/dekontlar';
if (!is_dir($dekontKlasor)) {
    @mkdir($dekontKlasor, 0755, true);
}

// 2. Veritabanı bağlantısı
try {
    $sunucu = new PDO("mysql:host=$dbHost;port=$dbPort;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    try {
        $sunucu->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci");
    } catch (PDOException $e) {
        // Paylaşımlı hostingde CREATE DATABASE yetkisi olmayabilir
    }
    $pdo = new PDO("mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "[HATA] Veritabanı bağlantısı kurulamadı: " . $e->getMessage() . "\n");
    exit(1);
}

$durum = vt_durum($pdo);
$mod = $opts['mod'] ?? ($durum === 'bos' ? 'yeni' : ($durum === 'v15' ? 'mevcut' : 'guncelle'));

$uretilenSifre = null;
$adminUser = strtolower(trim($opts['admin-user'] ?? 'admin'));

try {
    if ($mod === 'yeni') {
        $adminPass = (string)($opts['admin-pass'] ?? '');
        if ($adminPass === '') {
            $adminPass = bin2hex(random_bytes(6)); // 12 karakterli güvenli şifre
            $uretilenSifre = $adminPass;
        } elseif (strlen($adminPass) < 8) {
            fwrite(STDERR, "[HATA] Yönetici şifresi (--admin-pass) en az 8 karakter olmalıdır.\n");
            exit(1);
        }

        $sql = file_get_contents(__DIR__ . '/kurulum.sql');
        if (empty($opts['ornek'])) {
            $p = strpos($sql, '-- Örnek veriler');
            if ($p !== false) $sql = substr($sql, 0, $p);
        }
        sql_calistir($pdo, $sql);

        $adminAd = trim($opts['admin-ad'] ?? 'Sistem Yöneticisi');
        $adminEmail = trim($opts['admin-email'] ?? '') ?: null;
        $pdo->prepare('UPDATE kullanicilar SET ad_soyad = ?, kullanici_adi = ?, eposta = ?, sifre = ? WHERE id = 1')
            ->execute([$adminAd, $adminUser, $adminEmail, password_hash($adminPass, PASSWORD_DEFAULT)]);

        $ayar = $pdo->prepare('UPDATE ayarlar SET deger = ? WHERE anahtar = ?');
        $ayar->execute([trim($opts['site-adi'] ?? 'Apartman Yönetimi'), 'site_adi']);
        $ayar->execute([trim($opts['adres'] ?? ''), 'adres']);
        $ayar->execute([trim($opts['telefon'] ?? ''), 'telefon']);
        $ayar->execute([$adminEmail ?? '', 'eposta']);
        $ayar->execute([trim($opts['iban'] ?? ''), 'iban']);
        $ayar->execute([rtrim(trim($opts['site-url'] ?? ''), '/'), 'site_url']);
        if (!empty($opts['aidat'])) {
            $ayar->execute([(string)(float)$opts['aidat'], 'varsayilan_aidat']);
        }
        echo "[OK] Veritabanı şeması ve başlangıç verileri yüklendi.\n";
    } elseif ($mod === 'guncelle') {
        veritabani_guncelle($pdo);
        echo "[OK] Mevcut veritabanı v" . DB_SURUM . " sürümüne güncellendi.\n";
    } else {
        echo "[OK] Mevcut veritabanı (v" . DB_SURUM . ") korundu.\n";
    }

    // Gizli anahtar
    $gizliAnahtar = (string)$pdo->query("SELECT deger FROM ayarlar WHERE anahtar = 'gizli_anahtar'")->fetchColumn();
    if (strlen($gizliAnahtar) < 32) {
        $gizliAnahtar = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('gizli_anahtar', ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)")
            ->execute([$gizliAnahtar]);
    }

    $pdo->prepare('INSERT INTO islem_log (kullanici_id, islem, detay, ip) VALUES (1, ?, ?, ?)')
        ->execute(['Kurulum (CLI)', 'CLI otomatik kurulum tamamlandı (v' . DB_SURUM . ', ' . $mod . ')', '127.0.0.1']);

    $configIcerik = "<?php\n// CLI kurulum betiği tarafından oluşturuldu: " . date('d.m.Y H:i') . "\n"
        . "define('DB_HOST', " . var_export($dbHost, true) . ");\n"
        . "define('DB_PORT', " . var_export($dbPort, true) . ");\n"
        . "define('DB_NAME', " . var_export($dbName, true) . ");\n"
        . "define('DB_USER', " . var_export($dbUser, true) . ");\n"
        . "define('DB_PASS', " . var_export($dbPass, true) . ");\n"
        . "define('GIZLI_ANAHTAR', " . var_export($gizliAnahtar, true) . ");\n";

    if (file_put_contents(__DIR__ . '/config.local.php', $configIcerik) === false) {
        fwrite(STDERR, "[HATA] config.local.php dosyası yazılamadı.\n");
        exit(1);
    }
    @chmod(__DIR__ . '/config.local.php', 0600);
    file_put_contents(KURULUM_KILIT, 'Kuruldu (CLI): ' . date('c') . PHP_EOL);

    $cronKey = substr(hash_hmac('sha256', 'apt_web_cron', $gizliAnahtar), 0, 24);
    echo "[OK] config.local.php ve kurulum.kilit oluşturuldu.\n";
    if ($mod === 'yeni') {
        echo "------------------------------------------------------------\n";
        echo "Yönetici Kullanıcı : $adminUser\n";
        if ($uretilenSifre) {
            echo "Otomatik Şifre     : $uretilenSifre  (Lütfen ilk girişte değiştirin!)\n";
        }
    }
    echo "CLI Cron Komutu    : */5 * * * * php " . __DIR__ . "/cron_eposta.php >/dev/null 2>&1\n";
    echo "Web Cron Anahtarı  : ?key=$cronKey\n";
    echo "------------------------------------------------------------\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[HATA] Kurulum başarısız: " . $e->getMessage() . "\n");
    exit(1);
}
