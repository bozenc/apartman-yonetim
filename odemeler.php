<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('odeme.goruntule');

if (is_post() && post('islem') === 'sil') {
    csrf_check();
    yetki_zorunlu('odeme.sil');
    $st = $pdo->prepare('SELECT * FROM odemeler WHERE id = ?');
    $st->execute([(int)post('id')]);
    if ($o = $st->fetch()) {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE borclar SET odenen = GREATEST(0, odenen - ?) WHERE id = ?')->execute([$o['tutar'], $o['borc_id']]);
        $pdo->prepare('DELETE FROM odemeler WHERE id = ?')->execute([$o['id']]); // bağlı kasa hareketi de silinir (CASCADE)
        if ($o['bildirim_id']) {
            // Bildirime bağlı tüm tahsilatlar iptal edildiyse bildirim tekrar onaya düşer
            $st = $pdo->prepare('SELECT COUNT(*) FROM odemeler WHERE bildirim_id = ?');
            $st->execute([$o['bildirim_id']]);
            if (!$st->fetchColumn()) {
                $pdo->prepare("UPDATE odeme_bildirimleri SET durum = 'bekliyor', isleyen_id = NULL, islem_tarihi = NULL WHERE id = ?")->execute([$o['bildirim_id']]);
            }
        } elseif ($o['dekont']) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM odemeler WHERE dekont = ?');
            $st->execute([$o['dekont']]);
            if (!$st->fetchColumn()) @unlink(dekont_klasoru() . '/' . basename($o['dekont']));
        }
        $pdo->commit();
        logla('Tahsilat silindi', ($o['makbuz_no'] ?? '') . ' · ' . para($o['tutar']));
        flash('success', 'Tahsilat kaydı silindi ve borç bakiyesi geri alındı.');
    }
    redirect('odemeler.php?' . http_build_query(array_diff_key($_GET, ['s' => 1])));
}

$bas    = get('bas', date('Y-m-01'));
$bit    = get('bit', date('Y-m-d'));
$yontem = get('yontem', '');
$q      = get('q', '');
$sayfa  = max(1, (int)get('s', 1));
$limit  = 30;

$where = ['o.tarih BETWEEN ? AND ?'];
$par = [$bas, $bit];
if ($yontem && array_key_exists($yontem, odeme_yontemleri())) { $where[] = 'o.yontem = ?'; $par[] = $yontem; }
if ($q !== '') {
    $where[] = '(o.makbuz_no LIKE ? OR d.daire_no LIKE ? OR d.sakin_adi LIKE ? OR d.malik_adi LIKE ? OR CONCAT(d.blok,"-",d.daire_no) = ?)';
    array_push($par, "%$q%", "%$q%", "%$q%", "%$q%", $q);
}
$w = implode(' AND ', $where);
$from = "FROM odemeler o JOIN borclar b ON b.id = o.borc_id JOIN daireler d ON d.id = o.daire_id LEFT JOIN kullanicilar k ON k.id = o.kullanici_id WHERE $w";

$st = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(o.tutar),0) toplam $from");
$st->execute($par);
$ozet = $st->fetch();

$st = $pdo->prepare("SELECT o.yontem, SUM(o.tutar) t $from GROUP BY o.yontem");
$st->execute($par);
$yontemOzet = $st->fetchAll(PDO::FETCH_KEY_PAIR);

