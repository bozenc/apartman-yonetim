<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('aidat.goruntule');

if (is_post()) {
    csrf_check();
    $islem = post('islem');
    $geri = $_POST['geri'] ?? 'borclar.php';
    if (!is_string($geri) || !preg_match('~^borclar\.php~', $geri)) $geri = 'borclar.php';

    if ($islem === 'odeme') {
        yetki_zorunlu('odeme.al');
        $borcId = (int)post('borc_id');
        $tutar = sayi(post('tutar'));
        $yontem = array_key_exists(post('yontem'), odeme_yontemleri()) ? post('yontem') : 'nakit';
        $dk = null;
        try {
            $dk = dekont_kaydet($_FILES['dekont'] ?? null);
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT b.*, d.blok, d.daire_no FROM borclar b JOIN daireler d ON d.id = b.daire_id WHERE b.id = ? FOR UPDATE');
            $st->execute([$borcId]);
            $b = $st->fetch();
            $kalan = $b ? round($b['tutar'] - $b['odenen'], 2) : 0;
            if (!$b || $tutar <= 0 || $tutar > $kalan + 0.001) {
                throw new RuntimeException('Geçersiz tutar. Ödeme tutarı kalan borçtan (' . para($kalan) . ') fazla olamaz.');
            }
            $oid = odeme_isle($pdo, $b, $tutar, post('tarih') ?: date('Y-m-d'), $yontem, post('aciklama') ?: null, $dk['dosya'] ?? null);
            $pdo->commit();
            logla('Tahsilat', $b['blok'] . '-' . $b['daire_no'] . ' · ' . para($tutar) . ($dk ? ' · dekontlu' : ''));
            $bl = ['eposta' => 0, 'mesaj' => 0];
            if (post('eposta_gonder')) { $bl = bildir_makbuz([$oid]); }
            flash('success', para($tutar) . ' tahsilat kaydedildi.' . bildirim_ozeti($bl));
            if (post('makbuz')) redirect('makbuz_pdf.php?id=' . $oid);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!empty($dk)) @unlink(dekont_klasoru() . '/' . $dk['dosya']);
            flash('danger', $e->getMessage());
        }
    }

    if ($islem === 'toplu_odeme') {
        yetki_zorunlu('odeme.al');
        $daireId = (int)post('daire_id');
        $tutar = sayi(post('tutar'));
        $yontem = array_key_exists(post('yontem'), odeme_yontemleri()) ? post('yontem') : 'nakit';
        try {
            $dk = dekont_kaydet($_FILES['dekont'] ?? null);
            $pdo->beginTransaction();
            $idler = tahsilat_dagit($pdo, $daireId, $tutar, post('tarih') ?: date('Y-m-d'), $yontem, 'Toplu tahsilat', [], $dk['dosya'] ?? null);
            $pdo->commit();
            logla('Toplu tahsilat', 'Daire ID ' . $daireId . ' · ' . para($tutar) . ' · ' . count($idler) . ' borç');
            $bl = ['eposta' => 0, 'mesaj' => 0];
            if (post('eposta_gonder')) { $bl = bildir_makbuz($idler); }
            flash('success', para($tutar) . ' tahsil edildi, en eski borçtan başlayarak ' . count($idler) . ' kalem kapatıldı.' . bildirim_ozeti($bl));
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!empty($dk)) @unlink(dekont_klasoru() . '/' . $dk['dosya']);
            flash('danger', $e->getMessage());
        }
    }

    if ($islem === 'ek_borc') {
        yetki_zorunlu('borc.ekle');
        $tutar = sayi(post('tutar'));
        $tur = array_key_exists(post('tur'), borc_turleri()) ? post('tur') : 'ek';
        $muhatap = post('muhatap') === 'malik' ? 'malik' : 'sakin';
        $aciklama = post('aciklama');
        $sonOdeme = post('son_odeme') ?: date('Y-m-d', strtotime('+15 days'));
        $dagitim = post('dagitim', 'sabit');
        if ($tutar <= 0 || $aciklama === '') {
            flash('danger', 'Tutar ve açıklama zorunludur.');
        } else {
            $hedef = post('hedef');
            if ($hedef === 'tumu') {
                $dListe = $pdo->query("SELECT id, COALESCE(metrekare, 100) AS m2, GREATEST(1, kisi_sayisi) AS kisi FROM daireler WHERE durum = 'dolu'")->fetchAll();
            } elseif ($hedef === 'tum_malik') {
                $dListe = $pdo->query("SELECT id, COALESCE(metrekare, 100) AS m2, GREATEST(1, kisi_sayisi) AS kisi FROM daireler")->fetchAll();
            } else {
                $dListe = [['id' => (int)post('daire_id'), 'm2' => 100, 'kisi' => 1]];
                $dagitim = 'sabit';
            }
            $topM2 = array_sum(array_column($dListe, 'm2')) ?: 1;
            $topKisi = array_sum(array_column($dListe, 'kisi')) ?: 1;
            $adet = count($dListe) ?: 1;

            $ins = $pdo->prepare('INSERT INTO borclar (daire_id, tur, muhatap, aciklama, tutar, son_odeme) VALUES (?,?,?,?,?,?)');
            $eklenen = 0;
            foreach ($dListe as $dr) {
                $did = (int)$dr['id'];
                if (!$did) continue;
                $pay = match ($dagitim) {
                    'esit' => round($tutar / $adet, 2),
                    'm2'   => round($tutar * ((float)$dr['m2'] / $topM2), 2),
                    'kisi' => round($tutar * ((int)$dr['kisi'] / $topKisi), 2),
                    default => $tutar,
                };
                if ($pay <= 0) continue;
                $ins->execute([$did, $tur, $muhatap, $aciklama, $pay, $sonOdeme]);
                avans_mahsup_et($pdo, $did);
                $eklenen++;
            }
            logla('Ek borç', $aciklama . ' · ' . para($tutar) . ' (' . $dagitim . ') × ' . $eklenen);
            flash('success', $eklenen . ' daireye "' . $aciklama . '" borcu eklendi (Avansı olan dairelerde otomatik mahsup yapıldı).');
        }
    }

    if ($islem === 'banka_csv') {
        yetki_zorunlu('odeme.al');
        $f = $_FILES['banka_dosya'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash('danger', 'Lütfen banka hareketleri CSV dosyasını seçin.');
            redirect($geri);
        }
        $icerik = (string)file_get_contents($f['tmp_name']);
        if (str_starts_with($icerik, "\xEF\xBB\xBF")) $icerik = substr($icerik, 3);
        $satirlar = preg_split('/\r\n|\r|\n/', trim($icerik));
        $ayrac = str_contains($satirlar[0] ?? '', ';') ? ';' : ',';
        $tumDaireler = $pdo->query('SELECT id, blok, daire_no, malik_adi, sakin_adi FROM daireler')->fetchAll();

        $eslesen = 0; $atlanan = 0; $topTahsil = 0.0;
        foreach ($satirlar as $idx => $satir) {
            if (trim($satir) === '') continue;
            $s = array_map('trim', str_getcsv($satir, $ayrac));
            if ($idx === 0 && (str_contains(mb_strtolower($s[0] ?? ''), 'tarih') || str_contains(mb_strtolower($s[1] ?? ''), 'aciklama') || str_contains(mb_strtolower($s[1] ?? ''), 'açıklama'))) continue;
            $hamTarih = $s[0] ?? '';
            $acik = $s[1] ?? '';
            $tutar = sayi($s[2] ?? '0');
            if ($tutar <= 0 || $acik === '') { $atlanan++; continue; }
            $ts = strtotime(str_replace('.', '-', $hamTarih));
            $tarih = $ts ? date('Y-m-d', $ts) : date('Y-m-d');

            // Açıklamadan daireyi eşleştir (Önce "A-1", "A/1", "A Blok Daire 1", sonra isim-soyisim)
            $bulunanId = 0;
            $acikNorm = mb_strtoupper($acik);
            foreach ($tumDaireler as $dr) {
                $b = preg_quote(mb_strtoupper($dr['blok']), '/');
                $n = preg_quote(mb_strtoupper($dr['daire_no']), '/');
                if (preg_match('/\b' . $b . '\s*[- \/]\s*' . $n . '\b/u', $acikNorm) || preg_match('/\b' . $b . '\s*BLOK\s*(?:NO|DAİRE|DAIRE|D)?\s*[:.]?\s*' . $n . '\b/u', $acikNorm)) {
                    $bulunanId = (int)$dr['id'];
                    break;
                }
            }
            if (!$bulunanId) {
                foreach ($tumDaireler as $dr) {
                    foreach ([$dr['sakin_adi'], $dr['malik_adi']] as $isim) {
                        $isim = trim((string)$isim);
                        if (mb_strlen($isim) >= 5 && str_contains($acikNorm, mb_strtoupper($isim))) {
                            $bulunanId = (int)$dr['id'];
                            break 2;
                        }
                    }
                }
            }
            if ($bulunanId) {
                try {
                    $pdo->beginTransaction();
                    tahsilat_dagit($pdo, $bulunanId, $tutar, $tarih, 'havale', 'Banka CSV: ' . mb_substr($acik, 0, 120), [], null, null, true);
                    $pdo->commit();
                    $eslesen++;
                    $topTahsil += $tutar;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $atlanan++;
                }
            } else {
                $atlanan++;
            }
        }
        logla('Banka CSV içe aktarım', "$eslesen tahsilat (" . para($topTahsil) . "), $atlanan eşleşmeyen");
        flash($eslesen ? 'success' : 'warning', "Banka CSV işlendi: $eslesen işlem otomatik eşleştirilip tahsil edildi (" . para($topTahsil) . ")." . ($atlanan ? " $atlanan satır eşleşmediği için atlandı." : ''));
    }

    if ($islem === 'sil') {
        yetki_zorunlu('borc.sil');
        $id = (int)post('id');
        $st = $pdo->prepare('SELECT COUNT(*) FROM odemeler WHERE borc_id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            flash('warning', 'Bu borca ait tahsilat var. Önce tahsilat kaydını silmelisiniz.');
        } else {
            $pdo->prepare('DELETE FROM borclar WHERE id = ?')->execute([$id]);
            logla('Borç silindi', 'ID: ' . $id);
            flash('success', 'Borç kaydı silindi.');
        }
    }

    if ($islem === 'hatirlat') {
        if (!yetki_herhangi(['eposta.yonet', 'mesaj.yonet', 'aidat.donem'])) yetki_zorunlu('aidat.donem');
        $hedefDaire = (int)post('daire_id', 0);
        if ($hedefDaire > 0) {
            $hedefler = [$hedefDaire];
            $sadeceGeciken = false;
        } else {
            $hedefler = array_map('intval', $pdo->query("SELECT DISTINCT daire_id FROM borclar WHERE tutar > odenen AND son_odeme < CURDATE()")->fetchAll(PDO::FETCH_COLUMN));
            $sadeceGeciken = true;
        }
        $topEp = 0; $topMsj = 0;
        foreach ($hedefler as $did) {
            $r = bildir_borc_hatirlat($did, $sadeceGeciken);
            $topEp += $r['eposta'];
            $topMsj += $r['mesaj'];
        }
        logla('Borç hatırlatma', count($hedefler) . " daire · $topEp e-posta, $topMsj mesaj");
        if ($topEp + $topMsj > 0) {
            flash('success', count($hedefler) . ' daire için hatırlatma sıraya alındı (' . trim(bildirim_ozeti(['eposta' => $topEp, 'mesaj' => $topMsj])) . ')');
        } else {
            flash('warning', 'Hatırlatma gönderilemedi: gecikmiş borcu olan dairelerde kayıtlı iletişim bilgisi yok veya bildirim kanalları kapalı.');
        }
    }
    redirect($geri);
}

