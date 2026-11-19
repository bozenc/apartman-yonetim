<?php
require __DIR__ . '/includes/init.php';
giris_zorunlu();
if (!yetki_herhangi(['bildirim.onayla', 'odeme.goruntule'])) yetki_zorunlu('bildirim.onayla');

if (is_post()) {
    csrf_check();
    yetki_zorunlu('bildirim.onayla');
    $islem = post('islem');
    $st = $pdo->prepare("SELECT ob.*, d.blok, d.daire_no FROM odeme_bildirimleri ob JOIN daireler d ON d.id = ob.daire_id WHERE ob.id = ? AND ob.durum = 'bekliyor'");
    $st->execute([(int)post('id')]);
    $bl = $st->fetch();
    if (!$bl) { flash('warning', 'Bildirim bulunamadı veya daha önce işlenmiş.'); redirect('bildirimler.php'); }
    $etiket = $bl['blok'] . '-' . $bl['daire_no'];

    if ($islem === 'onayla') {
        $tutar = sayi(post('tutar'));
        $tarih = post('tarih') ?: $bl['tarih'];
        try {
            $pdo->beginTransaction();
            // Aynı bildirimin iki kez onaylanmasını engelle
            $kilit = $pdo->prepare("SELECT durum FROM odeme_bildirimleri WHERE id = ? FOR UPDATE");
            $kilit->execute([$bl['id']]);
            if ($kilit->fetchColumn() !== 'bekliyor') throw new RuntimeException('Bu bildirim az önce başka biri tarafından işlendi.');

            $oncelik = $bl['borc_ids'] ? array_map('intval', explode(',', $bl['borc_ids'])) : [];
            $idler = tahsilat_dagit($pdo, (int)$bl['daire_id'], $tutar, $tarih, $bl['yontem'], 'Ödeme bildirimi #' . $bl['id'], $oncelik, $bl['dekont'], (int)$bl['id']);
            $pdo->prepare("UPDATE odeme_bildirimleri SET durum = 'onaylandi', tutar = ?, tarih = ?, isleyen_id = ?, islem_tarihi = NOW() WHERE id = ?")
                ->execute([$tutar, $tarih, $_SESSION['uid'], $bl['id']]);
            $pdo->commit();
            logla('Bildirim onaylandı', $etiket . ' · ' . para($tutar) . ' · ' . count($idler) . ' borç kapatıldı');
            $ep = bildir_makbuz($idler, $bl);
            flash('success', $etiket . ' için ' . para($tutar) . ' tahsil edildi (' . count($idler) . ' borç kalemi). Makbuz oluşturuldu.' . bildirim_ozeti($ep));
            if (post('pdf')) redirect('makbuz_pdf.php?bildirim=' . $bl['id']);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('danger', $e->getMessage());
        }
    }

    if ($islem === 'reddet') {
        $neden = post('neden');
        if ($neden === '') {
            flash('danger', 'Reddetme nedenini yazın; sakin bu açıklamayı görecek.');
        } else {
            $pdo->prepare("UPDATE odeme_bildirimleri SET durum = 'reddedildi', red_neden = ?, isleyen_id = ?, islem_tarihi = NOW() WHERE id = ?")
                ->execute([mb_substr($neden, 0, 255), $_SESSION['uid'], $bl['id']]);
            logla('Bildirim reddedildi', $etiket . ' · ' . para($bl['tutar']) . ' · ' . $neden);
            $ep = bildir_red(array_merge($bl, ['red_neden' => mb_substr($neden, 0, 255)]));
            flash('success', 'Bildirim reddedildi.' . bildirim_ozeti($ep));
        }
    }
    redirect('bildirimler.php');
}

$durum = in_array(get('durum'), ['bekliyor', 'onaylandi', 'reddedildi'], true) ? get('durum') : 'bekliyor';
$sayilar = $pdo->query("SELECT durum, COUNT(*) FROM odeme_bildirimleri GROUP BY durum")->fetchAll(PDO::FETCH_KEY_PAIR);
$sayfa = max(1, (int)get('s', 1));
$limit = 25;

