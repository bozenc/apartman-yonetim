<?php
/**
 * E-posta altyapısı
 *  - Harici kütüphane gerektirmeyen SMTP istemcisi (STARTTLS / SSL / AUTH LOGIN)
 *  - PHP mail() yedeği
 *  - Kuyruk: e-postalar önce eposta_kuyrugu tablosuna yazılır, arka planda gönderilir
 *  - İmzalı bağlantılar: e-postadaki link, giriş yapmadan ilgili detayı gösterir
 *  - HTML e-posta şablonu
 */

defined('SMTP_SERTIFIKA_DOGRULA') || define('SMTP_SERTIFIKA_DOGRULA', true);

/* =====================================================================
   SMTP İSTEMCİSİ
   ===================================================================== */
class Smtp
{
    private $s = null;

    public function __construct(
        private string $host, private int $port, private string $guvenlik,
        private string $kullanici, private string $sifre, private int $zaman = 12
    ) {}

    public function baglan(): void
    {
        $dogrula = (bool)SMTP_SERTIFIKA_DOGRULA;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $dogrula, 'verify_peer_name' => $dogrula, 'allow_self_signed' => !$dogrula, 'SNI_enabled' => true, 'peer_name' => $this->host,
        ]]);
        $hedef = ($this->guvenlik === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $this->s = @stream_socket_client($hedef, $no, $msg, $this->zaman, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) {
            throw new RuntimeException("SMTP sunucusuna bağlanılamadı ({$this->host}:{$this->port}). " . ($msg ?: 'Sunucu adresi, port ve güvenlik türünü kontrol edin.'));
        }
        stream_set_timeout($this->s, $this->zaman);
        $this->bekle([220]);
        $this->komut('EHLO ' . $this->yerelAd(), [250]);
        if ($this->guvenlik === 'tls') {
            $this->komut('STARTTLS', [220]);
            $yontem = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) $yontem |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $yontem |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            if (!@stream_socket_enable_crypto($this->s, true, $yontem)) {
                throw new RuntimeException('TLS bağlantısı kurulamadı. Güvenlik türünü "SSL" (port 465) olarak deneyin.');
            }
            $this->komut('EHLO ' . $this->yerelAd(), [250]);
        }
        if ($this->kullanici !== '') {
            $this->komut('AUTH LOGIN', [334]);
            $this->komut(base64_encode($this->kullanici), [334]);
            try {
                $this->komut(base64_encode($this->sifre), [235]);
            } catch (RuntimeException $e) {
                throw new RuntimeException('SMTP kullanıcı adı veya şifresi kabul edilmedi. Gmail/Outlook için normal şifre yerine "Uygulama Şifresi" kullanın. (' . $e->getMessage() . ')');
            }
        }
    }

    public function gonder(string $from, string $mesaj, string $to): void
    {
        $from = preg_replace('/[\r\n<>\\s]/', '', $from);
        $to   = preg_replace('/[\r\n<>\\s]/', '', $to);
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Geçersiz e-posta adresi formatı.');
        }
        $this->komut('MAIL FROM:<' . $from . '>', [250]);
        $this->komut('RCPT TO:<' . $to . '>', [250, 251]);
        $this->komut('DATA', [354]);
        $mesaj = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $mesaj));
        fwrite($this->s, str_replace("\n", "\r\n", $mesaj) . "\r\n.\r\n");
        $this->bekle([250]);
    }

    public function sifirla(): void
    {
        try { $this->komut('RSET', [250]); } catch (Throwable $e) {}
    }

    public function kapat(): void
    {
        if ($this->s) { @fwrite($this->s, "QUIT\r\n"); @fclose($this->s); $this->s = null; }
    }

    private function yerelAd(): string
    {
        $h = $_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost';
        return preg_replace('/[^A-Za-z0-9.-]/', '', $h) ?: 'localhost';
    }

    private function oku(): string
    {
        $yanit = '';
        while (($satir = fgets($this->s, 1024)) !== false) {
            $yanit .= $satir;
            if (strlen($satir) < 4 || $satir[3] === ' ') break;
        }
        if ($yanit === '') {
            $meta = stream_get_meta_data($this->s);
            throw new RuntimeException(!empty($meta['timed_out']) ? 'SMTP sunucusu zaman aşımına uğradı.' : 'SMTP bağlantısı kapandı.');
        }
        return $yanit;
    }

    private function bekle(array $kodlar): string
    {
        $y = $this->oku();
        if (!in_array((int)substr($y, 0, 3), $kodlar, true)) throw new RuntimeException('SMTP: ' . trim(preg_replace('/\s+/', ' ', $y)));
        return $y;
    }

    private function komut(string $c, array $kodlar): string
    {
        fwrite($this->s, $c . "\r\n");
        return $this->bekle($kodlar);
    }
}