if (get('ornek_banka_csv')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ornek_banka_ekstresi.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Tarih', 'Aciklama', 'Tutar'], ';');
    fputcsv($out, [date('d.m.Y'), 'A-1 Aidat Ödemesi Ahmet Yılmaz', '1500,00'], ';');
    fputcsv($out, [date('d.m.Y'), 'A Blok Daire 2 Ekim Aidatı', '1500,00'], ';');
    exit;
}

/* ---------------- Liste ---------------- */
$daireF = (int)get('daire', 0);
$donemF = (int)get('donem', 0);
$durumF = get('durum', '');
$turF   = get('tur', '');
$sayfa  = max(1, (int)get('s', 1));
$limit  = 25;

$where = ['1=1'];
$par = [];
if ($daireF) { $where[] = 'b.daire_id = ?'; $par[] = $daireF; }
if ($donemF) { $where[] = 'b.donem_id = ?'; $par[] = $donemF; }
if ($turF && array_key_exists($turF, borc_turleri())) { $where[] = 'b.tur = ?'; $par[] = $turF; }
if ($durumF === 'odenmemis') $where[] = 'b.tutar > b.odenen';
if ($durumF === 'odenmis')   $where[] = 'b.tutar <= b.odenen';
if ($durumF === 'geciken')   $where[] = 'b.tutar > b.odenen AND b.son_odeme < CURDATE()';
$w = implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(b.tutar),0) AS tutar, COALESCE(SUM(b.odenen),0) AS odenen FROM borclar b WHERE $w");
$st->execute($par);
$ozet = $st->fetch();