$st = $pdo->prepare("SELECT ob.*, d.blok, d.daire_no, COALESCE(d.sakin_adi, d.malik_adi) AS daire_ad, k.ad_soyad AS gonderen, i.ad_soyad AS isleyen,
        (SELECT COALESCE(SUM(b.tutar - b.odenen), 0) FROM borclar b WHERE b.daire_id = ob.daire_id AND b.tutar > b.odenen) AS daire_borc
    FROM odeme_bildirimleri ob JOIN daireler d ON d.id = ob.daire_id
    LEFT JOIN kullanicilar k ON k.id = ob.kullanici_id LEFT JOIN kullanicilar i ON i.id = ob.isleyen_id
    WHERE ob.durum = ? ORDER BY ob.created_at " . ($durum === 'bekliyor' ? 'ASC' : 'DESC') . " LIMIT $limit OFFSET " . (($sayfa - 1) * $limit));
$st->execute([$durum]);
$liste = $st->fetchAll();

// Seçilen borçların açıklamaları
$borcAd = [];
$tumIds = array_filter(array_merge(...array_map(fn($b) => $b['borc_ids'] ? explode(',', $b['borc_ids']) : [], $liste ?: [['borc_ids' => '']])));
if ($tumIds) {
    $in = implode(',', array_map('intval', $tumIds));
    $borcAd = $pdo->query("SELECT id, aciklama FROM borclar WHERE id IN ($in)")->fetchAll(PDO::FETCH_KEY_PAIR);
}

$sayfaBaslik = 'Ödeme Bildirimleri';
$sayfaAciklama = 'Sakinlerin yüklediği dekontları inceleyin ve onaylayın';
require __DIR__ . '/includes/header.php';
?>
<ul class="nav nav-pills mb-3">
    <?php foreach (['bekliyor' => ['Onay Bekleyen', 'warning'], 'onaylandi' => ['Onaylanan', 'success'], 'reddedildi' => ['Reddedilen', 'danger']] as $k => [$ad, $renk]): ?>
        <li class="nav-item"><a class="nav-link <?= $durum === $k ? 'active' : '' ?>" href="?durum=<?= $k ?>"><?= $ad ?>
            <span class="badge rounded-pill bg-<?= $durum === $k ? 'light text-dark' : $renk ?> ms-1"><?= (int)($sayilar[$k] ?? 0) ?></span></a></li>
    <?php endforeach; ?>
</ul>

<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Gönderim</th><th>Daire</th><th>Ödeme</th><th>Ödenen Borçlar</th><th>Dekont</th><th><?= $durum === 'bekliyor' ? 'Daire Borcu' : 'İşlem' ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($liste as $bl): $secili = $bl['borc_ids'] ? explode(',', $bl['borc_ids']) : []; ?>
                <tr>
                    <td class="small text-nowrap"><?= tarih($bl['created_at'], true) ?><div class="text-muted"><?= e(kisi($bl['gonderen']) ?: '-') ?></div></td>
                    <td><strong><?= e($bl['blok'] . '-' . $bl['daire_no']) ?></strong><div class="small text-muted"><?= e(kisi($bl['daire_ad'])) ?></div></td>
                    <td><span class="fw-bold fs-6"><?= para($bl['tutar']) ?></span><div class="small text-muted"><?= tarih($bl['tarih']) ?> · <?= e(odeme_yontemleri()[$bl['yontem']] ?? '') ?></div>
                        <?php if ($bl['aciklama']): ?><div class="small fst-italic">“<?= e($bl['aciklama']) ?>”</div><?php endif; ?></td>
                    <td class="small"><?php if ($secili): foreach ($secili as $bid): ?><div>• <?= e($borcAd[$bid] ?? ('#' . (int)$bid)) ?></div><?php endforeach; else: ?><span class="text-muted">En eski borçtan</span><?php endif; ?></td>
                    <td><?php if ($bl['dekont']): $pdf = str_ends_with($bl['dekont'], '.pdf'); ?>
                        <button class="btn btn-sm btn-soft-primary" data-bs-toggle="modal" data-bs-target="#onizleModal" data-src="<?= e(dekont_url('b', (int)$bl['id'])) ?>" data-pdf="<?= $pdf ? 1 : 0 ?>" data-ad="<?= e($bl['dekont_ad']) ?>">
                            <i class="bi <?= $pdf ? 'bi-file-earmark-pdf' : 'bi-image' ?>"></i> İncele</button>
                        <?php else: ?><span class="text-muted small">Yok</span><?php endif; ?></td>
                    <td class="small">
                        <?php if ($durum === 'bekliyor'): ?>
                            <span class="<?= $bl['tutar'] > $bl['daire_borc'] + 0.001 ? 'text-danger fw-bold' : '' ?>"><?= para($bl['daire_borc']) ?></span>
                            <?php if ($bl['tutar'] > $bl['daire_borc'] + 0.001): ?><div class="text-danger">Tutar borçtan fazla</div><?php endif; ?>
                        <?php else: ?>
                            <?= e($bl['isleyen'] ?? '-') ?><div class="text-muted"><?= tarih($bl['islem_tarihi'], true) ?></div>
                            <?php if ($bl['red_neden']): ?><div class="text-danger"><?= e($bl['red_neden']) ?></div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="table-actions">
                        <?php if ($durum === 'bekliyor' && yetki('bildirim.onayla')): ?>
                            <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#onayModal"
                                data-edit='<?= e(json_encode(['id' => $bl['id'], 'tutar' => number_format(min((float)$bl['tutar'], (float)$bl['daire_borc']), 2, ',', ''), 'tarih' => $bl['tarih'], 'bilgi' => $bl['blok'] . '-' . $bl['daire_no'] . ' · bildirilen ' . para($bl['tutar']) . ' · daire borcu ' . para($bl['daire_borc'])], JSON_UNESCAPED_UNICODE)) ?>'>
                                <i class="bi bi-check2"></i> Onayla</button>
                            <button class="btn btn-sm btn-soft-danger" data-bs-toggle="modal" data-bs-target="#redModal" data-edit='<?= e(json_encode(['id' => $bl['id']])) ?>'><i class="bi bi-x-lg"></i></button>
                        <?php elseif ($durum === 'onaylandi'): ?>
                            <a href="<?= url('makbuz_pdf.php?bildirim=' . $bl['id']) ?>" target="_blank" class="btn btn-sm btn-soft-danger"><i class="bi bi-file-earmark-pdf"></i> Makbuz</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$liste): ?><tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i><p class="mt-2 mb-0"><?= $durum === 'bekliyor' ? 'Onay bekleyen bildirim yok.' : 'Kayıt yok.' ?></p></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (($sayilar[$durum] ?? 0) > $limit): ?><div class="card-body d-flex justify-content-end"><?= sayfalama((int)$sayilar[$durum], $sayfa, $limit) ?></div><?php endif; ?>
