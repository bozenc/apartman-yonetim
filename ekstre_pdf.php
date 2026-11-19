<?php
/**
 * PDF Daire Cari Hesap Ekstresi
 *   ekstre_pdf.php?daire=DAIRE_ID[&indir=1]
 * Dairenin tüm borç tahakkuklarını, yapılan ödemeleri, malik/sakin ayrımını,
 * avans (alacak) bakiyesini ve yürüyen (kümülatif) bakiyesini resmi PDF olarak üretir.
 */
require __DIR__ . '/includes/init.php';
require __DIR__ . '/includes/pdf.php';

$u = giris_zorunlu();
$daireId = (int)get('daire');

$st = $pdo->prepare('SELECT * FROM daireler WHERE id = ?');
$st->execute([$daireId]);
$d = $st->fetch();

$sahibi = $d && (int)$d['kullanici_id'] === (int)$u['id'];
if (!$d || (!yetki_herhangi(['aidat.goruntule', 'rapor.goruntule', 'odeme.goruntule']) && !($sahibi && yetki('borc.kendi')))) {
    http_response_code(404);
    exit('Daire bulunamadı veya bu ekstreye erişim yetkiniz yok.');
}

// Dairenin tüm borç ve ödemelerini tarih sırasıyla birleştir
$st = $pdo->prepare('SELECT id, son_odeme AS tarih, aciklama, tur, muhatap, tutar, odenen FROM borclar WHERE daire_id = ? ORDER BY son_odeme ASC, id ASC');
$st->execute([$daireId]);
$borclar = $st->fetchAll();

$st = $pdo->prepare('SELECT o.*, b.aciklama AS borc_aciklama FROM odemeler o JOIN borclar b ON b.id = o.borc_id WHERE o.daire_id = ? ORDER BY o.tarih ASC, o.id ASC');
$st->execute([$daireId]);
$odemeler = $st->fetchAll();

$hareketler = [];
foreach ($borclar as $b) {
    $hareketler[] = [
        'tarih'    => $b['tarih'],
        'tip'      => 'borc',
        'aciklama' => $b['aciklama'] . ($b['muhatap'] === 'malik' ? ' [Malik]' : ''),
        'belge'    => strtoupper($b['tur']),
        'borc'     => (float)$b['tutar'],
        'alacak'   => 0.0,
    ];
}
foreach ($odemeler as $o) {
    $hareketler[] = [
        'tarih'    => $o['tarih'],
        'tip'      => 'odeme',
        'aciklama' => 'Tahsilat: ' . $o['borc_aciklama'],
        'belge'    => $o['makbuz_no'] ?: ('#' . $o['id']),
        'borc'     => 0.0,
        'alacak'   => (float)$o['tutar'],
    ];
}
usort($hareketler, fn($a, $b) => strcmp($a['tarih'], $b['tarih']) ?: ($a['tip'] === 'borc' ? -1 : 1));

$topTahakkuk = round(array_sum(array_column($borclar, 'tutar')), 2);
$topOdenen   = round(array_sum(array_column($odemeler, 'tutar')), 2);
$kalanBorc   = round(max(0, $topTahakkuk - $topOdenen), 2);
$avansBakiye = round((float)($d['alacak_bakiye'] ?? 0), 2);
$sakinBorc   = round(array_sum(array_map(fn($b) => ($b['muhatap'] ?? 'sakin') === 'sakin' ? max(0, $b['tutar'] - $b['odenen']) : 0, $borclar)), 2);
$malikBorc   = round(array_sum(array_map(fn($b) => ($b['muhatap'] ?? 'sakin') === 'malik' ? max(0, $b['tutar'] - $b['odenen']) : 0, $borclar)), 2);

/* ======================== PDF ÇİZİMİ ======================== */
$MOR = [79, 70, 229]; $KOYU = [15, 23, 42]; $GRI = [100, 116, 139]; $ACIK = [241, 245, 249];
$pdf = new Pdf();
$etiket = $d['blok'] . '-' . $d['daire_no'];
$pdf->baslik = 'Daire Hesap Ekstresi ' . $etiket;
$L = 16; $R = 194; $G = $R - $L;

// Üst bant
$pdf->dolguRenk($MOR);
$pdf->kutu(0, 0, 210, 40);
$pdf->font(true, 16);
$pdf->yazi($L, 16, ayar('site_adi', 'Apartman Yönetimi'), [255, 255, 255]);
$pdf->font(false, 8.5);
$pdf->yazi($L, 23, (string)ayar('adres'), [224, 231, 255]);
$pdf->yazi($L, 28, implode('  •  ', array_filter([ayar('telefon'), ayar('eposta')])), [224, 231, 255]);

$pdf->font(true, 13);
$pdf->yazi($R, 16, 'DAİRE CARİ HESAP EKSTRESİ', [255, 255, 255], 'R');
$pdf->font(true, 11);
$pdf->yazi($R, 24, $d['blok'] . ' Blok · Daire ' . $d['daire_no'], [255, 255, 255], 'R');
$pdf->font(false, 8.5);
$pdf->yazi($R, 31, 'Düzenleme: ' . date('d.m.Y H:i'), [224, 231, 255], 'R');

// Daire & Özet Kutuları
$y = 48;
$pdf->dolguRenk([248, 250, 252]); $pdf->cizgiRenk([226, 232, 240]); $pdf->kalinlik(0.3);
$pdf->kutu($L, $y, $G, 26, 'FD', 3);

