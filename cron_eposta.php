<?php
/**
 * Bekleyen e-posta, SMS ve WhatsApp iletilerini gönderir (Zamanlanmış Görev / Cron).
 *   1) CLI Cron (önerilen):  */5 * * * * php /yol/apartman/cron_eposta.php
 *   2) Web Cron (paylaşımlı hosting):  */5 * * * * curl -fsS "https://alanadiniz.com/cron_eposta.php?key=ANAHTAR"
 */
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/cron_eposta.php';
require __DIR__ . '/includes/init.php';

if (PHP_SAPI !== 'cli') {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals(cron_anahtari(), $key)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Erişim reddedildi: Bu dosya yalnızca komut satırından (CLI) veya geçerli Web-Cron anahtarıyla (?key=...) çalıştırılabilir.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$r = eposta_kuyrugu_isle(100);
echo date('Y-m-d H:i:s') . " E-posta -> gönderilen: {$r['gonderilen']}, hata: {$r['hata']}" . ($r['mesaj'] ? " ({$r['mesaj']})" : '') . PHP_EOL;
$m = mesaj_kuyrugu_isle(100);
echo date('Y-m-d H:i:s') . " SMS/WhatsApp -> gönderilen: {$m['gonderilen']}, hata: {$m['hata']}" . ($m['mesaj'] ? " ({$m['mesaj']})" : '') . PHP_EOL;
