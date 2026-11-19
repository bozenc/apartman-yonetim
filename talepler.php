<?php
/**
 * Arıza, Talep ve Şikayet (Ticket / İş Emri) Modülü
 *  - Sakinler (talep.gonder): Fotoğraflı arıza/talep oluşturur ve durumunu takip eder.
 *  - Yönetim (talep.yonet): Talepleri inceler, yanıt yazar ve durumunu (Bekliyor / İşlemde / Çözüldü / İptal) günceller.
 */
require __DIR__ . '/includes/init.php';
$u = giris_zorunlu();
if (!yetki_herhangi(['talep.gonder', 'talep.yonet'])) yetki_zorunlu('talep.gonder');

$yonetici = yetki('talep.yonet');
$kategoriler = ['ariza' => 'Arıza / Bakım', 'temizlik' => 'Temizlik / Peyzaj', 'guvenlik' => 'Güvenlik / Otopark', 'oneri' => 'Öneri / Talep', 'diger' => 'Diğer'];
$durumlar = [
    'bekliyor' => ['Bekliyor', 'warning', 'bi-hourglass-split'],
    'islemde'  => ['İşlemde', 'info', 'bi-gear-wide-connected'],
    'cozuldu'  => ['Çözüldü', 'success', 'bi-check2-circle'],
    'iptal'    => ['İptal', 'secondary', 'bi-x-circle'],
];

// Kullanıcının bağlı daireleri
$st = $pdo->prepare('SELECT * FROM daireler WHERE kullanici_id = ? ORDER BY blok, CAST(daire_no AS UNSIGNED)');
$st->execute([$u['id']]);
$benimDaireler = $st->fetchAll();

