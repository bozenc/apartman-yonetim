<?php
/**
 * Periyodik Bakım Takvimi & Dijital Doküman / Karar Arşivi
 *  - Sekme 1 (dokuman): Yönetim planı, genel kurul kararları, sözleşmeler ve rapor arşivi (sakinlere açık olanları herkes indirebilir).
 *  - Sekme 2 (bakim): Asansör, yangın tüpü, jeneratör, su deposu, sigorta periyodik bakım ve yenileme takvimi.
 */
require __DIR__ . '/includes/init.php';
$u = giris_zorunlu();
if (!yetki_herhangi(['panel.goruntule', 'bakim.yonet'])) yetki_zorunlu('panel.goruntule');

$yonetici = yetki('bakim.yonet');
$sekme = get('sekme') === 'bakim' ? 'bakim' : 'dokuman';
$dokumanKat = [
    'karar'         => 'Genel Kurul / Yönetim Kararı',
    'yonetim_plani' => 'Yönetim Planı & Kurallar',
    'sozlesme'      => 'Sözleşme & Poliçe',
    'rapor'         => 'Denetim / Faaliyet Raporu',
    'diger'         => 'Diğer Belge',
];

if (is_post()) {
    csrf_check();
    yetki_zorunlu('bakim.yonet');
    $islem = post('islem');

    if ($islem === 'dokuman_ekle') {
        $baslik = trim((string)post('baslik'));
        $kat = array_key_exists(post('kategori'), $dokumanKat) ? post('kategori') : 'karar';
        $aciklama = trim((string)post('aciklama')) ?: null;
        $herkeseAcik = post('herkese_acik') ? 1 : 0;
        if ($baslik === '') {
            flash('danger', 'Belge başlığı zorunludur.');
            redirect('bakimlar.php?sekme=dokuman');
        }
        try {
            $dk = dekont_kaydet($_FILES['dosya'] ?? null, true);
            $boyut = (int)($_FILES['dosya']['size'] ?? 0);
            $pdo->prepare('INSERT INTO dokumanlar (baslik, kategori, aciklama, dosya, dosya_ad, boyut, herkese_acik, kullanici_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([mb_substr($baslik, 0, 150), $kat, $aciklama, $dk['dosya'], $dk['ad'], $boyut, $herkeseAcik, $u['id']]);
            logla('Doküman yüklendi', $baslik);
            flash('success', 'Belge arşive eklendi.');
        } catch (RuntimeException $e) {
            flash('danger', $e->getMessage());
        }
        redirect('bakimlar.php?sekme=dokuman');
    }

    if ($islem === 'dokuman_sil') {
        $id = (int)post('id');
        $st = $pdo->prepare('SELECT * FROM dokumanlar WHERE id = ?');
        $st->execute([$id]);
        if ($d = $st->fetch()) {
            $pdo->prepare('DELETE FROM dokumanlar WHERE id = ?')->execute([$id]);
            if ($d['dosya']) @unlink(dekont_klasoru() . '/' . basename($d['dosya']));
            logla('Doküman silindi', $d['baslik']);
            flash('success', 'Belge silindi.');
        }
        redirect('bakimlar.php?sekme=dokuman');
    }

    if ($islem === 'bakim_kaydet') {
        $id = (int)post('id');
        $baslik = trim((string)post('baslik'));
        $kategori = trim((string)post('kategori', 'Asansör')) ?: 'Genel';
        $firma = trim((string)post('firma')) ?: null;
        $telefon = trim((string)post('telefon')) ?: null;
        $periyot = max(1, min(60, (int)post('periyot_ay', 1)));
        $sonBakim = post('son_bakim') ?: null;
        $sonraki = post('sonraki_bakim') ?: date('Y-m-d', strtotime("+$periyot months"));
        $tutar = post('tutar') !== '' ? sayi(post('tutar')) : null;
        $notlar = trim((string)post('notlar')) ?: null;

        if ($baslik === '') {
            flash('danger', 'Bakım / demirbaş başlığı zorunludur.');
            redirect('bakimlar.php?sekme=bakim');
        }
        if ($id) {
            $pdo->prepare('UPDATE bakimlar SET baslik=?, kategori=?, firma=?, telefon=?, periyot_ay=?, son_bakim=?, sonraki_bakim=?, tutar=?, notlar=? WHERE id=?')
                ->execute([$baslik, $kategori, $firma, $telefon, $periyot, $sonBakim, $sonraki, $tutar, $notlar, $id]);
            flash('success', 'Bakım kaydı güncellendi.');
        } else {
            $pdo->prepare('INSERT INTO bakimlar (baslik, kategori, firma, telefon, periyot_ay, son_bakim, sonraki_bakim, tutar, notlar) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$baslik, $kategori, $firma, $telefon, $periyot, $sonBakim, $sonraki, $tutar, $notlar]);
            flash('success', 'Periyodik bakım takvime eklendi.');
        }
        logla('Bakım kaydedildi', $baslik);
        redirect('bakimlar.php?sekme=bakim');
    }

    if ($islem === 'bakim_tamamla') {
        $id = (int)post('id');
        $st = $pdo->prepare('SELECT * FROM bakimlar WHERE id = ?');
        $st->execute([$id]);
        if ($b = $st->fetch()) {
            $bugun = date('Y-m-d');
            $periyot = max(1, (int)$b['periyot_ay']);
            $sonraki = date('Y-m-d', strtotime("+$periyot months"));
            $pdo->prepare('UPDATE bakimlar SET son_bakim = ?, sonraki_bakim = ? WHERE id = ?')->execute([$bugun, $sonraki, $id]);
            if (post('gider_isle') && (float)$b['tutar'] > 0 && yetki('kasa.ekle')) {
                $katId = (int)$pdo->query("SELECT id FROM kategoriler WHERE tur = 'gider' ORDER BY (ad LIKE '%Bakım%' OR ad LIKE '%Asansör%') DESC, id LIMIT 1")->fetchColumn() ?: 11;
                $pdo->prepare("INSERT INTO hareketler (tur, kategori_id, tutar, tarih, aciklama, kullanici_id) VALUES ('gider', ?, ?, ?, ?, ?)")
                    ->execute([$katId, $b['tutar'], $bugun, $b['baslik'] . ($b['firma'] ? ' · ' . $b['firma'] : ''), $u['id']]);
            }
            logla('Periyodik bakım tamamlandı', $b['baslik'] . ' -> Sonraki: ' . tarih($sonraki));
            flash('success', '"' . $b['baslik'] . '" bakımı tamamlandı olarak işaretlendi. Sonraki bakım tarihi: ' . tarih($sonraki));
        }
        redirect('bakimlar.php?sekme=bakim');
    }

    if ($islem === 'bakim_sil') {
        $id = (int)post('id');
        $pdo->prepare('DELETE FROM bakimlar WHERE id = ?')->execute([$id]);
        logla('Bakım silindi', 'ID: ' . $id);
        flash('success', 'Bakım kaydı silindi.');
        redirect('bakimlar.php?sekme=bakim');
    }
}

$dokumanlar = $pdo->query('SELECT d.*, k.ad_soyad FROM dokumanlar d LEFT JOIN kullanicilar k ON k.id = d.kullanici_id'
    . ($yonetici ? '' : ' WHERE d.herkese_acik = 1') . ' ORDER BY d.id DESC')->fetchAll();

$bakimlar = $pdo->query('SELECT * FROM bakimlar WHERE aktif = 1 ORDER BY sonraki_bakim ASC')->fetchAll();

$sayfaBaslik = 'Bakım Takvimi & Dokümanlar';
$sayfaAciklama = 'Yönetim kararları, resmi belgeler ve bina demirbaş/asansör periyodik bakım takibi';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-pills">
        <li class="nav-item">
            <a class="nav-link <?= $sekme === 'dokuman' ? 'active' : '' ?>" href="?sekme=dokuman">
                <i class="bi bi-folder2-open me-1"></i>Doküman & Karar Arşivi <span class="badge rounded-pill bg-<?= $sekme === 'dokuman' ? 'light text-dark' : 'primary' ?> ms-1"><?= count($dokumanlar) ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $sekme === 'bakim' ? 'active' : '' ?>" href="?sekme=bakim">
                <i class="bi bi-calendar2-week me-1"></i>Periyodik Bakım Takvimi
                <?php if (yaklasan_bakim_sayisi()): ?><span class="badge rounded-pill bg-danger ms-1"><?= yaklasan_bakim_sayisi() ?></span><?php endif; ?>
            </a>
        </li>
    </ul>
    <?php if ($yonetici): ?>
        <?php if ($sekme === 'dokuman'): ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#dokumanModal"><i class="bi bi-cloud-arrow-up me-1"></i>Yeni Belge Yükle</button>
        <?php else: ?>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bakimModal" data-new data-title="Yeni Periyodik Bakım"><i class="bi bi-plus-lg me-1"></i>Yeni Bakım Tanımla</button>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($sekme === 'dokuman'): ?>
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Belge Başlığı</th><th>Kategori</th><th>Yükleyen / Tarih</th><th>Görünürlük</th><th>Boyut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($dokumanlar as $d): ?>
                <tr>
                    <td>
                        <strong><?= e($d['baslik']) ?></strong>
                        <?php if ($d['aciklama']): ?><div class="small text-muted"><?= e($d['aciklama']) ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge-soft primary"><?= e($dokumanKat[$d['kategori']] ?? $d['kategori']) ?></span></td>
                    <td class="small"><?= e($d['ad_soyad'] ?? 'Yönetim') ?><div class="text-muted"><?= tarih($d['created_at']) ?></div></td>
                    <td><?= $d['herkese_acik'] ? '<span class="badge-soft success">Tüm Sakinler</span>' : '<span class="badge-soft warning">Yalnızca Yönetim</span>' ?></td>
                    <td class="small text-muted"><?= $d['boyut'] ? round($d['boyut'] / 1024) . ' KB' : '-' ?></td>
                    <td class="table-actions">
                        <a href="<?= dekont_url('d', (int)$d['id']) ?>" target="_blank" class="btn btn-sm btn-soft-primary"><i class="bi bi-eye me-1"></i>Görüntüle</a>
                        <a href="<?= dekont_url('d', (int)$d['id']) ?>&indir=1" class="btn btn-sm btn-light" title="İndir"><i class="bi bi-download"></i></a>
                        <?php if ($yonetici): ?>
                        <form method="post" class="d-inline" data-confirm="<?= e($d['baslik']) ?> belgesi arşivden silinecek.">
                            <?= csrf_field() ?><input type="hidden" name="islem" value="dokuman_sil"><input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button class="btn btn-sm btn-soft-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$dokumanlar): ?>
                <tr><td colspan="6"><div class="empty-state"><i class="bi bi-folder2-open"></i><p class="mt-2 mb-0">Arşivde henüz belge bulunmuyor.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Demirbaş / Bakım Adı</th><th>Firma / İletişim</th><th>Periyot</th><th>Son Bakım</th><th>Sonraki Bakım</th><th class="text-end">Tahmini Tutar</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($bakimlar as $b):
                $farkGun = (int)floor((strtotime($b['sonraki_bakim']) - strtotime(date('Y-m-d'))) / 86400);
                $rozet = $farkGun < 0
                    ? '<span class="badge-soft danger"><i class="bi bi-exclamation-triangle"></i> ' . abs($farkGun) . ' gün gecikti</span>'
                    : ($farkGun <= 7 ? '<span class="badge-soft warning">' . ($farkGun === 0 ? 'Bugün' : $farkGun . ' gün kaldı') . '</span>' : '<span class="badge-soft success">' . $farkGun . ' gün var</span>');
            ?>
                <tr>
                    <td>
                        <span class="badge-soft secondary me-1"><?= e($b['kategori']) ?></span>
                        <strong><?= e($b['baslik']) ?></strong>
                        <?php if ($b['notlar']): ?><div class="small text-muted"><?= e($b['notlar']) ?></div><?php endif; ?>
                    </td>
                    <td class="small"><?= e($b['firma'] ?: '-') ?><?php if ($b['telefon']): ?><div class="text-muted"><?= e($b['telefon']) ?></div><?php endif; ?></td>
                    <td><?= (int)$b['periyot_ay'] ?> ayda bir</td>
                    <td><?= $b['son_bakim'] ? tarih($b['son_bakim']) : '-' ?></td>
                    <td><strong><?= tarih($b['sonraki_bakim']) ?></strong><div class="mt-1"><?= $rozet ?></div></td>
                    <td class="text-end"><?= $b['tutar'] ? para($b['tutar']) : '-' ?></td>
                    <td class="table-actions">
                        <?php if ($yonetici): ?>
                        <form method="post" class="d-inline" data-confirm="<?= e($b['baslik']) ?> bakımı bugün yapıldı olarak işaretlenecek ve sonraki bakım tarihi <?= (int)$b['periyot_ay'] ?> ay ileriye aktarılacak.">
                            <?= csrf_field() ?><input type="hidden" name="islem" value="bakim_tamamla"><input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button class="btn btn-sm btn-soft-success" title="Bakım Yapıldı İşaretle"><i class="bi bi-check2-circle me-1"></i>Yapıldı</button>
                        </form>
                        <button class="btn btn-sm btn-soft-primary" data-bs-toggle="modal" data-bs-target="#bakimModal" data-title="Bakımı Düzenle"
                            data-edit='<?= e(json_encode(array_merge($b, ['tutar' => $b['tutar'] ? number_format((float)$b['tutar'], 2, ',', '') : '']), JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-pencil"></i></button>
                        <form method="post" class="d-inline" data-confirm="Bu bakım kaydı silinecek.">
                            <?= csrf_field() ?><input type="hidden" name="islem" value="bakim_sil"><input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <button class="btn btn-sm btn-soft-danger"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$bakimlar): ?>
                <tr><td colspan="7"><div class="empty-state"><i class="bi bi-calendar2-check"></i><p class="mt-2 mb-0">Tanımlı periyodik bakım kaydı bulunmuyor.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($yonetici): ?>
<!-- Belge Yükleme Modalı -->
<div class="modal fade" id="dokumanModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?><input type="hidden" name="islem" value="dokuman_ekle">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cloud-arrow-up me-2"></i>Arşive Belge Yükle</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Belge Başlığı *</label><input name="baslik" class="form-control" placeholder="ör. 2026 Olağan Genel Kurul Toplantı Tutanağı" required></div>
                    <div class="mb-3"><label class="form-label">Kategori</label><select name="kategori" class="form-select">
                        <?php foreach ($dokumanKat as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                    <div class="mb-3"><label class="form-label">Açıklama</label><input name="aciklama" class="form-control" maxlength="255" placeholder="İsteğe bağlı kısa açıklama"></div>
                    <div class="mb-3"><label class="form-label">Dosya * <span class="text-muted fw-normal">(PDF, JPG veya PNG · maks. 5 MB)</span></label>
                        <input type="file" name="dosya" class="form-control" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="herkese_acik" value="1" id="dAcik" checked>
                        <label class="form-check-label" for="dAcik">Tüm apartman sakinleri görüntüleyebilsin</label>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Yükle</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Bakım Tanımlama Modalı -->
<div class="modal fade" id="bakimModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="bakim_kaydet"><input type="hidden" name="id">
                <div class="modal-header"><h5 class="modal-title">Yeni Periyodik Bakım</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Demirbaş / Bakım Başlığı *</label><input name="baslik" class="form-control" placeholder="ör. A Blok Asansör Aylık Bakımı" required></div>
                        <div class="col-md-4"><label class="form-label">Kategori</label><input name="kategori" class="form-control" value="Asansör" placeholder="Asansör, Yangın, Su…"></div>
                        <div class="col-md-6"><label class="form-label">Servis Firması</label><input name="firma" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Servis Telefonu</label><input name="telefon" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Periyot (Ay)</label><input type="number" min="1" max="60" name="periyot_ay" class="form-control" value="1" required></div>
                        <div class="col-md-4"><label class="form-label">Son Bakım</label><input type="date" name="son_bakim" class="form-control"></div>
                        <div class="col-md-4"><label class="form-label">Sonraki Bakım *</label><input type="date" name="sonraki_bakim" class="form-control" value="<?= date('Y-m-d', strtotime('+1 month')) ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Tahmini Tutar (₺)</label><input name="tutar" class="form-control" inputmode="decimal"></div>
                        <div class="col-md-6"><label class="form-label">Notlar</label><input name="notlar" class="form-control"></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Kaydet</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
