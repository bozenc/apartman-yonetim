<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('borc.kendi');
$u = kullanici();

$st = $pdo->prepare('SELECT * FROM daireler WHERE kullanici_id = ?');
$st->execute([$u['id']]);
$daireler = $st->fetchAll();
$ids = array_column($daireler, 'id') ?: [0];
$in = implode(',', array_fill(0, count($ids), '?'));

/* ---------------- Ödeme bildirimi / dekont yükleme ---------------- */
if (is_post()) {
    csrf_check();
    $islem = post('islem');

    if ($islem === 'bildir') {
        yetki_zorunlu('bildirim.gonder');
        $daireId = (int)post('daire_id');
        $tutar = sayi(post('tutar'));
        $tarih = post('tarih') ?: date('Y-m-d');
        $yontem = array_key_exists(post('yontem'), odeme_yontemleri()) ? post('yontem') : 'havale';
        $secilen = array_map('intval', (array)($_POST['borc'] ?? []));
        try {
            if (!in_array($daireId, array_map('intval', array_column($daireler, 'id')), true)) throw new RuntimeException('Geçersiz daire.');
            if (!strtotime($tarih) || $tarih > date('Y-m-d')) throw new RuntimeException('Ödeme tarihi bugünden ileri olamaz.');
            $acik = acik_borclar($pdo, $daireId);
            if ($tutar <= 0) throw new RuntimeException('Ödediğiniz tutarı girin.');
            // Seçilen borçlar gerçekten bu dairenin açık borçları mı?
            $secilen = array_values(array_intersect($secilen, array_map('intval', array_column($acik, 'id'))));

            $st = $pdo->prepare("SELECT COUNT(*) FROM odeme_bildirimleri WHERE daire_id = ? AND durum = 'bekliyor' AND tutar = ? AND tarih = ?");
            $st->execute([$daireId, $tutar, $tarih]);
            if ($st->fetchColumn()) throw new RuntimeException('Aynı tutar ve tarihli bir bildiriminiz zaten onay bekliyor.');

            $dk = dekont_kaydet($_FILES['dekont'] ?? null, true);
            $pdo->prepare('INSERT INTO odeme_bildirimleri (daire_id, kullanici_id, tutar, tarih, yontem, aciklama, borc_ids, dekont, dekont_ad) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$daireId, $u['id'], $tutar, $tarih, $yontem, post('aciklama') ?: null, $secilen ? implode(',', $secilen) : null, $dk['dosya'], $dk['ad']]);
            logla('Ödeme bildirimi', para($tutar) . ' · dekont yüklendi');
            flash('success', 'Ödeme bildiriminiz ve dekontunuz yönetime iletildi. Onaylandığında borcunuzdan düşülecek ve makbuzunuz oluşacak.');
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
        }
    }

    if ($islem === 'iptal') {
        yetki_zorunlu('bildirim.gonder');
        $st = $pdo->prepare("SELECT * FROM odeme_bildirimleri WHERE id = ? AND kullanici_id = ? AND durum = 'bekliyor'");
        $st->execute([(int)post('id'), $u['id']]);
        if ($bl = $st->fetch()) {
            $pdo->prepare('DELETE FROM odeme_bildirimleri WHERE id = ?')->execute([$bl['id']]);
            if ($bl['dekont']) @unlink(dekont_klasoru() . '/' . basename($bl['dekont']));
            flash('success', 'Bildirim geri çekildi.');
        }
    }
    redirect('borclarim.php');
}

$st = $pdo->prepare("SELECT b.*, d.blok, d.daire_no FROM borclar b JOIN daireler d ON d.id = b.daire_id WHERE b.daire_id IN ($in) ORDER BY b.son_odeme DESC");
$st->execute($ids);
$borclar = $st->fetchAll();
$acikBorclar = array_filter($borclar, fn($b) => $b['tutar'] - $b['odenen'] > 0.009);
usort($acikBorclar, fn($a, $b) => strcmp($a['son_odeme'], $b['son_odeme']));

$st = $pdo->prepare("SELECT o.*, b.aciklama borc_aciklama FROM odemeler o JOIN borclar b ON b.id = o.borc_id WHERE o.daire_id IN ($in) ORDER BY o.tarih DESC, o.id DESC LIMIT 20");
$st->execute($ids);
$odemeler = $st->fetchAll();

$st = $pdo->prepare("SELECT * FROM odeme_bildirimleri WHERE daire_id IN ($in) ORDER BY created_at DESC LIMIT 15");
$st->execute($ids);
$bildirimler = $st->fetchAll();

$kalan = array_sum(array_map(fn($b) => max(0, $b['tutar'] - $b['odenen']), $borclar));
$geciken = array_sum(array_map(fn($b) => $b['son_odeme'] < date('Y-m-d') ? max(0, $b['tutar'] - $b['odenen']) : 0, $borclar));
$bekleyenTutar = array_sum(array_map(fn($b) => $b['durum'] === 'bekliyor' ? (float)$b['tutar'] : 0, $bildirimler));
$avansToplam = array_sum(array_map(fn($d) => (float)($d['alacak_bakiye'] ?? 0), $daireler));

$sayfaBaslik = 'Borçlarım';
$sayfaAciklama = $daireler ? implode(', ', array_map('daire_etiket', $daireler)) : 'Hesabınıza bağlı daire yok';
require __DIR__ . '/includes/header.php';
$durumRozet = ['bekliyor' => '<span class="badge-soft warning"><i class="bi bi-hourglass-split"></i> Onay bekliyor</span>',
               'onaylandi' => '<span class="badge-soft success"><i class="bi bi-check2-circle"></i> Onaylandı</span>',
               'reddedildi' => '<span class="badge-soft danger"><i class="bi bi-x-circle"></i> Reddedildi</span>'];
?>
<?php if (!$daireler): ?>
    <div class="card"><div class="empty-state"><i class="bi bi-house-exclamation"></i><h5 class="mt-3">Hesabınıza bağlı bir daire bulunamadı</h5><p>Lütfen site yönetimiyle iletişime geçin.</p></div></div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card stat-card"><div class="stat-icon soft-warning"><i class="bi bi-wallet2"></i></div><div><div class="stat-label">Toplam Borç</div><div class="stat-value"><?= para($kalan) ?></div>
        <?php if ($avansToplam > 0): ?><div class="stat-sub text-success fw-semibold">+<?= para($avansToplam) ?> alacak (avans) bakiyesi</div><?php endif; ?>
        <?php if ($bekleyenTutar > 0): ?><div class="stat-sub text-warning fw-semibold"><?= para($bekleyenTutar) ?> onay bekliyor</div><?php endif; ?></div></div></div>
    <div class="col-md-4"><div class="card stat-card"><div class="stat-icon soft-danger"><i class="bi bi-alarm"></i></div><div><div class="stat-label">Gecikmiş</div><div class="stat-value"><?= para($geciken) ?></div></div></div></div>
    <div class="col-md-4"><div class="card stat-card"><div class="stat-icon soft-primary"><i class="bi bi-bank"></i></div><div><div class="stat-label">Ödeme İçin IBAN</div><div class="fw-bold small mt-1"><?= e(ayar('iban')) ?></div><div class="stat-sub">Açıklamaya daire numaranızı yazın</div></div></div></div>
</div>

<?php if (yetki('bildirim.gonder') && $acikBorclar): ?>
<div class="card mb-3 border-0" style="background:linear-gradient(90deg,var(--brand-soft),transparent)">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div class="stat-icon soft-primary"><i class="bi bi-receipt-cutoff"></i></div>
        <div class="flex-grow-1"><strong>Ödemenizi yaptınız mı?</strong><div class="small text-muted">Havale/EFT dekontunuzu yükleyin; yönetim onayladığında borcunuzdan düşülür ve PDF makbuzunuz oluşur.</div></div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bildirModal"><i class="bi bi-cloud-arrow-up me-1"></i>Ödeme Bildir / Dekont Yükle</button>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card mb-3">
            <div class="card-header">
                <h5>Borç Hareketleri</h5>
                <div class="d-flex gap-1">
                    <?php foreach ($daireler as $d): ?>
                        <a href="<?= url('ekstre_pdf.php?daire=' . $d['id']) ?>" target="_blank" class="btn btn-sm btn-light"><i class="bi bi-file-earmark-pdf text-danger me-1"></i><?= count($daireler) > 1 ? e($d['blok'] . '-' . $d['daire_no']) . ' ' : '' ?>PDF Ekstre</a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Açıklama</th><th>Son Ödeme</th><th class="text-end">Tutar</th><th class="text-end">Kalan</th><th>Durum</th></tr></thead>
                <tbody>
                <?php foreach ($borclar as $b): ?>
                    <tr><td><?= e($b['aciklama']) ?><?php if (($b['muhatap'] ?? 'sakin') === 'malik'): ?> <span class="badge-soft info">Malik</span><?php endif; ?><?php if (count($daireler) > 1): ?><div class="small text-muted"><?= e($b['blok'] . '-' . $b['daire_no']) ?></div><?php endif; ?></td>
                        <td><?= tarih($b['son_odeme']) ?></td><td class="text-end"><?= para($b['tutar']) ?></td>
                        <td class="text-end fw-bold"><?= para(max(0, $b['tutar'] - $b['odenen'])) ?></td><td><?= borc_durum($b) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$borclar): ?><tr><td colspan="5" class="text-center text-muted py-4">Borç kaydı yok.</td></tr><?php endif; ?>
                </tbody></table></div>
        </div>
        <?php if ($bildirimler): ?>
        <div class="card">
            <div class="card-header"><h5><i class="bi bi-send-check me-2"></i>Ödeme Bildirimlerim</h5></div>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Ödeme Tarihi</th><th>Tutar</th><th>Dekont</th><th>Durum</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($bildirimler as $bl): ?>
                    <tr>
                        <td><?= tarih($bl['tarih']) ?><div class="small text-muted">Gönderim: <?= tarih($bl['created_at'], true) ?></div></td>
                        <td class="fw-bold"><?= para($bl['tutar']) ?></td>
                        <td><?php if ($bl['dekont']): ?><a href="<?= dekont_url('b', (int)$bl['id']) ?>" target="_blank" class="small"><i class="bi bi-paperclip"></i> Görüntüle</a><?php endif; ?></td>
                        <td><?= $durumRozet[$bl['durum']] ?><?php if ($bl['durum'] === 'reddedildi' && $bl['red_neden']): ?><div class="small text-danger mt-1"><?= e($bl['red_neden']) ?></div><?php endif; ?></td>
                        <td class="table-actions">
                            <?php if ($bl['durum'] === 'onaylandi'): ?>
                                <a href="<?= url('makbuz_pdf.php?bildirim=' . $bl['id']) ?>" target="_blank" class="btn btn-sm btn-soft-danger" title="PDF makbuz"><i class="bi bi-file-earmark-pdf"></i> Makbuz</a>
                            <?php elseif ($bl['durum'] === 'bekliyor' && (int)$bl['kullanici_id'] === (int)$u['id']): ?>
                                <form method="post" class="d-inline" data-confirm="Bildirim geri çekilecek."><?= csrf_field() ?><input type="hidden" name="islem" value="iptal"><input type="hidden" name="id" value="<?= $bl['id'] ?>">
                                    <button class="btn btn-sm btn-light" title="Geri çek"><i class="bi bi-arrow-counterclockwise"></i></button></form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
        </div>
        <?php endif; ?>
    </div>
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><h5>Son Ödemelerim</h5></div>
            <div class="table-responsive"><table class="table">
                <tbody>
                <?php foreach ($odemeler as $o): ?>
                    <tr><td><strong><?= e($o['borc_aciklama']) ?></strong><div class="small text-muted"><?= tarih($o['tarih']) ?> · <?= e(odeme_yontemleri()[$o['yontem']] ?? '') ?> · <code><?= e($o['makbuz_no']) ?></code></div></td>
                        <td class="text-end text-nowrap"><div class="amount-in"><?= para($o['tutar']) ?></div>
                            <a href="<?= url('makbuz_pdf.php?id=' . $o['id']) ?>" target="_blank" class="small text-danger"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
                            <?php if ($o['dekont']): ?> · <a href="<?= dekont_url('o', (int)$o['id']) ?>" target="_blank" class="small"><i class="bi bi-paperclip"></i> Dekont</a><?php endif; ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$odemeler): ?><tr><td class="text-center text-muted py-4">Ödeme kaydı yok.</td></tr><?php endif; ?>
                </tbody></table></div>
        </div>
    </div>
