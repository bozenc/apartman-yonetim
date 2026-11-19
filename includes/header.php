<?php
$pdo = $GLOBALS['pdo'];  // 403 sayfası fonksiyon içinden de dahil edilebilir
$u = kullanici();
$aktifSayfa = basename($_SERVER['SCRIPT_NAME'], '.php');

/* Menü: [başlık, dosya, ikon, gereken yetki(ler)] */
$menu = [
    'Genel' => [
        ['Kontrol Paneli', 'index', 'bi-grid-1x2', ['panel.goruntule']],
        ['Borçlarım', 'borclarim', 'bi-wallet2', ['borc.kendi']],
        ['Duyurular', 'duyurular', 'bi-megaphone', ['panel.goruntule']],
        ['Arıza & Talepler', 'talepler', 'bi-tools', ['talep.gonder', 'talep.yonet']],
        ['Anket & Oylama', 'anketler', 'bi-bar-chart-steps', ['panel.goruntule']],
        ['Bakım & Dokümanlar', 'bakimlar', 'bi-folder2-open', ['panel.goruntule', 'bakim.yonet']],
    ],
    'Yönetim' => [
        ['Daireler', 'daireler', 'bi-building', ['daire.goruntule']],
        ['Aidat Dönemleri', 'aidat', 'bi-calendar2-check', ['aidat.goruntule']],
        ['Borç & Tahsilat', 'borclar', 'bi-receipt', ['aidat.goruntule']],
        ['Ödeme Bildirimleri', 'bildirimler', 'bi-receipt-cutoff', ['bildirim.onayla', 'odeme.goruntule']],
        ['Tahsilat Geçmişi', 'odemeler', 'bi-cash-coin', ['odeme.goruntule']],
    ],
    'Finans' => [
        ['Gelirler', 'kasa?tur=gelir', 'bi-arrow-down-left-circle', ['kasa.goruntule']],
        ['Giderler', 'kasa?tur=gider', 'bi-arrow-up-right-circle', ['kasa.goruntule']],
        ['Kategoriler', 'kategoriler', 'bi-tags', ['kategori.yonet']],
        ['Raporlar', 'raporlar', 'bi-bar-chart-line', ['rapor.goruntule']],
    ],
    'Sistem' => [
        ['Kullanıcılar', 'kullanicilar', 'bi-people', ['kullanici.goruntule']],
        ['Roller & Yetkiler', 'roller', 'bi-shield-check', ['rol.yonet']],
        ['E-posta', 'eposta', 'bi-envelope-paper', ['eposta.yonet']],
        ['SMS & WhatsApp', 'mesajlar', 'bi-whatsapp', ['mesaj.yonet']],
        ['Yasal Metinler', 'yasal_yonet', 'bi-file-earmark-lock', ['yasal.yonet']],
        ['İşlem Kayıtları', 'loglar', 'bi-clock-history', ['log.goruntule']],
        ['Ayarlar', 'ayarlar', 'bi-gear', ['ayar.yonet']],
    ],
];

function menu_aktif(string $dosya, string $aktif): bool
{
    $parca = explode('?', $dosya);
    if ($parca[0] !== $aktif) return false;
    if (isset($parca[1])) {
        parse_str($parca[1], $q);
        foreach ($q as $k => $v) if (($_GET[$k] ?? 'gelir') !== $v) return false;
    }
    return true;
}

$siteAdi = ayar('site_adi', 'Apartman Yönetimi');
$daireliKullanici = false;
if ($u) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM daireler WHERE kullanici_id = ?');
    $st->execute([$u['id']]);
    $daireliKullanici = (bool)$st->fetchColumn();
}
$basHarf = $u ? mb_strtoupper(mb_substr($u['ad_soyad'], 0, 1)) : '?';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($sayfaBaslik ?? 'Panel') . ' · ' . $siteAdi) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= url('assets/css/style.css') ?>" rel="stylesheet">
    <link rel="manifest" href="<?= url('manifest.json') ?>">
    <meta name="theme-color" content="#4f46e5">
    <script>try{if((localStorage.getItem('tema')||sessionStorage.getItem('tema'))==='dark')document.documentElement.setAttribute('data-bs-theme','dark')}catch(e){}</script>
</head>
<body>
<?php if ($u): ?>
<div class="app">
    <aside class="sidebar" id="sidebar">
        <a href="<?= url('index.php') ?>" class="brand">
            <span class="brand-logo"><i class="bi bi-buildings-fill"></i></span>
            <span class="brand-text">
                <strong><?= e($siteAdi) ?></strong>
                <small>Yönetim Paneli</small>
            </span>
        </a>
        <nav class="side-nav">
            <?php foreach ($menu as $grup => $ogeler):
                $gorunur = array_filter($ogeler, fn($o) => yetki_herhangi($o[3]) && ($o[1] !== 'borclarim' || $daireliKullanici));
                if (!$gorunur) continue; ?>
                <div class="nav-group"><?= e($grup) ?></div>
                <?php foreach ($gorunur as $o): ?>
                    <a href="<?= url(preg_replace('/^([a-z_]+)/', '$1.php', $o[1])) ?>"
                       class="nav-item<?= menu_aktif($o[1], $aktifSayfa) ? ' active' : '' ?>">
                        <i class="bi <?= $o[2] ?>"></i><span><?= e($o[0]) ?></span><?php
                        if ($o[1] === 'bildirimler' && bekleyen_bildirim()): ?><span class="nav-badge"><?= bekleyen_bildirim() ?></span><?php
                        elseif ($o[1] === 'talepler' && yetki('talep.yonet') && bekleyen_talep()): ?><span class="nav-badge"><?= bekleyen_talep() ?></span><?php
                        elseif ($o[1] === 'bakimlar' && yetki('bakim.yonet') && yaklasan_bakim_sayisi()): ?><span class="nav-badge"><?= yaklasan_bakim_sayisi() ?></span><?php
                        endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>
        <div class="side-footer">
            <i class="bi bi-shield-lock"></i> <?= e($u['rol_adi']) ?>
        </div>
    </aside>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="main">
        <header class="topbar">
            <button class="btn-icon d-lg-none" id="sidebarToggle" aria-label="Menü"><i class="bi bi-list"></i></button>
            <div class="topbar-title">
                <h1><?= e($sayfaBaslik ?? 'Panel') ?></h1>
                <?php if (!empty($sayfaAciklama)): ?><p><?= e($sayfaAciklama) ?></p><?php endif; ?>
            </div>
            <div class="topbar-actions">
                <button class="btn-icon" id="themeToggle" title="Tema değiştir"><i class="bi bi-moon-stars"></i></button>
                <div class="dropdown">
                    <button class="user-chip" data-bs-toggle="dropdown">
                        <span class="avatar"><?= e($basHarf) ?></span>
                        <span class="d-none d-md-block text-start">
                            <strong><?= e($u['ad_soyad']) ?></strong>
                            <small><?= e($u['rol_adi']) ?></small>
                        </span>
                        <i class="bi bi-chevron-down small ms-1"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item" href="<?= url('profil.php') ?>"><i class="bi bi-person me-2"></i>Profilim</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= url('logout.php?_csrf=' . csrf_token()) ?>"><i class="bi bi-box-arrow-right me-2"></i>Çıkış Yap</a></li>
                    </ul>
                </div>
            </div>
        </header>
        <div class="content">
            <?php foreach (flashes() as $f): ?>
                <div class="alert alert-<?= e($f['tur']) ?> alert-dismissible fade show" role="alert">
                    <?= e($f['mesaj']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
<?php endif; ?>