if (is_post()) {
    csrf_check();
    $islem = post('islem');

    if ($islem === 'olustur') {
        if (!yetki_herhangi(['talep.gonder', 'talep.yonet'])) yetki_zorunlu('talep.gonder');
        $baslik = trim((string)post('baslik'));
        $aciklama = trim((string)post('aciklama'));
        $kat = array_key_exists(post('kategori'), $kategoriler) ? post('kategori') : 'ariza';
        $oncelik = post('oncelik') === 'acil' ? 'acil' : 'normal';
        $daireId = (int)post('daire_id') ?: ($benimDaireler[0]['id'] ?? null);

        if (!$yonetici && $daireId && !in_array($daireId, array_map('intval', array_column($benimDaireler, 'id')), true)) {
            $daireId = $benimDaireler[0]['id'] ?? null;
        }
        if ($baslik === '' || $aciklama === '') {
            flash('danger', 'Başlık ve açıklama alanları zorunludur.');
            redirect('talepler.php');
        }
        try {
            $foto = dekont_kaydet($_FILES['fotograf'] ?? null, false);
            $pdo->prepare('INSERT INTO talepler (daire_id, kullanici_id, kategori, baslik, aciklama, fotograf, oncelik) VALUES (?,?,?,?,?,?,?)')
                ->execute([$daireId, $u['id'], $kat, mb_substr($baslik, 0, 150), $aciklama, $foto['dosya'] ?? null, $oncelik]);
            logla('Talep oluşturuldu', $baslik);
            flash('success', 'Talebiniz yönetime iletildi. Durum güncellendiğinde buradan takip edebilirsiniz.');
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
        }
    }

    if ($islem === 'guncelle' && $yonetici) {
        $id = (int)post('id');
        $yeniDurum = array_key_exists(post('durum'), $durumlar) ? post('durum') : 'islemde';
        $not = trim((string)post('yonetici_not')) ?: null;
        $st = $pdo->prepare('SELECT t.*, d.blok, d.daire_no FROM talepler t LEFT JOIN daireler d ON d.id = t.daire_id WHERE t.id = ?');
        $st->execute([$id]);
        if ($tlp = $st->fetch()) {
            $cozumTarih = $yeniDurum === 'cozuldu' ? date('Y-m-d H:i:s') : $tlp['cozum_tarihi'];
            $pdo->prepare('UPDATE talepler SET durum = ?, yonetici_not = ?, isleyen_id = ?, cozum_tarihi = ? WHERE id = ?')
                ->execute([$yeniDurum, $not, $u['id'], $cozumTarih, $id]);
            logla('Talep güncellendi', '#' . $id . ' · ' . $durumlar[$yeniDurum][0]);

            // Sakine bildirim gönder (daire bağlıysa)
            if (post('bildir') && $tlp['daire_id']) {
                $durumAd = $durumlar[$yeniDurum][0];
                if (eposta_aktif() && ($alicilar = daire_alicilari((int)$tlp['daire_id']))) {
                    $detay = [['Talep', $tlp['baslik']], ['Durum', $durumAd]];
                    if ($not) $detay[] = ['Yönetim Yanıtı', $not];
                    eposta_kuyruga($alicilar, 'Talebiniz güncellendi (' . $durumAd . ') · ' . ayar('site_adi'), fn($ad) => eposta_sablon(
                        'Talebinizin Durumu: ' . $durumAd, selamla($ad),
                        'Yönetime ilettiğiniz arıza/talep kaydının durumu güncellenmiştir.',
                        $detay, 'Taleplerimi Görüntüle', site_kok_url() . '/talepler.php', '', $durumAd, $yeniDurum === 'cozuldu' ? '#059669' : '#4f46e5'
                    ), 'genel', $id);
                }
                if (sms_aktif() || wa_aktif()) {
                    $metin = ayar('site_adi') . ': "' . mb_strimwidth($tlp['baslik'], 0, 45, '…') . '" talebinizin durumu "' . $durumAd . '" olarak güncellendi.' . ($not ? ' Not: ' . mb_strimwidth($not, 0, 70, '…') : '');
                    if (sms_aktif()) mesaj_kuyruga('sms', daire_telefonlari((int)$tlp['daire_id'], 'sms'), $metin, 'genel', $id);
                    if (wa_aktif()) mesaj_kuyruga('whatsapp', daire_telefonlari((int)$tlp['daire_id'], 'whatsapp'), $metin, 'genel', $id);
                }
            }
            flash('success', 'Talep durumu güncellendi.');
        }
    }

    if ($islem === 'sil') {
        $id = (int)post('id');
        $st = $pdo->prepare('SELECT * FROM talepler WHERE id = ?');
        $st->execute([$id]);
        $tlp = $st->fetch();
        if ($tlp && ($yonetici || ((int)$tlp['kullanici_id'] === (int)$u['id'] && $tlp['durum'] === 'bekliyor'))) {
            $pdo->prepare('DELETE FROM talepler WHERE id = ?')->execute([$id]);
            if ($tlp['fotograf']) @unlink(dekont_klasoru() . '/' . basename($tlp['fotograf']));
            logla('Talep silindi', 'ID: ' . $id);
            flash('success', 'Talep kaydı silindi.');
        }
    }
    redirect('talepler.php');
}

$durumF = array_key_exists(get('durum'), $durumlar) ? get('durum') : '';
$where = ['1=1'];
$par = [];
if (!$yonetici) {
    $dIds = array_map('intval', array_column($benimDaireler, 'id'));
    if ($dIds) {
        $where[] = '(t.kullanici_id = ? OR t.daire_id IN (' . implode(',', $dIds) . '))';
        $par[] = $u['id'];
    } else {
        $where[] = 't.kullanici_id = ?';
        $par[] = $u['id'];
    }
}
if ($durumF !== '') {
    $where[] = 't.durum = ?';
    $par[] = $durumF;
}
$w = implode(' AND ', $where);

