<?php
require __DIR__ . '/includes/init.php';
$u = giris_zorunlu();
if (!yetki('panel.goruntule')) redirect(yetki('borc.kendi') ? 'borclarim.php' : 'profil.php');

$sayfaBaslik = 'Kontrol Paneli';
$sayfaAciklama = 'Hoş geldiniz, ' . $u['ad_soyad'] . ' · ' . tarih(date('Y-m-d'));

$kasaGor  = yetki('kasa.goruntule');
$aidatGor = yetki('aidat.goruntule');
$yonetimGorunumu = $kasaGor || $aidatGor;
$kendiGor = false;
if (yetki('borc.kendi')) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM daireler WHERE kullanici_id = ?');
    $st->execute([$u['id']]);
    $kendiGor = (bool)$st->fetchColumn();
}
$buAyBas = date('Y-m-01');
$buAySon = date('Y-m-t');

$duyurular = $pdo->query('SELECT * FROM duyurular ORDER BY onemli DESC, created_at DESC LIMIT 4')->fetchAll();

if ($yonetimGorunumu) {
    $kasa = $pdo->query("SELECT
            COALESCE(SUM(CASE WHEN tur='gelir' THEN tutar END),0) AS gelir,
            COALESCE(SUM(CASE WHEN tur='gider' THEN tutar END),0) AS gider
        FROM hareketler")->fetch();
    $st = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN tur='gelir' THEN tutar END),0) AS gelir,
            COALESCE(SUM(CASE WHEN tur='gider' THEN tutar END),0) AS gider
        FROM hareketler WHERE tarih BETWEEN ? AND ?");
    $st->execute([$buAyBas, $buAySon]);
    $buAy = $st->fetch();

    $alacak = $pdo->query("SELECT
            COALESCE(SUM(tutar - odenen),0) AS toplam,
            COALESCE(SUM(CASE WHEN son_odeme < CURDATE() THEN tutar - odenen END),0) AS geciken,
            COUNT(DISTINCT CASE WHEN tutar - odenen > 0 THEN daire_id END) AS borclu_daire
        FROM borclar WHERE tutar > odenen")->fetch();

    $daire = $pdo->query("SELECT COUNT(*) AS toplam, SUM(durum='dolu') AS dolu FROM daireler")->fetch();

    // Son 12 ay gelir/gider
    $aylar = [];
    for ($i = 11; $i >= 0; $i--) {
        $k = date('Y-m', strtotime("first day of -$i month"));
        $aylar[$k] = ['gelir' => 0, 'gider' => 0];
    }
    $st = $pdo->prepare("SELECT DATE_FORMAT(tarih,'%Y-%m') AS ay, tur, SUM(tutar) AS toplam
        FROM hareketler WHERE tarih >= ? GROUP BY ay, tur");
    $st->execute([array_key_first($aylar) . '-01']);
    foreach ($st as $r) if (isset($aylar[$r['ay']])) $aylar[$r['ay']][$r['tur']] = (float)$r['toplam'];

    // Bu yıl gider dağılımı
    $st = $pdo->prepare("SELECT k.ad, k.renk, SUM(h.tutar) AS toplam FROM hareketler h
        JOIN kategoriler k ON k.id = h.kategori_id
        WHERE h.tur='gider' AND YEAR(h.tarih) = ? GROUP BY k.id ORDER BY toplam DESC");
    $st->execute([date('Y')]);
    $giderDag = $st->fetchAll();

    // Son aidat dönemi tahsilat oranı
    $sonDonem = $pdo->query("SELECT d.*, COALESCE(SUM(b.tutar),0) AS tahakkuk, COALESCE(SUM(b.odenen),0) AS tahsil
        FROM aidat_donemleri d LEFT JOIN borclar b ON b.donem_id = d.id
        GROUP BY d.id ORDER BY d.donem DESC LIMIT 1")->fetch();

    $enBorclu = $pdo->query("SELECT dr.id, dr.blok, dr.daire_no, COALESCE(dr.sakin_adi, dr.malik_adi) AS ad,
            SUM(b.tutar - b.odenen) AS kalan
        FROM borclar b JOIN daireler dr ON dr.id = b.daire_id
        WHERE b.tutar > b.odenen GROUP BY dr.id ORDER BY kalan DESC LIMIT 6")->fetchAll();

    $sonHareket = $pdo->query("SELECT h.*, k.ad AS kategori, k.renk FROM hareketler h
        JOIN kategoriler k ON k.id = h.kategori_id ORDER BY h.tarih DESC, h.id DESC LIMIT 7")->fetchAll();

    $grafikKullan = true;
}

require __DIR__ . '/includes/header.php';
?>

<?php if (yetki('bildirim.onayla') && bekleyen_bildirim()): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-3">
    <i class="bi bi-receipt-cutoff fs-4"></i>
    <div class="flex-grow-1"><strong><?= bekleyen_bildirim() ?> ödeme bildirimi</strong> onayınızı bekliyor. Sakinlerin yüklediği dekontları inceleyip tahsilatı onaylayın.</div>
    <a href="<?= url('bildirimler.php') ?>" class="btn btn-sm btn-warning">İncele</a>
</div>
<?php endif; ?>

<?php if (yetki('talep.yonet') && bekleyen_talep()): ?>
<div class="alert alert-info d-flex align-items-center gap-3 mb-3">
    <i class="bi bi-tools fs-4"></i>
    <div class="flex-grow-1"><strong><?= bekleyen_talep() ?> arıza / talep bildirimi</strong> bekliyor veya işlemde.</div>
    <a href="<?= url('talepler.php') ?>" class="btn btn-sm btn-primary">Taleplere Git</a>
</div>
<?php endif; ?>

<?php if (yetki('bakim.yonet') && yaklasan_bakim_sayisi()): ?>
<div class="alert alert-danger d-flex align-items-center gap-3 mb-3">
    <i class="bi bi-calendar2-week fs-4"></i>
    <div class="flex-grow-1"><strong><?= yaklasan_bakim_sayisi() ?> periyodik bakımın</strong> tarihi yaklaştı veya geçti.</div>
    <a href="<?= url('bakimlar.php?sekme=bakim') ?>" class="btn btn-sm btn-danger">Takvimi Gör</a>
</div>
<?php endif; ?>

<?php if ($kendiGor):
    $st = $pdo->prepare("SELECT COALESCE(SUM(b.tutar - b.odenen),0) AS kalan,
            COALESCE(SUM(CASE WHEN b.son_odeme < CURDATE() THEN b.tutar - b.odenen END),0) AS geciken
        FROM borclar b JOIN daireler d ON d.id = b.daire_id WHERE d.kullanici_id = ? AND b.tutar > b.odenen");
    $st->execute([$u['id']]);
    $benim = $st->fetch();
?>
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card stat-card">
            <div class="stat-icon soft-warning"><i class="bi bi-wallet2"></i></div>
            <div><div class="stat-label">Dairemin Borcu</div><div class="stat-value"><?= para($benim['kalan']) ?></div>
                <div class="stat-sub"><a href="<?= url('borclarim.php') ?>">Detayları görüntüle →</a></div></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card stat-card">
            <div class="stat-icon soft-danger"><i class="bi bi-exclamation-circle"></i></div>
            <div><div class="stat-label">Gecikmiş Borç</div><div class="stat-value"><?= para($benim['geciken']) ?></div>
                <div class="stat-sub">IBAN: <?= e(ayar('iban')) ?></div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($yonetimGorunumu): ?>
<div class="row g-3 mb-3">
    <?php if ($kasaGor): ?>
    <div class="col-sm-6 col-xl">
        <div class="card stat-card">
            <div class="stat-icon soft-primary"><i class="bi bi-safe2"></i></div>
            <div>
                <div class="stat-label">Kasa Bakiyesi</div>
                <div class="stat-value"><?= para($kasa['gelir'] - $kasa['gider']) ?></div>
                <div class="stat-sub">Toplam gelir − toplam gider</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl">
        <div class="card stat-card">
            <div class="stat-icon soft-success"><i class="bi bi-arrow-down-left"></i></div>
            <div>
                <div class="stat-label">Bu Ay Gelir</div>
                <div class="stat-value"><?= para($buAy['gelir']) ?></div>
                <div class="stat-sub"><?= donem_adi(date('Y-m')) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl">
        <div class="card stat-card">
            <div class="stat-icon soft-danger"><i class="bi bi-arrow-up-right"></i></div>
            <div>
                <div class="stat-label">Bu Ay Gider</div>
                <div class="stat-value"><?= para($buAy['gider']) ?></div>
                <div class="stat-sub"><?= donem_adi(date('Y-m')) ?></div>
            </div>
        </div>
    </div>
    <?php endif; if ($aidatGor): ?>
    <div class="col-sm-6 col-xl">
        <div class="card stat-card">
            <div class="stat-icon soft-warning"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="stat-label">Bekleyen Alacak</div>
                <div class="stat-value"><?= para($alacak['toplam']) ?></div>
                <div class="stat-sub"><span class="text-danger fw-semibold"><?= para($alacak['geciken']) ?></span> gecikmiş · <?= (int)$alacak['borclu_daire'] ?> daire</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($kasaGor): ?>
<div class="row g-3 mb-3">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header">
                <h5>Gelir & Gider (Son 12 Ay)</h5>
                <?php if (yetki('rapor.goruntule')): ?><a href="<?= url('raporlar.php') ?>" class="btn btn-sm btn-soft-primary">Raporlar <i class="bi bi-arrow-right"></i></a><?php endif; ?>
            </div>
            <div class="card-body"><div style="height:300px"><canvas id="grafikAylik"></canvas></div></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header"><h5>Gider Dağılımı (<?= date('Y') ?>)</h5></div>
            <div class="card-body">
                <?php if ($giderDag): ?>
                    <div style="height:200px"><canvas id="grafikGider"></canvas></div>
                    <div class="mt-3">
                        <?php foreach (array_slice($giderDag, 0, 5) as $g): ?>
                            <div class="d-flex justify-content-between align-items-center py-1 small">
                                <span><span class="dot me-2" style="background:<?= e($g['renk']) ?>"></span><?= e($g['ad']) ?></span>
                                <strong><?= para($g['toplam']) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="bi bi-pie-chart"></i><p class="mt-2 mb-0">Bu yıl henüz gider kaydı yok.</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <?php if ($aidatGor): ?>
    <div class="col-lg">
        <div class="card h-100">
            <div class="card-header"><h5>Son Aidat Dönemi</h5></div>
            <div class="card-body">
                <?php if ($sonDonem): $oran = $sonDonem['tahakkuk'] > 0 ? round($sonDonem['tahsil'] / $sonDonem['tahakkuk'] * 100) : 0; ?>
                    <div class="d-flex justify-content-between align-items-end mb-2">
                        <div>
                            <div class="fw-bold fs-5"><?= donem_adi($sonDonem['donem']) ?></div>
                            <div class="text-muted small">Son ödeme: <?= tarih($sonDonem['son_odeme']) ?></div>
                        </div>
                        <div class="fs-3 fw-bold text-primary">%<?= $oran ?></div>
                    </div>
                    <div class="progress mb-3"><div class="progress-bar bg-success" style="width:<?= $oran ?>%"></div></div>
                    <div class="row text-center g-2">
                        <div class="col-6"><div class="p-2 rounded-3 soft-primary"><div class="small">Tahakkuk</div><strong><?= para($sonDonem['tahakkuk']) ?></strong></div></div>
                        <div class="col-6"><div class="p-2 rounded-3 soft-success"><div class="small">Tahsil Edilen</div><strong><?= para($sonDonem['tahsil']) ?></strong></div></div>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="bi bi-calendar-x"></i><p class="mt-2">Henüz aidat dönemi oluşturulmadı.</p>
                        <?php if (yetki('aidat.donem')): ?><a href="<?= url('aidat.php') ?>" class="btn btn-sm btn-primary">Dönem Oluştur</a><?php endif; ?></div>
                <?php endif; ?>
                <hr>
                <div class="d-flex justify-content-between small">
                    <span class="text-muted"><i class="bi bi-building me-1"></i>Daire doluluk</span>
                    <strong><?= (int)$daire['dolu'] ?> / <?= (int)$daire['toplam'] ?></strong>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg">
        <div class="card h-100">
            <div class="card-header"><h5>En Yüksek Borçlu Daireler</h5>
                <a href="<?= url('borclar.php?durum=odenmemis') ?>" class="small">Tümü</a></div>
            <div class="card-body p-0">
                <?php if ($enBorclu): ?>
                <table class="table">
                    <tbody>
                    <?php foreach ($enBorclu as $b): ?>
                        <tr>
                            <td><strong><?= e($b['blok']) ?>-<?= e($b['daire_no']) ?></strong><div class="small text-muted"><?= e(kisi($b['ad']) ?: '-') ?></div></td>
                            <td class="text-end amount-out"><?= para($b['kalan']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty-state"><i class="bi bi-emoji-smile"></i><p class="mt-2 mb-0">Borçlu daire bulunmuyor.</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; if ($kasaGor): ?>
    <div class="col-lg">
        <div class="card h-100">
            <div class="card-header"><h5>Son Hareketler</h5></div>
            <div class="card-body p-0">
                <?php if ($sonHareket): ?>
                <table class="table">
                    <tbody>
                    <?php foreach ($sonHareket as $h): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold small"><span class="dot me-1" style="background:<?= e($h['renk']) ?>"></span><?= e($h['kategori']) ?></div>
                                <div class="small text-muted text-truncate" style="max-width:180px"><?= e($h['aciklama'] ?: '-') ?></div>
                            </td>
                            <td class="text-end">
                                <div class="<?= $h['tur'] === 'gelir' ? 'amount-in' : 'amount-out' ?>"><?= $h['tur'] === 'gelir' ? '+' : '−' ?><?= para($h['tutar']) ?></div>
                                <div class="small text-muted"><?= tarih($h['tarih']) ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div class="empty-state"><i class="bi bi-inbox"></i><p class="mt-2 mb-0">Kayıt yok.</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h5><i class="bi bi-megaphone me-2"></i>Duyurular</h5><a href="<?= url('duyurular.php') ?>" class="small">Tümü</a></div>
    <div class="card-body">
        <?php if ($duyurular): ?>
            <div class="row g-3">
            <?php foreach ($duyurular as $d): ?>
                <div class="col-md-6">
                    <div class="announce<?= $d['onemli'] ? ' important' : '' ?>">
                        <div class="d-flex justify-content-between"><strong><?= e($d['baslik']) ?></strong><small class="text-muted"><?= tarih($d['created_at']) ?></small></div>
                        <div class="small mt-1"><?= nl2br(e(mb_strimwidth($d['icerik'], 0, 220, '…'))) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state py-3"><p class="mb-0">Duyuru bulunmuyor.</p></div>
        <?php endif; ?>
    </div>
</div>

<?php if ($kasaGor):
    $etiketler = array_map(fn($k) => ay_adi((int)substr($k, 5)) . ' ' . substr($k, 2, 2), array_keys($aylar));
    ob_start(); ?>
<script>
(function () {
    let g1, g2;
    function ciz() {
        const r = grafikRenk();
        Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
        Chart.defaults.color = r.metin;
        if (g1) g1.destroy();
        g1 = new Chart(document.getElementById('grafikAylik'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($etiketler, JSON_UNESCAPED_UNICODE) ?>,
                datasets: [
                    { label: 'Gelir', data: <?= json_encode(array_column($aylar, 'gelir')) ?>, backgroundColor: '#10b981', borderRadius: 6, maxBarThickness: 22 },
                    { label: 'Gider', data: <?= json_encode(array_column($aylar, 'gider')) ?>, backgroundColor: '#f43f5e', borderRadius: 6, maxBarThickness: 22 }
                ]
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8 } },
                    tooltip: { callbacks: { label: c => c.dataset.label + ': ' + paraFmt(c.raw) } } },
                scales: { x: { grid: { display: false } }, y: { grid: { color: r.izgara }, ticks: { callback: v => paraFmt(v) } } }
            }
        });
        <?php if (!empty($giderDag)): ?>
        if (g2) g2.destroy();
        g2 = new Chart(document.getElementById('grafikGider'), {
            type: 'doughnut',
            data: { labels: <?= json_encode(array_column($giderDag, 'ad'), JSON_UNESCAPED_UNICODE) ?>,
                datasets: [{ data: <?= json_encode(array_map('floatval', array_column($giderDag, 'toplam'))) ?>,
                    backgroundColor: <?= json_encode(array_column($giderDag, 'renk')) ?>, borderWidth: 0 }] },
            options: { maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false },
                tooltip: { callbacks: { label: c => c.label + ': ' + paraFmt(c.raw) } } } }
        });
        <?php endif; ?>
    }
    ciz();
    document.addEventListener('temaDegisti', ciz);
})();
</script>
<?php $sayfaJs = ob_get_clean(); endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