$malikAd = $sahibi ? ($d['malik_adi'] ?: '-') : (kisi($d['malik_adi']) ?: '-');
$sakinAd = $sahibi ? ($d['sakin_adi'] ?: '-') : (kisi($d['sakin_adi']) ?: '-');

$pdf->font(true, 7.5); $pdf->yazi($L + 5, $y + 7, 'KAT MALİKİ', $GRI);
$pdf->font(true, 10);  $pdf->yazi($L + 5, $y + 12.5, mb_strimwidth($malikAd, 0, 28, '…'), $KOYU);
$pdf->font(false, 8);  $pdf->yazi($L + 5, $y + 18, 'Malik Borcu: ' . para($malikBorc), $GRI);

$pdf->font(true, 7.5); $pdf->yazi($L + 65, $y + 7, 'OTURAN (SAKİN)', $GRI);
$pdf->font(true, 10);  $pdf->yazi($L + 65, $y + 12.5, mb_strimwidth($sakinAd, 0, 28, '…'), $KOYU);
$pdf->font(false, 8);  $pdf->yazi($L + 65, $y + 18, 'Sakin Borcu: ' . para($sakinBorc), $GRI);

$pdf->font(true, 7.5); $pdf->yazi($R - 5, $y + 7, 'GÜNCEL NET BORÇ BAKİYESİ', $GRI, 'R');
$pdf->font(true, 14);  $pdf->yazi($R - 5, $y + 15, para($kalanBorc), $kalanBorc > 0 ? [220, 38, 38] : [5, 150, 105], 'R');
if ($avansBakiye > 0) {
    $pdf->font(true, 8); $pdf->yazi($R - 5, $y + 21, 'Alacak (Avans) Bakiyesi: ' . para($avansBakiye), [5, 150, 105], 'R');
} else {
    $pdf->font(false, 8); $pdf->yazi($R - 5, $y + 21, 'Tahakkuk: ' . para($topTahakkuk) . '  •  Ödenen: ' . para($topOdenen), $GRI, 'R');
}

// Hareketler Tablosu
$y = 82;
$tabloBaslik = function (float $y) use ($pdf, $L, $R, $G, $GRI, $ACIK) {
    $pdf->dolguRenk($ACIK);
    $pdf->kutu($L, $y, $G, 8.5, 'F', 2);
    $pdf->font(true, 7.5);
    $pdf->yazi($L + 3, $y + 5.8, 'TARİH', $GRI);
    $pdf->yazi($L + 25, $y + 5.8, 'BELGE / TÜR', $GRI);
    $pdf->yazi($L + 52, $y + 5.8, 'AÇIKLAMA', $GRI);
    $pdf->yazi($L + 128, $y + 5.8, 'BORÇ', $GRI, 'R');
    $pdf->yazi($L + 152, $y + 5.8, 'ÖDENEN', $GRI, 'R');
    $pdf->yazi($R - 3, $y + 5.8, 'BAKİYE', $GRI, 'R');
    return $y + 8.5;
};
$y = $tabloBaslik($y);

$bakiye = 0.0;
foreach ($hareketler as $h) {
    if ($y + 8 > 262) {
        $pdf->sayfaEkle();
        $y = $tabloBaslik(18);
    }
    $bakiye = round($bakiye + $h['borc'] - $h['alacak'], 2);
    $pdf->font(false, 8.5);
    $pdf->yazi($L + 3, $y + 5.5, tarih($h['tarih']), $GRI);
    $pdf->yazi($L + 25, $y + 5.5, mb_strimwidth($h['belge'], 0, 14, ''), $GRI);
    $pdf->yazi($L + 52, $y + 5.5, mb_strimwidth($h['aciklama'], 0, 42, '…'), $KOYU);
    $pdf->yazi($L + 128, $y + 5.5, $h['borc'] > 0 ? para($h['borc']) : '-', $KOYU, 'R');
    $pdf->yazi($L + 152, $y + 5.5, $h['alacak'] > 0 ? para($h['alacak']) : '-', [5, 150, 105], 'R');
    $pdf->font(true, 8.5);
    $pdf->yazi($R - 3, $y + 5.5, para(max(0, $bakiye)), $bakiye > 0 ? $KOYU : [5, 150, 105], 'R');
    $y += 7.5;
    $pdf->cizgiRenk([226, 232, 240]); $pdf->kalinlik(0.2);
    $pdf->cizgi($L, $y, $R, $y);
}

if (!$hareketler) {
    $pdf->font(false, 9.5);
    $pdf->yazi(105, $y + 12, 'Bu daireye ait borç veya ödeme hareketi bulunmamaktadır.', $GRI, 'C');
    $y += 20;
}

// Alt bilgi ve IBAN
$pdf->cizgiRenk([226, 232, 240]); $pdf->kalinlik(0.3);
$pdf->cizgi($L, 272, $R, 272);
$pdf->font(false, 7.5);
if (ayar('iban')) {
    $pdf->yazi(105, 277, 'Ödeme İçin Banka IBAN: ' . ayar('iban') . '  (Açıklamaya ' . $etiket . ' yazınız)', $KOYU, 'C');
}
$pdf->yazi(105, 282, ayar('site_adi') . ' Yönetim Sistemi · Bu belge elektronik ortamda üretilmiştir.', $GRI, 'C');

logla('PDF daire ekstresi', $etiket);
$pdf->gonder('Ekstre_' . $etiket . '_' . date('Ymd') . '.pdf', (bool)get('indir'));