$sayilar = $pdo->query('SELECT durum, COUNT(*) FROM talepler GROUP BY durum')->fetchAll(PDO::FETCH_KEY_PAIR);
$st = $pdo->prepare("SELECT t.*, d.blok, d.daire_no, k.ad_soyad AS olusturan, y.ad_soyad AS isleyen
    FROM talepler t LEFT JOIN daireler d ON d.id = t.daire_id
    LEFT JOIN kullanicilar k ON k.id = t.kullanici_id LEFT JOIN kullanicilar y ON y.id = t.isleyen_id
    WHERE $w ORDER BY FIELD(t.durum, 'bekliyor', 'islemde', 'cozuldu', 'iptal'), t.oncelik DESC, t.id DESC LIMIT 100");
$st->execute($par);
$talepler = $st->fetchAll();

$tumDaireler = $yonetici ? $pdo->query('SELECT id, blok, daire_no FROM daireler ORDER BY blok, CAST(daire_no AS UNSIGNED)')->fetchAll() : $benimDaireler;

$sayfaBaslik = 'Arıza & Talepler';
$sayfaAciklama = 'Bina arızalarını, bakım ihtiyaçlarını ve talepleri bildirin ve takip edin';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-pills">
        <li class="nav-item"><a class="nav-link <?= $durumF === '' ? 'active' : '' ?>" href="?">Tümü</a></li>
        <?php foreach ($durumlar as $k => [$ad, $renk, $ikon]): ?>
            <li class="nav-item">
                <a class="nav-link <?= $durumF === $k ? 'active' : '' ?>" href="?durum=<?= $k ?>"><?= $ad ?>
                    <?php if ($yonetici): ?><span class="badge rounded-pill bg-<?= $durumF === $k ? 'light text-dark' : $renk ?> ms-1"><?= (int)($sayilar[$k] ?? 0) ?></span><?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#yeniTalepModal"><i class="bi bi-plus-lg me-1"></i>Yeni Arıza / Talep Bildir</button>
</div>

<div class="row g-3">
<?php foreach ($talepler as $t): [$dAd, $dRenk, $dIkon] = $durumlar[$t['durum']]; ?>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                    <div class="d-flex flex-wrap gap-1 align-items-center">
                        <span class="badge-soft <?= $dRenk ?>"><i class="bi <?= $dIkon ?>"></i> <?= $dAd ?></span>
                        <span class="badge-soft secondary"><?= e($kategoriler[$t['kategori']] ?? $t['kategori']) ?></span>
                        <?php if ($t['oncelik'] === 'acil'): ?><span class="badge-soft danger"><i class="bi bi-lightning-fill"></i> Acil</span><?php endif; ?>
                        <?php if ($t['blok']): ?><span class="badge-soft primary"><?= e($t['blok'] . '-' . $t['daire_no']) ?></span><?php endif; ?>
                    </div>
                    <small class="text-muted text-nowrap">#<?= (int)$t['id'] ?> · <?= tarih($t['created_at'], true) ?></small>
                </div>
                <h5 class="fw-bold mb-1"><?= e($t['baslik']) ?></h5>
                <div class="small text-muted mb-2"><i class="bi bi-person"></i> <?= e(kisi($t['olusturan']) ?: 'Sakin') ?></div>
                <p class="mb-3" style="white-space:pre-line"><?= e($t['aciklama']) ?></p>

                <?php if ($t['fotograf']): ?>
                    <div class="mb-3">
                        <a href="<?= dekont_url('t', (int)$t['id']) ?>" target="_blank" class="btn btn-sm btn-light"><i class="bi bi-image me-1"></i>Ekli Fotoğrafı / Belgeyi Görüntüle</a>
                    </div>
                <?php endif; ?>

                <?php if ($t['yonetici_not']): ?>
                    <div class="p-3 rounded-3 small mb-3" style="background:var(--bg);border-left:3px solid var(--brand)">
                        <div class="fw-bold text-primary mb-1"><i class="bi bi-chat-left-quote me-1"></i>Yönetim Yanıtı <?= $t['isleyen'] ? '(' . e($t['isleyen']) . ')' : '' ?>:</div>
                        <div style="white-space:pre-line"><?= e($t['yonetici_not']) ?></div>
                        <?php if ($t['cozum_tarihi']): ?><div class="text-muted mt-1">Çözüm: <?= tarih($t['cozum_tarihi'], true) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="d-flex justify-content-end gap-2">
                    <?php if ($yonetici): ?>
                        <button class="btn btn-sm btn-soft-primary" data-bs-toggle="modal" data-bs-target="#islemModal"
                            data-edit='<?= e(json_encode(['id' => $t['id'], 'durum' => $t['durum'], 'yonetici_not' => $t['yonetici_not'] ?? '', 'baslik_bilgi' => '#' . $t['id'] . ' · ' . $t['baslik']], JSON_UNESCAPED_UNICODE)) ?>'>
                            <i class="bi bi-pencil-square me-1"></i>Yanıtla / Durum Güncelle
                        </button>
                    <?php endif; ?>
                    <?php if ($yonetici || ((int)$t['kullanici_id'] === (int)$u['id'] && $t['durum'] === 'bekliyor')): ?>
                        <form method="post" class="d-inline" data-confirm="Bu talep kaydı silinecek.">
                            <?= csrf_field() ?><input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button class="btn btn-sm btn-soft-danger" title="Sil"><i class="bi bi-trash"></i></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$talepler): ?>
    <div class="col-12"><div class="card"><div class="empty-state"><i class="bi bi-tools"></i><p class="mt-2 mb-0">Kayıtlı arıza veya talep bildirimi bulunmuyor.</p></div></div></div>