/* =====================================================================
   MESAJ OLUŞTURMA VE DOĞRUDAN GÖNDERİM
   ===================================================================== */

function eposta_basligi(string $s): string
{
    $s = trim(str_replace(["\r", "\n"], ' ', $s));
    return mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
}

function eposta_adres(string $eposta, ?string $ad): string
{
    $eposta = preg_replace('/[\r\n<>\\s]/', '', $eposta);
    return $ad ? eposta_basligi($ad) . ' <' . $eposta . '>' : '<' . $eposta . '>';
}

/** Ayarlardaki SMTP şifresini çözer (AES-256-GCM ve geriye dönük AES-256-CBC / base64 uyumlu) */
function smtp_sifre_coz(string $kayitli): string
{
    if ($kayitli === '') return '';
    if (str_starts_with($kayitli, 'gcm:') && function_exists('openssl_decrypt')) {
        $ham = base64_decode(substr($kayitli, 4), true);
        if ($ham === false || strlen($ham) < 28) return '';
        $iv = substr($ham, 0, 12);
        $tag = substr($ham, 12, 16);
        $sifreli = substr($ham, 28);
        return (string)openssl_decrypt($sifreli, 'aes-256-gcm', hash('sha256', gizli_anahtar(), true), OPENSSL_RAW_DATA, $iv, $tag);
    }
    if (str_starts_with($kayitli, 'enc:') && function_exists('openssl_decrypt')) {
        $ham = base64_decode(substr($kayitli, 4));
        $iv = substr($ham, 0, 16);
        return (string)openssl_decrypt(substr($ham, 16), 'aes-256-cbc', hash('sha256', gizli_anahtar(), true), OPENSSL_RAW_DATA, $iv);
    }
    if (str_starts_with($kayitli, 'b64:')) return (string)base64_decode(substr($kayitli, 4));
    return $kayitli;
}