</div>

<!-- Dekont önizleme -->
<div class="modal fade" id="onizleModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-paperclip me-1"></i><span id="onizleAd">Dekont</span></h5>
                <a href="#" id="onizleYeni" target="_blank" class="btn btn-sm btn-light ms-auto me-2"><i class="bi bi-box-arrow-up-right"></i> Yeni sekmede aç</a>
                <button type="button" class="btn-close m-0" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-0 text-center" id="onizleGovde" style="min-height:70vh;background:var(--bg)"></div>
        </div>
    </div>
</div>

<?php if (yetki('bildirim.onayla')): ?>
<div class="modal fade" id="onayModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="onayla"><input type="hidden" name="id">
                <div class="modal-header"><h5 class="modal-title">Ödemeyi Onayla</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input name="bilgi" class="form-control-plaintext fw-semibold small mb-2" readonly tabindex="-1">
                    <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>Dekonttaki tutarı kontrol edin. Tutar önce sakinin seçtiği borçlara, kalanı en eski borçlara dağıtılır ve kasaya gelir olarak işlenir.</div>
                    <div class="row g-3">
                        <div class="col-6"><label class="form-label">Onaylanan Tutar (₺)</label><input name="tutar" class="form-control" inputmode="decimal" required></div>
                        <div class="col-6"><label class="form-label">Ödeme Tarihi</label><input type="date" name="tarih" class="form-control" required></div>
                        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="pdf" value="1" id="onayPdf"><label class="form-check-label" for="onayPdf">Onaydan sonra PDF makbuzu indir</label></div></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Vazgeç</button><button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Onayla ve Tahsil Et</button></div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="redModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="reddet"><input type="hidden" name="id">
                <div class="modal-header"><h5 class="modal-title">Bildirimi Reddet</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <label class="form-label">Reddetme nedeni <span class="text-muted fw-normal">(sakin görecek)</span></label>
                    <textarea name="neden" class="form-control" rows="3" maxlength="255" required placeholder="ör. Dekonttaki tutar hesabımıza ulaşmadı / Dekont okunmuyor"></textarea>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Vazgeç</button><button class="btn btn-danger">Reddet</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php ob_start(); ?>
<script>
document.getElementById('onizleModal').addEventListener('show.bs.modal', function (e) {
    const b = e.relatedTarget, govde = document.getElementById('onizleGovde');
    document.getElementById('onizleAd').textContent = b.dataset.ad || 'Dekont';
    document.getElementById('onizleYeni').href = b.dataset.src;
    govde.innerHTML = b.dataset.pdf === '1'
        ? '<iframe src="' + b.dataset.src + '" sandbox="allow-same-origin" style="width:100%;height:75vh;border:0"></iframe>'
        : '<img src="' + b.dataset.src + '" style="max-width:100%;max-height:75vh" alt="Dekont">';
});
document.getElementById('onizleModal').addEventListener('hidden.bs.modal', () => document.getElementById('onizleGovde').innerHTML = '');
</script>
<?php $sayfaJs = ob_get_clean(); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