$st = $pdo->prepare("SELECT b.*, d.blok, d.daire_no, COALESCE(d.sakin_adi, d.malik_adi) AS ad
    FROM borclar b JOIN daireler d ON d.id = b.daire_id WHERE $w
    ORDER BY b.son_odeme DESC, d.blok, CAST(d.daire_no AS UNSIGNED) LIMIT $limit OFFSET " . (($sayfa - 1) * $limit));
$st->execute($par);
$borclar = $st->fetchAll();

$daireler = $pdo->query('SELECT id, blok, daire_no, COALESCE(sakin_adi, malik_adi) AS ad FROM daireler ORDER BY blok, CAST(daire_no AS UNSIGNED)')->fetchAll();
$donemler = $pdo->query('SELECT id, donem FROM aidat_donemleri ORDER BY donem DESC')->fetchAll();

$seciliDaire = null;
if ($daireF) foreach ($daireler as $d) if ($d['id'] == $daireF) $seciliDaire = $d;
$daireKalan = 0;
if ($seciliDaire) {
    $st = $pdo->prepare('SELECT COALESCE(SUM(tutar - odenen),0) FROM borclar WHERE daire_id = ? AND tutar > odenen');
    $st->execute([$daireF]);
    $daireKalan = (float)$st->fetchColumn();
}
$geriUrl = 'borclar.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');

