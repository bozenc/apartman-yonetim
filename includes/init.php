<?php
/**
 * Her sayfanın başında yüklenen çekirdek dosya.
 */
require_once __DIR__ . '/../config.php';

if (DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

require_once __DIR__ . '/functions.php';

// Güvenli oturum ayarları
if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => https_aktif(),
    ]);
    session_name('APTYS');
    session_start();

    // Hareketsizlik zaman aşımı (60 dakika)
    if (!empty($_SESSION['uid'])) {
        if (!empty($_SESSION['son_etkinlik']) && (time() - (int)$_SESSION['son_etkinlik'] > 3600)) {
            unset($_SESSION['uid'], $_SESSION['son_etkinlik'], $_SESSION['yasal_tamam']);
            session_regenerate_id(true);
            flash('warning', 'Güvenliğiniz için uzun süre işlem yapılmadığından oturumunuz sonlandırıldı.');
        } else {
            $_SESSION['son_etkinlik'] = time();
        }
    }
}

// Güvenlik başlıkları
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (https_aktif()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** Kurulum sihirbazına yönlendir */
function kuruluma_git(): void
{
    header('Location: ' . url('kurulum.php'));
    exit;
}

// Veritabanı bağlantısı
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Henüz kurulmamış → sihirbazı otomatik başlat
    if (!is_file(KURULUM_KILIT)) kuruluma_git();

    http_response_code(500);
    die('<div style="font-family:sans-serif;padding:40px;max-width:640px;margin:auto;line-height:1.6">'
        . '<h2>Veritabanına bağlanılamıyor</h2>'
        . '<p>Sistem daha önce kurulmuş, ancak şu an veritabanına ulaşılamıyor. MySQL/MariaDB servisinin çalıştığından '
        . 've <code>config.local.php</code> dosyasındaki bilgilerin doğru olduğundan emin olun.</p>'
        . '<p>Kurulum sihirbazını yeniden çalıştırmak için ana klasördeki <code>kurulum.kilit</code> dosyasını silip sayfayı yenileyin.</p>'
        . (DEBUG ? '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>' : '') . '</div>');
}

// Bağlantı var ama tablolar kurulmamış / eski sürüm → sihirbaz
if (!is_file(KURULUM_KILIT)) {
    $kurulu = (bool)$pdo->query("SHOW TABLES LIKE 'kullanici_yetki'")->fetchColumn();
    if (!$kurulu) kuruluma_git();
    @file_put_contents(KURULUM_KILIT, 'Kuruldu: ' . date('c') . PHP_EOL); // önceden elle kurulmuş sistem
}

// Yeni sürüm dosyaları yüklendiyse veritabanını otomatik güncelle
require_once __DIR__ . '/guncelleme.php';
if (ayar('db_surum') !== DB_SURUM) {
    try {
        veritabani_guncelle($pdo);
        ayar_sifirla();
    } catch (Throwable $e) {
        http_response_code(500);
        die('<div style="font-family:sans-serif;padding:40px;max-width:640px;margin:auto"><h2>Veritabanı güncellenemedi</h2>'
            . '<p>Yeni sürüm için gereken veritabanı güncellemesi uygulanamadı. Veritabanı kullanıcısının ALTER/CREATE yetkisi olduğundan emin olun '
            . 'veya <code>guncelleme_v1.x.sql</code> dosyalarını sırayla phpMyAdmin\'den elle içe aktarın.</p>'
            . (DEBUG ? '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>' : '') . '</div>');
    }
}

unset($_SESSION['kurulum_bitti']); // sihirbazın "tamamlandı" ekranı bilgisi

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/eposta.php';
require_once __DIR__ . '/mesaj.php';
require_once __DIR__ . '/yasal.php';

// Bekleyen e-posta / SMS / WhatsApp mesajları ve kayıt temizliği, sayfa yanıtı gönderildikten sonra arka planda işlenir
register_shutdown_function('arka_plan_isleri');