$st = $pdo->prepare("SELECT o.*, b.aciklama AS borc_aciklama, d.blok, d.daire_no, COALESCE(d.sakin_adi, d.malik_adi) ad, k.ad_soyad AS alan
    $from ORDER BY o.tarih DESC, o.id DESC LIMIT $limit OFFSET " . (($sayfa - 1) * $limit));
$st->execute($par);
$odemeler = $st->fetchAll();

$sayfaBaslik = 'Tahsilat Geçmişi';
$sayfaAciklama = tarih($bas) . ' – ' . tarih($bit) . ' arası tahsilatlar';
require __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-3">
    <div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon soft-success"><i class="bi bi-cash-coin"></i></div>
        <div><div class="stat-label">Toplam Tahsilat</div><div class="stat-value"><?= para($ozet['toplam']) ?></div><div class="stat-sub"><?= (int)$ozet['n'] ?> işlem</div></div></div></div>
    <?php foreach (['nakit' => ['bi-wallet', 'warning'], 'havale' => ['bi-bank', 'primary'], 'kredi_karti' => ['bi-credit-card', 'info']] as $k => $i): ?>
    <div class="col-md-3 col-6"><div class="card stat-card"><div class="stat-icon soft-<?= $i[1] ?>"><i class="bi <?= $i[0] ?>"></i></div>
        <div><div class="stat-label"><?= odeme_yontemleri()[$k] ?></div><div class="stat-value"><?= para($yontemOzet[$k] ?? 0) ?></div></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">
        <form class="filter-bar" method="get">
            <div><label class="form-label small mb-1">Başlangıç</label><input type="date" name="bas" value="<?= e($bas) ?>" class="form-control"></div>
            <div><label class="form-label small mb-1">Bitiş</label><input type="date" name="bit" value="<?= e($bit) ?>" class="form-control"></div>
            <div><label class="form-label small mb-1">Yöntem</label><select name="yontem" class="form-select"><option value="">Tümü</option>
                <?php foreach (odeme_yontemleri() as $k => $v): ?><option value="<?= $k ?>" <?= $yontem === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label small mb-1">Ara</label><input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="Makbuz, A-3, isim…"></div>
            <button class="btn btn-light"><i class="bi bi-funnel"></i> Filtrele</button>
        </form>
        <button class="btn btn-light no-print" onclick="window.print()"><i class="bi bi-printer me-1"></i>Yazdır</button>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Makbuz</th><th>Tarih</th><th>Daire</th><th>Borç</th><th>Yöntem</th><th>Alan</th><th class="text-end">Tutar</th><th class="no-print"></th></tr></thead>
            <tbody>
            <?php foreach ($odemeler as $o): ?>
                <tr>
                    <td><code><?= e($o['makbuz_no']) ?></code></td>
                    <td><?= tarih($o['tarih']) ?></td>
                    <td><strong><?= e($o['blok'] . '-' . $o['daire_no']) ?></strong><div class="small text-muted"><?= e(kisi($o['ad']) ?: '-') ?></div></td>
                    <td><?= e($o['borc_aciklama']) ?><?php if ($o['bildirim_id']): ?> <span class="badge-soft info" title="Sakin dekont yükleyerek bildirdi"><i class="bi bi-receipt-cutoff"></i> Bildirim</span><?php endif; ?><?php if ($o['aciklama']): ?><div class="small text-muted"><?= e($o['aciklama']) ?></div><?php endif; ?></td>
                    <td><span class="badge-soft secondary"><?= e(odeme_yontemleri()[$o['yontem']] ?? $o['yontem']) ?></span></td>
                    <td class="small"><?= e($o['alan'] ?? '-') ?></td>
                    <td class="text-end amount-in"><?= para($o['tutar']) ?></td>
                    <td class="table-actions no-print">
                        <?php if ($o['dekont']): ?><a href="<?= dekont_url('o', (int)$o['id']) ?>" target="_blank" class="btn btn-sm btn-light" title="Dekontu görüntüle"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                        <a href="<?= url('odeme_detay.php?id=' . $o['id']) ?>" class="btn btn-sm btn-light" title="Detay"><i class="bi bi-eye"></i></a>
                        <a href="<?= url('makbuz_pdf.php?id=' . $o['id']) ?>" target="_blank" class="btn btn-sm btn-soft-danger" title="PDF makbuz indir"><i class="bi bi-file-earmark-pdf"></i></a>
                        <a href="<?= url('makbuz.php?id=' . $o['id']) ?>" target="_blank" class="btn btn-sm btn-soft-primary" title="Yazdır"><i class="bi bi-printer"></i></a>
                        <?php if (yetki('odeme.sil')): ?>
                        <form method="post" class="d-inline" data-confirm="Tahsilat silinecek ve borç tekrar açılacak. Kasa kaydı da kaldırılacak.">
                            <?= csrf_field() ?><input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $o['id'] ?>">
                            <button class="btn btn-sm btn-soft-danger"><i class="bi bi-trash"></i></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$odemeler): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-cash"></i><p class="mt-2 mb-0">Bu aralıkta tahsilat yok.</p></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($ozet['n'] > $limit): ?><div class="card-body d-flex justify-content-end"><?= sayfalama((int)$ozet['n'], $sayfa, $limit) ?></div><?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
