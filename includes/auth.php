<?php
/**
 * Kimlik doğrulama ve yetki kontrolü
 */

/** Giriş yapmış kullanıcı (her istekte veritabanından tazelenir) */
function kullanici(): ?array
{
    global $pdo;
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (empty($_SESSION['uid'])) return null;

    $st = $pdo->prepare('SELECT k.*, r.ad AS rol_adi, r.renk AS rol_renk, r.sistem AS rol_sistem
                         FROM kullanicilar k JOIN roller r ON r.id = k.rol_id
                         WHERE k.id = ? AND k.aktif = 1');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch() ?: null;

    if ($u) {
        $u['yetkiler'] = etkin_yetkiler((int)$u['id'], (int)$u['rol_id']);
    } else {
        // Hesap pasife alındıysa / silindiyse oturumu kapat
        unset($_SESSION['uid']);
    }
    return $u;
}

/**
 * Bir kullanıcının etkin yetki anahtarları:
 *   rol yetkileri  +  kullanıcıya özel "izin ver"  −  kullanıcıya özel "engelle"
 */
function etkin_yetkiler(int $kullaniciId, int $rolId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT y.anahtar FROM yetkiler y
        LEFT JOIN rol_yetki ry ON ry.yetki_id = y.id AND ry.rol_id = ?
        LEFT JOIN kullanici_yetki ky ON ky.yetki_id = y.id AND ky.kullanici_id = ?
        WHERE (ry.rol_id IS NOT NULL AND (ky.izin IS NULL OR ky.izin = 1))
           OR ky.izin = 1');
    $st->execute([$rolId, $kullaniciId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Kişisel bilgi (isim/telefon) maskesi: yetki yoksa "A*** Y***" */
function kisi(?string $s): string
{
    if ($s === null || $s === '') return '';
    if (yetki('kisi.goruntule')) return $s;
    return implode(' ', array_map(fn($p) => mb_substr($p, 0, 1) . '***', preg_split('/\s+/u', trim($s))));
}

function tel(?string $s): string
{
    if ($s === null || $s === '') return '';
    return yetki('kisi.goruntule') ? $s : '••• ••• •• ••';
}

/** Yetki var mı? Süper Yönetici (sistem rolü) her şeye erişir. */
function yetki(string $anahtar): bool
{
    $u = kullanici();
    if (!$u) return false;
    if ((int)$u['rol_sistem'] === 1) return true;
    return in_array($anahtar, $u['yetkiler'], true);
}

/** Herhangi biri */
function yetki_herhangi(array $anahtarlar): bool
{
    foreach ($anahtarlar as $a) if (yetki($a)) return true;
    return false;
}

/** Giriş zorunlu */
function giris_zorunlu(): array
{
    $u = kullanici();
    if (!$u) {
        $_SESSION['donus'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php');
    }
    yasal_kapi($u); // kullanım koşulları / KVKK onayı bekleniyorsa onay.php'ye yönlendirir
    return $u;
}

/** Yetki zorunlu - yoksa 403 sayfası */
function yetki_zorunlu(string $anahtar): void
{
    giris_zorunlu();
    if (!yetki($anahtar)) {
        http_response_code(403);
        $sayfaBaslik = 'Erişim Engellendi';
        require __DIR__ . '/header.php';
        echo '<div class="empty-state py-5"><i class="bi bi-shield-lock"></i>'
           . '<h4 class="mt-3">Bu sayfaya erişim yetkiniz yok</h4>'
           . '<p class="text-muted">Gerekli yetki: <code>' . e($anahtar) . '</code><br>Yetki için sistem yöneticinizle iletişime geçin.</p>'
           . '<a href="' . url('index.php') . '" class="btn btn-primary mt-2"><i class="bi bi-house me-1"></i>Ana sayfaya dön</a></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
}

/** Giriş denemesi */
function giris_yap(string $kadi, string $sifre): bool
{
    global $pdo;

    // Kaba kuvvet koruması: 15 dakikada 5 hatalı deneme (hem oturum hem veritabanı IP/kullanıcı bazlı)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $_SESSION['giris_deneme'] = array_filter($_SESSION['giris_deneme'] ?? [], fn($t) => $t > time() - 900);
    $dbDeneme = 0;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM islem_log WHERE islem = 'Hatalı Giriş' AND (ip = ? OR detay = ?) AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $st->execute([$ip, mb_substr($kadi, 0, 500)]);
        $dbDeneme = (int)$st->fetchColumn();
    } catch (Throwable $e) {}

    if (count($_SESSION['giris_deneme']) >= 5 || $dbDeneme >= 5) {
        flash('danger', 'Çok fazla hatalı deneme. Lütfen 15 dakika sonra tekrar deneyin.');
        return false;
    }

    $st = $pdo->prepare('SELECT * FROM kullanicilar WHERE (kullanici_adi = ? OR eposta = ?) LIMIT 1');
    $st->execute([$kadi, $kadi]);
    $u = $st->fetch();

    if (!$u || !password_verify($sifre, $u['sifre'])) {
        $_SESSION['giris_deneme'][] = time();
        logla('Hatalı Giriş', $kadi);
        flash('danger', 'Kullanıcı adı veya şifre hatalı.');
        return false;
    }
    if (!(int)$u['aktif']) {
        flash('warning', 'Hesabınız pasif durumda. Yöneticinizle iletişime geçin.');
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['son_etkinlik'] = time();
    unset($_SESSION['giris_deneme']);

    try {
        $pdo->prepare("UPDATE islem_log SET islem = 'Hatalı Giriş (Sıfırlandı)' WHERE islem = 'Hatalı Giriş' AND (ip = ? OR detay = ?) AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)")
            ->execute([$ip, mb_substr($kadi, 0, 500)]);
    } catch (Throwable $e) {}

    if (password_needs_rehash($u['sifre'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE kullanicilar SET sifre = ? WHERE id = ?')->execute([password_hash($sifre, PASSWORD_DEFAULT), $u['id']]);
    }
    $pdo->prepare('UPDATE kullanicilar SET son_giris = NOW() WHERE id = ?')->execute([$u['id']]);
    logla('Giriş', $u['kullanici_adi'] . ' sisteme giriş yaptı');
    return true;
}

/** Rolün, oturumdaki kullanıcıda olmayan yetkilerini döndürür (yetki yükseltme koruması) */
function rol_fazla_yetki(PDO $pdo, int $rolId): array
{
    $st = $pdo->prepare('SELECT r.sistem FROM roller r WHERE r.id = ?');
    $st->execute([$rolId]);
    if ((int)$st->fetchColumn() === 1) return ['Süper Yönetici'];
    $st = $pdo->prepare('SELECT y.anahtar, y.ad FROM rol_yetki ry JOIN yetkiler y ON y.id = ry.yetki_id WHERE ry.rol_id = ?');
    $st->execute([$rolId]);
    $fazla = [];
    foreach ($st as $r) if (!yetki($r['anahtar'])) $fazla[] = $r['ad'];
    return $fazla;
}