function smtp_sifre_sifrele(string $sifre): string
{
    if ($sifre === '') return '';
    $anahtar = hash('sha256', gizli_anahtar(), true);
    if (function_exists('openssl_encrypt')) {
        if (in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            $iv = random_bytes(12);
            $tag = '';
            $sifreli = openssl_encrypt($sifre, 'aes-256-gcm', $anahtar, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
            if ($sifreli !== false) {
                return 'gcm:' . base64_encode($iv . $tag . $sifreli);
            }
        }
        $iv = random_bytes(16);
        return 'enc:' . base64_encode($iv . openssl_encrypt($sifre, 'aes-256-cbc', $anahtar, OPENSSL_RAW_DATA, $iv));
    }
    return 'b64:' . base64_encode($sifre);
}

function eposta_gonderen(): array
{
    $g = trim((string)ayar('mail_gonderen')) ?: trim((string)ayar('smtp_kullanici'));
    return [$g, trim((string)ayar('mail_gonderen_ad')) ?: (string)ayar('site_adi', 'Apartman Yönetimi')];
}

/** RFC 5322 mesaj metni (başlıklar + multipart/alternative gövde) */
function eposta_mesaji(string $kime, ?string $kimeAd, string $konu, string $html, string $metin, bool $toBasligi = true): string
{
    [$from, $fromAd] = eposta_gonderen();
    $sinir = 'b_' . bin2hex(random_bytes(10));
    $alan = substr(strrchr($from, '@') ?: '@localhost', 1);
    $b = [];
    $b[] = 'Date: ' . date('r');
    $b[] = 'From: ' . eposta_adres($from, $fromAd);
    if ($toBasligi) $b[] = 'To: ' . eposta_adres($kime, $kimeAd);
    if ($toBasligi) $b[] = 'Subject: ' . eposta_basligi($konu);
    $b[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $alan . '>';
    $b[] = 'MIME-Version: 1.0';
    $b[] = 'X-Mailer: Apartman Yonetim Sistemi';
    $b[] = 'Auto-Submitted: auto-generated';
    $b[] = 'Content-Type: multipart/alternative; boundary="' . $sinir . '"';
    $govde = "--$sinir\nContent-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n" . rtrim(chunk_split(base64_encode($metin), 76, "\n"))
        . "\n--$sinir\nContent-Type: text/html; charset=UTF-8\nContent-Transfer-Encoding: base64\n\n" . rtrim(chunk_split(base64_encode($html), 76, "\n"))
        . "\n--$sinir--";
    return implode("\n", $b) . "\n\n" . $govde;
}

/** Tek bir e-postayı hemen gönderir (test için). Hata olursa RuntimeException. */
function eposta_hemen_gonder(string $kime, ?string $kimeAd, string $konu, string $html, string $metin): void
{
    [$from] = eposta_gonderen();
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Gönderen e-posta adresi ayarlanmamış.');
    if (ayar('mail_yontem') === 'mail') {
        $mesaj = eposta_mesaji($kime, $kimeAd, $konu, $html, $metin, false);
        [$basliklar, $govde] = explode("\n\n", $mesaj, 2);
        if (!@mail($kime, eposta_basligi($konu), str_replace("\n", "\r\n", $govde), str_replace("\n", "\r\n", $basliklar), '-f' . $from)) {
            throw new RuntimeException('PHP mail() e-postayı gönderemedi. Sunucunuzda e-posta servisi yapılandırılmamış olabilir; SMTP kullanmanız önerilir.');
        }
        return;
    }
    $smtp = smtp_olustur();
    try {
        $smtp->baglan();
        $smtp->gonder($from, eposta_mesaji($kime, $kimeAd, $konu, $html, $metin), $kime);
    } finally {
        $smtp->kapat();
    }
}

function smtp_olustur(): Smtp
{
    $host = trim((string)ayar('smtp_host'));
    if ($host === '') throw new RuntimeException('SMTP sunucusu ayarlanmamış.');
    return new Smtp($host, (int)ayar('smtp_port', 587) ?: 587, (string)ayar('smtp_guvenlik', 'tls'),
        trim((string)ayar('smtp_kullanici')), smtp_sifre_coz((string)ayar('smtp_sifre')));
}

/* =====================================================================
   KUYRUK
   ===================================================================== */

function eposta_aktif(): bool
{
    return ayar('mail_aktif') === '1';
}

/** Kuyruğa ekler; eklenen alıcı sayısını döner */
function eposta_kuyruga(array $alicilar, string $konu, array|callable $icerik, string $tur, ?int $ilgiliId = null): int
{
    global $pdo;
    if (!eposta_aktif() || !$alicilar) return 0;
    $st = $pdo->prepare('INSERT INTO eposta_kuyrugu (alici, alici_ad, konu, html, metin, tur, ilgili_id) VALUES (?,?,?,?,?,?,?)');
    $n = 0;
    foreach ($alicilar as $eposta => $ad) {
        [$html, $metin] = is_callable($icerik) ? $icerik($ad) : $icerik;
        $st->execute([$eposta, $ad ?: null, mb_substr($konu, 0, 255), $html, $metin, $tur, $ilgiliId]);
        $n++;
    }
    if ($n) $GLOBALS['eposta_tetik'] = true;
    return $n;
}

/**
 * Bekleyen e-postaları gönderir. Aynı anda tek işlem çalışır (MySQL kilidi).
 * @return array{gonderilen:int, hata:int, mesaj:?string}
 */
function eposta_kuyrugu_isle(int $limit = 20): array
{
    global $pdo;
    $sonuc = ['gonderilen' => 0, 'hata' => 0, 'mesaj' => null];
    if (!eposta_aktif()) { $sonuc['mesaj'] = 'E-posta gönderimi kapalı.'; return $sonuc; }
    if (!(int)$pdo->query("SELECT GET_LOCK('apt_eposta_kuyrugu', 0)")->fetchColumn()) { $sonuc['mesaj'] = 'Başka bir gönderim işlemi sürüyor.'; return $sonuc; }
    try {
        $pdo->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('mail_son_calisma', ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)")->execute([(string)time()]);
        $liste = $pdo->query("SELECT * FROM eposta_kuyrugu WHERE durum = 'bekliyor' AND (sonraki_deneme IS NULL OR sonraki_deneme <= NOW()) ORDER BY id LIMIT " . max(1, $limit))->fetchAll();
        if (!$liste) return $sonuc;

        [$from] = eposta_gonderen();
        $ok = $pdo->prepare("UPDATE eposta_kuyrugu SET durum = 'gonderildi', gonderim = NOW(), hata = NULL, deneme = deneme + 1 WHERE id = ?");
        $basarisiz = function (array $m, string $hata) use ($pdo, &$sonuc) {
            $deneme = (int)$m['deneme'] + 1;
            $pdo->prepare("UPDATE eposta_kuyrugu SET deneme = ?, hata = ?, durum = ?, sonraki_deneme = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?")
                ->execute([$deneme, mb_substr($hata, 0, 500), $deneme >= 3 ? 'hata' : 'bekliyor', $deneme * 10, $m['id']]);
            $sonuc['hata']++;
        };

        if (ayar('mail_yontem') === 'mail') {
            foreach ($liste as $m) {
                try { eposta_hemen_gonder($m['alici'], $m['alici_ad'], $m['konu'], $m['html'], (string)$m['metin']); $ok->execute([$m['id']]); $sonuc['gonderilen']++; }
                catch (Throwable $e) { $basarisiz($m, $e->getMessage()); }
            }
            return $sonuc;
        }

        $smtp = null;
        try {
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Gönderen e-posta adresi ayarlanmamış.');
            $smtp = smtp_olustur();
            $smtp->baglan();
        } catch (Throwable $e) {
            // Bağlantı kurulamadıysa bu turdaki iletileri ertele
            foreach ($liste as $m) $basarisiz($m, $e->getMessage());
            $sonuc['mesaj'] = $e->getMessage();
            if ($smtp) $smtp->kapat();
            return $sonuc;
        }
        foreach ($liste as $m) {
            try {
                $smtp->gonder($from, eposta_mesaji($m['alici'], $m['alici_ad'], $m['konu'], $m['html'], (string)$m['metin']), $m['alici']);
                $ok->execute([$m['id']]);
                $sonuc['gonderilen']++;
            } catch (Throwable $e) {
                $basarisiz($m, $e->getMessage());
                $smtp->sifirla();
            }
        }
        $smtp->kapat();
        return $sonuc;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('apt_eposta_kuyrugu')");
    }
}

/**
 * Sayfa yanıtı tarayıcıya gönderildikten sonra kuyruğu işler (kullanıcı beklemez).
 * register_shutdown_function ile çağrılır.
 */
function eposta_arka_plan(): void
{
    try {
        global $pdo;
        if (!isset($pdo) || !eposta_aktif()) return;
        $tetik = !empty($GLOBALS['eposta_tetik']);
        $son = (int)ayar('mail_son_calisma', 0);
        if (!$tetik && time() - $son < 60) return;
        if (!$tetik && !(int)$pdo->query("SELECT COUNT(*) FROM eposta_kuyrugu WHERE durum = 'bekliyor' AND (sonraki_deneme IS NULL OR sonraki_deneme <= NOW())")->fetchColumn()) {
            $pdo->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('mail_son_calisma', ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)")->execute([(string)time()]);
            return;
        }

        // Yanıtı tamamlayıp bağlantıyı bırak
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
            return; // yanıt zaten akıyor; kullanıcıyı bekletmemek için sonraki isteğe bırak
        }
        ignore_user_abort(true);
        @set_time_limit(120);
        eposta_kuyrugu_isle($tetik ? 30 : 10);
    } catch (Throwable $e) {
        // arka plan hatası sayfayı etkilemez
    }
}

/* =====================================================================
   İMZALI BAĞLANTILAR
   ===================================================================== */

function site_kok_url(): string
{
    $u = rtrim(trim((string)ayar('site_url')), '/');
    if ($u !== '') return $u;
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . BASE_URL;
    }
    return 'http://localhost' . BASE_URL;
}

