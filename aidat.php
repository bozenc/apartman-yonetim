<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('aidat.goruntule');

/** Bir dönem için daireleri borçlandırır, eklenen kayıt sayısını döner */
function donem_borclandir(PDO $pdo, array $donem, bool $sadeceDolu, bool $katsayi): int
{
    $daireler = $pdo->query('SELECT id, aidat_katsayi FROM daireler' . ($sadeceDolu ? " WHERE durum = 'dolu'" : ''))->fetchAll();
    $ins = $pdo->prepare("INSERT IGNORE INTO borclar (daire_id, donem_id, tur, aciklama, tutar, son_odeme) VALUES (?,?,?,?,?,?)");
    $n = 0;
    foreach ($daireler as $d) {
        $tutar = round((float)$donem['tutar'] * ($katsayi ? (float)$d['aidat_katsayi'] : 1), 2);
        if ($tutar <= 0) continue;
        $ins->execute([$d['id'], $donem['id'], 'aidat', donem_adi($donem['donem']) . ' Aidatı', $tutar, $donem['son_odeme']]);
        if ($ins->rowCount()) {
            $n++;
            avans_mahsup_et($pdo, (int)$d['id']);
        }
    }
    return $n;
}

if (is_post()) {
    csrf_check();
    yetki_zorunlu('aidat.donem');
    $islem = post('islem');

    if ($islem === 'olustur') {
        $donem = post('donem');
        $tutar = sayi(post('tutar'));
        $sonOdeme = post('son_odeme');
        if (!preg_match('/^\d{4}-\d{2}$/', $donem) || $tutar <= 0 || !strtotime($sonOdeme)) {
            flash('danger', 'Lütfen dönem, tutar ve son ödeme tarihini doğru girin.');
            redirect('aidat.php');
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO aidat_donemleri (donem, tutar, son_odeme, aciklama) VALUES (?,?,?,?)')
                ->execute([$donem, $tutar, $sonOdeme, post('aciklama') ?: null]);
            $d = ['id' => $pdo->lastInsertId(), 'donem' => $donem, 'tutar' => $tutar, 'son_odeme' => $sonOdeme];
            $n = post('borclandir') ? donem_borclandir($pdo, $d, (bool)post('sadece_dolu'), (bool)post('katsayi')) : 0;
            $pdo->commit();
            logla('Aidat dönemi oluşturuldu', donem_adi($donem) . " · $n daire borçlandırıldı");
            flash('success', donem_adi($donem) . " dönemi oluşturuldu. $n daire borçlandırıldı.");
        } catch (PDOException $e) {
            $pdo->rollBack();
            flash('danger', $e->getCode() == 23000 ? 'Bu dönem zaten mevcut.' : 'Dönem oluşturulamadı.');
        }
    }

    if ($islem === 'borclandir') {
        $st = $pdo->prepare('SELECT * FROM aidat_donemleri WHERE id = ?');
        $st->execute([(int)post('id')]);
        if ($d = $st->fetch()) {
            $n = donem_borclandir($pdo, $d, true, true);
            logla('Eksik borçlandırma', donem_adi($d['donem']) . " · $n daire");
            flash($n ? 'success' : 'info', $n ? "$n daire daha borçlandırıldı." : 'Borçlandırılmamış dolu daire bulunamadı.');
        }
    }

    if ($islem === 'gecikme') {
        $st = $pdo->prepare('SELECT * FROM aidat_donemleri WHERE id = ?');
        $st->execute([(int)post('id')]);
        $d = $st->fetch();
        $oran = (float)ayar('gecikme_orani', 0);
        if ($d && $oran > 0) {
            $borclar = $pdo->prepare("SELECT * FROM borclar WHERE donem_id = ? AND tutar > odenen AND son_odeme < CURDATE()");
            $borclar->execute([$d['id']]);
            $ins = $pdo->prepare("INSERT INTO borclar (daire_id, donem_id, tur, aciklama, tutar, son_odeme) VALUES (?,NULL,'gecikme',?,?,?)");
            $kontrol = $pdo->prepare("SELECT COUNT(*) FROM borclar WHERE daire_id = ? AND tur = 'gecikme' AND aciklama = ?");
            $n = 0;
            foreach ($borclar as $b) {
                $acik = donem_adi($d['donem']) . " gecikme bedeli (%$oran)";
                $kontrol->execute([$b['daire_id'], $acik]);
                if ($kontrol->fetchColumn()) continue; // aynı dönem için tekrar uygulanmaz
                $tutar = round(((float)$b['tutar'] - (float)$b['odenen']) * $oran / 100, 2);
                if ($tutar <= 0) continue;
                $ins->execute([$b['daire_id'], $acik, $tutar, date('Y-m-d', strtotime('+15 days'))]);
                $n++;
            }
            logla('Gecikme bedeli', donem_adi($d['donem']) . " · $n daire");
            flash($n ? 'success' : 'info', $n ? "$n daireye gecikme bedeli eklendi." : 'Gecikme bedeli uygulanacak borç bulunamadı.');
        } else {
            flash('warning', 'Gecikme oranı ayarlarda 0 olarak tanımlı.');
        }
    }

    if ($islem === 'sil') {
        $id = (int)post('id');
        $st = $pdo->prepare('SELECT COUNT(*) FROM odemeler o JOIN borclar b ON b.id = o.borc_id WHERE b.donem_id = ?');
        $st->execute([$id]);
        if ($st->fetchColumn()) {
            flash('warning', 'Bu döneme ait tahsilatlar olduğu için dönem silinemez.');
        } else {
            $pdo->prepare('DELETE FROM aidat_donemleri WHERE id = ?')->execute([$id]);
            logla('Aidat dönemi silindi', 'ID: ' . $id);
            flash('success', 'Dönem ve bağlı borç kayıtları silindi.');
        }
    }
    redirect('aidat.php');
}

