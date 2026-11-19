<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('rol.yonet');

$renkler = ['primary' => 'Mor', 'success' => 'Yeşil', 'danger' => 'Kırmızı', 'warning' => 'Turuncu', 'info' => 'Mavi', 'secondary' => 'Gri'];

if (is_post()) {
    csrf_check();
    $islem = post('islem');

    if ($islem === 'kaydet') {
        $id = (int)post('id');
        $ad = post('ad');
        $aciklama = post('aciklama') ?: null;
        $renk = array_key_exists(post('renk'), $renkler) ? post('renk') : 'primary';
        $secilen = array_map('intval', (array)($_POST['yetkiler'] ?? []));
        if ($ad === '') { flash('danger', 'Rol adı zorunludur.'); redirect('roller.php'); }

        $sistem = 0;
        if ($id) {
            $st = $pdo->prepare('SELECT sistem FROM roller WHERE id = ?');
            $st->execute([$id]);
            $sistem = (int)$st->fetchColumn();
        }
        try {
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare('UPDATE roller SET ad=?, aciklama=?, renk=? WHERE id=?')->execute([$ad, $aciklama, $renk, $id]);
            } else {
                $pdo->prepare('INSERT INTO roller (ad, aciklama, renk) VALUES (?,?,?)')->execute([$ad, $aciklama, $renk]);
                $id = (int)$pdo->lastInsertId();
            }
            if (!$sistem) { // Sistem rolünün yetkileri sabittir (tümü)
                // Yetki yükseltme koruması: kullanıcı yalnızca kendisinde olan yetkileri açıp kapatabilir
                $eski = $pdo->prepare('SELECT yetki_id FROM rol_yetki WHERE rol_id = ?');
                $eski->execute([$id]);
                $eski = array_map('intval', $eski->fetchAll(PDO::FETCH_COLUMN));
                $sonuc = [];
                foreach ($pdo->query('SELECT id, anahtar FROM yetkiler') as $y) {
                    $yid = (int)$y['id'];
                    if (yetki($y['anahtar'])) { if (in_array($yid, $secilen, true)) $sonuc[] = $yid; }
                    elseif (in_array($yid, $eski, true)) $sonuc[] = $yid;
                }
                $pdo->prepare('DELETE FROM rol_yetki WHERE rol_id = ?')->execute([$id]);
                $ins = $pdo->prepare('INSERT INTO rol_yetki (rol_id, yetki_id) VALUES (?, ?)');
                foreach ($sonuc as $y) $ins->execute([$id, $y]);
            }
            $pdo->commit();
            logla('Rol kaydedildi', $ad . ' · ' . count($secilen) . ' yetki');
            flash('success', '"' . $ad . '" rolü kaydedildi.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            flash('danger', $e->getCode() == 23000 ? 'Bu isimde bir rol zaten var.' : 'Rol kaydedilemedi.');
        }
    }

    if ($islem === 'sil') {
        $id = (int)post('id');
        $st = $pdo->prepare('SELECT r.sistem, r.ad, (SELECT COUNT(*) FROM kullanicilar WHERE rol_id = r.id) n FROM roller r WHERE r.id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) redirect('roller.php');
        if ($r['sistem']) flash('danger', 'Sistem rolü silinemez.');
        elseif ($r['n'] > 0) flash('warning', 'Bu role atanmış ' . $r['n'] . ' kullanıcı var. Önce kullanıcıları başka bir role taşıyın.');
        else {
            $pdo->prepare('DELETE FROM roller WHERE id = ?')->execute([$id]);
            logla('Rol silindi', $r['ad']);
            flash('success', 'Rol silindi.');
        }
    }
    redirect('roller.php');
}

$yetkiler = $pdo->query('SELECT * FROM yetkiler ORDER BY sira')->fetchAll();
$yetkiGrup = [];
foreach ($yetkiler as $y) $yetkiGrup[$y['grup']][] = $y;

$roller = $pdo->query('SELECT r.*, (SELECT COUNT(*) FROM kullanicilar k WHERE k.rol_id = r.id) kullanici_sayisi FROM roller r ORDER BY r.id')->fetchAll();
$rolYetki = [];
foreach ($pdo->query('SELECT rol_id, yetki_id FROM rol_yetki') as $r) $rolYetki[$r['rol_id']][] = (int)$r['yetki_id'];

$sayfaBaslik = 'Roller & Yetkiler';
$sayfaAciklama = 'Kullanıcı rollerini oluşturun ve her rolün erişebileceği işlemleri belirleyin';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-end mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#rolModal" data-new data-title="Yeni Rol" id="yeniRolBtn"><i class="bi bi-plus-lg me-1"></i>Yeni Rol</button>
</div>

<div class="row g-3 mb-4">
<?php foreach ($roller as $r): $ys = $r['sistem'] ? array_column($yetkiler, 'id') : ($rolYetki[$r['id']] ?? []); ?>
    <div class="col-md-6 col-xl-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex gap-3 align-items-center">
                        <div class="stat-icon soft-<?= in_array($r['renk'], ['danger', 'success', 'warning', 'info']) ? $r['renk'] : 'primary' ?>"><i class="bi bi-shield-<?= $r['sistem'] ? 'lock' : 'check' ?>"></i></div>
                        <div><h5 class="fw-bold mb-0"><?= e($r['ad']) ?></h5><div class="small text-muted"><?= (int)$r['kullanici_sayisi'] ?> kullanıcı · <?= count($ys) ?>/<?= count($yetkiler) ?> yetki</div></div>
                    </div>
                    <?php if ($r['sistem']): ?><span class="badge-soft danger">Sistem</span><?php endif; ?>
                </div>
                <p class="small text-muted mt-3 mb-3"><?= e($r['aciklama'] ?: '—') ?></p>
                <div class="progress mb-3" style="height:5px"><div class="progress-bar" style="width:<?= count($yetkiler) ? round(count($ys) / count($yetkiler) * 100) : 0 ?>%"></div></div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-soft-primary flex-grow-1" data-bs-toggle="modal" data-bs-target="#rolModal" data-title="Rolü Düzenle: <?= e($r['ad']) ?>" data-sistem="<?= (int)$r['sistem'] ?>"
                        data-edit='<?= e(json_encode(['id' => $r['id'], 'ad' => $r['ad'], 'aciklama' => $r['aciklama'], 'renk' => $r['renk'], 'yetkiler' => $ys], JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-sliders me-1"></i>Yetkileri Düzenle</button>
                    <?php if (!$r['sistem']): ?>
                    <form method="post" data-confirm="&quot;<?= e($r['ad']) ?>&quot; rolü silinecek."><?= csrf_field() ?><input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button class="btn btn-sm btn-soft-danger"><i class="bi bi-trash"></i></button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>

<!-- Yetki matrisi -->
<div class="card">
    <div class="card-header"><h5><i class="bi bi-grid-3x3-gap me-2"></i>Yetki Matrisi</h5></div>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Yetki</th><?php foreach ($roller as $r): ?><th class="text-center"><?= e($r['ad']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($yetkiGrup as $grup => $liste): ?>
                <tr><td colspan="<?= count($roller) + 1 ?>" class="fw-bold small text-uppercase text-muted" style="background:var(--bg)"><?= e($grup) ?></td></tr>
                <?php foreach ($liste as $y): ?>
                <tr><td><?= e($y['ad']) ?> <?= $y['tip'] === 'islem' ? '<span class="badge-soft warning perm-badge">Değiştirme</span>' : '<span class="badge-soft info perm-badge">Görüntüleme</span>' ?></td>
                    <?php foreach ($roller as $r): $var = $r['sistem'] || in_array((int)$y['id'], $rolYetki[$r['id']] ?? [], true); ?>
                        <td class="text-center"><?= $var ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash text-muted"></i>' ?></td>
                    <?php endforeach; ?></tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="rolModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="kaydet"><input type="hidden" name="id">
                <div class="modal-header"><h5 class="modal-title">Yeni Rol</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4"><label class="form-label">Rol Adı *</label><input name="ad" class="form-control" required></div>
                        <div class="col-md-5"><label class="form-label">Açıklama</label><input name="aciklama" class="form-control"></div>
                        <div class="col-md-3"><label class="form-label">Renk</label><select name="renk" class="form-select"><?php foreach ($renkler as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>Rol yetkileri o roldeki tüm kullanıcılara uygulanır. Tek bir kullanıcıya farklı yetki vermek için <strong>Kullanıcılar</strong> sayfasındaki <i class="bi bi-shield-lock"></i> düğmesini kullanın.</div>
                    <div class="alert alert-warning small d-none" id="sistemUyari"><i class="bi bi-lock me-1"></i>Bu bir sistem rolüdür; tüm yetkilere sahiptir ve yetkileri değiştirilemez.</div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong>Yetkiler</strong>
                        <div><button type="button" class="btn btn-sm btn-light" id="saltOkunur"><i class="bi bi-eye me-1"></i>Sadece görüntüleme</button> <button type="button" class="btn btn-sm btn-light" id="tumunuSec">Tümünü seç</button> <button type="button" class="btn btn-sm btn-light" id="hicbiri">Temizle</button></div>
                    </div>
                    <div class="row g-3" id="yetkiKutusu">
                        <?php foreach ($yetkiGrup as $grup => $liste): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="perm-group">
                                <h6><?= e($grup) ?></h6>
                                <?php foreach ($liste as $y): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="yetkiler[]" value="<?= $y['id'] ?>" id="y<?= $y['id'] ?>" data-tip="<?= e($y['tip']) ?>" <?= yetki($y['anahtar']) ? '' : 'data-kilit="1"' ?>>
                                    <label class="form-check-label" for="y<?= $y['id'] ?>"><?= e($y['ad']) ?>
                                        <?= $y['tip'] === 'islem' ? '<span class="badge-soft warning perm-badge">Değiştirme</span>' : '<span class="badge-soft info perm-badge">Görüntüleme</span>' ?><br><code><?= e($y['anahtar']) ?></code></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Kaydet</button></div>
            </form>
        </div>
    </div>
</div>

<?php ob_start(); ?>
<script>
(function () {
    const kutular = () => document.querySelectorAll('#yetkiKutusu input[type=checkbox]');
    document.getElementById('tumunuSec').onclick = () => kutular().forEach(c => { if (!c.disabled) c.checked = true; });
    document.getElementById('hicbiri').onclick = () => kutular().forEach(c => { if (!c.disabled) c.checked = false; });
    document.getElementById('saltOkunur').onclick = () => kutular().forEach(c => { if (!c.disabled) c.checked = c.dataset.tip === 'goruntule' && c.value != <?= (int)$pdo->query("SELECT id FROM yetkiler WHERE anahtar='borc.kendi'")->fetchColumn() ?>; });
    document.addEventListener('click', function (e) {
        const b = e.target.closest('[data-bs-target="#rolModal"]');
        if (!b) return;
        setTimeout(() => {
            const sistem = b.dataset.sistem === '1';
            document.getElementById('sistemUyari').classList.toggle('d-none', !sistem);
            kutular().forEach(c => { c.disabled = sistem || c.dataset.kilit === '1'; if (sistem) c.checked = true; });
        });
    });
})();
</script>
<?php $sayfaJs = ob_get_clean(); ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
