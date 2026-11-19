<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('rapor.goruntule');

$yil = (int)get('yil', date('Y'));
$sekme = get('sekme', 'ozet');

$yillar = $pdo->query("SELECT DISTINCT YEAR(tarih) y FROM hareketler UNION SELECT YEAR(CURDATE()) ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);

// Devreden bakiye
$st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tur='gelir' THEN tutar ELSE -tutar END),0) FROM hareketler WHERE tarih < ?");
$st->execute(["$yil-01-01"]);
$devreden = (float)$st->fetchColumn();

// Aylık özet
$aylik = array_fill(1, 12, ['gelir' => 0.0, 'gider' => 0.0]);
$st = $pdo->prepare("SELECT MONTH(tarih) ay, tur, SUM(tutar) t FROM hareketler WHERE YEAR(tarih) = ? GROUP BY ay, tur");
$st->execute([$yil]);
foreach ($st as $r) $aylik[(int)$r['ay']][$r['tur']] = (float)$r['t'];

// Kategori × ay
$st = $pdo->prepare("SELECT k.id, k.ad, k.tur, k.renk, MONTH(h.tarih) ay, SUM(h.tutar) t FROM hareketler h
    JOIN kategoriler k ON k.id = h.kategori_id WHERE YEAR(h.tarih) = ? GROUP BY k.id, ay ORDER BY k.tur, k.ad");
$st->execute([$yil]);
$pivot = [];
foreach ($st as $r) {
    $pivot[$r['tur']][$r['id']]['ad'] = $r['ad'];
    $pivot[$r['tur']][$r['id']]['renk'] = $r['renk'];
    $pivot[$r['tur']][$r['id']]['ay'][(int)$r['ay']] = (float)$r['t'];
}

// Daire bazlı borç durumu
$daireRapor = $pdo->query("SELECT d.id, d.blok, d.daire_no, COALESCE(d.sakin_adi, d.malik_adi) ad,
        COALESCE(SUM(b.tutar),0) tahakkuk, COALESCE(SUM(b.odenen),0) odenen,
        COALESCE(SUM(CASE WHEN b.son_odeme < CURDATE() THEN b.tutar - b.odenen END),0) geciken
    FROM daireler d LEFT JOIN borclar b ON b.daire_id = d.id
    GROUP BY d.id ORDER BY d.blok, CAST(d.daire_no AS UNSIGNED)")->fetchAll();

$sayfaBaslik = 'Raporlar';
$sayfaAciklama = $yil . ' yılı mali raporları';
$grafikKullan = true;
require __DIR__ . '/includes/header.php';

$topGelir = array_sum(array_column($aylik, 'gelir'));
$topGider = array_sum(array_column($aylik, 'gider'));
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3 no-print">
    <ul class="nav nav-pills">
        <?php foreach (['ozet' => 'Gelir-Gider Özeti', 'kategori' => 'Kategori Dökümü', 'daire' => 'Daire Borç Durumu'] as $k => $v): ?>
            <li class="nav-item"><a class="nav-link <?= $sekme === $k ? 'active' : '' ?>" href="?yil=<?= $yil ?>&sekme=<?= $k ?>"><?= $v ?></a></li>
        <?php endforeach; ?>
    </ul>
    <div class="d-flex gap-2">
        <form method="get"><input type="hidden" name="sekme" value="<?= e($sekme) ?>">
            <select name="yil" class="form-select" onchange="this.form.submit()"><?php foreach ($yillar as $y): ?><option <?= $y == $yil ? 'selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?></select></form>
        <button class="btn btn-light" onclick="window.print()"><i class="bi bi-printer me-1"></i>Yazdır</button>
    </div>
</div>

<?php if ($sekme === 'ozet'): ?>
<div class="row g-3 mb-3">
    <?php foreach ([['Devreden Bakiye', $devreden, 'bi-box-arrow-in-right', 'info'], ['Yıllık Gelir', $topGelir, 'bi-arrow-down-left', 'success'],
                    ['Yıllık Gider', $topGider, 'bi-arrow-up-right', 'danger'], ['Yıl Sonu Bakiye', $devreden + $topGelir - $topGider, 'bi-safe2', 'primary']] as $s): ?>
    <div class="col-6 col-xl-3"><div class="card stat-card"><div class="stat-icon soft-<?= $s[3] ?>"><i class="bi <?= $s[2] ?>"></i></div>
        <div><div class="stat-label"><?= $s[0] ?></div><div class="stat-value"><?= para($s[1]) ?></div></div></div></div>
    <?php endforeach; ?>
</div>
<div class="card mb-3"><div class="card-header"><h5>Aylık Kasa Seyri</h5></div><div class="card-body"><div style="height:280px"><canvas id="grafikSeyir"></canvas></div></div></div>
<div class="card">
    <div class="card-header"><h5>Aylık Gelir - Gider Tablosu</h5></div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Ay</th><th class="text-end">Gelir</th><th class="text-end">Gider</th><th class="text-end">Net</th><th class="text-end">Bakiye</th></tr></thead>
            <tbody>
            <?php $bakiye = $devreden; $seyir = []; foreach ($aylik as $ay => $v): $net = $v['gelir'] - $v['gider']; $bakiye += $net; $seyir[] = round($bakiye, 2); ?>
                <tr>
                    <td class="fw-semibold"><?= donem_adi(sprintf('%d-%02d', $yil, $ay)) ?></td>
                    <td class="text-end amount-in"><?= para($v['gelir']) ?></td>
                    <td class="text-end amount-out"><?= para($v['gider']) ?></td>
                    <td class="text-end fw-bold <?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= para($net) ?></td>
                    <td class="text-end fw-bold"><?= para($bakiye) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th>Toplam</th><th class="text-end amount-in"><?= para($topGelir) ?></th><th class="text-end amount-out"><?= para($topGider) ?></th>
                <th class="text-end"><?= para($topGelir - $topGider) ?></th><th class="text-end"><?= para($bakiye) ?></th></tr></tfoot>
        </table>
    </div>
</div>
<?php ob_start(); ?>
<script>
(function () {
    let g;
    function ciz() {
        const r = grafikRenk(); Chart.defaults.color = r.metin; Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
        if (g) g.destroy();
        g = new Chart(document.getElementById('grafikSeyir'), {
            data: {
                labels: ['Oca','Şub','Mar','Nis','May','Haz','Tem','Ağu','Eyl','Eki','Kas','Ara'],
                datasets: [
                    { type: 'line', label: 'Bakiye', data: <?= json_encode($seyir) ?>, borderColor: '#4f46e5', backgroundColor: 'rgba(79,70,229,.08)', fill: true, tension: .35, pointRadius: 3, yAxisID: 'y' },
                    { type: 'bar', label: 'Gelir', data: <?= json_encode(array_column($aylik, 'gelir')) ?>, backgroundColor: 'rgba(16,185,129,.7)', borderRadius: 5, maxBarThickness: 16 },
                    { type: 'bar', label: 'Gider', data: <?= json_encode(array_column($aylik, 'gider')) ?>, backgroundColor: 'rgba(244,63,94,.7)', borderRadius: 5, maxBarThickness: 16 }
                ]
            },
            options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, boxWidth: 8 } }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + paraFmt(c.raw) } } },
                scales: { x: { grid: { display: false } }, y: { grid: { color: r.izgara }, ticks: { callback: v => paraFmt(v) } } } }
        });
    }
    ciz(); document.addEventListener('temaDegisti', ciz);
})();
</script>
<?php $sayfaJs = ob_get_clean(); ?>

