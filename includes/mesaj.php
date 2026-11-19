<?php
/**
 * SMS ve WhatsApp bildirimleri
 *  SMS      : Netgsm (XML API), Twilio, Özel HTTP
 *  WhatsApp : Meta WhatsApp Cloud API (resmi), Twilio WhatsApp, Özel HTTP
 * Mesajlar mesaj_kuyrugu tablosuna yazılır, arka planda gönderilir.
 * Ayrıca ücretsiz "wa.me" bağlantıları ile WhatsApp'tan elle paylaşım.
 */

// Sağlayıcı adresleri (test için config.local.php'de değiştirilebilir)
defined('NETGSM_API_URL') || define('NETGSM_API_URL', 'https://api.netgsm.com.tr/sms/send/xml');
defined('TWILIO_API_URL') || define('TWILIO_API_URL', 'https://api.twilio.com');
defined('META_API_URL')   || define('META_API_URL', 'https://graph.facebook.com');
defined('META_API_SURUM') || define('META_API_SURUM', 'v21.0');

/* =====================================================================
   YARDIMCILAR
   ===================================================================== */

/** Telefonu uluslararası biçime çevirir: 0532 111 22 33 → 905321112233. Geçersizse null. */
function telefon_normal(?string $t): ?string
{
    $t = trim((string)$t);
    if ($t === '') return null;
    $yabanci = str_starts_with($t, '+') && !str_starts_with($t, '+90');
    $r = preg_replace('/\D/', '', $t);
    if (str_starts_with($r, '00')) $r = substr($r, 2);
    if ($yabanci) return (strlen($r) >= 10 && strlen($r) <= 15) ? $r : null;
    if (strlen($r) === 11 && $r[0] === '0') $r = '9' . $r;
    elseif (strlen($r) === 10 && $r[0] === '5') $r = '90' . $r;
    return preg_match('/^905\d{9}$/', $r) ? $r : null;
}

function telefon_goster(string $n): string
{
    return str_starts_with($n, '90') && strlen($n) === 12
        ? '0' . substr($n, 2, 3) . ' ' . substr($n, 5, 3) . ' ' . substr($n, 8, 2) . ' ' . substr($n, 10, 2) : '+' . $n;
}

/** Türkçe karakterleri ASCII'ye çevirir (SMS maliyetini düşürür) */
function ascii_tr(string $s): string
{
    return strtr($s, ['ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S',
        'ü' => 'u', 'Ü' => 'U', '₺' => 'TL', '–' => '-', '—' => '-', '’' => "'", '“' => '"', '”' => '"', '…' => '...']);
}

/** Tahmini SMS adedi (Türkçe karakterli 1 SMS ≈ 155, ASCII ≈ 160 karakter; uzun mesajlarda 150 / 153) */
function sms_adet(string $metin): int
{
    $n = mb_strlen($metin);
    $tr = (bool)preg_match('/[çğıöşüÇĞİÖŞÜ]/u', $metin);
    $tek = $tr ? 155 : 160; $cok = $tr ? 150 : 153;
    return $n <= $tek ? 1 : (int)ceil($n / $cok);
}

function gizli_sifrele(string $s): string { return smtp_sifre_sifrele($s); }
function gizli_coz(string $s): string { return smtp_sifre_coz($s); }

/** Dış HTTP isteklerinde SSRF koruması: yalnızca http/https ve genel (public) IP adreslerine izin verir */
function guvenli_dis_url(string $url): void
{
    $p = @parse_url($url);
    $sema = strtolower((string)($p['scheme'] ?? ''));
    $host = strtolower(trim((string)($p['host'] ?? ''), '[]'));
    if (!in_array($sema, ['https', 'http'], true) || $host === '' || in_array($host, ['localhost', '0.0.0.0'], true) || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
        throw new RuntimeException('Geçersiz veya güvenlik nedeniyle izin verilmeyen istek adresi.');
    }
    $ipler = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
    if (!$ipler) {
        throw new RuntimeException('Sağlayıcı sunucu adresi çözümlenemedi (' . $host . ').');
    }
    foreach ($ipler as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('Güvenlik nedeniyle yerel veya özel ağ adreslerine HTTP isteği gönderilemez.');
        }
    }
}