$sayfaBaslik = 'Borç & Tahsilat';
$sayfaAciklama = $seciliDaire ? daire_etiket($seciliDaire) . ' · ' . kisi($seciliDaire['ad']) : 'Daire borçlarını takip edin ve ödeme alın';
require __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card stat-card"><div class="stat-icon soft-primary"><i class="bi bi-receipt"></i></div>
        <div><div class="stat-label">Toplam Tahakkuk</div><div class="stat-value"><?= para($ozet['tutar']) ?></div><div class="stat-sub"><?= (int)$ozet['n'] ?> kayıt</div></div></div></div>
    <div class="col-md-4"><div class="card stat-card"><div class="stat-icon soft-success"><i class="bi bi-check2-circle"></i></div>
        <div><div class="stat-label">Tahsil Edilen</div><div class="stat-value"><?= para($ozet['odenen']) ?></div></div></div></div>
    <div class="col-md-4"><div class="card stat-card"><div class="stat-icon soft-danger"><i class="bi bi-exclamation-circle"></i></div>
        <div><div class="stat-label">Kalan</div><div class="stat-value"><?= para($ozet['tutar'] - $ozet['odenen']) ?></div></div></div></div>
</div>

<div class="card">
    <div class="card-header">
        <form class="filter-bar" method="get">
            <select name="daire" class="form-select">
                <option value="">Tüm daireler</option>
                <?php foreach ($daireler as $d): ?><option value="<?= $d['id'] ?>" <?= $daireF == $d['id'] ? 'selected' : '' ?>><?= e($d['blok'] . '-' . $d['daire_no'] . ' ' . ($d['ad'] ? '· ' . kisi($d['ad']) : '')) ?></option><?php endforeach; ?>
            </select>
            <select name="donem" class="form-select">
                <option value="">Tüm dönemler</option>
                <?php foreach ($donemler as $d): ?><option value="<?= $d['id'] ?>" <?= $donemF == $d['id'] ? 'selected' : '' ?>><?= donem_adi($d['donem']) ?></option><?php endforeach; ?>
            </select>
            <select name="tur" class="form-select">
                <option value="">Tüm türler</option>
                <?php foreach (borc_turleri() as $k => $v): ?><option value="<?= $k ?>" <?= $turF === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
            <select name="durum" class="form-select">
                <option value="">Tüm durumlar</option>
                <option value="odenmemis" <?= $durumF === 'odenmemis' ? 'selected' : '' ?>>Ödenmemiş</option>
                <option value="geciken" <?= $durumF === 'geciken' ? 'selected' : '' ?>>Gecikmiş</option>
                <option value="odenmis" <?= $durumF === 'odenmis' ? 'selected' : '' ?>>Ödenmiş</option>
            </select>
            <button class="btn btn-light"><i class="bi bi-funnel"></i></button>
            <?php if ($_GET): ?><a href="<?= url('borclar.php') ?>" class="btn btn-link">Temizle</a><?php endif; ?>
        </form>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($seciliDaire): ?>
                <a href="<?= url('ekstre_pdf.php?daire=' . $daireF) ?>" target="_blank" class="btn btn-light" title="Daire Cari Hesap Ekstresi (PDF)"><i class="bi bi-file-earmark-pdf text-danger me-1"></i>PDF Ekstre</a>
            <?php endif; ?>
            <?php if (herhangi_bildirim_aktif() && yetki_herhangi(['eposta.yonet', 'mesaj.yonet', 'aidat.donem'])): ?>
                <form method="post" class="d-inline" data-confirm="<?= $seciliDaire ? e(daire_etiket($seciliDaire)) . ' sakinine açık borç hatırlatması gönderilecek.' : 'Son ödeme tarihi geçmiş borcu bulunan tüm dairelere hatırlatma bildirimi gönderilecek.' ?>">
                    <?= csrf_field() ?><input type="hidden" name="islem" value="hatirlat"><input type="hidden" name="daire_id" value="<?= $daireF ?>"><input type="hidden" name="geri" value="<?= e($geriUrl) ?>">
                    <button class="btn btn-light" title="E-posta / SMS / WhatsApp ile borç hatırlatması gönder"><i class="bi bi-bell me-1"></i><?= $seciliDaire ? 'Hatırlat' : 'Gecikenlere Hatırlat' ?></button>
                </form>
            <?php endif; ?>
            <?php if (yetki('odeme.al')): ?>
                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#bankaCsvModal" title="Banka hesap ekstresi (CSV) yükleyerek otomatik tahsilat eşleştir"><i class="bi bi-bank me-1"></i>Banka CSV</button>
            <?php endif; ?>
            <?php if ($seciliDaire && yetki('odeme.al')): ?>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#topluModal"><i class="bi bi-cash-stack me-1"></i>Toplu Tahsilat / Avans</button>
            <?php endif; ?>
            <?php if (yetki('borc.ekle')): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ekBorcModal"><i class="bi bi-plus-lg me-1"></i>Borç / Gider Paylaştır</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Daire</th><th>Açıklama</th><th>Tür</th><th>Son Ödeme</th><th class="text-end">Tutar</th><th class="text-end">Ödenen</th><th class="text-end">Kalan</th><th>Durum</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($borclar as $b): $kalan = round($b['tutar'] - $b['odenen'], 2); ?>
                <tr>
                    <td><a href="?daire=<?= $b['daire_id'] ?>" class="fw-bold"><?= e($b['blok'] . '-' . $b['daire_no']) ?></a><div class="small text-muted"><?= e(kisi($b['ad']) ?: '-') ?></div></td>
                    <td><?= e($b['aciklama']) ?></td>
                    <td>
                        <span class="badge-soft secondary"><?= e(borc_turleri()[$b['tur']] ?? $b['tur']) ?></span>
                        <?php if (($b['muhatap'] ?? 'sakin') === 'malik'): ?><span class="badge-soft info">Malik</span><?php endif; ?>
                    </td>
                    <td><?= tarih($b['son_odeme']) ?></td>
                    <td class="text-end"><?= para($b['tutar']) ?></td>
                    <td class="text-end text-success"><?= para($b['odenen']) ?></td>
                    <td class="text-end fw-bold"><?= para($kalan) ?></td>
                    <td><?= borc_durum($b) ?></td>
                    <td class="table-actions">
                        <?php if ($kalan > 0 && yetki('odeme.al')): ?>
                            <button class="btn btn-sm btn-soft-success" data-bs-toggle="modal" data-bs-target="#odemeModal"
                                data-edit='<?= e(json_encode(['borc_id' => $b['id'], 'tutar' => number_format($kalan, 2, ',', ''), 'bilgi' => $b['blok'] . '-' . $b['daire_no'] . ' · ' . $b['aciklama'] . ' · Kalan: ' . para($kalan)], JSON_UNESCAPED_UNICODE)) ?>'>
                                <i class="bi bi-cash"></i> Öde</button>
                        <?php endif; ?>
                        <?php if (yetki('borc.sil') && (float)$b['odenen'] == 0.0): ?>
                            <form method="post" class="d-inline" data-confirm="Bu borç kaydı silinecek."><?= csrf_field() ?>
                                <input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="geri" value="<?= e($geriUrl) ?>">
                                <button class="btn btn-sm btn-soft-danger"><i class="bi bi-trash"></i></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$borclar): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i><p class="mt-2 mb-0">Kayıt bulunamadı.</p></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($ozet['n'] > $limit): ?><div class="card-body d-flex justify-content-end"><?= sayfalama((int)$ozet['n'], $sayfa, $limit) ?></div><?php endif; ?>
