<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('ayar.yonet');

$alanlar = [
    'Site Bilgileri' => [
        'site_adi' => ['Site / Apartman Adı', 'text'],
        'adres'    => ['Adres', 'textarea'],
        'telefon'  => ['Telefon', 'text'],
        'eposta'   => ['E-posta', 'email'],
        'vergi_no' => ['Vergi No / Kimlik No', 'text'],
        'iban'     => ['Banka IBAN (sakinlere gösterilir)', 'text'],
    ],
    'Aidat Varsayılanları' => [
        'varsayilan_aidat' => ['Varsayılan Aidat Tutarı (₺)', 'text'],
        'son_odeme_gunu'   => ['Son Ödeme Günü (ayın kaçı)', 'number'],
        'gecikme_orani'    => ['Gecikme Bedeli Oranı (%)', 'text'],
    ],
];

if (is_post()) {
    csrf_check();
    $islem = post('islem', 'kaydet');

    if ($islem === 'yedek_sql' || $islem === 'yedek_zip') {
        $sqlOut = "-- Apartman Yönetim Sistemi Veritabanı Yedeği\n-- Tarih: " . date('d.m.Y H:i:s') . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
        $tablolar = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tablolar as $tb) {
            $cr = $pdo->query("SHOW CREATE TABLE `$tb`")->fetch(PDO::FETCH_NUM);
            $sqlOut .= "DROP TABLE IF EXISTS `$tb`;\n" . ($cr[1] ?? '') . ";\n\n";
            $satirlar = $pdo->query("SELECT * FROM `$tb`");
            foreach ($satirlar as $r) {
                $degerler = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($r));
                $sqlOut .= "INSERT INTO `$tb` VALUES (" . implode(',', $degerler) . ");\n";
            }
            $sqlOut .= "\n";
        }
        $sqlOut .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        logla('Sistem yedeği alındı', $islem === 'yedek_zip' ? 'Tam yedek (ZIP)' : 'Veritabanı (SQL)');

        if ($islem === 'yedek_zip' && class_exists('ZipArchive')) {
            $gecici = tempnam(sys_get_temp_dir(), 'apt_zip_');
            $zip = new ZipArchive();
            if ($zip->open($gecici, ZipArchive::OVERWRITE) === true) {
                $zip->addFromString('veritabani_' . date('Ymd_His') . '.sql', $sqlOut);
                $dekontKlasor = dekont_klasoru();
                if (is_dir($dekontKlasor)) {
                    foreach (glob($dekontKlasor . '/*') ?: [] as $dkDosya) {
                        if (is_file($dkDosya) && basename($dkDosya) !== '.htaccess') {
                            $zip->addFile($dkDosya, 'uploads/dekontlar/' . basename($dkDosya));
                        }
                    }
                }
                $zip->close();
                while (ob_get_level()) ob_end_clean();
                header('Content-Type: application/zip');
                header('Content-Length: ' . filesize($gecici));
                header('Content-Disposition: attachment; filename="apartman_tam_yedek_' . date('Ymd_His') . '.zip"');
                readfile($gecici);
                @unlink($gecici);
                exit;
            }
        }
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Length: ' . strlen($sqlOut));
        header('Content-Disposition: attachment; filename="apartman_veritabani_' . date('Ymd_His') . '.sql"');
        echo $sqlOut;
        exit;
    }

    $st = $pdo->prepare('INSERT INTO ayarlar (anahtar, deger) VALUES (?, ?) ON DUPLICATE KEY UPDATE deger = VALUES(deger)');
    foreach ($alanlar as $grup) {
        foreach ($grup as $k => $_) {
            $v = (string)post($k, '');
            if (in_array($k, ['varsayilan_aidat', 'gecikme_orani'], true)) $v = (string)sayi($v);
            if ($k === 'son_odeme_gunu') $v = (string)min(28, max(1, (int)$v));
            $st->execute([$k, $v]);
        }
    }
    ayar_sifirla();
    logla('Ayarlar güncellendi');
    flash('success', 'Ayarlar kaydedildi.');
    redirect('ayarlar.php');
}

$sayfaBaslik = 'Ayarlar';
$sayfaAciklama = 'Site bilgileri ve aidat varsayılanları';
require __DIR__ . '/includes/header.php';
?>
<form method="post">
    <?= csrf_field() ?>
    <div class="row g-3">
        <?php foreach ($alanlar as $grup => $liste): ?>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h5><?= e($grup) ?></h5></div>
                <div class="card-body">
                    <?php foreach ($liste as $k => [$etiket, $tip]): ?>
                        <div class="mb-3">
                            <label class="form-label"><?= e($etiket) ?></label>
                            <?php if ($tip === 'textarea'): ?>
                                <textarea name="<?= $k ?>" class="form-control" rows="2"><?= e(ayar($k)) ?></textarea>
                            <?php else: ?>
                                <input type="<?= $tip ?>" name="<?= $k ?>" class="form-control" value="<?= e(ayar($k)) ?>" <?= $tip === 'number' ? 'min="1" max="28"' : '' ?>>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($grup === 'Aidat Varsayılanları'): ?>
                        <div class="alert alert-info small mb-0"><i class="bi bi-info-circle me-1"></i>Gecikme bedeli, "Aidat Dönemleri" sayfasından ilgili dönem için manuel olarak uygulanır ve aynı dönem için yalnızca bir kez eklenir.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="mt-3 text-end"><button class="btn btn-primary px-4"><i class="bi bi-check2 me-1"></i>Ayarları Kaydet</button></div>
</form>

<div class="card mt-4">
    <div class="card-header"><h5><i class="bi bi-cloud-arrow-down me-2"></i>Yedekleme</h5></div>
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <strong>Veritabanı ve Dekont Yedeği</strong>
            <div class="small text-muted">Veritabanındaki tüm kayıtların (`.sql`) veya yüklenen dekont dosyalarıyla birlikte tam sistem yedeğinin (`.zip`) bir kopyasını bilgisayarınıza indirin.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="yedek_sql">
                <button class="btn btn-soft-primary"><i class="bi bi-database-down me-1"></i>Veritabanı Yedeği (.sql)</button>
            </form>
            <?php if (class_exists('ZipArchive')): ?>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="yedek_zip">
                <button class="btn btn-primary"><i class="bi bi-file-earmark-zip me-1"></i>Tam Yedek (SQL + Dekontlar .zip)</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
