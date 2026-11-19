<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('kategori.yonet');

if (is_post()) {
    csrf_check();
    $islem = post('islem');
    if ($islem === 'kaydet') {
        $id = (int)post('id');
        $ad = post('ad');
        $tur = post('tur') === 'gider' ? 'gider' : 'gelir';
        $renk = preg_match('/^#[0-9a-fA-F]{6}$/', post('renk', '')) ? post('renk') : '#6366f1';
        if ($ad === '') { flash('danger', 'Kategori adı zorunludur.'); redirect('kategoriler.php'); }
        if ($id) {
            // Sistem kategorisinin türü değiştirilemez
            $pdo->prepare('UPDATE kategoriler SET ad = ?, renk = ?, tur = IF(sistem = 1, tur, ?) WHERE id = ?')->execute([$ad, $renk, $tur, $id]);
            flash('success', 'Kategori güncellendi.');
        } else {
            $pdo->prepare('INSERT INTO kategoriler (ad, tur, renk) VALUES (?,?,?)')->execute([$ad, $tur, $renk]);
            flash('success', 'Kategori eklendi.');
        }
        logla('Kategori kaydedildi', $ad);
    }
    if ($islem === 'sil') {
        $id = (int)post('id');
        $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM hareketler WHERE kategori_id = ?) + (SELECT sistem FROM kategoriler WHERE id = ?)');
        $st->execute([$id, $id]);
        if ($st->fetchColumn() > 0) {
            flash('warning', 'Sistem kategorileri veya kayıt içeren kategoriler silinemez.');
        } else {
            $pdo->prepare('DELETE FROM kategoriler WHERE id = ?')->execute([$id]);
            logla('Kategori silindi', 'ID: ' . $id);
            flash('success', 'Kategori silindi.');
        }
    }
    redirect('kategoriler.php');
}

$kategoriler = $pdo->query('SELECT k.*, COUNT(h.id) adet, COALESCE(SUM(h.tutar),0) toplam FROM kategoriler k
    LEFT JOIN hareketler h ON h.kategori_id = k.id GROUP BY k.id ORDER BY k.tur, k.ad')->fetchAll();
$gruplar = ['gelir' => [], 'gider' => []];
foreach ($kategoriler as $k) $gruplar[$k['tur']][] = $k;

$sayfaBaslik = 'Kategoriler';
$sayfaAciklama = 'Gelir ve gider kalemlerini sınıflandırın';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#katModal" data-new data-title="Yeni Kategori"><i class="bi bi-plus-lg me-1"></i>Yeni Kategori</button>
</div>
<div class="row g-3">
<?php foreach ($gruplar as $tur => $liste): ?>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h5><i class="bi <?= $tur === 'gelir' ? 'bi-arrow-down-left-circle text-success' : 'bi-arrow-up-right-circle text-danger' ?> me-2"></i><?= $tur === 'gelir' ? 'Gelir' : 'Gider' ?> Kategorileri</h5></div>
            <table class="table">
                <thead><tr><th>Kategori</th><th>Kayıt</th><th class="text-end">Toplam</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($liste as $k): ?>
                    <tr>
                        <td><span class="dot me-2" style="background:<?= e($k['renk']) ?>"></span><strong><?= e($k['ad']) ?></strong> <?php if ($k['sistem']): ?><span class="badge-soft secondary">Sistem</span><?php endif; ?></td>
                        <td><?= (int)$k['adet'] ?></td>
                        <td class="text-end"><?= para($k['toplam']) ?></td>
                        <td class="table-actions">
                            <button class="btn btn-sm btn-soft-primary" data-bs-toggle="modal" data-bs-target="#katModal" data-title="Kategoriyi Düzenle"
                                data-edit='<?= e(json_encode(['id' => $k['id'], 'ad' => $k['ad'], 'tur' => $k['tur'], 'renk' => $k['renk']], JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-pencil"></i></button>
                            <?php if (!$k['sistem'] && !$k['adet']): ?>
                            <form method="post" class="d-inline" data-confirm="&quot;<?= e($k['ad']) ?>&quot; kategorisi silinecek."><?= csrf_field() ?>
                                <input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $k['id'] ?>">
                                <button class="btn btn-sm btn-soft-danger"><i class="bi bi-trash"></i></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div class="modal fade" id="katModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="kaydet"><input type="hidden" name="id">
                <div class="modal-header"><h5 class="modal-title">Yeni Kategori</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Kategori Adı</label><input name="ad" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Tür</label><select name="tur" class="form-select"><option value="gelir">Gelir</option><option value="gider">Gider</option></select></div>
                    <div><label class="form-label">Renk</label><input type="color" name="renk" class="form-control form-control-color w-100" value="#6366f1"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary">Kaydet</button></div>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