</div>

<?php if (yetki('odeme.al')): ?>
<div class="modal fade" id="odemeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="islem" value="odeme"><input type="hidden" name="borc_id"><input type="hidden" name="geri" value="<?= e($geriUrl) ?>">
                <div class="modal-header"><h5 class="modal-title">Tahsilat Al</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input name="bilgi" class="form-control-plaintext fw-semibold mb-2" readonly tabindex="-1">
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">Tutar (₺)</label><input name="tutar" class="form-control" inputmode="decimal" required></div>
                        <div class="col-6"><label class="form-label">Tarih</label><input type="date" name="tarih" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-6"><label class="form-label">Ödeme Yöntemi</label><select name="yontem" class="form-select"><?php foreach (odeme_yontemleri() as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                        <div class="col-6"><label class="form-label">Açıklama</label><input name="aciklama" class="form-control"></div>
<div class="col-12"><label class="form-label">Dekont <span class="text-muted fw-normal">(isteğe bağlı · PDF, JPG, PNG · en fazla 5 MB)</span></label><input type="file" name="dekont" class="form-control" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></div>
<?php if (herhangi_bildirim_aktif()): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="eposta_gonder" value="1" id="epT" <?= ayar('mail_makbuz') === '1' || ayar('sms_makbuz') === '1' || ayar('wa_makbuz') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="epT"><i class="bi bi-bell"></i> Sakine makbuz bildirimi gönder <span class="text-muted small">(<?= e(implode(' / ', array_keys(array_filter(['e-posta' => eposta_aktif(), 'SMS' => sms_aktif(), 'WhatsApp' => wa_aktif()])))) ?>)</span></label></div></div><?php endif; ?>
                        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="makbuz" value="1" id="mk"><label class="form-check-label" for="mk">Kaydettikten sonra PDF makbuzu indir</label></div></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-success"><i class="bi bi-check2 me-1"></i>Tahsil Et</button></div>
            </form>
        </div>
    </div>