function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_coz(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/')); }

/** tur: odeme | duyuru | bildirim ; ids: virgülle id listesi */
function imzali_link(string $tur, string $ids, int $daireId, int $gun): string
{
    $yuk = $tur . '|' . $ids . '|' . $daireId . '|' . (time() + $gun * 86400);
    $imza = substr(b64url(hash_hmac('sha256', $yuk, gizli_anahtar(), true)), 0, 22);
    return site_kok_url() . '/goster.php?t=' . b64url($yuk) . '.' . $imza;
}

/** @return array{tur:string, ids:int[], daire:int}|null */
function link_dogrula(?string $t): ?array
{
    if (!$t || !str_contains($t, '.')) return null;
    [$yukB, $imza] = explode('.', $t, 2);
    $yuk = b64url_coz($yukB);
    $beklenen = substr(b64url(hash_hmac('sha256', $yuk, gizli_anahtar(), true)), 0, 22);
    if (!hash_equals($beklenen, $imza)) return null;
    $p = explode('|', $yuk);
    if (count($p) !== 4 || (int)$p[3] < time()) return null;
    return ['tur' => $p[0], 'ids' => array_values(array_filter(array_map('intval', explode(',', $p[1])))), 'daire' => (int)$p[2]];
}

/* =====================================================================
   ALICILAR
   ===================================================================== */

/** Bir dairenin e-posta alıcıları [eposta => ad] */
function daire_alicilari(int $daireId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT d.*, k.eposta AS k_eposta, k.ad_soyad AS k_ad, k.eposta_bildirim AS k_izin, k.aktif AS k_aktif
                         FROM daireler d LEFT JOIN kullanicilar k ON k.id = d.kullanici_id WHERE d.id = ?');
    $st->execute([$daireId]);
    $d = $st->fetch();
    if (!$d) return [];
    $liste = [];
    $ekle = function (?string $e, ?string $ad) use (&$liste) {
        $e = mb_strtolower(trim((string)$e));
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !isset($liste[$e])) $liste[$e] = $ad ?: null;
    };
    if ($d['k_eposta'] && (int)$d['k_izin'] === 1 && (int)$d['k_aktif'] === 1) $ekle($d['k_eposta'], $d['k_ad']);
    $ekle($d['sakin_eposta'], $d['sakin_adi'] ?: $d['malik_adi']);
    if ($d['oturan_tipi'] === 'malik' || $d['durum'] === 'bos' || ayar('mail_malik') === '1') $ekle($d['malik_eposta'], $d['malik_adi']);
    return $liste;
}