$donemler = $pdo->query("SELECT d.*, COUNT(b.id) AS daire_sayisi, COALESCE(SUM(b.tutar),0) AS tahakkuk, COALESCE(SUM(b.odenen),0) AS tahsil,
        SUM(b.tutar <= b.odenen) AS odeyen
    FROM aidat_donemleri d LEFT JOIN borclar b ON b.donem_id = d.id
    GROUP BY d.id ORDER BY d.donem DESC")->fetchAll();

// Yeni dönem önerisi: son dönemden bir sonraki ay
$sonraki = $donemler ? date('Y-m', strtotime($donemler[0]['donem'] . '-01 +1 month')) : date('Y-m');
$gun = str_pad((string)min(28, max(1, (int)ayar('son_odeme_gunu', 10))), 2, '0', STR_PAD_LEFT);

$sayfaBaslik = 'Aidat Dönemleri';
$sayfaAciklama = 'Aylık aidat tanımlayın ve daireleri toplu borçlandırın';
require __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h5>Dönemler</h5>
        <?php if (yetki('aidat.donem')): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#donemModal"><i class="bi bi-plus-lg me-1"></i>Yeni Dönem</button>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Dönem</th><th>Aidat</th><th>Son Ödeme</th><th>Daire</th><th>Tahakkuk</th><th>Tahsilat</th><th style="min-width:160px">Oran</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($donemler as $d): $oran = $d['tahakkuk'] > 0 ? round($d['tahsil'] / $d['tahakkuk'] * 100) : 0; ?>
                <tr>
                    <td><strong><?= donem_adi($d['donem']) ?></strong><?php if ($d['aciklama']): ?><div class="small text-muted"><?= e($d['aciklama']) ?></div><?php endif; ?></td>
                    <td><?= para($d['tutar']) ?></td>
                    <td><?= tarih($d['son_odeme']) ?> <?php if ($d['son_odeme'] < date('Y-m-d') && $oran < 100): ?><span class="badge-soft danger">Süresi geçti</span><?php endif; ?></td>
                    <td><?= (int)$d['odeyen'] ?> / <?= (int)$d['daire_sayisi'] ?></td>
                    <td><?= para($d['tahakkuk']) ?></td>
                    <td class="amount-in"><?= para($d['tahsil']) ?></td>
                    <td><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1"><div class="progress-bar <?= $oran >= 80 ? 'bg-success' : ($oran >= 40 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= $oran ?>%"></div></div><small class="fw-bold">%<?= $oran ?></small></div></td>
                    <td class="table-actions">
                        <a href="<?= url('borclar.php?donem=' . $d['id']) ?>" class="btn btn-sm btn-soft-primary" title="Borçları gör"><i class="bi bi-list-ul"></i></a>
                        <?php if (yetki('aidat.donem')): ?>
                        <div class="dropdown d-inline">
                            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><form method="post"><?= csrf_field() ?><input type="hidden" name="islem" value="borclandir"><input type="hidden" name="id" value="<?= $d['id'] ?>">
                                    <button class="dropdown-item"><i class="bi bi-plus-circle me-2"></i>Eksik daireleri borçlandır</button></form></li>
                                <li><form method="post" data-confirm="Son ödeme tarihi geçmiş ödenmemiş borçlara %<?= e(ayar('gecikme_orani')) ?> gecikme bedeli eklenecek."><?= csrf_field() ?><input type="hidden" name="islem" value="gecikme"><input type="hidden" name="id" value="<?= $d['id'] ?>">
                                    <button class="dropdown-item"><i class="bi bi-alarm me-2"></i>Gecikme bedeli uygula</button></form></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><form method="post" data-confirm="<?= e(donem_adi($d['donem'])) ?> dönemi ve tüm borç kayıtları silinecek."><?= csrf_field() ?><input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $d['id'] ?>">
                                    <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Dönemi sil</button></form></li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$donemler): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-calendar2-plus"></i><p class="mt-2 mb-0">Henüz aidat dönemi oluşturulmadı.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (yetki('aidat.donem')): ?>
<div class="modal fade" id="donemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="olustur">
                <div class="modal-header"><h5 class="modal-title">Yeni Aidat Dönemi</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">Dönem</label><input type="month" name="donem" class="form-control" value="<?= $sonraki ?>" required id="donemInput"></div>
                        <div class="col-6"><label class="form-label">Aidat Tutarı (₺)</label><input name="tutar" class="form-control" value="<?= e(ayar('varsayilan_aidat')) ?>" inputmode="decimal" required></div>
                        <div class="col-6"><label class="form-label">Son Ödeme Tarihi</label><input type="date" name="son_odeme" class="form-control" value="<?= $sonraki . '-' . $gun ?>" required id="sonOdemeInput"></div>
                        <div class="col-6"><label class="form-label">Açıklama</label><input name="aciklama" class="form-control" placeholder="İsteğe bağlı"></div>
                        <div class="col-12">
                            <div class="p-3 rounded-3 soft-primary">
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="borclandir" value="1" id="c1" checked><label class="form-check-label" for="c1">Daireleri hemen borçlandır</label></div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="sadece_dolu" value="1" id="c2" checked><label class="form-check-label" for="c2">Yalnızca dolu daireler</label></div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="katsayi" value="1" id="c3" checked><label class="form-check-label" for="c3">Daire aidat katsayısını uygula</label></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Oluştur</button></div>
            </form>
        </div>
    </div>
</div>
<?php ob_start(); ?>
<script>
document.getElementById('donemInput').addEventListener('change', function () {
    if (this.value) document.getElementById('sonOdemeInput').value = this.value + '-<?= $gun ?>';
});
</script>
<?php $sayfaJs = ob_get_clean(); endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
