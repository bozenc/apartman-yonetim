<?php
/** Panel dışı sade sayfa üst kısmı (yasal metinler, onay ekranı). $sadeBaslik kullanılır. */
$siteAdi = ayar('site_adi', 'Apartman Yönetimi');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($sadeBaslik ?? '') ?> · <?= e($siteAdi) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
    <script>try{if((localStorage.getItem('tema')||sessionStorage.getItem('tema'))==='dark')document.documentElement.setAttribute('data-bs-theme','dark')}catch(e){}</script>
</head>
<body>
<div class="yasal-wrap">
    <div class="yasal-ust">
        <a href="<?= url(kullanici() ? 'index.php' : 'login.php') ?>" class="d-flex align-items-center gap-2 text-reset">
            <span class="brand-logo"><i class="bi bi-buildings-fill"></i></span><strong class="fs-5"><?= e($siteAdi) ?></strong></a>
        <?php if (!empty($sadeSag)) echo $sadeSag; ?>
    </div>
