<?php
/**
 * Dekont dosyalarını yetki kontrolüyle sunar.
 *   dekont.php?b=ID  → ödeme bildirimi dekontu
 *   dekont.php?o=ID  → tahsilata eklenmiş dekont
 *   &indir=1         → indirme olarak gönder
 * Dosyalar uploads/dekontlar içinde, doğrudan erişime kapalıdır.
 */
require __DIR__ . '/includes/init.php';
$u = giris_zorunlu();

$kayit = null;
if ($id = (int)get('b')) {
    $st = $pdo->prepare('SELECT ob.dekont, ob.dekont_ad, d.kullanici_id AS sahip FROM odeme_bildirimleri ob JOIN daireler d ON d.id = ob.daire_id WHERE ob.id = ?');
    $st->execute([$id]);
    $kayit = $st->fetch();
    $izin = $kayit && (yetki_herhangi(['bildirim.onayla', 'odeme.goruntule']) || (int)$kayit['sahip'] === (int)$u['id']);
} elseif ($id = (int)get('o')) {
    $st = $pdo->prepare('SELECT o.dekont, o.makbuz_no, ob.dekont_ad, d.kullanici_id AS sahip FROM odemeler o JOIN daireler d ON d.id = o.daire_id
                         LEFT JOIN odeme_bildirimleri ob ON ob.id = o.bildirim_id WHERE o.id = ?');
    $st->execute([$id]);
    $kayit = $st->fetch();
    $izin = $kayit && (yetki_herhangi(['odeme.goruntule', 'aidat.goruntule']) || (int)$kayit['sahip'] === (int)$u['id']);
} elseif ($id = (int)get('h')) {
    $st = $pdo->prepare('SELECT h.belge_dosya AS dekont, h.belge_no AS makbuz_no, NULL AS dekont_ad FROM hareketler h WHERE h.id = ?');
    $st->execute([$id]);
    $kayit = $st->fetch();
    $izin = $kayit && yetki_herhangi(['kasa.goruntule', 'rapor.goruntule', 'panel.goruntule']);
} elseif ($id = (int)get('t')) {
    $st = $pdo->prepare('SELECT t.fotograf AS dekont, CONCAT("talep_", t.id) AS makbuz_no, NULL AS dekont_ad, t.kullanici_id, d.kullanici_id AS sahip
                         FROM talepler t LEFT JOIN daireler d ON d.id = t.daire_id WHERE t.id = ?');
    $st->execute([$id]);
    $kayit = $st->fetch();
    $izin = $kayit && (yetki('talep.yonet') || (int)$kayit['kullanici_id'] === (int)$u['id'] || (int)($kayit['sahip'] ?? 0) === (int)$u['id']);
} elseif ($id = (int)get('d')) {
    $st = $pdo->prepare('SELECT dosya AS dekont, dosya_ad AS dekont_ad, herkese_acik FROM dokumanlar WHERE id = ?');
    $st->execute([$id]);
    $kayit = $st->fetch();
    $izin = $kayit && ((int)$kayit['herkese_acik'] === 1 || yetki_herhangi(['bakim.yonet', 'daire.goruntule', 'kasa.goruntule']));
} else {
    $izin = false;
}

$dosya = $kayit['dekont'] ?? '';
$yol = dekont_klasoru() . '/' . basename($dosya);
if (!$izin || $dosya === '' || !preg_match('/^[\w]+\.(pdf|jpg|png)$/', $dosya) || !is_file($yol)) {
    http_response_code(404);
    exit('Dekont bulunamadı veya görüntüleme yetkiniz yok.');
}

$uzanti = pathinfo($yol, PATHINFO_EXTENSION);
$tip = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$uzanti];
$ad = $kayit['dekont_ad'] ?: ('dekont' . (!empty($kayit['makbuz_no']) ? '_' . $kayit['makbuz_no'] : '') . '.' . $uzanti);
$asciiAd = preg_replace('/[^A-Za-z0-9._-]/', '_', strtr($ad, ['ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U']));

while (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $tip);
header('Content-Length: ' . filesize($yol));
header('Content-Disposition: ' . (get('indir') ? 'attachment' : 'inline') . '; filename="' . $asciiAd . '"; filename*=UTF-8\'\'' . rawurlencode($ad));
header('Cache-Control: private, max-age=0, no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
readfile($yol);
