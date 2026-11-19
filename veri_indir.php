<?php
/** KVKK md. 11 — kullanıcının sistemdeki kişisel verilerinin JSON kopyası */
require __DIR__ . '/includes/init.php';
$u = giris_zorunlu();
$kid = (int)$u['id'];

$sorgu = function (string $sql, array $p = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
};

$hesap = $sorgu('SELECT k.id, k.ad_soyad, k.kullanici_adi, k.eposta, k.telefon, k.eposta_bildirim, k.mesaj_bildirim, k.aktif, k.son_giris, k.created_at, r.ad AS rol
                 FROM kullanicilar k JOIN roller r ON r.id = k.rol_id WHERE k.id = ?', [$kid])[0];

$daireler = [];
foreach ($sorgu('SELECT * FROM daireler WHERE kullanici_id = ?', [$kid]) as $d) {
    $kayit = ['blok' => $d['blok'], 'daire_no' => $d['daire_no'], 'kat' => $d['kat'], 'metrekare' => $d['metrekare'], 'oturan_tipi' => $d['oturan_tipi'],
              'kisi_sayisi' => $d['kisi_sayisi'], 'sakin_adi' => $d['sakin_adi'], 'sakin_tel' => $d['sakin_tel'], 'sakin_eposta' => $d['sakin_eposta'],
              'sms_whatsapp_izni' => (bool)$d['mesaj_izin']];
    // Malik bilgileri yalnızca kullanıcı malikin kendisiyse verilir (kiracıya başkasının verisi verilmez)
    if ($d['oturan_tipi'] === 'malik') $kayit += ['malik_adi' => $d['malik_adi'], 'malik_tel' => $d['malik_tel'], 'malik_eposta' => $d['malik_eposta']];
    $kayit['borclar'] = $sorgu('SELECT aciklama, tur, tutar, odenen, son_odeme, created_at FROM borclar WHERE daire_id = ? ORDER BY son_odeme', [$d['id']]);
    $kayit['odemeler'] = $sorgu('SELECT makbuz_no, tutar, tarih, yontem, aciklama, created_at FROM odemeler WHERE daire_id = ? ORDER BY tarih', [$d['id']]);
    $daireler[] = $kayit;
}

$telefonlar = array_values(array_filter(array_unique(array_map('telefon_normal', array_merge([$u['telefon'] ?? ''], array_column($daireler, 'sakin_tel'))))));
$epostalar = array_values(array_filter(array_unique(array_map(fn($x) => mb_strtolower((string)$x), array_merge([$u['eposta'] ?? ''], array_column($daireler, 'sakin_eposta'))))));
$yerTut = fn(array $a) => $a ? implode(',', array_fill(0, count($a), '?')) : 'NULL';

$veri = [
    'aciklama'          => 'KVKK md. 11 kapsamında ' . ayar('site_adi') . ' yönetim sisteminde kayıtlı kişisel verilerinizin kopyasıdır. Şifreniz geri döndürülemez biçimde saklandığından dahil edilmemiştir.',
    'olusturma'         => date('c'),
    'veri_sorumlusu'    => ['unvan' => ayar('kvkk_sorumlu') ?: ayar('site_adi') . ' Yönetimi', 'adres' => ayar('adres'), 'eposta' => ayar('kvkk_eposta') ?: ayar('eposta')],
    'hesap'             => $hesap,
    'daireler'          => $daireler,
    'odeme_bildirimleri'=> $sorgu('SELECT tutar, tarih, yontem, aciklama, dekont_ad, durum, red_neden, islem_tarihi, created_at FROM odeme_bildirimleri WHERE kullanici_id = ? ORDER BY id', [$kid]),
    'onaylar'           => $sorgu('SELECT metin, surum, durum, ip, tarayici, created_at FROM onaylar WHERE kullanici_id = ? ORDER BY id', [$kid]),
    'islem_kayitlari'   => $sorgu('SELECT islem, detay, ip, created_at FROM islem_log WHERE kullanici_id = ? ORDER BY id DESC LIMIT 1000', [$kid]),
    'gonderilen_epostalar' => $epostalar ? $sorgu('SELECT alici, konu, tur, durum, gonderim, created_at FROM eposta_kuyrugu WHERE alici IN (' . $yerTut($epostalar) . ') ORDER BY id DESC LIMIT 500', $epostalar) : [],
    'gonderilen_mesajlar'  => $telefonlar ? $sorgu('SELECT kanal, telefon, metin, tur, durum, gonderim, created_at FROM mesaj_kuyrugu WHERE telefon IN (' . $yerTut($telefonlar) . ') ORDER BY id DESC LIMIT 500', $telefonlar) : [],
];

logla('Kişisel veri kopyası indirildi', $u['kullanici_adi']);
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="kisisel-verilerim-' . $u['kullanici_adi'] . '-' . date('Ymd') . '.json"');
header('Cache-Control: no-store');
echo json_encode($veri, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