</div>

<?php if (yetki('bildirim.gonder') && $acikBorclar): ?>
<div class="modal fade" id="bildirModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" id="bildirForm">
                <?= csrf_field() ?><input type="hidden" name="islem" value="bildir">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cloud-arrow-up me-2"></i>Ödeme Bildirimi</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <?php if (count($daireler) > 1): ?>
                    <div class="mb-3"><label class="form-label">Daire</label><select name="daire_id" class="form-select" id="bdDaire">
                        <?php foreach ($daireler as $d): ?><option value="<?= $d['id'] ?>"><?= e(daire_etiket($d)) ?></option><?php endforeach; ?></select></div>
                    <?php else: ?><input type="hidden" name="daire_id" value="<?= $daireler[0]['id'] ?>" id="bdDaire"><?php endif; ?>

                    <label class="form-label">Hangi borçları ödediniz?</label>
                    <div class="border rounded-3 mb-3" style="max-height:220px;overflow:auto">
                        <?php foreach ($acikBorclar as $b): $k = round($b['tutar'] - $b['odenen'], 2); ?>
                        <label class="d-flex align-items-center gap-2 px-3 py-2 border-bottom borc-satir" data-daire="<?= $b['daire_id'] ?>">
                            <input class="form-check-input m-0 borc-sec" type="checkbox" name="borc[]" value="<?= $b['id'] ?>" data-tutar="<?= $k ?>">
                            <span class="flex-grow-1"><?= e($b['aciklama']) ?><span class="small text-muted d-block">Son ödeme <?= tarih($b['son_odeme']) ?></span></span>
                            <strong><?= para($k) ?></strong>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Ödenen Tutar (₺) *</label><input name="tutar" id="bdTutar" class="form-control" inputmode="decimal" required></div>
                        <div class="col-md-4"><label class="form-label">Ödeme Tarihi *</label><input type="date" name="tarih" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
                        <div class="col-md-4"><label class="form-label">Yöntem</label><select name="yontem" class="form-select">
                            <?php foreach (odeme_yontemleri() as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'havale' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
                        <div class="col-12"><label class="form-label">Dekont * <span class="text-muted fw-normal">(PDF, JPG veya PNG · en fazla <?= DEKONT_MAX_MB ?> MB)</span></label>
                            <input type="file" name="dekont" class="form-control" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
                            <div class="form-text">Banka uygulamanızdan indirdiğiniz PDF dekontu ya da dekontun fotoğrafını yükleyebilirsiniz.</div></div>
                        <div class="col-12"><label class="form-label">Not</label><input name="aciklama" class="form-control" maxlength="255" placeholder="İsteğe bağlı, ör. gönderen hesap sahibi farklıysa"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button>
                    <button class="btn btn-primary" id="bdGonder"><i class="bi bi-send me-1"></i>Bildirimi Gönder</button></div>
            </form>
        </div>
    </div>
</div>
<?php ob_start(); ?>
<script>
(function () {
    const tutar = document.getElementById('bdTutar');
    const daire = document.getElementById('bdDaire');
    function topla() {
        let t = 0;
        document.querySelectorAll('.borc-sec:checked').forEach(c => t += parseFloat(c.dataset.tutar));
        if (t > 0) tutar.value = t.toFixed(2).replace('.', ',');
    }
    function daireFiltre() {
        document.querySelectorAll('.borc-satir').forEach(s => {
            const gor = s.dataset.daire === daire.value;
            s.style.display = gor ? '' : 'none';
            if (!gor) s.querySelector('input').checked = false;
        });
    }
    document.querySelectorAll('.borc-sec').forEach(c => c.addEventListener('change', topla));
    if (daire.tagName === 'SELECT') { daire.addEventListener('change', daireFiltre); daireFiltre(); }
    document.getElementById('bildirForm').addEventListener('submit', function () {
        const b = document.getElementById('bdGonder'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Yükleniyor…';
    });
})();
</script>
<?php $sayfaJs = ob_get_clean(); endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