/** Basit HTTP istemcisi (cURL varsa onu, yoksa akış bağlamını kullanır) → [durum kodu, yanıt] */
function http_istek(string $yontem, string $url, ?string $govde = null, array $basliklar = [], ?string $kullanici = null, ?string $sifre = null): array
{
    guvenli_dis_url($url);
    if (function_exists('curl_init')) {
        $c = curl_init($url);
        $ayarlar = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $yontem, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => $basliklar, CURLOPT_FOLLOWLOCATION => false];
        if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $ayarlar[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($c, $ayarlar);
        if ($govde !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $govde);
        if ($kullanici !== null) curl_setopt($c, CURLOPT_USERPWD, $kullanici . ':' . $sifre);
        $yanit = curl_exec($c);
        if ($yanit === false) { $h = curl_error($c); curl_close($c); throw new RuntimeException('Sağlayıcıya bağlanılamadı: ' . $h); }
        $kod = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
        curl_close($c);
        return [$kod, (string)$yanit];
    }
    if ($kullanici !== null) $basliklar[] = 'Authorization: Basic ' . base64_encode($kullanici . ':' . $sifre);
    $ctx = stream_context_create(['http' => ['method' => $yontem, 'header' => implode("\r\n", $basliklar), 'content' => (string)$govde, 'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0]]);
    $yanit = @file_get_contents($url, false, $ctx);
    if ($yanit === false) throw new RuntimeException('Sağlayıcıya bağlanılamadı.');
    preg_match('~HTTP/\S+\s+(\d{3})~', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $yanit];
}

/* =====================================================================
   SAĞLAYICILAR — başarılıysa sağlayıcı mesaj numarasını döner, değilse RuntimeException
   ===================================================================== */

function sms_gonder_tek(string $tel, string $metin): string
{
    if (ayar('sms_turkce') !== '1') $metin = ascii_tr($metin);
    $s = ayar('sms_saglayici', 'netgsm');
    if ($s === 'netgsm') {
        $kul = trim((string)ayar('netgsm_kullanici'));
        $baslik = trim((string)ayar('sms_baslik'));
        if ($kul === '' || $baslik === '') throw new RuntimeException('Netgsm kullanıcı adı ve SMS başlığı ayarlanmalı.');
        $x = fn($v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = '<?xml version="1.0" encoding="UTF-8"?><mainbody><header><company dil="TR">Netgsm</company>'
            . '<usercode>' . $x($kul) . '</usercode><password>' . $x(gizli_coz((string)ayar('netgsm_sifre'))) . '</password>'
            . '<type>1:n</type><msgheader>' . $x($baslik) . '</msgheader></header>'
            . '<body><msg><![CDATA[' . str_replace(']]>', ']] >', $metin) . ']]></msg><no>' . substr($tel, 2) . '</no></body></mainbody>';
        [$kod, $y] = http_istek('POST', NETGSM_API_URL, $xml, ['Content-Type: text/xml; charset=UTF-8']);
        $y = trim($y);
        if (preg_match('/^(00|01|02)\s*(\S*)/', $y, $m)) return $m[2] ?: $m[1];
        $hatalar = ['20' => 'Mesaj metni hatalı veya çok uzun', '30' => 'Geçersiz kullanıcı adı/şifre veya API erişim izni yok',
            '40' => 'SMS başlığı (gönderici adı) sistemde tanımlı değil', '41' => 'SMS başlığı tanımlı değil', '50' => 'İYS kontrollü gönderim yapılamıyor',
            '51' => 'İYS kontrollü gönderim yapılamıyor', '70' => 'Hatalı veya eksik parametre', '85' => 'Mükerrer gönderim sınırı aşıldı', '100' => 'Netgsm sistem hatası'];
        $k = preg_match('/^(\d+)/', $y, $m) ? $m[1] : '';
        throw new RuntimeException('Netgsm: ' . ($hatalar[$k] ?? ('Beklenmeyen yanıt (' . mb_substr($y, 0, 120) . ')')) . ($k ? " [kod $k]" : ''));
    }
    if ($s === 'twilio') return twilio_gonder('+' . $tel, trim((string)ayar('twilio_sms_no')), $metin);
    if ($s === 'ozel') return ozel_http_gonder('sms', $tel, $metin);
    throw new RuntimeException('SMS sağlayıcısı seçilmemiş.');
}

function wa_gonder_tek(string $tel, string $metin): string
{
    $s = ayar('wa_saglayici', 'meta');
    if ($s === 'meta') {
        $token = gizli_coz((string)ayar('wa_meta_token'));
        $pid = trim((string)ayar('wa_meta_telefon_id'));
        if ($token === '' || $pid === '') throw new RuntimeException('WhatsApp erişim anahtarı (token) ve Telefon Numarası Kimliği ayarlanmalı.');
        if (ayar('wa_meta_tip', 'sablon') === 'metin') {
            $govde = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $tel, 'type' => 'text', 'text' => ['preview_url' => true, 'body' => $metin]];
        } else {
            $sablon = trim((string)ayar('wa_meta_sablon'));
            if ($sablon === '') throw new RuntimeException('WhatsApp şablon adı ayarlanmalı (Meta\'da onaylı, gövdesinde {{1}} bulunan şablon).');
            // Şablon parametrelerinde satır sonu ve 4+ boşluk kullanılamaz
            $param = trim(preg_replace('/\s{2,}/u', ' ', str_replace(["\r", "\n", "\t"], ' ', $metin)));
            $govde = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $tel, 'type' => 'template',
                'template' => ['name' => $sablon, 'language' => ['code' => (string)ayar('wa_meta_dil', 'tr')],
                    'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($param, 0, 1000)]]]]]];
        }
        [$kod, $y] = http_istek('POST', rtrim(META_API_URL, '/') . '/' . META_API_SURUM . '/' . rawurlencode($pid) . '/messages',
            json_encode($govde, JSON_UNESCAPED_UNICODE), ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        $j = json_decode($y, true);
        if ($kod >= 200 && $kod < 300 && !empty($j['messages'][0]['id'])) return (string)$j['messages'][0]['id'];
        $h = $j['error']['message'] ?? mb_substr($y, 0, 160);
        $ipucu = match ((int)($j['error']['code'] ?? 0)) {
            190 => ' — Erişim anahtarının süresi dolmuş olabilir; kalıcı (System User) token kullanın.',
            131047 => ' — 24 saatlik pencere dışında serbest metin gönderilemez; "Onaylı şablon" türünü seçin.',
            132001 => ' — Şablon bulunamadı veya onaylanmamış; şablon adı ve dil kodunu kontrol edin.',
            131026 => ' — Numara WhatsApp kullanmıyor olabilir.',
            default => '',
        };
        throw new RuntimeException('WhatsApp (Meta): ' . $h . $ipucu . " [HTTP $kod]");
    }
    if ($s === 'twilio') return twilio_gonder('whatsapp:+' . $tel, 'whatsapp:' . ltrim(trim((string)ayar('twilio_wa_no')), 'whatsapp:'), $metin);
    if ($s === 'ozel') return ozel_http_gonder('wa', $tel, $metin);
    throw new RuntimeException('WhatsApp sağlayıcısı seçilmemiş.');
}

function twilio_gonder(string $kime, string $kimden, string $metin): string
{
    $sid = trim((string)ayar('twilio_sid'));
    $token = gizli_coz((string)ayar('twilio_token'));
    if ($sid === '' || $token === '' || $kimden === '' || $kimden === 'whatsapp:') throw new RuntimeException('Twilio Account SID, Auth Token ve gönderen numara ayarlanmalı.');
    [$kod, $y] = http_istek('POST', rtrim(TWILIO_API_URL, '/') . '/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json',
        http_build_query(['To' => $kime, 'From' => $kimden, 'Body' => $metin]), ['Content-Type: application/x-www-form-urlencoded'], $sid, $token);
    $j = json_decode($y, true);
    if ($kod >= 200 && $kod < 300 && !empty($j['sid'])) return (string)$j['sid'];
    throw new RuntimeException('Twilio: ' . ($j['message'] ?? mb_substr($y, 0, 160)) . (isset($j['code']) ? ' [kod ' . $j['code'] . ']' : '') . " [HTTP $kod]");
}

/** Özel HTTP: herhangi bir sağlayıcının API'si için {telefon} {telefon10} {mesaj} yer tutucularıyla */
function ozel_http_gonder(string $k, string $tel, string $metin): string
{
    $url = trim((string)ayar("ozel_{$k}_url"));
    if ($url === '') throw new RuntimeException('Özel HTTP adresi ayarlanmamış.');
    $yontem = ayar("ozel_{$k}_yontem") === 'GET' ? 'GET' : 'POST';
    $tip = (string)ayar("ozel_{$k}_tip", 'application/json');
    $degis = fn(string $s, callable $kac) => strtr($s, ['{telefon}' => $kac($tel), '{telefon10}' => $kac(substr($tel, 2)), '{mesaj}' => $kac($metin)]);
    $url = $degis($url, 'rawurlencode');
    $govde = null;
    if ($yontem === 'POST') {
        $sablon = (string)ayar("ozel_{$k}_govde");
        $govde = $degis($sablon, str_contains($tip, 'json') ? fn($v) => substr(json_encode((string)$v, JSON_UNESCAPED_UNICODE), 1, -1) : 'rawurlencode');
    }
    [$kod, $y] = http_istek($yontem, $url, $govde, $govde !== null ? ['Content-Type: ' . $tip] : []);
    $basari = trim((string)ayar("ozel_{$k}_basari"));
    if ($kod >= 200 && $kod < 300 && ($basari === '' || str_contains($y, $basari))) return mb_substr(trim($y), 0, 90);
    throw new RuntimeException("Özel HTTP: HTTP $kod — " . mb_substr(trim($y), 0, 200));
}

/* =====================================================================
   KISA BAĞLANTI, ŞABLON, ALICILAR
   ===================================================================== */

/** SMS için kısa imzalı bağlantı: …/goster.php?k=Ab3dE9xY */
function kisa_link(string $tur, string $ids, int $daireId, int $gun): string
{
    global $pdo;
    $tam = imzali_link($tur, $ids, $daireId, $gun);
    $token = substr($tam, strpos($tam, '?t=') + 3);
    $harf = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for ($i = 0; $i < 5; $i++) {
        $kod = '';
        for ($j = 0; $j < 8; $j++) $kod .= $harf[random_int(0, strlen($harf) - 1)];
        try {
            $pdo->prepare('INSERT INTO kisa_linkler (kod, token) VALUES (?, ?)')->execute([$kod, $token]);
            return site_kok_url() . '/goster.php?k=' . $kod;
        } catch (PDOException $e) { /* çakışma: yeniden dene */ }
    }
    return $tam;
}

function mesaj_sablon(string $tur, array $d): string
{
    $s = (string)ayar('msj_sablon_' . $tur);
    $r = [];
    foreach ($d as $k => $v) $r['{' . $k . '}'] = (string)$v;
    return trim(preg_replace('/[ \t]+/', ' ', strtr($s, $r)));
}

function sms_aktif(): bool { return ayar('sms_aktif') === '1'; }
function wa_aktif(): bool { return ayar('wa_aktif') === '1'; }

/** Kullanıcı yurt dışı aktarım açık rızasını geri almışsa WhatsApp gönderilmez */
function acik_riza_geri_alindi(int $kullaniciId): bool
{
    global $pdo;
    $st = $pdo->prepare("SELECT durum FROM onaylar WHERE kullanici_id = ? AND metin = 'acik_riza' ORDER BY id DESC LIMIT 1");
    $st->execute([$kullaniciId]);
    return $st->fetchColumn() === 'geri_alindi';
}

/** Dairenin telefon alıcıları [905… => ad] */
function daire_telefonlari(int $daireId, string $kanal): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT d.*, k.id AS k_id, k.telefon AS k_tel, k.ad_soyad AS k_ad, k.mesaj_bildirim AS k_izin, k.aktif AS k_aktif
                         FROM daireler d LEFT JOIN kullanicilar k ON k.id = d.kullanici_id WHERE d.id = ?');
    $st->execute([$daireId]);
    $d = $st->fetch();
    if (!$d || !(int)$d['mesaj_izin']) return [];
    $liste = [];
    $ekle = function (?string $t, ?string $ad) use (&$liste) { if ($n = telefon_normal($t)) $liste[$n] ??= $ad ?: null; };
    $kullaniciEngel = $d['k_id'] && (!(int)$d['k_izin'] || !(int)$d['k_aktif'] || ($kanal === 'whatsapp' && acik_riza_geri_alindi((int)$d['k_id'])));
    if (!$kullaniciEngel) {
        if ($d['k_id']) $ekle($d['k_tel'], $d['k_ad']);
        $ekle($d['sakin_tel'], $d['sakin_adi'] ?: $d['malik_adi']);
        if ($d['oturan_tipi'] === 'malik') $ekle($d['malik_tel'], $d['malik_adi']);
    }
    // Kiracılı dairede malik ayrı kişidir; ayar açıksa ona da gönderilir
    if ($d['oturan_tipi'] === 'kiraci' && ayar('mail_malik') === '1') $ekle($d['malik_tel'], $d['malik_adi']);
    return $liste;
}

/* =====================================================================
   KUYRUK
   ===================================================================== */

/** @param array $alicilar [telefon => ad] */
function mesaj_kuyruga(string $kanal, array $alicilar, string $metin, string $tur, ?int $ilgiliId = null): int
{
    global $pdo;
    if (($kanal === 'sms' && !sms_aktif()) || ($kanal === 'whatsapp' && !wa_aktif()) || !$alicilar) return 0;
    $st = $pdo->prepare('INSERT INTO mesaj_kuyrugu (kanal, telefon, alici_ad, metin, tur, ilgili_id) VALUES (?,?,?,?,?,?)');
    foreach ($alicilar as $tel => $ad) $st->execute([$kanal, $tel, $ad ?: null, $metin, $tur, $ilgiliId]);
    $GLOBALS['mesaj_tetik'] = true;
    return count($alicilar);
}

function mesaj_kuyrugu_isle(int $limit = 30): array
{
    global $pdo;
    $sonuc = ['gonderilen' => 0, 'hata' => 0, 'mesaj' => null];
    if (!(int)$pdo->query("SELECT GET_LOCK('apt_mesaj_kuyrugu', 0)")->fetchColumn()) { $sonuc['mesaj'] = 'Başka bir gönderim sürüyor.'; return $sonuc; }
    try {
        $kanallar = array_keys(array_filter(['sms' => sms_aktif(), 'whatsapp' => wa_aktif()]));
        if (!$kanallar) { $sonuc['mesaj'] = 'SMS ve WhatsApp gönderimi kapalı.'; return $sonuc; }
        $in = "'" . implode("','", $kanallar) . "'";
        $liste = $pdo->query("SELECT * FROM mesaj_kuyrugu WHERE durum = 'bekliyor' AND kanal IN ($in) AND (sonraki_deneme IS NULL OR sonraki_deneme <= NOW()) ORDER BY id LIMIT " . max(1, $limit))->fetchAll();
        $ok = $pdo->prepare("UPDATE mesaj_kuyrugu SET durum = 'gonderildi', gonderim = NOW(), hata = NULL, saglayici_id = ?, deneme = deneme + 1 WHERE id = ?");
        $kotu = $pdo->prepare("UPDATE mesaj_kuyrugu SET deneme = ?, hata = ?, durum = ?, sonraki_deneme = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?");
        $durdur = [];
        foreach ($liste as $m) {
            if (isset($durdur[$m['kanal']])) continue;
            try {
                $sid = $m['kanal'] === 'sms' ? sms_gonder_tek($m['telefon'], $m['metin']) : wa_gonder_tek($m['telefon'], $m['metin']);
                $ok->execute([mb_substr($sid, 0, 100), $m['id']]);
                $sonuc['gonderilen']++;
            } catch (Throwable $e) {
                $deneme = (int)$m['deneme'] + 1;
                $kotu->execute([$deneme, mb_substr($e->getMessage(), 0, 500), $deneme >= 3 ? 'hata' : 'bekliyor', $deneme * 10, $m['id']]);
                $sonuc['hata']++;
                $sonuc['mesaj'] = $e->getMessage();
                // Kimlik / ayar hatalarında aynı kanalda devam etmenin anlamı yok
                if (preg_match('/ayarlan|kullanıcı adı|Geçersiz kullanıcı|token|bağlanılamadı|kod 30|HTTP 401|HTTP 403/iu', $e->getMessage())) $durdur[$m['kanal']] = true;
            }
        }
        return $sonuc;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('apt_mesaj_kuyrugu')");
    }
}

/* =====================================================================
   OLAYLAR
   ===================================================================== */

function _mesaj_gonder_olay(string $olay, int $daireId, string $metin, string $tur, ?int $ilgili): int
{
    $n = 0;
    $zorla = !empty($GLOBALS['mesaj_zorla']);
    if (sms_aktif() && ($zorla || ayar('sms_' . $olay) === '1')) $n += mesaj_kuyruga('sms', daire_telefonlari($daireId, 'sms'), $metin, $tur, $ilgili);
    if (wa_aktif() && ($zorla || ayar('wa_' . $olay) === '1')) $n += mesaj_kuyruga('whatsapp', daire_telefonlari($daireId, 'whatsapp'), $metin, $tur, $ilgili);
    return $n;
}

function mesaj_makbuz(array $odemeIdler, ?array $bildirim = null): int
{
    global $pdo;
    if ((!sms_aktif() && !wa_aktif()) || !$odemeIdler) return 0;
    $in = implode(',', array_map('intval', $odemeIdler));
    $s = $pdo->query("SELECT o.id, o.tutar, o.tarih, o.daire_id, d.blok, d.daire_no FROM odemeler o JOIN daireler d ON d.id = o.daire_id WHERE o.id IN ($in) ORDER BY o.id")->fetchAll();
    if (!$s) return 0;
    $toplam = array_sum(array_column($s, 'tutar'));
    $link = kisa_link('odeme', implode(',', array_column($s, 'id')), (int)$s[0]['daire_id'], 180);
    $metin = mesaj_sablon($bildirim ? 'onay' : 'makbuz', ['site' => ayar('site_adi'), 'daire' => $s[0]['blok'] . '-' . $s[0]['daire_no'],
        'tutar' => para($toplam), 'tarih' => tarih($s[0]['tarih']), 'link' => $link]);
    return _mesaj_gonder_olay($bildirim ? 'bildirim' : 'makbuz', (int)$s[0]['daire_id'], $metin, $bildirim ? 'bildirim' : 'makbuz', (int)$s[0]['id']);
}

function mesaj_bildirim_red(array $bl): int
{
    if (!sms_aktif() && !wa_aktif()) return 0;
    $link = kisa_link('bildirim', (string)$bl['id'], (int)$bl['daire_id'], 60);
    $metin = mesaj_sablon('red', ['site' => ayar('site_adi'), 'tutar' => para($bl['tutar']), 'tarih' => tarih($bl['tarih']),
        'neden' => mb_strimwidth((string)$bl['red_neden'], 0, 90, '…'), 'link' => $link]);
    return _mesaj_gonder_olay('bildirim', (int)$bl['daire_id'], $metin, 'bildirim', (int)$bl['id']);
}

function mesaj_duyuru(int $duyuruId, bool $elle = false): int
{
    global $pdo;
    if (!sms_aktif() && !wa_aktif()) return 0;
    $st = $pdo->prepare('SELECT * FROM duyurular WHERE id = ?');
    $st->execute([$duyuruId]);
    $du = $st->fetch();
    if (!$du) return 0;
    $metin = mesaj_sablon('duyuru', ['site' => ayar('site_adi'), 'baslik' => mb_strimwidth($du['baslik'], 0, 80, '…'), 'link' => kisa_link('duyuru', (string)$duyuruId, 0, 120)]);
    $n = 0;
    foreach (['sms' => sms_aktif(), 'whatsapp' => wa_aktif()] as $kanal => $acik) {
        if (!$acik || (!$elle && ayar(($kanal === 'sms' ? 'sms' : 'wa') . '_duyuru') !== '1')) continue;
        $alici = [];
        foreach ($pdo->query('SELECT id FROM daireler')->fetchAll(PDO::FETCH_COLUMN) as $id) $alici += daire_telefonlari((int)$id, $kanal);
        $n += mesaj_kuyruga($kanal, $alici, $metin, 'duyuru', $duyuruId);
    }
    return $n;
}

/* Tüm kanallara birlikte bildirim (e-posta + SMS + WhatsApp) */
function bildir_makbuz(array $odemeIdler, ?array $bildirim = null): array
{
    return ['eposta' => eposta_makbuz($odemeIdler, $bildirim), 'mesaj' => mesaj_makbuz($odemeIdler, $bildirim)];
}
function bildir_red(array $bl): array
{
    return ['eposta' => eposta_bildirim_red($bl), 'mesaj' => mesaj_bildirim_red($bl)];
}
function bildir_duyuru(int $id, bool $elle = false): array
{
    return ['eposta' => eposta_duyuru($id), 'mesaj' => mesaj_duyuru($id, $elle)];
}
function mesaj_borc_hatirlat(int $daireId, bool $sadeceGeciken = false): int
{
    global $pdo;
    if (!sms_aktif() && !wa_aktif()) return 0;
    $acik = acik_borclar($pdo, $daireId);
    if ($sadeceGeciken) {
        $acik = array_values(array_filter($acik, fn($b) => $b['son_odeme'] < date('Y-m-d')));
    }
    if (!$acik) return 0;
    $ilk = $acik[0];
    $toplam = round(array_sum(array_map(fn($b) => $b['tutar'] - $b['odenen'], $acik)), 2);
    $link = kisa_link('borc', (string)$daireId, $daireId, 30);
    $metin = ayar('site_adi') . ': ' . $ilk['blok'] . '-' . $ilk['daire_no'] . ' dairenizin ' . para($toplam) . ' ödenmemiş borcu bulunmaktadır. Detay ve ödeme: ' . $link;
    $n = 0;
    if (sms_aktif()) $n += mesaj_kuyruga('sms', daire_telefonlari($daireId, 'sms'), $metin, 'genel', $daireId);
    if (wa_aktif()) $n += mesaj_kuyruga('whatsapp', daire_telefonlari($daireId, 'whatsapp'), $metin, 'genel', $daireId);
    return $n;
}
function bildir_borc_hatirlat(int $daireId, bool $sadeceGeciken = false): array
{
    return ['eposta' => eposta_borc_hatirlat($daireId, $sadeceGeciken), 'mesaj' => mesaj_borc_hatirlat($daireId, $sadeceGeciken)];
}
function bildirim_ozeti(array $r): string
{
    $p = [];
    if ($r['eposta']) $p[] = $r['eposta'] . ' e-posta';
    if ($r['mesaj']) $p[] = $r['mesaj'] . ' SMS/WhatsApp';
    return $p ? ' Bildirim gönderiliyor: ' . implode(', ', $p) . '.' : '';
}
function herhangi_bildirim_aktif(): bool { return eposta_aktif() || sms_aktif() || wa_aktif(); }

/* =====================================================================
   wa.me — WhatsApp Business API olmadan, tek tıkla elle paylaşım (ücretsiz)
   ===================================================================== */
function wa_me(?string $tel, string $metin): string
{
    $n = $tel ? telefon_normal($tel) : null;
    return 'https://wa.me/' . ($n ?: '') . '?text=' . rawurlencode($metin);
}

/* =====================================================================
   ARKA PLAN: e-posta + mesaj kuyruğu + kayıt saklama süresi
   ===================================================================== */
function arka_plan_isleri(): void
{
    try {
        global $pdo;
        if (!isset($pdo)) return;
        // Günde bir: saklama süresi dolan kayıtları imha et (KVKK)
        if (ayar('log_temizlik') !== date('Y-m-d')) {
            $gun = (int)ayar('log_saklama_gun', 730);
            if ($gun > 0) {
                $pdo->prepare('DELETE FROM islem_log WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)')->execute([$gun]);
                $pdo->prepare("DELETE FROM eposta_kuyrugu WHERE durum IN ('gonderildi','iptal','hata') AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)")->execute([min($gun, 365)]);
                $pdo->prepare("DELETE FROM mesaj_kuyrugu WHERE durum IN ('gonderildi','iptal','hata') AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)")->execute([min($gun, 365)]);
                $pdo->exec('DELETE FROM kisa_linkler WHERE created_at < DATE_SUB(NOW(), INTERVAL 185 DAY)');
            }
            $pdo->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('log_temizlik', ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)")->execute([date('Y-m-d')]);
        }

        $eAktif = eposta_aktif();
        $mAktif = sms_aktif() || wa_aktif();
        if (!$eAktif && !$mAktif) return;
        $tetik = !empty($GLOBALS['eposta_tetik']) || !empty($GLOBALS['mesaj_tetik']);
        if (!$tetik && time() - (int)ayar('mail_son_calisma', 0) < 60) return;
        $bekleyen = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM eposta_kuyrugu WHERE durum = 'bekliyor' AND (sonraki_deneme IS NULL OR sonraki_deneme <= NOW()))
                                        + (SELECT COUNT(*) FROM mesaj_kuyrugu WHERE durum = 'bekliyor' AND (sonraki_deneme IS NULL OR sonraki_deneme <= NOW()))")->fetchColumn();
        if (!$bekleyen) {
            $pdo->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('mail_son_calisma', ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)")->execute([(string)time()]);
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (!headers_sent() && !ini_get('zlib.output_compression')) {
            $govde = '';
            while (ob_get_level() > 0) $govde .= ob_get_clean();
            header('Content-Length: ' . strlen($govde));
            header('Connection: close');
            echo $govde;
            flush();
        } elseif (!$tetik) {
            return;
        }
        ignore_user_abort(true);
        @set_time_limit(120);
        // Yanıt zaten gönderildi: kuyruk boşalana kadar (en fazla ~100 sn) partiler hâlinde gönder
        $bas = time();
        do {
            $e = $eAktif ? eposta_kuyrugu_isle($tetik ? 30 : 10) : ['gonderilen' => 0, 'hata' => 0];
            $m = $mAktif ? mesaj_kuyrugu_isle($tetik ? 30 : 10) : ['gonderilen' => 0, 'hata' => 0];
            $islenen = ($e['gonderilen'] ?? 0) + ($e['hata'] ?? 0) + ($m['gonderilen'] ?? 0) + ($m['hata'] ?? 0);
        } while ($tetik && $islenen > 0 && time() - $bas < 100);
        $pdo->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('mail_son_calisma', ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)")->execute([(string)time()]);
    } catch (Throwable $e) {
        // arka plan hatası sayfayı etkilemez
    }
}
