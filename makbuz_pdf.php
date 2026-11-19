<?php
/**
 * PDF tahsilat makbuzu
 *   makbuz_pdf.php?id=ODEME_ID[,ID…]  → tek / çok kalemli tahsilat
 *   makbuz_pdf.php?t=İMZALI_ANAHTAR   → e-postadaki bağlantı (giriş gerekmez)
 *   makbuz_pdf.php?bildirim=ID        → onaylanmış ödeme bildirimi (birden çok borç kalemi)
 *   &indir=1                          → indirme olarak gönder
 */
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/pdf.php';
// E-postadaki imzalı bağlantı: giriş gerektirmeden yalnızca o makbuz
$token = link_dogrula(get('t'));
if ($token && $token['tur'] === 'odeme') {
    [$satirlar, $no] = makbuz_satirlari(null, $token['ids']);
    $satirlar = array_values(array_filter($satirlar, fn($s) => (int)$s['daire_id'] === $token['daire']));
    $ilk = $satirlar[0] ?? null;
    $sahibi = true;
    if (!$ilk) { http_response_code(404); exit('Makbuz bulunamadı.'); }
} else {
    $u = giris_zorunlu();
    [$satirlar, $no] = ($bid = (int)get('bildirim')) ? makbuz_satirlari($bid) : makbuz_satirlari(null, explode(',', (string)get('id')));
    $ilk = $satirlar[0] ?? null;
    $sahibi = $ilk && (int)$ilk['sahip'] === (int)$u['id'];
    if (!makbuz_erisim($satirlar, $u)) {
        http_response_code(404);
        exit('Makbuz bulunamadı veya görüntüleme yetkiniz yok.');
    }
}

$toplam = round(array_sum(array_column($satirlar, 'tutar')), 2);
$st = $pdo->prepare('SELECT COALESCE(SUM(tutar - odenen), 0) FROM borclar WHERE daire_id = ? AND tutar > odenen');
$st->execute([$ilk['daire_id']]);
$kalanBorc = (float)$st->fetchColumn();
$odeyen = $sahibi ? (string)$ilk['odeyen'] : kisi($ilk['odeyen']);
$kod = dogrulama_kodu($no, $toplam, $ilk['tarih']);

/* ======================== ÇİZİM ======================== */
$MOR = [79, 70, 229]; $KOYU = [15, 23, 42]; $GRI = [100, 116, 139]; $ACIK = [241, 245, 249]; $YESIL = [5, 150, 105];
$pdf = new Pdf();
$pdf->baslik = 'Tahsilat Makbuzu ' . $no;
$L = 18; $R = 192; $G = $R - $L;

// Üst bant
$pdf->dolguRenk($MOR);
$pdf->kutu(0, 0, 210, 44);
$pdf->dolguRenk([99, 102, 241]);
$pdf->kutu(150, 0, 60, 44);
$pdf->font(true, 17);
$pdf->yazi($L, 17, ayar('site_adi', 'Apartman Yönetimi'), [255, 255, 255]);
$pdf->font(false, 8.5);
$alt = array_filter([ayar('adres'), implode('  •  ', array_filter([ayar('telefon'), ayar('eposta')])), ayar('vergi_no') ? 'Vergi No: ' . ayar('vergi_no') : '']);
$y = 24;
foreach ($alt as $s) foreach ($pdf->satirlar($s, 120) as $satir) { $pdf->yazi($L, $y, $satir, [224, 231, 255]); $y += 4.5; }
$pdf->font(true, 13);
$pdf->yazi($R, 16, 'TAHSİLAT MAKBUZU', [255, 255, 255], 'R');
$pdf->font(false, 9);
$pdf->yazi($R, 24, 'Makbuz No', [224, 231, 255], 'R');
$pdf->font(true, 12);
$pdf->yazi($R, 30, $no, [255, 255, 255], 'R');
$pdf->font(false, 9);
$pdf->yazi($R, 37, 'Tarih: ' . tarih($ilk['tarih']), [224, 231, 255], 'R');

// Bilgi kutuları
$y = 54;
$kutular = [
    [$L, [['DAİRE', $ilk['blok'] . ' Blok, No: ' . $ilk['daire_no']], ['ÖDEYEN', $odeyen ?: '-']]],
    [$L + $G / 2 + 3, [['ÖDEME YÖNTEMİ', odeme_yontemleri()[$ilk['yontem']] ?? $ilk['yontem']], ['TAHSİL EDEN', $ilk['alan'] ?: 'Yönetim']]],
];
foreach ($kutular as [$kx, $alanlar]) {
    $pdf->dolguRenk([248, 250, 252]); $pdf->cizgiRenk([226, 232, 240]); $pdf->kalinlik(0.3);
    $pdf->kutu($kx, $y, $G / 2 - 3, 31, 'FD', 3);
    foreach ($alanlar as $i => [$etiket, $deger]) {
        $pdf->font(true, 7.5); $pdf->yazi($kx + 5, $y + 8 + $i * 13, $etiket, $GRI);
        $pdf->font(true, 11);  $pdf->yazi($kx + 5, $y + 13.5 + $i * 13, mb_strimwidth((string)$deger, 0, 40, '…'), $KOYU);
    }
}

