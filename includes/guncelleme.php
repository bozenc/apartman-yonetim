<?php
/**
 * Veritabanı sürüm yönetimi.
 * Dosyalar yeni sürümle değiştirildiğinde, site ilk açıldığında eksik güncellemeler
 * otomatik uygulanır (verilere dokunulmaz).
 */

const DB_SURUM = '1.5';

/** SQL metnini ifadelere böler (tırnak ve -- yorumlarına dikkat eder) */
function sql_bol(string $sql): array
{
    $out = []; $buf = ''; $q = null; $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($q !== null) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $n) { $buf .= $sql[++$i]; continue; }
            if ($c === $q) $q = null;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $q = $c; $buf .= $c; continue; }
        if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
            $j = strpos($sql, "\n", $i);
            $i = $j === false ? $n : $j;
            $buf .= "\n";
            continue;
        }
        if ($c === ';') { if (trim($buf) !== '') $out[] = trim($buf); $buf = ''; continue; }
        $buf .= $c;
    }
    if (trim($buf) !== '') $out[] = trim($buf);
    return $out;
}

/**
 * @param bool $toleransli true ise "zaten var" türündeki hatalar yok sayılır
 *                         (yarıda kalmış bir güncellemenin tekrar çalıştırılabilmesi için)
 */
function sql_calistir(PDO $pdo, string $sql, bool $toleransli = false): void
{
    // 1060 kolon var, 1061 index var, 1050 tablo var, 1091 silinecek öğe yok, 1826/121 FK var
    $yoksay = ['1060', '1061', '1050', '1091', '1826', '121'];
    foreach (sql_bol($sql) as $ifade) {
        try {
            $pdo->exec($ifade);
        } catch (PDOException $e) {
            $kod = (string)($e->errorInfo[1] ?? '');
            if ($toleransli && (in_array($kod, $yoksay, true) || stripos($e->getMessage(), 'Duplicate') !== false)) continue;
            throw $e;
        }
    }
}

function vt_tablo_var(PDO $pdo, string $tablo): bool
{
    // Not: SHOW ... LIKE ? yer tutucusu, gerçek (native) hazır sorgularda desteklenmez
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$tablo]);
    return (bool)$st->fetchColumn();
}

function vt_kolon_var(PDO $pdo, string $tablo, string $kolon): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$tablo, $kolon]);
    return (bool)$st->fetchColumn();
}

/** Veritabanı durumu: bos | v10 | v11 | v12 | v13 | v14 | v15 */
function vt_durum(PDO $pdo): string
{
    if (vt_tablo_var($pdo, 'talepler') && vt_tablo_var($pdo, 'anketler') && vt_kolon_var($pdo, 'daireler', 'alacak_bakiye')) return 'v15';
    if (vt_tablo_var($pdo, 'mesaj_kuyrugu') && vt_tablo_var($pdo, 'onaylar')) return 'v14';
    if (vt_tablo_var($pdo, 'eposta_kuyrugu') && vt_kolon_var($pdo, 'daireler', 'sakin_eposta')) return 'v13';
    if (vt_tablo_var($pdo, 'odeme_bildirimleri') && vt_kolon_var($pdo, 'odemeler', 'dekont')) return 'v12';
    if (vt_tablo_var($pdo, 'kullanici_yetki')) return 'v11';
    if (vt_tablo_var($pdo, 'ayarlar') && vt_tablo_var($pdo, 'kullanicilar')) return 'v10';
    return 'bos';
}

/** Eksik tüm sürüm güncellemelerini sırayla uygular */
function veritabani_guncelle(PDO $pdo): void
{
    $kok = dirname(__DIR__);
    if (!vt_tablo_var($pdo, 'kullanici_yetki')) {
        sql_calistir($pdo, file_get_contents($kok . '/guncelleme_v1.1.sql'), true);
    }
    if (!vt_tablo_var($pdo, 'odeme_bildirimleri') || !vt_kolon_var($pdo, 'odemeler', 'bildirim_id')) {
        sql_calistir($pdo, file_get_contents($kok . '/guncelleme_v1.2.sql'), true);
    }
    if (!vt_tablo_var($pdo, 'eposta_kuyrugu') || !vt_kolon_var($pdo, 'kullanicilar', 'eposta_bildirim')) {
        sql_calistir($pdo, file_get_contents($kok . '/guncelleme_v1.3.sql'), true);
    }
    if (!vt_tablo_var($pdo, 'mesaj_kuyrugu') || !vt_tablo_var($pdo, 'onaylar') || !vt_kolon_var($pdo, 'daireler', 'mesaj_izin')) {
        sql_calistir($pdo, file_get_contents($kok . '/guncelleme_v1.4.sql'), true);
    }
    if (!vt_tablo_var($pdo, 'talepler') || !vt_tablo_var($pdo, 'anketler') || !vt_kolon_var($pdo, 'daireler', 'alacak_bakiye')) {
        sql_calistir($pdo, file_get_contents($kok . '/guncelleme_v1.5.sql'), true);
    }
    $pdo->prepare("INSERT INTO ayarlar (anahtar, deger) VALUES ('db_surum', ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)")
        ->execute([DB_SURUM]);
}
