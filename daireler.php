<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('daire.goruntule');

/* ------------ İşlemler ------------ */
if (is_post()) {
    csrf_check();
    $islem = post('islem');

    if ($islem === 'kaydet') {
        $id = (int)post('id');
        yetki_zorunlu($id ? 'daire.duzenle' : 'daire.ekle');
        $v = [
            'blok'          => mb_strtoupper(post('blok', 'A')),
            'daire_no'      => post('daire_no'),
            'kat'           => post('kat') ?: null,
            'metrekare'     => post('metrekare') !== '' ? sayi(post('metrekare')) : null,
            'durum'         => post('durum') === 'bos' ? 'bos' : 'dolu',
            'oturan_tipi'   => post('oturan_tipi') === 'kiraci' ? 'kiraci' : 'malik',
            'malik_adi'     => post('malik_adi') ?: null,
            'malik_tel'     => post('malik_tel') ?: null,
            'malik_eposta'  => filter_var(post('malik_eposta'), FILTER_VALIDATE_EMAIL) ? mb_strtolower(post('malik_eposta')) : null,
            'sakin_adi'     => post('sakin_adi') ?: null,
            'sakin_tel'     => post('sakin_tel') ?: null,
            'sakin_eposta'  => filter_var(post('sakin_eposta'), FILTER_VALIDATE_EMAIL) ? mb_strtolower(post('sakin_eposta')) : null,
            'plaka'         => post('plaka') ? mb_strtoupper(post('plaka')) : null,
            'kisi_sayisi'   => max(0, (int)post('kisi_sayisi', 1)),
            'aidat_katsayi' => max(0, sayi(post('aidat_katsayi', '1'))),
            'kullanici_id'  => (int)post('kullanici_id') ?: null,
            'notlar'        => post('notlar') ?: null,
            'mesaj_izin'    => post('mesaj_izin') ? 1 : 0,
        ];
        if ($v['daire_no'] === '' || $v['blok'] === '') {
            flash('danger', 'Blok ve daire numarası zorunludur.');
            redirect('daireler.php');
        }
        try {
            $alanlar = array_keys($v);
            if ($id) {
                $set = implode(', ', array_map(fn($a) => "$a = ?", $alanlar));
                $pdo->prepare("UPDATE daireler SET $set WHERE id = ?")->execute([...array_values($v), $id]);
                logla('Daire güncellendi', $v['blok'] . '-' . $v['daire_no']);
                flash('success', 'Daire bilgileri güncellendi.');
            } else {
                $pdo->prepare('INSERT INTO daireler (' . implode(',', $alanlar) . ') VALUES (' . rtrim(str_repeat('?,', count($v)), ',') . ')')
                    ->execute(array_values($v));
                logla('Daire eklendi', $v['blok'] . '-' . $v['daire_no']);
                flash('success', 'Yeni daire eklendi.');
            }
        } catch (PDOException $e) {
            flash('danger', $e->getCode() == 23000 ? 'Bu blokta aynı numaralı bir daire zaten var.' : 'Kayıt sırasında hata oluştu.');
        }
    }

    if ($islem === 'sil') {
        yetki_zorunlu('daire.sil');
        $id = (int)post('id');
        $st = $pdo->prepare('SELECT COUNT(*) FROM odemeler WHERE daire_id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn() > 0) {
            flash('warning', 'Bu daireye ait tahsilat kayıtları olduğu için silinemez. Daireyi "Boş" olarak işaretleyebilirsiniz.');
        } else {
            $pdo->prepare('DELETE FROM daireler WHERE id = ?')->execute([$id]);
            logla('Daire silindi', 'ID: ' . $id);
            flash('success', 'Daire silindi.');
        }
    }

    if ($islem === 'csv_yukle') {
        yetki_zorunlu('daire.ekle');
        $f = $_FILES['csv_dosya'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash('danger', 'Lütfen geçerli bir CSV dosyası seçin.');
            redirect('daireler.php');
        }
        $icerik = (string)file_get_contents($f['tmp_name']);
        if (str_starts_with($icerik, "\xEF\xBB\xBF")) $icerik = substr($icerik, 3);
        $satirlar = preg_split('/\r\n|\r|\n/', trim($icerik));
        $ayrac = str_contains($satirlar[0] ?? '', ';') ? ';' : ',';
        $st = $pdo->prepare('INSERT INTO daireler (blok, daire_no, kat, metrekare, durum, oturan_tipi, malik_adi, malik_tel, malik_eposta, sakin_adi, sakin_tel, sakin_eposta, kisi_sayisi, aidat_katsayi)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE kat=VALUES(kat), metrekare=VALUES(metrekare), durum=VALUES(durum), oturan_tipi=VALUES(oturan_tipi),
            malik_adi=VALUES(malik_adi), malik_tel=VALUES(malik_tel), malik_eposta=VALUES(malik_eposta),
            sakin_adi=VALUES(sakin_adi), sakin_tel=VALUES(sakin_tel), sakin_eposta=VALUES(sakin_eposta),
            kisi_sayisi=VALUES(kisi_sayisi), aidat_katsayi=VALUES(aidat_katsayi)');
        $eklenen = 0;
        foreach ($satirlar as $idx => $satir) {
            if (trim($satir) === '') continue;
            $s = array_map('trim', str_getcsv($satir, $ayrac));
            if ($idx === 0 && (mb_strtolower($s[0] ?? '') === 'blok' || str_contains(mb_strtolower($s[1] ?? ''), 'daire'))) continue;
            $b = mb_strtoupper($s[0] ?? 'A') ?: 'A';
            $no = $s[1] ?? '';
            if ($no === '') continue;
            $kat = ($s[2] ?? '') !== '' ? $s[2] : null;
            $m2 = ($s[3] ?? '') !== '' ? sayi($s[3]) : null;
            $dur = mb_strtolower($s[4] ?? '') === 'bos' || mb_strtolower($s[4] ?? '') === 'boş' ? 'bos' : 'dolu';
            $tip = str_contains(mb_strtolower($s[5] ?? ''), 'kira') ? 'kiraci' : 'malik';
            $mEposta = filter_var($s[8] ?? '', FILTER_VALIDATE_EMAIL) ? mb_strtolower($s[8]) : null;
            $sEposta = filter_var($s[11] ?? '', FILTER_VALIDATE_EMAIL) ? mb_strtolower($s[11]) : null;
            $kisiSay = max(0, (int)($s[12] ?? 1));
            $katsayi = ($s[13] ?? '') !== '' ? max(0, sayi($s[13])) : 1.0;
            $st->execute([$b, $no, $kat, $m2, $dur, $tip, ($s[6] ?? '') ?: null, ($s[7] ?? '') ?: null, $mEposta, ($s[9] ?? '') ?: null, ($s[10] ?? '') ?: null, $sEposta, $kisiSay, $katsayi]);
            $eklenen++;
        }
        logla('Toplu daire CSV yüklendi', "$eklenen satır işlendi");
        flash('success', "CSV dosyasındaki $eklenen daire kaydı başarıyla içe aktarıldı / güncellendi.");
    }
    redirect('daireler.php?' . http_build_query(array_intersect_key($_GET, array_flip(['blok', 'durum', 'q']))));
}

if (get('ornek_csv')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ornek_daire_sablonu.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Blok', 'Daire_No', 'Kat', 'Metrekare', 'Durum', 'Oturan_Tipi', 'Malik_Adi', 'Malik_Tel', 'Malik_Eposta', 'Sakin_Adi', 'Sakin_Tel', 'Sakin_Eposta', 'Kisi_Sayisi', 'Aidat_Katsayi'], ';');
    fputcsv($out, ['A', '1', '1', '110', 'dolu', 'malik', 'Ahmet Yılmaz', '0532 111 22 33', 'ahmet@ornek.com', 'Ahmet Yılmaz', '0532 111 22 33', 'ahmet@ornek.com', '3', '1,00'], ';');
    fputcsv($out, ['A', '2', '1', '110', 'dolu', 'kiraci', 'Mehmet Demir', '0533 222 33 44', 'mehmet@ornek.com', 'Ayşe Kaya', '0544 333 44 55', 'ayse@ornek.com', '2', '1,00'], ';');
    exit;
}

/* ------------ Liste ------------ */
$blok  = get('blok', '');
$durum = get('durum', '');
$q     = get('q', '');

$where = ['1=1'];
$par = [];
if ($blok !== '')  { $where[] = 'd.blok = ?'; $par[] = $blok; }
if (in_array($durum, ['dolu', 'bos'], true)) { $where[] = 'd.durum = ?'; $par[] = $durum; }
if ($q !== '') {
    $where[] = '(d.daire_no LIKE ? OR d.malik_adi LIKE ? OR d.sakin_adi LIKE ? OR d.malik_tel LIKE ? OR d.sakin_tel LIKE ? OR d.plaka LIKE ?)';
    array_push($par, ...array_fill(0, 6, "%$q%"));
}

$st = $pdo->prepare("SELECT d.*, k.ad_soyad AS kullanici_adi_soyad,
        (SELECT COALESCE(SUM(b.tutar - b.odenen),0) FROM borclar b WHERE b.daire_id = d.id) AS kalan
    FROM daireler d LEFT JOIN kullanicilar k ON k.id = d.kullanici_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY d.blok, CAST(d.daire_no AS UNSIGNED), d.daire_no");
$st->execute($par);
$daireler = $st->fetchAll();

if (get('csv')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="daireler_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Blok', 'Daire_No', 'Kat', 'Metrekare', 'Durum', 'Oturan_Tipi', 'Malik_Adi', 'Malik_Tel', 'Malik_Eposta', 'Sakin_Adi', 'Sakin_Tel', 'Sakin_Eposta', 'Kisi_Sayisi', 'Aidat_Katsayi', 'Guncel_Borc'], ';');
    foreach ($daireler as $d) {
        fputcsv($out, [
            csv_hucre($d['blok']), csv_hucre($d['daire_no']), csv_hucre($d['kat']), $d['metrekare'] ?? '',
            $d['durum'], $d['oturan_tipi'],
            csv_hucre(kisi($d['malik_adi'])), csv_hucre(tel($d['malik_tel'])), yetki('kisi.goruntule') ? csv_hucre($d['malik_eposta']) : '',
            csv_hucre(kisi($d['sakin_adi'])), csv_hucre(tel($d['sakin_tel'])), yetki('kisi.goruntule') ? csv_hucre($d['sakin_eposta']) : '',
            (int)$d['kisi_sayisi'], number_format((float)$d['aidat_katsayi'], 2, ',', ''), number_format((float)$d['kalan'], 2, ',', '')
        ], ';');
    }
    exit;
}

$bloklar = $pdo->query('SELECT DISTINCT blok FROM daireler ORDER BY blok')->fetchAll(PDO::FETCH_COLUMN);
$kullanicilar = $pdo->query('SELECT id, ad_soyad, kullanici_adi FROM kullanicilar WHERE aktif = 1 ORDER BY ad_soyad')->fetchAll();
$ozet = $pdo->query("SELECT COUNT(*) t, SUM(durum='dolu') dolu, SUM(durum='bos') bos, SUM(oturan_tipi='kiraci' AND durum='dolu') kiraci, COALESCE(SUM(kisi_sayisi),0) kisi FROM daireler")->fetch();

$sayfaBaslik = 'Daireler';
$sayfaAciklama = 'Daire, malik ve sakin bilgilerini yönetin';
require __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-3">
    <?php foreach ([['Toplam Daire', $ozet['t'], 'bi-building', 'primary'], ['Dolu', $ozet['dolu'], 'bi-house-check', 'success'],
                    ['Boş', $ozet['bos'], 'bi-house-dash', 'warning'], ['Toplam Kişi', $ozet['kisi'], 'bi-people', 'info']] as $s): ?>
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <div class="stat-icon soft-<?= $s[3] ?>"><i class="bi <?= $s[2] ?>"></i></div>
            <div><div class="stat-label"><?= $s[0] ?></div><div class="stat-value"><?= (int)$s[1] ?></div></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">
        <form class="filter-bar" method="get">
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Daire no, isim, telefon veya plaka ara…">
            <select name="blok" class="form-select" style="min-width:120px">
                <option value="">Tüm bloklar</option>
                <?php foreach ($bloklar as $b): ?><option value="<?= e($b) ?>" <?= $blok === $b ? 'selected' : '' ?>><?= e($b) ?> Blok</option><?php endforeach; ?>
            </select>
            <select name="durum" class="form-select" style="min-width:120px">
                <option value="">Tüm durumlar</option>
                <option value="dolu" <?= $durum === 'dolu' ? 'selected' : '' ?>>Dolu</option>
                <option value="bos" <?= $durum === 'bos' ? 'selected' : '' ?>>Boş</option>
            </select>
            <button class="btn btn-light"><i class="bi bi-funnel"></i> Filtrele</button>
        </form>
        <div class="d-flex flex-wrap gap-2">
            <a href="?<?= e(http_build_query(array_merge($_GET, ['csv' => 1]))) ?>" class="btn btn-light" title="Excel / CSV Dışa Aktar"><i class="bi bi-filetype-csv"></i></a>
            <?php if (yetki('daire.ekle')): ?>
                <button class="btn btn-light" data-bs-toggle="modal" data-bs-target="#csvModal"><i class="bi bi-upload me-1"></i>CSV İçe Aktar</button>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#daireModal" data-new data-title="Yeni Daire"><i class="bi bi-plus-lg me-1"></i>Yeni Daire</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr>
                <th>Daire</th><th>Kat / m²</th><th>Malik</th><th>Oturan</th><th>Durum</th><th>Katsayı</th><?php if (yetki('aidat.goruntule')): ?><th class="text-end">Bakiye</th><?php endif; ?><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($daireler as $d): ?>
                <tr>
                    <td><div class="d-flex align-items-center gap-2">
                        <span class="stat-icon soft-primary" style="width:38px;height:38px;font-size:.85rem;font-weight:800"><?= e($d['blok']) ?><?= e($d['daire_no']) ?></span>
                        <div><strong><?= e($d['blok']) ?> Blok · No <?= e($d['daire_no']) ?></strong>
                            <?php if ($d['kullanici_adi_soyad']): ?><div class="small text-muted"><i class="bi bi-person-badge"></i> <?= e(kisi($d['kullanici_adi_soyad'])) ?></div><?php endif; ?>
                        </div></div></td>
                    <td><?= e($d['kat'] ?? '-') ?><div class="small text-muted"><?= $d['metrekare'] ? e((float)$d['metrekare']) . ' m²' : '' ?></div></td>
                    <td><?= e(kisi($d['malik_adi']) ?: '-') ?><div class="small text-muted"><?= e(tel($d['malik_tel'])) ?><?php if ($d['malik_eposta'] && yetki('kisi.goruntule')): ?> <i class="bi bi-envelope-check text-success" title="<?= e($d['malik_eposta']) ?>"></i><?php endif; ?></div></td>
                    <td><?= e(kisi($d['sakin_adi']) ?: '-') ?><?php if ($d['sakin_eposta'] && yetki('kisi.goruntule')): ?> <i class="bi bi-envelope-check text-success small" title="<?= e($d['sakin_eposta']) ?>"></i><?php endif; ?>
                        <div class="small">
                            <?php if ($d['durum'] === 'dolu'): ?><span class="badge-soft <?= $d['oturan_tipi'] === 'kiraci' ? 'info' : 'primary' ?>"><?= $d['oturan_tipi'] === 'kiraci' ? 'Kiracı' : 'Malik' ?></span> <span class="text-muted"><?= (int)$d['kisi_sayisi'] ?> kişi</span><?php endif; ?>
                            <?php if (!empty($d['plaka'])): ?>
                                <span class="badge-soft secondary"><i class="bi bi-car-front-fill"></i> <?= e($d['plaka']) ?></span>
                                <?php if (yetki('kisi.goruntule') && ($aracTel = ($d['sakin_tel'] ?: $d['malik_tel']))): ?>
                                    <a href="<?= e(wa_me($aracTel, ayar('site_adi') . ': Merhaba, ' . $d['plaka'] . ' plakalı aracınızla ilgili bilgi vermek istiyoruz.')) ?>" target="_blank" rel="noopener" class="text-success ms-1" title="Araç sahibine WhatsApp mesajı gönder"><i class="bi bi-whatsapp"></i></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div></td>
                    <td><?= $d['durum'] === 'dolu' ? '<span class="badge-soft success">Dolu</span>' : '<span class="badge-soft warning">Boş</span>' ?></td>
                    <td>×<?= e((float)$d['aidat_katsayi']) ?></td>
                    <?php if (yetki('aidat.goruntule')): ?>
                        <td class="text-end <?= $d['kalan'] > 0 ? 'amount-out' : 'text-muted' ?>">
                            <?= para($d['kalan']) ?>
                            <?php if ((float)($d['alacak_bakiye'] ?? 0) > 0): ?><div class="small text-success fw-semibold">+<?= para($d['alacak_bakiye']) ?> avans</div><?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td class="table-actions">
                        <?php if (yetki('aidat.goruntule')): ?>
                            <a href="<?= url('borclar.php?daire=' . $d['id']) ?>" class="btn btn-sm btn-soft-success" title="Borç / Tahsilat"><i class="bi bi-receipt"></i></a>
                            <a href="<?= url('ekstre_pdf.php?daire=' . $d['id']) ?>" target="_blank" class="btn btn-sm btn-light" title="PDF Cari Hesap Ekstresi"><i class="bi bi-file-earmark-pdf text-danger"></i></a>
                        <?php endif; ?>
                        <?php if (yetki('daire.duzenle')): ?>
                        <button class="btn btn-sm btn-soft-primary" data-bs-toggle="modal" data-bs-target="#daireModal" data-title="Daireyi Düzenle"
                                data-edit='<?= e(json_encode(array_merge($d, ['metrekare' => $d['metrekare'] ? (float)$d['metrekare'] : '', 'aidat_katsayi' => (float)$d['aidat_katsayi']]), JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-pencil"></i></button>
                        <?php endif; if (yetki('daire.sil')): ?>
                        <form method="post" class="d-inline" data-confirm="<?= e($d['blok'] . '-' . $d['daire_no']) ?> numaralı daire ve tüm borç kayıtları silinecek.">
                            <?= csrf_field() ?><input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button class="btn btn-sm btn-soft-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$daireler): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-building"></i><p class="mt-2 mb-0">Kayıtlı daire bulunamadı.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (yetki_herhangi(['daire.ekle', 'daire.duzenle'])): ?>
<div class="modal fade" id="daireModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="kaydet"><input type="hidden" name="id">
                <div class="modal-header"><h5 class="modal-title">Yeni Daire</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label">Blok *</label><input name="blok" class="form-control" value="A" required></div>
                        <div class="col-md-3"><label class="form-label">Daire No *</label><input name="daire_no" class="form-control" required></div>
                        <div class="col-md-3"><label class="form-label">Kat</label><input name="kat" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label">Metrekare</label><input name="metrekare" class="form-control" inputmode="decimal"></div>
                        <div class="col-md-4"><label class="form-label">Durum</label>
                            <select name="durum" class="form-select"><option value="dolu">Dolu</option><option value="bos">Boş</option></select></div>
                        <div class="col-md-4"><label class="form-label">Oturan</label>
                            <select name="oturan_tipi" class="form-select"><option value="malik">Malik (ev sahibi)</option><option value="kiraci">Kiracı</option></select></div>
                        <div class="col-md-2"><label class="form-label">Kişi</label><input type="number" min="0" name="kisi_sayisi" class="form-control" value="1"></div>
                        <div class="col-md-2"><label class="form-label">Aidat ×</label><input name="aidat_katsayi" class="form-control" value="1" inputmode="decimal" title="Aidat tutarı bu katsayı ile çarpılır"></div>
                        <div class="col-12"><hr class="my-1"><small class="text-muted fw-semibold">MALİK BİLGİLERİ</small></div>
                        <div class="col-md-4"><label class="form-label">Malik Adı Soyadı</label><input name="malik_adi" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Malik Telefon</label><input name="malik_tel" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Malik E-posta</label><input type="email" name="malik_eposta" class="form-control"></div>
                        <div class="col-12"><small class="text-muted fw-semibold">OTURAN (SAKİN) BİLGİLERİ</small></div>
                        <div class="col-md-4"><label class="form-label">Sakin Adı Soyadı</label><input name="sakin_adi" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Sakin Telefon</label><input name="sakin_tel" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Sakin E-posta</label><input type="email" name="sakin_eposta" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Araç Plakası</label><input name="plaka" class="form-control" placeholder="34 ABC 123"></div>
                        <div class="col-md-4"><label class="form-label">Bağlı Kullanıcı Hesabı</label>
                            <select name="kullanici_id" class="form-select">
                                <option value="">— Yok —</option>
                                <?php foreach ($kullanicilar as $k): ?><option value="<?= $k['id'] ?>"><?= e($k['ad_soyad']) ?> (<?= e($k['kullanici_adi']) ?>)</option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-4"><label class="form-label">Notlar</label><input name="notlar" class="form-control"></div>
                        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="mesaj_izin" value="1" id="dMsj" checked>
                            <label class="form-check-label" for="dMsj">SMS / WhatsApp bildirimi gönderilebilir <span class="text-muted small">(makbuz, dekont sonucu ve duyurular cep telefonuna gider)</span></label></div></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Kaydet</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (yetki('daire.ekle')): ?>
<div class="modal fade" id="csvModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="islem" value="csv_yukle">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-filetype-csv me-2"></i>Toplu Daire İçe Aktar (CSV)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="small text-muted">Örnek CSV şablonunu indirip Excel'de doldurduktan sonra buraya yükleyerek tüm daireleri tek seferde ekleyebilir veya güncelleyebilirsiniz.</p>
                    <div class="mb-3">
                        <a href="?ornek_csv=1" class="btn btn-sm btn-soft-primary"><i class="bi bi-download me-1"></i>Örnek CSV Şablonunu İndir</a>
                    </div>
                    <div>
                        <label class="form-label">CSV Dosyası *</label>
                        <input type="file" name="csv_dosya" class="form-control" accept=".csv,text/csv" required>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-upload me-1"></i>İçe Aktar</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
