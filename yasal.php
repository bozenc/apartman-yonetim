<?php
/** Yasal metinler (herkese açık): yasal.php?metin=kvkk|gizlilik|cerez|sozlesme|acik_riza */
require __DIR__ . '/includes/init.php';

$anahtar = in_array(get('metin'), YASAL_SIRA, true) ? get('metin') : 'kvkk';
$m = yasal_metin($anahtar);
$adlar = [];
foreach (YASAL_SIRA as $a) $adlar[$a] = yasal_metin($a)['baslik'];

$sadeBaslik = $m['baslik'];
$sadeSag = '<div class="d-flex gap-2"><button class="btn btn-light btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Yazdır</button>'
    . '<a href="' . url(kullanici() ? 'index.php' : 'login.php') . '" class="btn btn-primary btn-sm">' . (kullanici() ? 'Panele dön' : 'Giriş yap') . '</a></div>';
require __DIR__ . '/includes/sade_ust.php';
?>
<nav class="yasal-nav d-print-none">
    <?php foreach ($adlar as $a => $ad): ?><a href="?metin=<?= $a ?>" class="<?= $a === $anahtar ? 'active' : '' ?>"><?= e($ad) ?></a><?php endforeach; ?>
</nav>
<div class="card"><div class="card-body p-4 p-md-5">
    <h2 class="fw-bold mb-1"><?= e($m['baslik']) ?></h2>
    <div class="text-muted small mb-4">Sürüm <?= (int)$m['surum'] ?> · Yürürlük: <?= tarih($m['updated_at']) ?></div>
    <div class="yasal-metin"><?= yasal_html(yasal_doldur($m['icerik'], $m['updated_at'])) ?></div>
</div></div>
<?php require __DIR__ . '/includes/sade_alt.php'; ?>
