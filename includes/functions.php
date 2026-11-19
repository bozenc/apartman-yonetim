<?php
/**
 * Yardımcı fonksiyonlar
 */

/** HTML kaçışı */
function e($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Para formatı: 1.234,56 ₺ */
function para($n): string
{
    return number_format((float)$n, 2, ',', '.') . ' ₺';
}

/** Kullanıcı girişinden sayıya: "1.234,56" veya "1234.56" */
function sayi($v): float
{
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    if (strpos($v, ',') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    }
    return round((float)$v, 2);
}

/** Tarih formatı: 30.10.2026 */
function tarih($t, bool $saat = false): string
{
    if (!$t) return '-';
    $ts = strtotime($t);
    return $saat ? date('d.m.Y H:i', $ts) : date('d.m.Y', $ts);
}

/** Dönem adı: 2026-10 → Ekim 2026 */
function donem_adi(?string $d): string
{
    if (!$d) return '-';
    $aylar = ['', 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz',
              'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    [$y, $m] = explode('-', $d);
    return ($aylar[(int)$m] ?? $m) . ' ' . $y;
}

function ay_adi(int $m): string
{
    return ['', 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'][$m] ?? '';
}

function url(string $yol = ''): string
{
    return BASE_URL . '/' . ltrim($yol, '/');
}

function redirect(string $yol): void
{
    if (!preg_match('~^https?://~', $yol)) $yol = url($yol);
    header('Location: ' . $yol);
    exit;
}

function post(string $k, $varsayilan = null)
{
    return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $varsayilan;
}

function get(string $k, $varsayilan = null)
{
    return isset($_GET[$k]) ? (is_string($_GET[$k]) ? trim($_GET[$k]) : $_GET[$k]) : $varsayilan;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

/** İsteğin HTTPS üzerinden gelip gelmediğini (Reverse Proxy / Cloudflare dahil) algılar */
function https_aktif(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') return true;
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** CSV dışa aktarımda formül enjeksiyonunu (Formula Injection) önler */
function csv_hucre($deger): string
{
    $s = (string)($deger ?? '');
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s)) {
        return "'" . $s;
    }
    return $s;
}

/* ---------------- Flash mesajları ---------------- */

function flash(string $tur, string $mesaj): void
{
    $_SESSION['flash'][] = ['tur' => $tur, 'mesaj' => $mesaj];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------- CSRF koruması ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        flash('danger', 'Oturum doğrulaması başarısız oldu. Lütfen tekrar deneyin.');
        redirect($_SERVER['HTTP_REFERER'] ?? 'index.php');
    }
}

/* ---------------- Ayarlar ---------------- */

function ayar(string $k, $varsayilan = '')
{
    global $pdo;
    if (!isset($GLOBALS['_ayar_cache']) || $GLOBALS['_ayar_cache'] === null) {
        $GLOBALS['_ayar_cache'] = $pdo->query('SELECT anahtar, deger FROM ayarlar')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $GLOBALS['_ayar_cache'][$k] ?? $varsayilan;
}

function ayar_sifirla(): void
{
    $GLOBALS['_ayar_cache'] = null;
}

/* ---------------- İşlem kaydı ---------------- */

function logla(string $islem, string $detay = ''): void
{
    global $pdo;
    try {
        $pdo->prepare('INSERT INTO islem_log (kullanici_id, islem, detay, ip) VALUES (?,?,?,?)')
            ->execute([$_SESSION['uid'] ?? null, $islem, mb_substr($detay, 0, 500), $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        // Log hatası uygulamayı durdurmamalı
    }
}

/* ---------------- Yardımcılar ---------------- */

function daire_etiket(array $d): string
{
    return $d['blok'] . ' Blok / No: ' . $d['daire_no'];
}

function borc_turleri(): array
{
    return ['aidat' => 'Aidat', 'ek' => 'Ek Ödeme', 'demirbas' => 'Demirbaş', 'gecikme' => 'Gecikme Bedeli', 'diger' => 'Diğer'];
}

function odeme_yontemleri(): array
{
    return ['nakit' => 'Nakit', 'havale' => 'Havale / EFT', 'kredi_karti' => 'Kredi Kartı', 'diger' => 'Diğer'];
}

/** Bir borcun durum rozetini döndürür */
function borc_durum(array $b): string
{
    $kalan = (float)$b['tutar'] - (float)$b['odenen'];
    if ($kalan <= 0.009) return '<span class="badge-soft success">Ödendi</span>';
    if ($b['son_odeme'] < date('Y-m-d')) return '<span class="badge-soft danger">Gecikmiş</span>';
    if ((float)$b['odenen'] > 0) return '<span class="badge-soft warning">Kısmi</span>';
    return '<span class="badge-soft secondary">Bekliyor</span>';
}

/** Basit sayfalama */
function sayfalama(int $toplam, int $sayfa, int $limit): string
{
    $sayfaSayisi = (int)ceil($toplam / $limit);
    if ($sayfaSayisi <= 1) return '';
    $q = $_GET;
    $html = '<nav><ul class="pagination pagination-sm mb-0">';
    for ($i = 1; $i <= $sayfaSayisi; $i++) {
        if ($sayfaSayisi > 10 && abs($i - $sayfa) > 2 && $i !== 1 && $i !== $sayfaSayisi) {
            if ($i === 2 || $i === $sayfaSayisi - 1) $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            continue;
        }
        $q['s'] = $i;
        $html .= '<li class="page-item' . ($i === $sayfa ? ' active' : '') . '"><a class="page-link" href="?' . e(http_build_query($q)) . '">' . $i . '</a></li>';
    }
    return $html . '</ul></nav>';
}

/* =====================================================================
   TAHSİLAT
   ===================================================================== */

/** Tek bir borca ödeme işler: ödeme kaydı + borç güncelleme + kasaya gelir. Ödeme id döner. */
function odeme_isle(PDO $pdo, array $borc, float $tutar, string $tarih, string $yontem, ?string $aciklama,
                    ?string $dekont = null, ?int $bildirimId = null, bool $kasayaIsle = true): int
{
    $pdo->prepare('INSERT INTO odemeler (borc_id, daire_id, tutar, tarih, yontem, aciklama, dekont, bildirim_id, kullanici_id) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$borc['id'], $borc['daire_id'], $tutar, $tarih, $yontem, $aciklama, $dekont, $bildirimId, $_SESSION['uid'] ?? null]);
    $oid = (int)$pdo->lastInsertId();
    $makbuz = 'MK' . date('y') . str_pad((string)$oid, 6, '0', STR_PAD_LEFT);
    $pdo->prepare('UPDATE odemeler SET makbuz_no = ? WHERE id = ?')->execute([$makbuz, $oid]);
    $pdo->prepare('UPDATE borclar SET odenen = odenen + ? WHERE id = ?')->execute([$tutar, $borc['id']]);
    if ($kasayaIsle) {
        $pdo->prepare("INSERT INTO hareketler (tur, kategori_id, tutar, tarih, aciklama, belge_no, odeme_id, kullanici_id) VALUES ('gelir', 1, ?, ?, ?, ?, ?, ?)")
            ->execute([$tutar, $tarih, $borc['blok'] . '-' . $borc['daire_no'] . ' · ' . $borc['aciklama'], $makbuz, $oid, $_SESSION['uid'] ?? null]);
    }
    return $oid;
}

/** Dairenin açık borçları (önce seçilenler, sonra en eski son ödeme tarihli) */
function acik_borclar(PDO $pdo, int $daireId, array $oncelik = [], bool $kilitle = false): array
{
    $sql = 'SELECT b.*, d.blok, d.daire_no FROM borclar b JOIN daireler d ON d.id = b.daire_id
            WHERE b.daire_id = ? AND b.tutar > b.odenen ORDER BY b.son_odeme, b.id' . ($kilitle ? ' FOR UPDATE' : '');
    $st = $pdo->prepare($sql);
    $st->execute([$daireId]);
    $borclar = $st->fetchAll();
    if ($oncelik) {
        $oncelik = array_map('intval', $oncelik);
        usort($borclar, fn($a, $b) => (int)!in_array((int)$a['id'], $oncelik, true) <=> (int)!in_array((int)$b['id'], $oncelik, true));
    }
    return $borclar;
}

/**
 * Bir tutarı dairenin açık borçlarına dağıtarak tahsil eder.
 * Fazla ödeme varsa ve $avansKabul true ise kalan tutar dairenin alacak (avans) bakiyesine eklenir.
 */
function tahsilat_dagit(PDO $pdo, int $daireId, float $tutar, string $tarih, string $yontem, ?string $aciklama,
                        array $oncelik = [], ?string $dekont = null, ?int $bildirimId = null, bool $avansKabul = true): array
{
    if ($tutar <= 0) {
        throw new RuntimeException('Tahsilat tutarı 0\'dan büyük olmalıdır.');
    }
    $borclar = acik_borclar($pdo, $daireId, $oncelik, $pdo->inTransaction());
    $toplam = round(array_sum(array_map(fn($b) => $b['tutar'] - $b['odenen'], $borclar)), 2);
    if (!$avansKabul && $tutar > $toplam + 0.001) {
        throw new RuntimeException('Tutar dairenin toplam borcundan (' . para($toplam) . ') fazla olmamalıdır.');
    }
    $kalan = $tutar;
    $idler = [];
    foreach ($borclar as $b) {
        if ($kalan <= 0.001) break;
        $odeme = min($kalan, round($b['tutar'] - $b['odenen'], 2));
        $idler[] = odeme_isle($pdo, $b, $odeme, $tarih, $yontem, $aciklama, $dekont, $bildirimId);
        $kalan = round($kalan - $odeme, 2);
    }
    if ($kalan > 0.001 && $avansKabul) {
        $st = $pdo->prepare('SELECT blok, daire_no FROM daireler WHERE id = ?');
        $st->execute([$daireId]);
        $dr = $st->fetch();
        $etiket = $dr ? ($dr['blok'] . '-' . $dr['daire_no']) : ('Daire #' . $daireId);
        $pdo->prepare('UPDATE daireler SET alacak_bakiye = alacak_bakiye + ? WHERE id = ?')->execute([$kalan, $daireId]);
        $pdo->prepare("INSERT INTO hareketler (tur, kategori_id, tutar, tarih, aciklama, belge_no, belge_dosya, kullanici_id) VALUES ('gelir', 1, ?, ?, ?, 'AVANS', ?, ?)")
            ->execute([$kalan, $tarih, $etiket . ' · Peşin / Avans Tahsilatı' . ($aciklama ? ' (' . $aciklama . ')' : ''), $dekont, $_SESSION['uid'] ?? null]);
    }
    return $idler;
}

/**
 * Dairenin alacak (avans) bakiyesi varsa açık borçlarından otomatik mahsup eder.
 * Mahsup edilen toplam tutarı döner.
 */
function avans_mahsup_et(PDO $pdo, int $daireId): float
{
    $st = $pdo->prepare('SELECT alacak_bakiye FROM daireler WHERE id = ?');
    $st->execute([$daireId]);
    $avans = round((float)$st->fetchColumn(), 2);
    if ($avans <= 0.009) return 0.0;

    $borclar = acik_borclar($pdo, $daireId);
    if (!$borclar) return 0.0;

    $kullanilan = 0.0;
    foreach ($borclar as $b) {
        if ($avans <= 0.009) break;
        $kalanBorc = round($b['tutar'] - $b['odenen'], 2);
        $mahsup = min($avans, $kalanBorc);
        if ($mahsup <= 0) continue;
        odeme_isle($pdo, $b, $mahsup, date('Y-m-d'), 'diger', 'Avans bakiyesinden otomatik mahsup', null, null, false);
        $avans = round($avans - $mahsup, 2);
        $kullanilan = round($kullanilan + $mahsup, 2);
    }
    if ($kullanilan > 0) {
        $pdo->prepare('UPDATE daireler SET alacak_bakiye = GREATEST(0, alacak_bakiye - ?) WHERE id = ?')->execute([$kullanilan, $daireId]);
    }
    return $kullanilan;
}

/* =====================================================================
   DEKONT DOSYALARI
   ===================================================================== */

const DEKONT_MAX_MB = 5;

function dekont_klasoru(): string
{
    return dirname(__DIR__) . '/uploads/dekontlar';
}

/**
 * Yüklenen dekontu doğrular ve kaydeder. Dosya seçilmemişse null döner.
 * İzin verilenler: PDF, JPG, PNG (içerik imzasıyla doğrulanır, uzantıya güvenilmez).
 * @return array{dosya:string, ad:string}|null
 */
function dekont_kaydet(?array $f, bool $zorunlu = false): ?array
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($zorunlu) throw new RuntimeException('Lütfen dekont dosyasını seçin.');
        return null;
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > DEKONT_MAX_MB * 1048576) {
        throw new RuntimeException('Dekont dosyası en fazla ' . DEKONT_MAX_MB . ' MB olabilir.');
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException('Dosya yüklenemedi. Lütfen tekrar deneyin.');
    }
    $bas = (string)file_get_contents($f['tmp_name'], false, null, 0, 8);
    $uzanti = match (true) {
        str_starts_with($bas, '%PDF')          => 'pdf',
        str_starts_with($bas, "\xFF\xD8\xFF")  => 'jpg',
        str_starts_with($bas, "\x89PNG\r\n")   => 'png',
        default => null,
    };
    if (!$uzanti) throw new RuntimeException('Yalnızca PDF, JPG veya PNG formatında dekont yükleyebilirsiniz.');

    $klasor = dekont_klasoru();
    if (!is_dir($klasor)) @mkdir($klasor, 0755, true);
    $dosya = date('Ym') . '_' . bin2hex(random_bytes(12)) . '.' . $uzanti;
    if (!move_uploaded_file($f['tmp_name'], $klasor . '/' . $dosya)) {
        throw new RuntimeException('Dekont kaydedilemedi: uploads/dekontlar klasörü yazılabilir olmalı.');
    }
    $ad = mb_substr(preg_replace('/[^\p{L}\p{N}._ -]/u', '_', basename((string)$f['name'])), 0, 150);
    return ['dosya' => $dosya, 'ad' => $ad ?: ('dekont.' . $uzanti)];
}

/** Dekont önizleme/indirme bağlantısı */
function dekont_url(string $tur, int $id): string
{
    return url('dekont.php?' . $tur . '=' . $id);
}

/* =====================================================================
   MAKBUZ YARDIMCILARI
   ===================================================================== */

/** Sayıyı Türkçe yazıya çevirir: 1500.50 → "BinBeşYüz Türk Lirası Elli Kuruş" */
function yaziyla(float $sayi): string
{
    $birler = ['', 'Bir', 'İki', 'Üç', 'Dört', 'Beş', 'Altı', 'Yedi', 'Sekiz', 'Dokuz'];
    $onlar  = ['', 'On', 'Yirmi', 'Otuz', 'Kırk', 'Elli', 'Altmış', 'Yetmiş', 'Seksen', 'Doksan'];
    $basamak = ['', 'Bin', 'Milyon', 'Milyar'];
    $uc = function (int $n) use ($birler, $onlar) {
        $y = intdiv($n, 100); $o = intdiv($n % 100, 10); $b = $n % 10;
        return ($y ? ($y > 1 ? $birler[$y] : '') . 'Yüz' : '') . $onlar[$o] . $birler[$b];
    };
    $sayi = round($sayi, 2);
    $tl = (int)floor($sayi);
    $kr = (int)round(($sayi - $tl) * 100);
    if ($tl === 0) $metin = 'Sıfır';
    else {
        $metin = ''; $i = 0;
        while ($tl > 0) {
            $grup = $tl % 1000;
            if ($grup) $metin = ($i === 1 && $grup === 1 ? '' : $uc($grup)) . $basamak[$i] . $metin;
            $tl = intdiv($tl, 1000); $i++;
        }
    }
    return $metin . ' Türk Lirası' . ($kr ? ' ' . $uc($kr) . ' Kuruş' : '');
}

/** Sisteme özel gizli anahtar (config.local.php'de tanımlıysa oradan, yoksa veritabanından alınır / üretilir) */
function gizli_anahtar(): string
{
    if (defined('GIZLI_ANAHTAR') && is_string(GIZLI_ANAHTAR) && strlen(GIZLI_ANAHTAR) >= 32) {
        return GIZLI_ANAHTAR;
    }
    global $pdo;
    $k = (string)ayar('gizli_anahtar', '');
    if ($k === '') {
        $k = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('gizli_anahtar', ?) ON DUPLICATE KEY UPDATE deger = IF(deger = '' OR deger IS NULL, VALUES(deger), deger)")->execute([$k]);
        ayar_sifirla();
        $k = (string)ayar('gizli_anahtar', $k);
    }
    return $k;
}

/** Makbuz doğrulama kodu: makbuz no + tutar + tarih üzerinden HMAC → "AB12-CD34" */
function dogrulama_kodu(string $makbuzNo, float $tutar, string $tarih): string
{
    $h = strtoupper(substr(hash_hmac('sha256', $makbuzNo . '|' . number_format($tutar, 2, '.', '') . '|' . $tarih, gizli_anahtar()), 0, 8));
    return substr($h, 0, 4) . '-' . substr($h, 4);
}

/** Web-Cron (HTTP üzerinden zamanlanmış görev) için imzalı güvenlik anahtarı */
function cron_anahtari(): string
{
    return substr(hash_hmac('sha256', 'apt_web_cron', gizli_anahtar()), 0, 24);
}

/** Onay bekleyen ödeme bildirimi sayısı (menü rozeti için) */
function bekleyen_bildirim(): int
{
    global $pdo;
    static $n = null;
    return $n ??= (int)$pdo->query("SELECT COUNT(*) FROM odeme_bildirimleri WHERE durum = 'bekliyor'")->fetchColumn();
}

/** Bekleyen / işlemdeki arıza & talep sayısı (menü rozeti için) */
function bekleyen_talep(): int
{
    global $pdo;
    static $n = null;
    if ($n !== null) return $n;
    try {
        return $n = (int)$pdo->query("SELECT COUNT(*) FROM talepler WHERE durum IN ('bekliyor','islemde')")->fetchColumn();
    } catch (Throwable $e) {
        return $n = 0;
    }
}

/** Tarihi geçmiş veya 7 gün içinde yaklaşan periyodik bakım sayısı */
function yaklasan_bakim_sayisi(): int
{
    global $pdo;
    static $n = null;
    if ($n !== null) return $n;
    try {
        return $n = (int)$pdo->query("SELECT COUNT(*) FROM bakimlar WHERE aktif = 1 AND sonraki_bakim <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
    } catch (Throwable $e) {
        return $n = 0;
    }
}

/**
 * Makbuz satırlarını getirir.
 * @param int|null $bildirimId  onaylanmış bildirime ait tüm tahsilatlar
 * @param int[]    $idler       ödeme id listesi (aynı daire)
 * @return array{0:array,1:string} [satırlar, makbuz no]
 */
function makbuz_satirlari(?int $bildirimId, array $idler = []): array
{
    global $pdo;
    $sorgu = 'SELECT o.*, b.aciklama AS borc_aciklama, b.son_odeme, d.blok, d.daire_no, d.kullanici_id AS sahip,
            COALESCE(d.sakin_adi, d.malik_adi) AS odeyen, k.ad_soyad AS alan
        FROM odemeler o JOIN borclar b ON b.id = o.borc_id JOIN daireler d ON d.id = o.daire_id
        LEFT JOIN kullanicilar k ON k.id = o.kullanici_id ';
    if ($bildirimId) {
        $st = $pdo->prepare($sorgu . 'WHERE o.bildirim_id = ? ORDER BY o.id');
        $st->execute([$bildirimId]);
        $satirlar = $st->fetchAll();
        return [$satirlar, 'BL' . ($satirlar ? date('y', strtotime($satirlar[0]['created_at'])) : '') . str_pad((string)$bildirimId, 6, '0', STR_PAD_LEFT)];
    }
    $idler = array_values(array_filter(array_map('intval', $idler)));
    if (!$idler) return [[], ''];
    $satirlar = $pdo->query($sorgu . 'WHERE o.id IN (' . implode(',', $idler) . ') ORDER BY o.id')->fetchAll();
    // Tüm satırlar aynı daireye ait olmalı
    $satirlar = array_values(array_filter($satirlar, fn($s) => $s['daire_id'] === ($satirlar[0]['daire_id'] ?? null)));
    // Tek bildirime aitse bildirim numarasını kullan
    $bl = array_unique(array_column($satirlar, 'bildirim_id'));
    if ($satirlar && count($bl) === 1 && $bl[0]) return makbuz_satirlari((int)$bl[0]);
    return [$satirlar, ($satirlar[0]['makbuz_no'] ?? '') . (count($satirlar) > 1 ? '-' . count($satirlar) : '')];
}

/** Ödeme listesine erişim: yetkili personel veya dairenin sakini */
function makbuz_erisim(array $satirlar, ?array $u): bool
{
    if (!$satirlar || !$u) return false;
    if (yetki_herhangi(['odeme.goruntule', 'aidat.goruntule'])) return true;
    return yetki('borc.kendi') && (int)$satirlar[0]['sahip'] === (int)$u['id'];
}