<?php elseif ($sekme === 'kategori'): ?>
<?php foreach (['gelir' => 'Gelir', 'gider' => 'Gider'] as $tur => $ad): ?>
<div class="card mb-3">
    <div class="card-header"><h5><?= $ad ?> Kategorileri · <?= $yil ?></h5></div>
    <div class="table-responsive">
        <table class="table table-sm small">
            <thead><tr><th>Kategori</th><?php for ($m = 1; $m <= 12; $m++): ?><th class="text-end"><?= ay_adi($m) ?></th><?php endfor; ?><th class="text-end">Toplam</th></tr></thead>
            <tbody>
            <?php $sutun = array_fill(1, 12, 0); foreach ($pivot[$tur] ?? [] as $k): $satir = 0; ?>
                <tr><td class="text-nowrap"><span class="dot me-1" style="background:<?= e($k['renk']) ?>"></span><?= e($k['ad']) ?></td>
                <?php for ($m = 1; $m <= 12; $m++): $v = $k['ay'][$m] ?? 0; $satir += $v; $sutun[$m] += $v; ?>
                    <td class="text-end text-nowrap"><?= $v ? number_format($v, 0, ',', '.') : '<span class="text-muted">-</span>' ?></td>
                <?php endfor; ?>
                <td class="text-end fw-bold text-nowrap"><?= para($satir) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($pivot[$tur])): ?><tr><td colspan="14" class="text-center text-muted py-4">Kayıt yok</td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr><th>Toplam</th><?php for ($m = 1; $m <= 12; $m++): ?><th class="text-end text-nowrap"><?= number_format($sutun[$m], 0, ',', '.') ?></th><?php endfor; ?><th class="text-end text-nowrap"><?= para(array_sum($sutun)) ?></th></tr></tfoot>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php else: ?>
<div class="card">
    <div class="card-header"><h5>Daire Bazlı Borç Durumu (Tüm Zamanlar)</h5></div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Daire</th><th>Oturan</th><th class="text-end">Tahakkuk</th><th class="text-end">Ödenen</th><th class="text-end">Kalan</th><th class="text-end">Gecikmiş</th><th style="min-width:140px">Ödeme Oranı</th></tr></thead>
            <tbody>
            <?php $t = ['tahakkuk' => 0, 'odenen' => 0, 'geciken' => 0]; foreach ($daireRapor as $d):
                foreach ($t as $k => $_) $t[$k] += $d[$k];
                $oran = $d['tahakkuk'] > 0 ? round($d['odenen'] / $d['tahakkuk'] * 100) : 100; ?>
                <tr>
                    <td><a href="<?= url('borclar.php?daire=' . $d['id']) ?>" class="fw-bold"><?= e($d['blok'] . '-' . $d['daire_no']) ?></a></td>
                    <td><?= e(kisi($d['ad']) ?: '-') ?></td>
                    <td class="text-end"><?= para($d['tahakkuk']) ?></td>
                    <td class="text-end text-success"><?= para($d['odenen']) ?></td>
                    <td class="text-end fw-bold"><?= para($d['tahakkuk'] - $d['odenen']) ?></td>
                    <td class="text-end <?= $d['geciken'] > 0 ? 'amount-out' : 'text-muted' ?>"><?= para($d['geciken']) ?></td>
                    <td><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1"><div class="progress-bar <?= $oran >= 90 ? 'bg-success' : ($oran >= 50 ? 'bg-warning' : 'bg-danger') ?>" style="width:<?= $oran ?>%"></div></div><small>%<?= $oran ?></small></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="2">Toplam</th><th class="text-end"><?= para($t['tahakkuk']) ?></th><th class="text-end text-success"><?= para($t['odenen']) ?></th>
                <th class="text-end"><?= para($t['tahakkuk'] - $t['odenen']) ?></th><th class="text-end text-danger"><?= para($t['geciken']) ?></th><th></th></tr></tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
