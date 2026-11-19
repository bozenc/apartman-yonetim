<?php
/**
 * Dijital Anket ve Genel Kurul Ön Oylama Modülü
 *  - Her dairenin her ankette 1 oy hakkı vardır (uk_anket_daire).
 *  - Yöneticiler (anket.yonet) çoktan seçmeli anket başlatabilir, kapatabilir veya silebilir.
 */
require __DIR__ . '/includes/init.php';
$u = giris_zorunlu();
yetki_zorunlu('panel.goruntule');

$yonetici = yetki('anket.yonet');
$st = $pdo->prepare('SELECT * FROM daireler WHERE kullanici_id = ? ORDER BY blok, CAST(daire_no AS UNSIGNED)');
$st->execute([$u['id']]);
$benimDaireler = $st->fetchAll();

if (is_post()) {
    csrf_check();
    $islem = post('islem');

    if ($islem === 'olustur' && $yonetici) {
        $baslik = trim((string)post('baslik'));
        $aciklama = trim((string)post('aciklama')) ?: null;
        $bitis = post('bitis_tarihi') ?: null;
        $secenekler = array_values(array_filter(array_map('trim', (array)($_POST['secenek'] ?? [])), fn($s) => $s !== ''));

        if ($baslik === '' || count($secenekler) < 2) {
            flash('danger', 'Anket başlığı ve en az 2 seçenek girmelisiniz.');
            redirect('anketler.php');
        }
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO anketler (baslik, aciklama, bitis_tarihi, kullanici_id) VALUES (?,?,?,?)')
            ->execute([mb_substr($baslik, 0, 180), $aciklama, $bitis, $u['id']]);
        $aid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO anket_secenekler (anket_id, secenek) VALUES (?,?)');
        foreach ($secenekler as $s) {
            $ins->execute([$aid, mb_substr($s, 0, 180)]);
        }
        $pdo->commit();
        logla('Anket oluşturuldu', $baslik);
        flash('success', 'Yeni anket başlatıldı.');
    }

    if ($islem === 'oyla') {
        $anketId = (int)post('anket_id');
        $secenekId = (int)post('secenek_id');
        $daireId = (int)post('daire_id');
        $izinliDaireler = array_map('intval', array_column($benimDaireler, 'id'));

        $st = $pdo->prepare('SELECT * FROM anketler WHERE id = ? AND aktif = 1 AND (bitis_tarihi IS NULL OR bitis_tarihi >= CURDATE())');
        $st->execute([$anketId]);
        $anket = $st->fetch();

        $st = $pdo->prepare('SELECT COUNT(*) FROM anket_secenekler WHERE id = ? AND anket_id = ?');
        $st->execute([$secenekId, $anketId]);
        $gecerliSecenek = (bool)$st->fetchColumn();

        if (!$anket || !$gecerliSecenek) {
            flash('danger', 'Anket aktif değil veya geçersiz seçenek.');
        } elseif (!in_array($daireId, $izinliDaireler, true)) {
            flash('danger', 'Oy kullanmak için hesabınıza bağlı bir daire seçmelisiniz.');
        } else {
            $pdo->prepare('INSERT INTO anket_oylar (anket_id, secenek_id, daire_id, kullanici_id) VALUES (?,?,?,?)
                ON DUPLICATE KEY UPDATE secenek_id = VALUES(secenek_id), kullanici_id = VALUES(kullanici_id), created_at = CURRENT_TIMESTAMP')
                ->execute([$anketId, $secenekId, $daireId, $u['id']]);
            logla('Anket oyu kullanıldı', 'Anket #' . $anketId . ' · Daire #' . $daireId);
            flash('success', 'Oyunuz kaydedildi.');
        }
    }

    if ($islem === 'durum' && $yonetici) {
        $id = (int)post('id');
        $pdo->prepare('UPDATE anketler SET aktif = 1 - aktif WHERE id = ?')->execute([$id]);
        flash('success', 'Anket durumu güncellendi.');
    }

    if ($islem === 'sil' && $yonetici) {
        $id = (int)post('id');
        $pdo->prepare('DELETE FROM anketler WHERE id = ?')->execute([$id]);
        logla('Anket silindi', 'ID: ' . $id);
        flash('success', 'Anket silindi.');
    }
    redirect('anketler.php');
}

$anketler = $pdo->query('SELECT a.*, k.ad_soyad FROM anketler a LEFT JOIN kullanicilar k ON k.id = a.kullanici_id ORDER BY a.aktif DESC, a.id DESC')->fetchAll();
$secenekler = [];
foreach ($pdo->query('SELECT s.*, (SELECT COUNT(*) FROM anket_oylar o WHERE o.secenek_id = s.id) AS oy FROM anket_secenekler s ORDER BY s.id') as $s) {
    $secenekler[$s['anket_id']][] = $s;
}
$benimOylar = [];
if ($benimDaireler) {
    $dIds = implode(',', array_map('intval', array_column($benimDaireler, 'id')));
    foreach ($pdo->query("SELECT anket_id, daire_id, secenek_id FROM anket_oylar WHERE daire_id IN ($dIds)") as $o) {
        $benimOylar[$o['anket_id']][$o['daire_id']] = (int)$o['secenek_id'];
    }
}

$sayfaBaslik = 'Anket & Oylama';
$sayfaAciklama = 'Apartman/site kararları için daire bazlı şeffaf oylama';
require __DIR__ . '/includes/header.php';
?>

<?php if ($yonetici): ?>
<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#anketModal"><i class="bi bi-plus-lg me-1"></i>Yeni Anket Başlat</button>
</div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($anketler as $a):
    $sec = $secenekler[$a['id']] ?? [];
    $toplamOy = array_sum(array_column($sec, 'oy'));
    $acik = (int)$a['aktif'] === 1 && (!$a['bitis_tarihi'] || $a['bitis_tarihi'] >= date('Y-m-d'));
    $ilkDaireId = $benimDaireler[0]['id'] ?? 0;
    $seciliOy = $benimOylar[$a['id']][$ilkDaireId] ?? 0;
?>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div>
                        <?= $acik ? '<span class="badge-soft success"><i class="bi bi-broadcast"></i> Oylamaya Açık</span>' : '<span class="badge-soft secondary"><i class="bi bi-lock"></i> Tamamlandı</span>' ?>
                        <span class="badge-soft primary ms-1"><?= $toplamOy ?> daire oy kullandı</span>
                    </div>
                    <?php if ($yonetici): ?>
                    <div class="d-flex gap-1">
                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="islem" value="durum"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button class="btn btn-sm btn-light" title="<?= $a['aktif'] ? 'Oylamayı Kapat' : 'Yeniden Aç' ?>"><i class="bi <?= $a['aktif'] ? 'bi-pause-fill' : 'bi-play-fill' ?>"></i></button></form>
                        <form method="post" class="d-inline" data-confirm="Anket ve tüm oylar silinecek."><?= csrf_field() ?><input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <button class="btn btn-sm btn-soft-danger"><i class="bi bi-trash"></i></button></form>
                    </div>
                    <?php endif; ?>
                </div>
                <h5 class="fw-bold mb-1"><?= e($a['baslik']) ?></h5>
                <div class="small text-muted mb-2">
                    <?= e($a['ad_soyad'] ?? 'Yönetim') ?> · Başlangıç: <?= tarih($a['created_at']) ?>
                    <?php if ($a['bitis_tarihi']): ?> · Son Oy: <strong><?= tarih($a['bitis_tarihi']) ?></strong><?php endif; ?>
                </div>
                <?php if ($a['aciklama']): ?><p class="small text-muted mb-3"><?= nl2br(e($a['aciklama'])) ?></p><?php endif; ?>

                <!-- Sonuç Çubukları -->
                <div class="mb-3">
                    <?php foreach ($sec as $s): $oran = $toplamOy > 0 ? round($s['oy'] / $toplamOy * 100) : 0; ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="<?= $seciliOy === (int)$s['id'] ? 'fw-bold text-primary' : '' ?>">
                                    <?php if ($seciliOy === (int)$s['id']): ?><i class="bi bi-check-circle-fill me-1"></i><?php endif; ?>
                                    <?= e($s['secenek']) ?>
                                </span>
                                <strong><?= (int)$s['oy'] ?> oy (%<?= $oran ?>)</strong>
                            </div>
                            <div class="progress" style="height:8px">
                                <div class="progress-bar <?= $seciliOy === (int)$s['id'] ? 'bg-primary' : 'bg-secondary' ?>" style="width:<?= $oran ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($acik && $benimDaireler): ?>
                <form method="post" class="border-top pt-3">
                    <?= csrf_field() ?><input type="hidden" name="islem" value="oyla"><input type="hidden" name="anket_id" value="<?= $a['id'] ?>">
                    <?php if (count($benimDaireler) > 1): ?>
                        <div class="mb-2">
                            <select name="daire_id" class="form-select form-select-sm">
                                <?php foreach ($benimDaireler as $d): ?><option value="<?= $d['id'] ?>"><?= e(daire_etiket($d)) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="daire_id" value="<?= $ilkDaireId ?>">
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($sec as $s): ?>
                            <button name="secenek_id" value="<?= $s['id'] ?>" class="btn btn-sm <?= $seciliOy === (int)$s['id'] ? 'btn-primary' : 'btn-outline-primary' ?>">
                                <i class="bi bi-hand-index-thumb me-1"></i><?= e($s['secenek']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$anketler): ?>
    <div class="col-12"><div class="card"><div class="empty-state"><i class="bi bi-bar-chart-steps"></i><p class="mt-2 mb-0">Henüz aktif bir anket veya oylama bulunmuyor.</p></div></div></div>
<?php endif; ?>
</div>

<?php if ($yonetici): ?>
<div class="modal fade" id="anketModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="olustur">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-bar-chart-steps me-2"></i>Yeni Anket Başlat</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Soru / Başlık *</label><input name="baslik" class="form-control" placeholder="ör. Bina dış cephe boyası hangi renk olsun?" required></div>
                    <div class="mb-3"><label class="form-label">Açıklama</label><textarea name="aciklama" class="form-control" rows="2" placeholder="İsteğe bağlı detay veya teklif tutarları"></textarea></div>
                    <div class="mb-3"><label class="form-label">Bitiş Tarihi <span class="text-muted fw-normal">(isteğe bağlı)</span></label><input type="date" name="bitis_tarihi" class="form-control" min="<?= date('Y-m-d') ?>"></div>
                    <label class="form-label">Seçenekler (En az 2 seçenek girin)</label>
                    <div class="d-grid gap-2">
                        <input name="secenek[]" class="form-control" placeholder="1. Seçenek (zorunlu)" required>
                        <input name="secenek[]" class="form-control" placeholder="2. Seçenek (zorunlu)" required>
                        <input name="secenek[]" class="form-control" placeholder="3. Seçenek (isteğe bağlı)">
                        <input name="secenek[]" class="form-control" placeholder="4. Seçenek (isteğe bağlı)">
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Anketi Başlat</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