<?php endif; ?>
</div>

<!-- Yeni Talep Modalı -->
<div class="modal fade" id="yeniTalepModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="islem" value="olustur">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-tools me-2"></i>Arıza / Talep Bildir</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Kategori</label>
                            <select name="kategori" class="form-select">
                                <?php foreach ($kategoriler as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Öncelik</label>
                            <select name="oncelik" class="form-select">
                                <option value="normal">Normal</option>
                                <option value="acil">Acil (Su kaçağı, asansör vb.)</option>
                            </select>
                        </div>
                        <?php if ($tumDaireler): ?>
                        <div class="col-12">
                            <label class="form-label">İlgili Daire</label>
                            <select name="daire_id" class="form-select">
                                <?php if ($yonetici): ?><option value="">— Ortak Alan / Genel —</option><?php endif; ?>
                                <?php foreach ($tumDaireler as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['blok'] . ' Blok · No ' . $d['daire_no']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <label class="form-label">Başlık *</label>
                            <input name="baslik" class="form-control" maxlength="150" placeholder="ör. 3. kat merdiven otomatı yanmıyor" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Açıklama *</label>
                            <textarea name="aciklama" class="form-control" rows="4" placeholder="Arızanın veya talebin ayrıntılarını yazın…" required></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Fotoğraf / Belge <span class="text-muted fw-normal">(isteğe bağlı · JPG, PNG, PDF)</span></label>
                            <input type="file" name="fotograf" class="form-control" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf">
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-send me-1"></i>Gönder</button></div>
            </form>
        </div>
    </div>
</div>

<?php if ($yonetici): ?>
<!-- Yönetim Durum Güncelleme Modalı -->
<div class="modal fade" id="islemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="guncelle"><input type="hidden" name="id">
                <div class="modal-header"><h5 class="modal-title">Talep Durumunu Güncelle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input name="baslik_bilgi" class="form-control-plaintext fw-bold mb-3" readonly tabindex="-1">
                    <div class="mb-3">
                        <label class="form-label">Durum</label>
                        <select name="durum" class="form-select">
                            <?php foreach ($durumlar as $k => [$ad]): ?><option value="<?= $k ?>"><?= $ad ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Yönetim Yanıtı / Yapılan İşlem</label>
                        <textarea name="yonetici_not" class="form-control" rows="3" placeholder="Sakinin göreceği açıklama (ör. Elektrikçi çağrıldı, ampul değiştirildi)"></textarea>
                    </div>
                    <?php if (herhangi_bildirim_aktif()): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="bildir" value="1" id="tlpBildir" checked>
                        <label class="form-check-label small" for="tlpBildir">Durum değişikliğini daire sakinine bildir (E-posta / SMS / WhatsApp)</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Kaydet</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
