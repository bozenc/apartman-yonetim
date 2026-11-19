<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('log.goruntule');

$kullaniciF = (int)get('k', 0);
$q = get('q', '');
$sayfa = max(1, (int)get('s', 1));
$limit = 50;

$where = ['1=1']; $par = [];
if ($kullaniciF) { $where[] = 'l.kullanici_id = ?'; $par[] = $kullaniciF; }
if ($q !== '') { $where[] = '(l.islem LIKE ? OR l.detay LIKE ?)'; array_push($par, "%$q%", "%$q%"); }
$w = implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) FROM islem_log l WHERE $w");
$st->execute($par);
$toplam = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT l.*, k.ad_soyad FROM islem_log l LEFT JOIN kullanicilar k ON k.id = l.kullanici_id WHERE $w
    ORDER BY l.id DESC LIMIT $limit OFFSET " . (($sayfa - 1) * $limit));
$st->execute($par);
$loglar = $st->fetchAll();
$kullanicilar = $pdo->query('SELECT id, ad_soyad FROM kullanicilar ORDER BY ad_soyad')->fetchAll();

$sayfaBaslik = 'İşlem Kayıtları';
$sayfaAciklama = 'Sistemde yapılan tüm işlemlerin denetim kaydı';
require __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <form class="filter-bar" method="get">
            <select name="k" class="form-select"><option value="">Tüm kullanıcılar</option>
                <?php foreach ($kullanicilar as $k): ?><option value="<?= $k['id'] ?>" <?= $kullaniciF == $k['id'] ? 'selected' : '' ?>><?= e($k['ad_soyad']) ?></option><?php endforeach; ?></select>
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="İşlem veya detay ara…">
            <button class="btn btn-light"><i class="bi bi-funnel"></i></button>
        </form>
        <span class="text-muted small"><?= $toplam ?> kayıt</span>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Tarih</th><th>Kullanıcı</th><th>İşlem</th><th>Detay</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($loglar as $l): ?>
                <tr>
                    <td class="text-nowrap small"><?= tarih($l['created_at'], true) ?></td>
                    <td><?= e($l['ad_soyad'] ?? '—') ?></td>
                    <td><span class="badge-soft primary"><?= e($l['islem']) ?></span></td>
                    <td class="small"><?= e($l['detay']) ?></td>
                    <td class="small text-muted"><code><?= e($l['ip']) ?></code></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$loglar): ?><tr><td colspan="5"><div class="empty-state"><i class="bi bi-clock-history"></i><p class="mt-2 mb-0">Kayıt yok.</p></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($toplam > $limit): ?><div class="card-body d-flex justify-content-end"><?= sayfalama($toplam, $sayfa, $limit) ?></div><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
