<?php
/**
 * Apartman Yönetim Sistemi - Yapılandırma
 *
 * NORMALDE BU DOSYAYI DÜZENLEMENİZE GEREK YOKTUR.
 * Siteyi tarayıcıda ilk açtığınızda kurulum sihirbazı (kurulum.php) otomatik başlar
 * ve veritabanı bilgilerini "config.local.php" dosyasına kendisi yazar.
 *
 * Sihirbazı kullanmadan elle kurmak isterseniz aşağıdaki varsayılanları değiştirebilir
 * veya bir config.local.php dosyası oluşturabilirsiniz.
 */

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

defined('DB_HOST')    || define('DB_HOST', 'localhost');
defined('DB_PORT')    || define('DB_PORT', '3306');
defined('DB_NAME')    || define('DB_NAME', 'apartman_db');
defined('DB_USER')    || define('DB_USER', 'root');
defined('DB_PASS')    || define('DB_PASS', '');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');

// Uygulamanın kök URL yolu. Boş bırakılırsa otomatik algılanır (alt klasör kurulumları dahil).
if (!defined('BASE_URL')) {
    $kok = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    define('BASE_URL', rtrim($kok, '/.'));
}

// Geliştirme sırasında true yapabilirsiniz; canlıda false olmalıdır.
defined('DEBUG') || define('DEBUG', false);

date_default_timezone_set('Europe/Istanbul');

/** Kurulum kilit dosyası: varsa kurulum sihirbazı devre dışıdır */
define('KURULUM_KILIT', __DIR__ . '/kurulum.kilit');
