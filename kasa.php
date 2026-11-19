<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('kasa.goruntule');

$tur = get('tur') === 'gider' ? 'gider' : 'gelir';
$turAd = $tur === 'gelir' ? 'Gelir' : 'Gider';

if (is_post()) {
    csrf_check();
    $islem = post('islem');

    if ($islem === 'kaydet') {
        $id = (int)post('id');
        yetki_zorunlu($id ? 'kasa.duzenle' : 'kasa.ekle');
        $kat = (int)post('kategori_id');
        $tutar = sayi(post('tutar'));
        $tarih = post('tarih') ?: date('Y-m-d');
        $st = $pdo->prepare('SELECT COUNT(*) FROM kategoriler WHERE id = ? AND tur = ?');
        $st->execute([$kat, $tur]);
        if (!$st->fetchColumn() || $tutar <= 0) {
            flash('danger', 'Kategori ve geçerli bir tutar seçmelisiniz.');
        } else {
            try {
                $dk = dekont_kaydet($_FILES['belge_dosya'] ?? null, false);
                $v = [$kat, $tutar, $tarih, post('aciklama') ?: null, post('belge_no') ?: null];
                if ($id) {
                    if ($dk) {
                        $pdo->prepare('UPDATE hareketler SET kategori_id=?, tutar=?, tarih=?, aciklama=?, belge_no=?, belge_dosya=? WHERE id=? AND tur=? AND odeme_id IS NULL')
                            ->execute([...$v, $dk['dosya'], $id, $tur]);
                    } else {
                        $pdo->prepare('UPDATE hareketler SET kategori_id=?, tutar=?, tarih=?, aciklama=?, belge_no=? WHERE id=? AND tur=? AND odeme_id IS NULL')
                            ->execute([...$v, $id, $tur]);
                    }
                    logla("$turAd güncellendi", para($tutar) . ' · ' . post('aciklama'));
                    flash('success', "$turAd kaydı güncellendi.");
                } else {
                    $pdo->prepare('INSERT INTO hareketler (kategori_id, tutar, tarih, aciklama, belge_no, belge_dosya, tur, kullanici_id) VALUES (?,?,?,?,?,?,?,?)')
                        ->execute([...$v, $dk['dosya'] ?? null, $tur, $_SESSION['uid']]);
                    logla("$turAd eklendi", para($tutar) . ' · ' . post('aciklama'));
                    flash('success', "$turAd kaydı eklendi.");
                }
            } catch (RuntimeException $e) {
                flash('danger', $e->getMessage());
            }
        }
    }
    if ($islem === 'sil') {
        yetki_zorunlu('kasa.sil');
        $st = $pdo->prepare('SELECT belge_dosya FROM hareketler WHERE id = ? AND odeme_id IS NULL');
        $st->execute([(int)post('id')]);
        $eski = $st->fetch();
        $n = $pdo->prepare('DELETE FROM hareketler WHERE id = ? AND odeme_id IS NULL');
        $n->execute([(int)post('id')]);
        if ($n->rowCount()) {
            if (!empty($eski['belge_dosya'])) @unlink(dekont_klasoru() . '/' . basename($eski['belge_dosya']));
            logla("$turAd silindi", 'ID: ' . post('id'));
            flash('success', 'Kayıt silindi.');
        } else {
            flash('warning', 'Aidat tahsilatından oluşan kayıtlar yalnızca "Tahsilat Geçmişi" sayfasından silinebilir.');
        }
    }
    redirect('kasa.php?' . http_build_query(array_merge($_GET, ['tur' => $tur])));
}

$bas = get('bas', date('Y-01-01'));
$bit = get('bit', date('Y-m-d'));
$katF = (int)get('kategori', 0);
$q = get('q', '');
$sayfa = max(1, (int)get('s', 1));
$limit = 30;

$where = ['h.tur = ?', 'h.tarih BETWEEN ? AND ?'];
$par = [$tur, $bas, $bit];
if ($katF) { $where[] = 'h.kategori_id = ?'; $par[] = $katF; }
if ($q !== '') { $where[] = '(h.aciklama LIKE ? OR h.belge_no LIKE ?)'; array_push($par, "%$q%", "%$q%"); }
$w = implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(tutar),0) toplam FROM hareketler h WHERE $w");
$st->execute($par);
$ozet = $st->fetch();