</div>
<?php if ($seciliDaire): ?>
<div class="modal fade" id="topluModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="islem" value="toplu_odeme"><input type="hidden" name="daire_id" value="<?= $daireF ?>"><input type="hidden" name="geri" value="<?= e($geriUrl) ?>">
                <div class="modal-header"><h5 class="modal-title">Toplu Tahsilat / Avans · <?= e($seciliDaire['blok'] . '-' . $seciliDaire['daire_no']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>Güncel borç: <strong><?= para($daireKalan) ?></strong>. Girilen tutar önce açık borçları kapatır; fazla yatırılan tutar dairenin <strong>Avans (Alacak) Bakiyesine</strong> eklenerek sonraki aidatlardan otomatik düşülür.</div>
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">Tutar (₺)</label><input name="tutar" class="form-control" value="<?= $daireKalan > 0 ? number_format($daireKalan, 2, ',', '') : '' ?>" inputmode="decimal" required></div>
                        <div class="col-6"><label class="form-label">Tarih</label><input type="date" name="tarih" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-12"><label class="form-label">Ödeme Yöntemi</label><select name="yontem" class="form-select"><?php foreach (odeme_yontemleri() as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                        <div class="col-12"><label class="form-label">Dekont <span class="text-muted fw-normal">(isteğe bağlı · PDF, JPG, PNG · en fazla 5 MB)</span></label><input type="file" name="dekont" class="form-control" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></div>
                        <?php if (herhangi_bildirim_aktif()): ?><div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="eposta_gonder" value="1" id="epTop" <?= ayar('mail_makbuz') === '1' || ayar('sms_makbuz') === '1' || ayar('wa_makbuz') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="epTop"><i class="bi bi-bell"></i> Sakine makbuz bildirimi gönder <span class="text-muted small">(<?= e(implode(' / ', array_keys(array_filter(['e-posta' => eposta_aktif(), 'SMS' => sms_aktif(), 'WhatsApp' => wa_aktif()])))) ?>)</span></label></div></div><?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-success"><i class="bi bi-check2 me-1"></i>Tahsil Et</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="modal fade" id="bankaCsvModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="islem" value="banka_csv"><input type="hidden" name="geri" value="<?= e($geriUrl) ?>">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-bank me-2"></i>Banka Hesap Hareketleri (CSV) Eşleştir</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="small text-muted">Bankadan indirdiğiniz <code>Tarih;Açıklama;Tutar</code> sütunlu CSV dosyasını yükleyin. Sistem açıklama kısmındaki daire numarasını (<code>A-1</code>, <code>A Blok Daire 2</code>) veya sakin/malik adını otomatik bulup borçlardan düşer.</p>
                    <div class="mb-3"><a href="?ornek_banka_csv=1" class="btn btn-sm btn-soft-primary"><i class="bi bi-download me-1"></i>Örnek Banka CSV Şablonu İndir</a></div>
                    <div><label class="form-label">CSV Dosyası *</label><input type="file" name="banka_dosya" class="form-control" accept=".csv,text/csv" required></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-check2-all me-1"></i>Eşleştir ve Tahsil Et</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (yetki('borc.ekle')): ?>
<div class="modal fade" id="ekBorcModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="ek_borc"><input type="hidden" name="geri" value="<?= e($geriUrl) ?>">
                <div class="modal-header"><h5 class="modal-title">Borç Ekle / Ortak Gider Paylaştır</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Hedef</label>
                            <select name="hedef" class="form-select" id="hedefSec">
                                <option value="tek">Tek daire</option>
                                <option value="tumu">Tüm dolu daireler</option>
                                <option value="tum_malik">Tüm daireler (boşlar dahil)</option>
                            </select></div>
                        <div class="col-md-6"><label class="form-label">Sorumlu (Muhatap)</label>
                            <select name="muhatap" class="form-select">
                                <option value="sakin">Oturan (Sakin / Kiracı)</option>
                                <option value="malik">Kat Maliki (Ev Sahibi)</option>
                            </select></div>
                        <div class="col-12" id="daireSecKutu"><label class="form-label">Daire</label>
                            <select name="daire_id" class="form-select"><?php foreach ($daireler as $d): ?><option value="<?= $d['id'] ?>" <?= $daireF == $d['id'] ? 'selected' : '' ?>><?= e($d['blok'] . '-' . $d['daire_no'] . ' ' . ($d['ad'] ? '· ' . kisi($d['ad']) : '')) ?></option><?php endforeach; ?></select></div>
                        <div class="col-12" id="dagitimKutu" style="display:none"><label class="form-label">Paylaştırma Yöntemi</label>
                            <select name="dagitim" class="form-select">
                                <option value="sabit">Daire Başına Girilen Tutar (Sabit)</option>
                                <option value="esit">Toplam Fatura Tutarını Dairelere Eşit Böl</option>
                                <option value="m2">Toplam Fatura Tutarını Metrekare (m²) Oranında Böl</option>
                                <option value="kisi">Toplam Fatura Tutarını Kişi Sayısı Oranında Böl</option>
                            </select></div>
                        <div class="col-6"><label class="form-label">Tür</label><select name="tur" class="form-select"><?php foreach (borc_turleri() as $k => $v): if ($k === 'aidat') continue; ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                        <div class="col-6"><label class="form-label">Tutar (₺)</label><input name="tutar" class="form-control" inputmode="decimal" required></div>
                        <div class="col-8"><label class="form-label">Açıklama</label><input name="aciklama" class="form-control" placeholder="ör. Merkezi ısıtma / Dış cephe payı" required></div>
                        <div class="col-4"><label class="form-label">Son Ödeme</label><input type="date" name="son_odeme" class="form-control" value="<?= date('Y-m-d', strtotime('+15 days')) ?>"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Ekle</button></div>
            </form>
        </div>
    </div>
</div>
<?php ob_start(); ?>
<script>
document.getElementById('hedefSec').addEventListener('change', function () {
    const tek = this.value === 'tek';
    document.getElementById('daireSecKutu').style.display = tek ? '' : 'none';
    document.getElementById('dagitimKutu').style.display = tek ? 'none' : '';
});
</script>
<?php $sayfaJs = ob_get_clean(); endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