/** Tüm sakinler (duyurular için) [eposta => ad] */
function tum_sakin_alicilari(): array
{
    global $pdo;
    $liste = [];
    foreach ($pdo->query("SELECT id FROM daireler ORDER BY blok, CAST(daire_no AS UNSIGNED)")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $liste += daire_alicilari((int)$id);
    }
    return $liste;
}

/* =====================================================================
   ŞABLON
   ===================================================================== */

/**
 * @param array $satirlar [['Etiket', 'Değer'], ...]
 * @return array{0:string,1:string} [html, düz metin]
 */
function eposta_sablon(string $baslik, string $selam, string $giris, array $satirlar, string $butonYazi, string $link, string $not = '', string $vurgu = '', string $renk = '#4f46e5'): array
{
    $site = (string)ayar('site_adi', 'Apartman Yönetimi');
    $alt = implode(' · ', array_filter([ayar('adres'), ayar('telefon')]));
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $kok = site_kok_url();
    $kvkkLink = $kok . '/yasal.php?metin=kvkk';
    $tercihLink = $kok . '/profil.php';
    $tablo = '';
    foreach ($satirlar as [$k, $v]) {
        $tablo .= '<tr><td style="padding:9px 0;color:#64748b;font-size:14px;border-bottom:1px solid #eef1f6;width:42%">' . $h($k)
            . '</td><td style="padding:9px 0;color:#0f172a;font-size:14px;font-weight:600;border-bottom:1px solid #eef1f6;text-align:right">' . nl2br($h($v)) . '</td></tr>';
    }
    $html = '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($baslik) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f4f6fb;font-family:Segoe UI,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fb;padding:28px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e7eaf3">'
        . '<tr><td style="background:' . $renk . ';padding:22px 28px;color:#ffffff"><div style="font-size:19px;font-weight:700">' . $h($site) . '</div>'
        . '<div style="font-size:13px;opacity:.85;margin-top:3px">Apartman Yönetim Sistemi</div></td></tr>'
        . '<tr><td style="padding:28px">'
        . '<h1 style="margin:0 0 14px;font-size:21px;color:#0f172a">' . $h($baslik) . '</h1>'
        . '<p style="margin:0 0 6px;font-size:15px;color:#334155">' . $h($selam) . '</p>'
        . '<p style="margin:0 0 18px;font-size:15px;color:#334155;line-height:1.6">' . nl2br($h($giris)) . '</p>'
        . ($vurgu !== '' ? '<div style="background:#eef2ff;border-radius:12px;padding:16px 18px;margin:0 0 18px;text-align:center"><div style="font-size:26px;font-weight:800;color:' . $renk . '">' . $h($vurgu) . '</div></div>' : '')
        . ($tablo ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 22px">' . $tablo . '</table>' : '')
        . '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:6px auto 18px"><tr><td style="border-radius:10px;background:' . $renk . '">'
        . '<a href="' . $h($link) . '" style="display:inline-block;padding:13px 28px;font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px">' . $h($butonYazi) . '</a></td></tr></table>'
        . ($not !== '' ? '<p style="margin:0 0 10px;font-size:13px;color:#64748b;line-height:1.55">' . nl2br($h($not)) . '</p>' : '')
        . '<p style="margin:14px 0 0;font-size:12px;color:#94a3b8;line-height:1.5">Düğme çalışmazsa bu adresi tarayıcınıza yapıştırın:<br><a href="' . $h($link) . '" style="color:#64748b;word-break:break-all">' . $h($link) . '</a></p>'
        . '</td></tr>'
        . '<tr><td style="background:#f8fafc;padding:16px 28px;font-size:12px;color:#94a3b8;line-height:1.5;border-top:1px solid #eef1f6">'
        . $h($site) . ($alt ? '<br>' . $h($alt) : '') . '<br>Bu e-posta otomatik olarak gönderilmiştir, lütfen yanıtlamayınız.'
        . '<br>Kişisel verileriniz <a href="' . $h($kvkkLink) . '" style="color:#64748b">KVKK Aydınlatma Metni</a> kapsamında işlenir. '
        . 'Bildirimleri <a href="' . $h($tercihLink) . '" style="color:#64748b">Profilim</a> sayfasından kapatabilirsiniz.</td></tr>'
        . '</table></td></tr></table></body></html>';

    $metin = $site . "\n" . str_repeat('=', mb_strlen($site)) . "\n\n" . $baslik . "\n\n" . $selam . "\n" . $giris . "\n\n";
    if ($vurgu !== '') $metin .= $vurgu . "\n\n";
    foreach ($satirlar as [$k, $v]) $metin .= $k . ': ' . str_replace("\n", "\n   ", (string)$v) . "\n";
    $metin .= "\n" . $butonYazi . ":\n" . $link . "\n\n" . ($not !== '' ? $not . "\n\n" : '') . "--\nBu e-posta otomatik olarak gönderilmiştir.\nKVKK Aydınlatma Metni: " . $kvkkLink . "\nBildirim tercihleri: " . $tercihLink . "\n";
    return [$html, $metin];
}

function selamla(?string $ad): string
{
    return $ad ? 'Sayın ' . $ad . ',' : 'Merhaba,';
}

/* =====================================================================
   OLAY E-POSTALARI
   ===================================================================== */

/**
 * Tahsilat makbuzu e-postası (tek veya çok kalemli).
 * @param int[] $odemeIdler aynı daireye ait ödeme kayıtları
 * @param array|null $bildirim onaylanan ödeme bildirimi (varsa)
 */
function eposta_makbuz(array $odemeIdler, ?array $bildirim = null): int
{
    global $pdo;
    if (!eposta_aktif() || !$odemeIdler) return 0;
    if (ayar($bildirim ? 'mail_bildirim' : 'mail_makbuz') !== '1' && empty($GLOBALS['eposta_zorla'])) return 0;
    $in = implode(',', array_map('intval', $odemeIdler));
    $satirlar = $pdo->query("SELECT o.*, b.aciklama AS borc_aciklama, d.blok, d.daire_no FROM odemeler o JOIN borclar b ON b.id = o.borc_id
                             JOIN daireler d ON d.id = o.daire_id WHERE o.id IN ($in) ORDER BY o.id")->fetchAll();
    if (!$satirlar) return 0;
    $ilk = $satirlar[0];
    $daireId = (int)$ilk['daire_id'];
    $alicilar = daire_alicilari($daireId);
    if (!$alicilar) return 0;

    $toplam = array_sum(array_column($satirlar, 'tutar'));
    $st = $pdo->prepare('SELECT COALESCE(SUM(tutar - odenen), 0) FROM borclar WHERE daire_id = ? AND tutar > odenen');
    $st->execute([$daireId]);
    $kalan = (float)$st->fetchColumn();
    $daire = $ilk['blok'] . ' Blok, No: ' . $ilk['daire_no'];
    $no = $bildirim ? 'BL' . date('y', strtotime($ilk['created_at'])) . str_pad((string)$bildirim['id'], 6, '0', STR_PAD_LEFT) : $ilk['makbuz_no'] . (count($satirlar) > 1 ? ' (+' . (count($satirlar) - 1) . ')' : '');
    $link = imzali_link('odeme', implode(',', array_column($satirlar, 'id')), $daireId, 180);
    $kalemler = implode("\n", array_map(fn($s) => $s['borc_aciklama'] . ' — ' . para($s['tutar']), $satirlar));
    $detay = [['Makbuz No', $no], ['Daire', $daire], ['Ödeme Tarihi', tarih($ilk['tarih'])],
              ['Ödeme Yöntemi', odeme_yontemleri()[$ilk['yontem']] ?? $ilk['yontem']], ['Ödenen Kalemler', $kalemler], ['Kalan Borcunuz', para($kalan)]];
    $giris = $bildirim
        ? 'Yüklediğiniz dekont incelendi ve ' . tarih($ilk['tarih']) . ' tarihli ödemeniz onaylandı. Ödemeniz aşağıdaki borçlarınızdan düşülmüştür.'
        : $daire . ' için ' . tarih($ilk['tarih']) . ' tarihli ödemeniz kaydedilmiştir. Teşekkür ederiz.';
    $konu = ($bildirim ? 'Ödemeniz onaylandı' : 'Ödemeniz alındı') . ' – ' . para($toplam) . ' · ' . ayar('site_adi');
    return eposta_kuyruga($alicilar, $konu, fn($ad) => eposta_sablon(
        $bildirim ? 'Ödeme bildiriminiz onaylandı' : 'Ödemeniz alındı', selamla($ad), $giris, $detay,
        'Makbuzu Görüntüle', $link, 'Bağlantıdan makbuzunuzun detayını görebilir ve PDF olarak indirebilirsiniz.', para($toplam), '#059669'
    ), $bildirim ? 'bildirim' : 'makbuz', (int)$ilk['id']);
}

/** Ödeme bildirimi reddedildi e-postası */
function eposta_bildirim_red(array $bildirim): int
{
    global $pdo;
    if (!eposta_aktif() || ayar('mail_bildirim') !== '1') return 0;
    $alicilar = daire_alicilari((int)$bildirim['daire_id']);
    if (!$alicilar) return 0;
    $st = $pdo->prepare('SELECT * FROM daireler WHERE id = ?');
    $st->execute([$bildirim['daire_id']]);
    $d = $st->fetch();
    $link = imzali_link('bildirim', (string)$bildirim['id'], (int)$bildirim['daire_id'], 60);
    $detay = [['Daire', $d['blok'] . ' Blok, No: ' . $d['daire_no']], ['Bildirilen Tutar', para($bildirim['tutar'])], ['Ödeme Tarihi', tarih($bildirim['tarih'])],
              ['Reddetme Nedeni', (string)$bildirim['red_neden']]];
    return eposta_kuyruga($alicilar, 'Ödeme bildiriminiz onaylanmadı · ' . ayar('site_adi'), fn($ad) => eposta_sablon(
        'Ödeme bildiriminiz onaylanmadı', selamla($ad),
        'Gönderdiğiniz ödeme bildirimi ve dekont yönetim tarafından incelendi ancak onaylanamadı. Nedeni aşağıda belirtilmiştir.',
        $detay, 'Detayı Görüntüle', $link, 'Gerekirse dekontunuzu sisteme yeniden yükleyebilir veya yönetimle iletişime geçebilirsiniz.', '', '#dc2626'
    ), 'bildirim', (int)$bildirim['id']);
}

/** Duyuru e-postası (tüm sakinlere) */
function eposta_duyuru(int $duyuruId): int
{
    global $pdo;
    if (!eposta_aktif()) return 0;
    $st = $pdo->prepare('SELECT d.*, k.ad_soyad FROM duyurular d LEFT JOIN kullanicilar k ON k.id = d.kullanici_id WHERE d.id = ?');
    $st->execute([$duyuruId]);
    $du = $st->fetch();
    if (!$du) return 0;
    $link = imzali_link('duyuru', (string)$duyuruId, 0, 120);
    $ozet = mb_strlen($du['icerik']) > 700 ? mb_substr($du['icerik'], 0, 700) . '…' : $du['icerik'];
    return eposta_kuyruga(tum_sakin_alicilari(), ($du['onemli'] ? 'Önemli duyuru: ' : 'Duyuru: ') . $du['baslik'] . ' · ' . ayar('site_adi'), fn($ad) => eposta_sablon(
        $du['baslik'], selamla($ad), $ozet, [['Yayın Tarihi', tarih($du['created_at'], true)], ['Yayınlayan', $du['ad_soyad'] ?: 'Yönetim']],
        'Duyuruyu Görüntüle', $link, '', '', $du['onemli'] ? '#dc2626' : '#4f46e5'
    ), 'duyuru', $duyuruId);
}

/** Daireye açık / gecikmiş borç hatırlatma e-postası */
function eposta_borc_hatirlat(int $daireId, bool $sadeceGeciken = false): int
{
    global $pdo;
    if (!eposta_aktif()) return 0;
    $alicilar = daire_alicilari($daireId);
    if (!$alicilar) return 0;
    $acik = acik_borclar($pdo, $daireId);
    if ($sadeceGeciken) {
        $acik = array_values(array_filter($acik, fn($b) => $b['son_odeme'] < date('Y-m-d')));
    }
    if (!$acik) return 0;
    $ilk = $acik[0];
    $daire = $ilk['blok'] . ' Blok, No: ' . $ilk['daire_no'];
    $toplam = round(array_sum(array_map(fn($b) => $b['tutar'] - $b['odenen'], $acik)), 2);
    $kalemler = implode("\n", array_map(fn($b) => $b['aciklama'] . ' (Son ödeme: ' . tarih($b['son_odeme']) . ') — ' . para($b['tutar'] - $b['odenen']), $acik));
    $detay = [['Daire', $daire], ['Açık Borç Kalemleri', $kalemler]];
    if (ayar('iban')) $detay[] = ['Ödeme İçin IBAN', (string)ayar('iban')];
    $link = imzali_link('borc', (string)$daireId, $daireId, 30);
    $konu = 'Aidat / Borç Hatırlatması – ' . para($toplam) . ' · ' . ayar('site_adi');
    $giris = $daire . ' için sistemimizde kayıtlı ödenmemiş borç bakiyeniz bulunmaktadır. Ödemenizi yaptıysanız bağlantıya tıklayarak veya panele giriş yaparak dekontunuzu yükleyebilirsiniz.';
    return eposta_kuyruga($alicilar, $konu, fn($ad) => eposta_sablon(
        'Ödeme Hatırlatması', selamla($ad), $giris, $detay,
        'Borç Detayını Görüntüle', $link, 'Ödeme açıklamasına daire numaranızı yazmayı unutmayınız.', para($toplam), '#d97706'
    ), 'genel', $daireId);
}