// CSV dışa aktarım
if (get('csv')) {
    $st = $pdo->prepare("SELECT h.tarih, k.ad AS kategori, h.aciklama, h.belge_no, h.tutar FROM hareketler h JOIN kategoriler k ON k.id = h.kategori_id WHERE $w ORDER BY h.tarih");
    $st->execute($par);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $tur . '_' . $bas . '_' . $bit . '.csv"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Tarih', 'Kategori', 'Açıklama', 'Belge No', 'Tutar'], ';');
    foreach ($st as $r) fputcsv($f, [tarih($r['tarih']), csv_hucre($r['kategori']), csv_hucre($r['aciklama']), csv_hucre($r['belge_no']), number_format((float)$r['tutar'], 2, ',', '')], ';');
    exit;
}

$st = $pdo->prepare("SELECT h.*, k.ad AS kategori, k.renk, u.ad_soyad AS ekleyen FROM hareketler h
    JOIN kategoriler k ON k.id = h.kategori_id LEFT JOIN kullanicilar u ON u.id = h.kullanici_id
    WHERE $w ORDER BY h.tarih DESC, h.id DESC LIMIT $limit OFFSET " . (($sayfa - 1) * $limit));
$st->execute($par);
$kayitlar = $st->fetchAll();

$st = $pdo->prepare("SELECT k.id, k.ad, k.renk, COALESCE(SUM(h.tutar),0) toplam FROM kategoriler k
    LEFT JOIN hareketler h ON h.kategori_id = k.id AND h.tarih BETWEEN ? AND ?
    WHERE k.tur = ? GROUP BY k.id ORDER BY toplam DESC, k.ad");
$st->execute([$bas, $bit, $tur]);
$kategoriler = $st->fetchAll();

$sayfaBaslik = $turAd . 'ler';
$sayfaAciklama = tarih($bas) . ' – ' . tarih($bit) . ' · Toplam ' . para($ozet['toplam']);
require __DIR__ . '/includes/header.php';
$renk = $tur === 'gelir' ? 'success' : 'danger';
?>

<div class="row g-3">
    <div class="col-xl-9">
        <div class="card">
            <div class="card-header">
                <form class="filter-bar" method="get">
                    <input type="hidden" name="tur" value="<?= $tur ?>">
                    <input type="date" name="bas" value="<?= e($bas) ?>" class="form-control" style="min-width:140px">
                    <input type="date" name="bit" value="<?= e($bit) ?>" class="form-control" style="min-width:140px">
                    <select name="kategori" class="form-select"><option value="">Tüm kategoriler</option>
                        <?php foreach ($kategoriler as $k): ?><option value="<?= $k['id'] ?>" <?= $katF == $k['id'] ? 'selected' : '' ?>><?= e($k['ad']) ?></option><?php endforeach; ?></select>
                    <input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Açıklama / belge no">
                    <button class="btn btn-light"><i class="bi bi-funnel"></i></button>
                </form>
                <div class="d-flex gap-2">
                    <a href="?<?= e(http_build_query(array_merge($_GET, ['tur' => $tur, 'csv' => 1]))) ?>" class="btn btn-light" title="Excel/CSV"><i class="bi bi-filetype-csv"></i></a>
                    <?php if (yetki('kasa.ekle')): ?>
                    <button class="btn btn-<?= $renk ?>" data-bs-toggle="modal" data-bs-target="#kasaModal" data-new data-title="Yeni <?= $turAd ?>"><i class="bi bi-plus-lg me-1"></i>Yeni <?= $turAd ?></button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Tarih</th><th>Kategori</th><th>Açıklama</th><th>Belge No</th><th>Ekleyen</th><th class="text-end">Tutar</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($kayitlar as $h): ?>
                        <tr>
                            <td class="text-nowrap"><?= tarih($h['tarih']) ?></td>
                            <td><span class="dot me-1" style="background:<?= e($h['renk']) ?>"></span><?= e($h['kategori']) ?></td>
                            <td><?= e($h['aciklama'] ?: '-') ?> <?php if ($h['odeme_id']): ?><span class="badge-soft info">Otomatik</span><?php endif; ?></td>
                            <td>
                                <code><?= e($h['belge_no'] ?: '') ?></code>
                                <?php if (!empty($h['belge_dosya'])): ?>
                                    <a href="<?= dekont_url('h', (int)$h['id']) ?>" target="_blank" class="btn btn-sm btn-light py-0 px-2 ms-1" title="Fatura / Belgeyi Görüntüle"><i class="bi bi-paperclip"></i> Fatura</a>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= e($h['ekleyen'] ?? '-') ?></td>
                            <td class="text-end <?= $tur === 'gelir' ? 'amount-in' : 'amount-out' ?>"><?= para($h['tutar']) ?></td>
                            <td class="table-actions">
                                <?php if (!$h['odeme_id']): if (yetki('kasa.duzenle')): ?>
                                <button class="btn btn-sm btn-soft-primary" data-bs-toggle="modal" data-bs-target="#kasaModal" data-title="<?= $turAd ?> Düzenle"
                                    data-edit='<?= e(json_encode(['id' => $h['id'], 'kategori_id' => $h['kategori_id'], 'tutar' => number_format((float)$h['tutar'], 2, ',', ''), 'tarih' => $h['tarih'], 'aciklama' => $h['aciklama'], 'belge_no' => $h['belge_no']], JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-pencil"></i></button>
                                <?php endif; if (yetki('kasa.sil')): ?>
                                <form method="post" class="d-inline" data-confirm="<?= para($h['tutar']) ?> tutarındaki kayıt silinecek."><?= csrf_field() ?>
                                    <input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $h['id'] ?>">
                                    <button class="btn btn-sm btn-soft-danger"><i class="bi bi-trash"></i></button></form>
                                <?php endif; endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$kayitlar): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i><p class="mt-2 mb-0">Bu aralıkta <?= mb_strtolower($turAd) ?> kaydı yok.</p></div></td></tr><?php endif; ?>
                    </tbody>
                    <?php if ($kayitlar): ?>
                    <tfoot><tr><th colspan="5" class="text-end">Toplam (<?= (int)$ozet['n'] ?> kayıt)</th><th class="text-end <?= $tur === 'gelir' ? 'amount-in' : 'amount-out' ?>"><?= para($ozet['toplam']) ?></th><th></th></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
            <?php if ($ozet['n'] > $limit): ?><div class="card-body d-flex justify-content-end"><?= sayfalama((int)$ozet['n'], $sayfa, $limit) ?></div><?php endif; ?>
        </div>
    </div>
    <div class="col-xl-3">
        <div class="card">
            <div class="card-header"><h5>Kategori Özeti</h5></div>
            <div class="card-body">
                <?php foreach ($kategoriler as $k): $oran = $ozet['toplam'] > 0 && !$katF ? $k['toplam'] / $ozet['toplam'] * 100 : 0; ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between small mb-1"><a href="?<?= e(http_build_query(['tur' => $tur, 'bas' => $bas, 'bit' => $bit, 'kategori' => $k['id']])) ?>" class="text-reset"><span class="dot me-1" style="background:<?= e($k['renk']) ?>"></span><?= e($k['ad']) ?></a><strong><?= para($k['toplam']) ?></strong></div>
                        <div class="progress" style="height:5px"><div class="progress-bar" style="width:<?= round($oran) ?>%;background:<?= e($k['renk']) ?>"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if (yetki_herhangi(['kasa.ekle', 'kasa.duzenle'])): ?>
<div class="modal fade" id="kasaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="islem" value="kaydet"><input type="hidden" name="id">
                <div class="modal-header"><h5 class="modal-title">Yeni <?= $turAd ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">Kategori</label><select name="kategori_id" class="form-select" required>
                            <?php foreach ($kategoriler as $k): if ($k['id'] == 1) continue; ?><option value="<?= $k['id'] ?>"><?= e($k['ad']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-6"><label class="form-label">Tutar (₺)</label><input name="tutar" class="form-control" inputmode="decimal" required></div>
                        <div class="col-6"><label class="form-label">Tarih</label><input type="date" name="tarih" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                        <div class="col-6"><label class="form-label">Belge / Fatura No</label><input name="belge_no" class="form-control"></div>
                        <div class="col-12"><label class="form-label">Fatura / Fiş Belgesi <span class="text-muted fw-normal">(isteğe bağlı · PDF, JPG, PNG · maks. 5 MB)</span></label>
                            <input type="file" name="belge_dosya" class="form-control" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></div>
                        <div class="col-12"><label class="form-label">Açıklama</label><textarea name="aciklama" class="form-control" rows="2"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-<?= $renk ?>"><i class="bi bi-check2 me-1"></i>Kaydet</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