// Kalemler tablosu
$y = 96;
$tabloBaslik = function (float $y) use ($pdf, $L, $R, $G, $GRI, $ACIK) {
    $pdf->dolguRenk($ACIK);
    $pdf->kutu($L, $y, $G, 9, 'F', 2);
    $pdf->font(true, 8);
    $pdf->yazi($L + 4, $y + 6, '#', $GRI);
    $pdf->yazi($L + 14, $y + 6, 'AÇIKLAMA', $GRI);
    $pdf->yazi($L + 118, $y + 6, 'SON ÖDEME', $GRI);
    $pdf->yazi($R - 4, $y + 6, 'TUTAR', $GRI, 'R');
    return $y + 9;
};
$y = $tabloBaslik($y);
$pdf->cizgiRenk([226, 232, 240]); $pdf->kalinlik(0.25);
foreach ($satirlar as $i => $s) {
    $pdf->font(false, 10);
    $acik = $pdf->satirlar($s['borc_aciklama'] . ($s['makbuz_no'] && count($satirlar) > 1 ? '  (' . $s['makbuz_no'] . ')' : ''), 100);
    $yuk = max(10, 4 + count($acik) * 5);
    if ($y + $yuk > 200) { $pdf->sayfaEkle(); $y = $tabloBaslik(20); }
    $pdf->font(false, 9); $pdf->yazi($L + 4, $y + 6.5, (string)($i + 1), $GRI);
    $pdf->font(false, 10);
    foreach ($acik as $j => $a) $pdf->yazi($L + 14, $y + 6.5 + $j * 5, $a, $KOYU);
    $pdf->font(false, 9); $pdf->yazi($L + 118, $y + 6.5, tarih($s['son_odeme']), $GRI);
    $pdf->font(true, 10); $pdf->yazi($R - 4, $y + 6.5, para($s['tutar']), $KOYU, 'R');
    $y += $yuk;
    $pdf->cizgiRenk([226, 232, 240]);
    $pdf->cizgi($L, $y, $R, $y);
}

// Toplam
$y += 6;
$pdf->dolguRenk([238, 242, 255]);
$pdf->kutu($R - 72, $y, 72, 22, 'F', 3);
$pdf->font(true, 8); $pdf->yazi($R - 67, $y + 7.5, 'TOPLAM TAHSİLAT', $MOR);
$pdf->font(true, 17); $pdf->yazi($R - 5, $y + 17, para($toplam), $MOR, 'R');
$pdf->font(true, 8); $pdf->yazi($L, $y + 7.5, 'YALNIZ', $GRI);
$pdf->font(false, 10);
$pdf->paragraf($L, $y + 13, '#' . yaziyla($toplam) . '#', 98, 5, $KOYU);

// ÖDENDİ damgası
$pdf->dondur(12, $L + 45, $y + 46, function (Pdf $p) use ($L, $y, $YESIL) {
    $p->cizgiRenk($YESIL); $p->kalinlik(1.1);
    $p->kutu($L + 20, $y + 38, 50, 16, 'D', 3);
    $p->kalinlik(0.4);
    $p->kutu($L + 21.5, $y + 39.5, 47, 13, 'D', 2);
    $p->font(true, 18);
    $p->yazi($L + 45, $y + 49.5, 'ÖDENDİ', $YESIL, 'C');
});

// Notlar
$ny = $y + 34;
$pdf->font(false, 9);
$notlar = ['Dairenin güncel kalan borcu: ' . para($kalanBorc)];
if ($ilk['bildirim_id']) $notlar[] = 'Sakinin ödeme bildirimi (#' . $ilk['bildirim_id'] . ') ve dekontu incelenerek onaylanmıştır.';
elseif ($ilk['dekont']) $notlar[] = 'Bu tahsilata ait banka dekontu sistemde kayıtlıdır.';
if ($ilk['aciklama'] && !str_starts_with((string)$ilk['aciklama'], 'Ödeme bildirimi')) $notlar[] = 'Not: ' . $ilk['aciklama'];
foreach ($notlar as $n) { $ny = $pdf->paragraf($R - 92, $ny, '•  ' . $n, 92, 4.6, $GRI) + 5.5; }

// İmzalar
$iy = 238;
$pdf->cizgiRenk([148, 163, 184]); $pdf->kalinlik(0.3);
foreach ([[$L, 'Teslim Eden', $odeyen], [$R - 60, 'Teslim Alan', $ilk['alan'] ?: 'Yönetim']] as [$sx, $etiket, $ad]) {
    $pdf->cizgi($sx, $iy, $sx + 60, $iy);
    $pdf->font(false, 8.5); $pdf->yazi($sx + 30, $iy + 5, $etiket, $GRI, 'C');
    $pdf->font(true, 9.5);  $pdf->yazi($sx + 30, $iy + 10, (string)$ad, $KOYU, 'C');
}

// Alt bilgi
$pdf->cizgiRenk([226, 232, 240]); $pdf->kalinlik(0.3);
$pdf->cizgi($L, 272, $R, 272);
$pdf->font(false, 7.5);
$pdf->yazi(105, 277, 'Bu makbuz ' . ayar('site_adi') . ' yönetim sistemi tarafından elektronik olarak oluşturulmuştur.', $GRI, 'C');
$pdf->font(true, 7.5);
$pdf->yazi(105, 281.5, 'Doğrulama kodu: ' . $kod . '   •   Oluşturma: ' . date('d.m.Y H:i'), $GRI, 'C');

logla('PDF makbuz', $no);
$pdf->gonder('Makbuz_' . $no . '.pdf', (bool)get('indir'));
