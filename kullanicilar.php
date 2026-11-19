<?php
require __DIR__ . '/includes/init.php';
yetki_zorunlu('kullanici.goruntule');
$ben = kullanici();
$benSuper = (int)$ben['rol_sistem'] === 1;

/** Aktif süper yönetici sayısı */
function super_sayisi(PDO $pdo, int $haric = 0): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM kullanicilar k JOIN roller r ON r.id = k.rol_id WHERE r.sistem = 1 AND k.aktif = 1 AND k.id <> ?');
    $st->execute([$haric]);
    return (int)$st->fetchColumn();
}

if (is_post()) {
    csrf_check();
    $islem = post('islem');

    if ($islem === 'kaydet') {
        $id = (int)post('id');
        yetki_zorunlu($id ? 'kullanici.duzenle' : 'kullanici.ekle');
        $ad = post('ad_soyad');
        $kadi = mb_strtolower(post('kullanici_adi'));
        $eposta = post('eposta') ?: null;
        $tel = post('telefon') ?: null;
        $rol = (int)post('rol_id');
        $aktif = post('aktif') ? 1 : 0;
        $sifre = (string)($_POST['sifre'] ?? '');
        $daireId = (int)post('daire_id');

        $st = $pdo->prepare('SELECT * FROM roller WHERE id = ?');
        $st->execute([$rol]);
        $rolKayit = $st->fetch();

        $hedef = null;
        if ($id) {
            $st = $pdo->prepare('SELECT k.*, r.sistem FROM kullanicilar k JOIN roller r ON r.id = k.rol_id WHERE k.id = ?');
            $st->execute([$id]);
            $hedef = $st->fetch();
        }

        $hata = null;
        if ($ad === '' || !preg_match('/^[a-z0-9._-]{3,60}$/', $kadi)) $hata = 'Ad soyad zorunludur. Kullanıcı adı en az 3 karakter olmalı ve yalnızca a-z, 0-9, nokta, tire içermelidir.';
        elseif ($eposta && !filter_var($eposta, FILTER_VALIDATE_EMAIL)) $hata = 'Geçerli bir e-posta adresi girin.';
        elseif (!$rolKayit) $hata = 'Geçerli bir rol seçin.';
        elseif (!$benSuper && ((int)$rolKayit['sistem'] === 1 || ($hedef && (int)$hedef['sistem'] === 1))) $hata = 'Süper Yönetici hesaplarını yalnızca Süper Yöneticiler yönetebilir.';
        elseif (!$id && strlen($sifre) < 8) $hata = 'Şifre en az 8 karakter olmalıdır.';
        elseif ($id && $sifre !== '' && strlen($sifre) < 8) $hata = 'Yeni şifre en az 8 karakter olmalıdır.';
        elseif ($id === (int)$ben['id'] && !$aktif) $hata = 'Kendi hesabınızı pasife alamazsınız.';
        elseif ($hedef && (int)$hedef['sistem'] === 1 && ((int)$rolKayit['sistem'] !== 1 || !$aktif) && super_sayisi($pdo, $id) === 0) $hata = 'Sistemde en az bir aktif Süper Yönetici kalmalıdır.';
        elseif (!$benSuper && (!$hedef || (int)$hedef['rol_id'] !== $rol) && ($fazla = rol_fazla_yetki($pdo, $rol))) $hata = 'Bu rolü atayamazsınız; sizde olmayan yetkiler içeriyor: ' . implode(', ', $fazla);

        if ($hata) { flash('danger', $hata); redirect('kullanicilar.php'); }

        try {
            $pdo->beginTransaction();
            if ($id) {
                $pdo->prepare('UPDATE kullanicilar SET ad_soyad=?, kullanici_adi=?, eposta=?, telefon=?, rol_id=?, aktif=? WHERE id=?')
                    ->execute([$ad, $kadi, $eposta, $tel, $rol, $aktif, $id]);
                if ($sifre !== '') $pdo->prepare('UPDATE kullanicilar SET sifre=? WHERE id=?')->execute([password_hash($sifre, PASSWORD_DEFAULT), $id]);
                logla('Kullanıcı güncellendi', $kadi);
                flash('success', 'Kullanıcı güncellendi.');
            } else {
                $pdo->prepare('INSERT INTO kullanicilar (ad_soyad, kullanici_adi, eposta, telefon, sifre, rol_id, aktif) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$ad, $kadi, $eposta, $tel, password_hash($sifre, PASSWORD_DEFAULT), $rol, $aktif]);
                $id = (int)$pdo->lastInsertId();
                logla('Kullanıcı eklendi', $kadi . ' (' . $rolKayit['ad'] . ')');
                flash('success', 'Yeni kullanıcı oluşturuldu.');
            }
            // Daire bağlantısı (çoklu daire bağlantısını bozmadan güncelle)
            $mevcutDaireler = $pdo->prepare('SELECT id FROM daireler WHERE kullanici_id = ? ORDER BY id');
            $mevcutDaireler->execute([$id]);
            $mevcutIds = array_map('intval', $mevcutDaireler->fetchAll(PDO::FETCH_COLUMN));
            if (!$daireId) {
                $pdo->prepare('UPDATE daireler SET kullanici_id = NULL WHERE kullanici_id = ?')->execute([$id]);
            } elseif (!in_array($daireId, $mevcutIds, true)) {
                if (count($mevcutIds) === 1) {
                    $pdo->prepare('UPDATE daireler SET kullanici_id = NULL WHERE id = ?')->execute([$mevcutIds[0]]);
                }
                $pdo->prepare('UPDATE daireler SET kullanici_id = ? WHERE id = ?')->execute([$id, $daireId]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            flash('danger', $e->getCode() == 23000 ? 'Bu kullanıcı adı zaten kullanılıyor.' : 'Kayıt sırasında hata oluştu.');
        }
    }

    if ($islem === 'yetki') {
        yetki_zorunlu('kullanici.yetki');
        $id = (int)post('id');
        $st = $pdo->prepare('SELECT k.*, r.sistem FROM kullanicilar k JOIN roller r ON r.id = k.rol_id WHERE k.id = ?');
        $st->execute([$id]);
        $h = $st->fetch();
        if (!$h) redirect('kullanicilar.php');
        if ((int)$h['sistem'] === 1) { flash('info', 'Süper Yönetici zaten tüm yetkilere sahiptir.'); redirect('kullanicilar.php'); }
        if ($id === (int)$ben['id'] && !$benSuper) { flash('danger', 'Kendi yetkilerinizi değiştiremezsiniz.'); redirect('kullanicilar.php'); }

        $secim = (array)($_POST['y'] ?? []);
        $mevcut = $pdo->prepare('SELECT yetki_id, izin FROM kullanici_yetki WHERE kullanici_id = ?');
        $mevcut->execute([$id]);
        $mevcut = $mevcut->fetchAll(PDO::FETCH_KEY_PAIR);
        $tum = $pdo->query('SELECT id, anahtar FROM yetkiler')->fetchAll(PDO::FETCH_KEY_PAIR);

        $pdo->beginTransaction();
        $sil = $pdo->prepare('DELETE FROM kullanici_yetki WHERE kullanici_id = ? AND yetki_id = ?');
        $ekle = $pdo->prepare('INSERT INTO kullanici_yetki (kullanici_id, yetki_id, izin) VALUES (?,?,?) ON DUPLICATE KEY UPDATE izin = VALUES(izin)');
        $atlanan = 0; $ver = 0; $engel = 0;
        foreach ($tum as $yid => $anahtar) {
            $eski = array_key_exists($yid, $mevcut) ? ((int)$mevcut[$yid] ? 'ver' : 'engelle') : 'rol';
            $deger = $secim[$yid] ?? $eski; // gönderilmeyen (kilitli) alanlar değişmez
            if (!in_array($deger, ['rol', 'ver', 'engelle'], true)) $deger = $eski;
            if ($deger === $eski) { if ($deger === 'ver') $ver++; if ($deger === 'engelle') $engel++; continue; }
            // Yetki yükseltme koruması: sahip olmadığınız bir yetkiyi veremez / değiştiremezsiniz
            if (!yetki($anahtar)) { $atlanan++; continue; }
            if ($deger === 'ver')          { $ekle->execute([$id, $yid, 1]); $ver++; }
            elseif ($deger === 'engelle')  { $ekle->execute([$id, $yid, 0]); $engel++; }
            else                           { $sil->execute([$id, $yid]); }
        }
        $pdo->commit();
        logla('Kullanıcı yetkileri', $h['kullanici_adi'] . " · +$ver izin, -$engel engel");
        flash('success', $h['ad_soyad'] . " için özel yetkiler kaydedildi ($ver ek izin, $engel engel)." . ($atlanan ? " Sizde olmayan $atlanan yetki değiştirilmedi." : ''));
    }

    if ($islem === 'sil') {
        yetki_zorunlu('kullanici.sil');
        $id = (int)post('id');
        $st = $pdo->prepare('SELECT k.*, r.sistem FROM kullanicilar k JOIN roller r ON r.id = k.rol_id WHERE k.id = ?');
        $st->execute([$id]);
        $h = $st->fetch();
        if (!$h) redirect('kullanicilar.php');
        if ($id === (int)$ben['id']) flash('danger', 'Kendi hesabınızı silemezsiniz.');
        elseif ((int)$h['sistem'] === 1 && !$benSuper) flash('danger', 'Süper Yönetici hesaplarını yalnızca Süper Yöneticiler silebilir.');
        elseif ((int)$h['sistem'] === 1 && super_sayisi($pdo, $id) === 0) flash('danger', 'Son Süper Yönetici silinemez.');
        else {
            $pdo->prepare('DELETE FROM kullanicilar WHERE id = ?')->execute([$id]);
            logla('Kullanıcı silindi', $h['kullanici_adi']);
            flash('success', 'Kullanıcı silindi.');
        }
    }
    redirect('kullanicilar.php');
}

$rolF = (int)get('rol', 0);
$q = get('q', '');
$where = ['1=1']; $par = [];
if ($rolF) { $where[] = 'k.rol_id = ?'; $par[] = $rolF; }
if ($q !== '') { $where[] = '(k.ad_soyad LIKE ? OR k.kullanici_adi LIKE ? OR k.eposta LIKE ?)'; array_push($par, "%$q%", "%$q%", "%$q%"); }
$st = $pdo->prepare('SELECT k.*, r.ad rol_adi, r.renk, r.sistem,
        (SELECT COUNT(*) FROM kullanici_yetki ky WHERE ky.kullanici_id = k.id) ozel_sayi,
        (SELECT d.id FROM daireler d WHERE d.kullanici_id = k.id ORDER BY d.id LIMIT 1) daire_id,
        (SELECT GROUP_CONCAT(CONCAT(d.blok, "-", d.daire_no) ORDER BY d.blok, CAST(d.daire_no AS UNSIGNED) SEPARATOR ", ") FROM daireler d WHERE d.kullanici_id = k.id) daire
    FROM kullanicilar k JOIN roller r ON r.id = k.rol_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id, k.ad_soyad');
$st->execute($par);
$kullanicilar = $st->fetchAll();

$roller = $pdo->query('SELECT r.*, (SELECT COUNT(*) FROM kullanicilar k WHERE k.rol_id = r.id) adet FROM roller r ORDER BY r.id')->fetchAll();
$daireler = $pdo->query('SELECT id, blok, daire_no, COALESCE(sakin_adi, malik_adi) ad FROM daireler ORDER BY blok, CAST(daire_no AS UNSIGNED)')->fetchAll();

// Kullanıcıya özel yetki ekranı verileri
$yetkiler = $pdo->query('SELECT * FROM yetkiler ORDER BY sira')->fetchAll();
$yetkiGrup = [];
foreach ($yetkiler as $y) $yetkiGrup[$y['grup']][] = $y;
$rolYetki = [];
foreach ($pdo->query('SELECT rol_id, yetki_id FROM rol_yetki') as $r) $rolYetki[$r['rol_id']][] = (int)$r['yetki_id'];
$ozel = [];
foreach ($pdo->query('SELECT kullanici_id, yetki_id, izin FROM kullanici_yetki') as $r) $ozel[$r['kullanici_id']][$r['yetki_id']] = (int)$r['izin'] ? 'ver' : 'engelle';
$benimYetkiIdleri = array_map('intval', array_keys(array_filter(array_column($yetkiler, 'anahtar', 'id'), fn($a) => yetki($a))));

$sayfaBaslik = 'Kullanıcılar';
$sayfaAciklama = 'Kullanıcı ekleyin, rol atayın ve her kullanıcının neyi görüp neyi değiştirebileceğini belirleyin';
require __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-3">
    <?php foreach ($roller as $r): ?>
    <div class="col-6 col-md-4 col-xl">
        <a href="?rol=<?= $r['id'] ?>" class="card stat-card text-reset <?= $rolF == $r['id'] ? 'border-primary' : '' ?>">
            <div class="stat-icon soft-<?= in_array($r['renk'], ['danger', 'success', 'warning', 'info']) ? $r['renk'] : 'primary' ?>"><i class="bi bi-person-badge"></i></div>
            <div><div class="stat-label"><?= e($r['ad']) ?></div><div class="stat-value"><?= (int)$r['adet'] ?></div></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">
        <form class="filter-bar" method="get">
            <input type="search" name="q" value="<?= e($q) ?>" class="form-control" placeholder="İsim, kullanıcı adı, e-posta…">
            <select name="rol" class="form-select"><option value="">Tüm roller</option>
                <?php foreach ($roller as $r): ?><option value="<?= $r['id'] ?>" <?= $rolF == $r['id'] ? 'selected' : '' ?>><?= e($r['ad']) ?></option><?php endforeach; ?></select>
            <button class="btn btn-light"><i class="bi bi-funnel"></i></button>
        </form>
        <?php if (yetki('kullanici.ekle')): ?>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#kullaniciModal" data-new data-title="Yeni Kullanıcı"><i class="bi bi-person-plus me-1"></i>Yeni Kullanıcı</button>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Kullanıcı</th><th>İletişim</th><th>Rol</th><th>Daire</th><th>Durum</th><th>Özel Yetki</th><th>Son Giriş</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($kullanicilar as $k): ?>
                <tr>
                    <td><div class="d-flex align-items-center gap-2"><span class="avatar"><?= e(mb_strtoupper(mb_substr($k['ad_soyad'], 0, 1))) ?></span>
                        <div><strong><?= e($k['ad_soyad']) ?></strong> <?php if ($k['id'] == $ben['id']): ?><span class="badge-soft info">Siz</span><?php endif; ?><div class="small text-muted">@<?= e($k['kullanici_adi']) ?></div></div></div></td>
                    <td class="small"><?= e($k['eposta'] ?: '-') ?><div class="text-muted"><?= e($k['telefon']) ?></div></td>
                    <td><span class="badge-soft <?= e($k['renk']) ?>"><?= e($k['rol_adi']) ?></span></td>
                    <td><?= e($k['daire'] ?: '-') ?></td>
                    <td><?= $k['aktif'] ? '<span class="badge-soft success">Aktif</span>' : '<span class="badge-soft secondary">Pasif</span>' ?></td>
                    <td><?php if ($k['sistem']): ?><span class="badge-soft danger">Tümü</span><?php elseif ($k['ozel_sayi']): ?><span class="badge-soft warning"><i class="bi bi-sliders"></i> <?= (int)$k['ozel_sayi'] ?> istisna</span><?php else: ?><span class="text-muted small">Rol varsayılanı</span><?php endif; ?></td>
                    <td class="small text-muted"><?= $k['son_giris'] ? tarih($k['son_giris'], true) : 'Hiç' ?></td>
                    <td class="table-actions">
                        <?php if (yetki('kullanici.yetki') && !$k['sistem'] && ($benSuper || $k['id'] != $ben['id'])): ?>
                        <button class="btn btn-sm btn-soft-success" data-bs-toggle="modal" data-bs-target="#yetkiModal" title="Görebileceği / yapabileceği işlemler"
                            data-yetki='<?= e(json_encode(['id' => $k['id'], 'ad' => $k['ad_soyad'], 'rol' => (int)$k['rol_id'], 'rolAd' => $k['rol_adi'], 'ozel' => $ozel[$k['id']] ?? new stdClass()], JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-shield-lock"></i></button>
                        <?php endif; ?>
                        <?php if (yetki('kullanici.duzenle') && ($benSuper || !$k['sistem'])): ?>
                        <button class="btn btn-sm btn-soft-primary" data-bs-toggle="modal" data-bs-target="#kullaniciModal" data-title="Kullanıcıyı Düzenle"
                            data-edit='<?= e(json_encode(['id' => $k['id'], 'ad_soyad' => $k['ad_soyad'], 'kullanici_adi' => $k['kullanici_adi'], 'eposta' => $k['eposta'], 'telefon' => $k['telefon'], 'rol_id' => $k['rol_id'], 'aktif' => $k['aktif'], 'daire_id' => $k['daire_id']], JSON_UNESCAPED_UNICODE)) ?>'><i class="bi bi-pencil"></i></button>
                        <?php endif; ?>
                        <?php if (yetki('kullanici.sil') && ($benSuper || !$k['sistem']) && $k['id'] != $ben['id']): ?>
                        <form method="post" class="d-inline" data-confirm="<?= e($k['ad_soyad']) ?> adlı kullanıcı silinecek."><?= csrf_field() ?>
                            <input type="hidden" name="islem" value="sil"><input type="hidden" name="id" value="<?= $k['id'] ?>">
                            <button class="btn btn-sm btn-soft-danger"><i class="bi bi-trash"></i></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$kullanicilar): ?><tr><td colspan="8"><div class="empty-state"><i class="bi bi-people"></i><p class="mt-2 mb-0">Kullanıcı bulunamadı.</p></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (yetki_herhangi(['kullanici.ekle', 'kullanici.duzenle'])): ?>
<div class="modal fade" id="kullaniciModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post" autocomplete="off">
                <?= csrf_field() ?><input type="hidden" name="islem" value="kaydet"><input type="hidden" name="id">
                <div class="modal-header"><h5 class="modal-title">Yeni Kullanıcı</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Ad Soyad *</label><input name="ad_soyad" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Kullanıcı Adı *</label><input name="kullanici_adi" class="form-control" required pattern="[a-zA-Z0-9._\-]{3,60}"></div>
                        <div class="col-md-6"><label class="form-label">E-posta</label><input type="email" name="eposta" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Telefon</label><input name="telefon" class="form-control"></div>
                        <div class="col-md-6"><label class="form-label">Rol *</label><select name="rol_id" class="form-select" required>
                            <?php foreach ($roller as $r): if ($r['sistem'] && !$benSuper) continue; ?><option value="<?= $r['id'] ?>" <?= $r['id'] == 5 ? 'selected' : '' ?>><?= e($r['ad']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label">Şifre</label><input type="password" name="sifre" class="form-control" minlength="8" autocomplete="new-password">
                            <div class="form-text">Yeni kullanıcıda zorunlu (min. 8). Düzenlemede boş bırakılırsa değişmez.</div></div>
                        <div class="col-md-6"><label class="form-label">Bağlı Daire</label><select name="daire_id" class="form-select"><option value="">— Yok —</option>
                            <?php foreach ($daireler as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['blok'] . '-' . $d['daire_no'] . ($d['ad'] ? ' · ' . kisi($d['ad']) : '')) ?></option><?php endforeach; ?></select>
                            <div class="form-text">Sakin rolündeki kullanıcı bu dairenin borçlarını görür.</div></div>
                        <div class="col-md-6 d-flex align-items-center"><div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="aktif" value="1" id="aktif" checked><label class="form-check-label" for="aktif">Hesap aktif</label></div></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Kaydet</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (yetki('kullanici.yetki')): ?>
<div class="modal fade" id="yetkiModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem" value="yetki"><input type="hidden" name="id" id="ykId">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-0"><i class="bi bi-shield-lock me-2"></i><span id="ykAd"></span> · Özel Yetkiler</h5>
                        <div class="small text-muted mt-1">Rol: <strong id="ykRol"></strong> — Her yetki için rolden gelen ayarı kullanabilir, ek izin verebilir veya engelleyebilirsiniz.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex flex-wrap gap-2 align-items-center mb-3 p-3 rounded-3 soft-primary">
                        <strong class="me-2 small">Hızlı şablon:</strong>
                        <button type="button" class="btn btn-sm btn-light" data-sablon="sifirla"><i class="bi bi-arrow-counterclockwise me-1"></i>Rol varsayılanına dön</button>
                        <button type="button" class="btn btn-sm btn-light" data-sablon="saltokunur"><i class="bi bi-eye me-1"></i>Salt okunur yap (değişiklik yapamaz)</button>
                        <button type="button" class="btn btn-sm btn-light" data-sablon="kendi"><i class="bi bi-house me-1"></i>Yalnızca kendi borcunu görsün</button>
                        <button type="button" class="btn btn-sm btn-light" data-sablon="tumborc"><i class="bi bi-buildings me-1"></i>Tüm dairelerin borçlarını görsün</button>
                        <span class="ms-auto small"><span class="badge-soft success" id="ykAcik">0</span> açık yetki</span>
                    </div>
                    <div class="table-responsive">
                    <table class="table table-sm align-middle perm-table">
                        <thead><tr><th>Yetki</th><th>Tür</th><th class="text-center">Rolde</th><th class="text-center" style="width:290px">Bu kullanıcı için</th><th class="text-center">Sonuç</th></tr></thead>
                        <tbody>
                        <?php foreach ($yetkiGrup as $grup => $liste): ?>
                            <tr class="perm-grup"><td colspan="5"><?= e($grup) ?></td></tr>
                            <?php foreach ($liste as $y): $kilit = !in_array((int)$y['id'], $benimYetkiIdleri, true); ?>
                            <tr data-yid="<?= $y['id'] ?>">
                                <td><?= e($y['ad']) ?><div><code class="small text-muted"><?= e($y['anahtar']) ?></code></div></td>
                                <td><?= $y['tip'] === 'islem' ? '<span class="badge-soft warning"><i class="bi bi-pencil"></i> Değiştirme</span>' : '<span class="badge-soft info"><i class="bi bi-eye"></i> Görüntüleme</span>' ?></td>
                                <td class="text-center rol-hucre"></td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm tri" role="group" <?= $kilit ? 'title="Sizde olmayan bir yetkiyi değiştiremezsiniz"' : '' ?>>
                                        <input type="radio" class="btn-check" name="y[<?= $y['id'] ?>]" id="r<?= $y['id'] ?>a" value="rol" <?= $kilit ? 'disabled' : '' ?>>
                                        <label class="btn btn-outline-secondary" for="r<?= $y['id'] ?>a">Rolden</label>
                                        <input type="radio" class="btn-check" name="y[<?= $y['id'] ?>]" id="r<?= $y['id'] ?>b" value="ver" <?= $kilit ? 'disabled' : '' ?>>
                                        <label class="btn btn-outline-success" for="r<?= $y['id'] ?>b"><i class="bi bi-check-lg"></i> İzin ver</label>
                                        <input type="radio" class="btn-check" name="y[<?= $y['id'] ?>]" id="r<?= $y['id'] ?>c" value="engelle" <?= $kilit ? 'disabled' : '' ?>>
                                        <label class="btn btn-outline-danger" for="r<?= $y['id'] ?>c"><i class="bi bi-x-lg"></i> Engelle</label>
                                    </div>
                                    <?php if ($kilit): ?><i class="bi bi-lock text-muted ms-1"></i><?php endif; ?>
                                </td>
                                <td class="text-center sonuc-hucre"></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Yetkileri Kaydet</button></div>
            </form>
        </div>
    </div>
</div>
<?php
$anahtarlar = array_column($yetkiler, 'anahtar', 'id');
$tipler = array_column($yetkiler, 'tip', 'id');
ob_start(); ?>
<script>
(function () {
    const ROL = <?= json_encode($rolYetki) ?>;
    const ANAHTAR = <?= json_encode($anahtarlar) ?>;
    const TIP = <?= json_encode($tipler) ?>;
    const modal = document.getElementById('yetkiModal');
    let rolId = 0;
    const satirlar = () => modal.querySelectorAll('tr[data-yid]');
    const rolde = yid => (ROL[rolId] || []).includes(parseInt(yid, 10));
    function sec(tr, deger) {
        const r = tr.querySelector('input[value="' + deger + '"]');
        if (r && !r.disabled) r.checked = true;
    }
    function guncelle() {
        let acik = 0;
        satirlar().forEach(tr => {
            const yid = tr.dataset.yid;
            const d = (tr.querySelector('input:checked') || {}).value || 'rol';
            const sonuc = d === 'ver' ? true : d === 'engelle' ? false : rolde(yid);
            if (sonuc) acik++;
            tr.querySelector('.rol-hucre').innerHTML = rolde(yid) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash text-muted"></i>';
            tr.querySelector('.sonuc-hucre').innerHTML = sonuc ? '<span class="badge-soft success">Açık</span>' : '<span class="badge-soft secondary">Kapalı</span>';
            tr.classList.toggle('degisti', d !== 'rol');
        });
        document.getElementById('ykAcik').textContent = acik;
    }
    document.addEventListener('click', function (e) {
        const b = e.target.closest('[data-yetki]');
        if (!b) return;
        const v = JSON.parse(b.dataset.yetki);
        rolId = v.rol;
        document.getElementById('ykId').value = v.id;
        document.getElementById('ykAd').textContent = v.ad;
        document.getElementById('ykRol').textContent = v.rolAd;
        satirlar().forEach(tr => {
            tr.querySelectorAll('input').forEach(i => i.checked = false);
            tr.querySelector('input[value="' + (v.ozel[tr.dataset.yid] || 'rol') + '"]').checked = true;
        });
        guncelle();
    });
    modal.addEventListener('change', guncelle);
    // Şablonlar
    modal.querySelectorAll('[data-sablon]').forEach(btn => btn.addEventListener('click', function () {
        const s = this.dataset.sablon;
        satirlar().forEach(tr => {
            const yid = tr.dataset.yid, k = ANAHTAR[yid];
            if (s === 'sifirla') sec(tr, 'rol');
            if (s === 'saltokunur' && TIP[yid] === 'islem') sec(tr, rolde(yid) ? 'engelle' : 'rol');
            if (s === 'kendi') {
                if (k === 'borc.kendi' || k === 'panel.goruntule') sec(tr, rolde(yid) ? 'rol' : 'ver');
                else if (['aidat.goruntule', 'odeme.goruntule', 'kasa.goruntule', 'rapor.goruntule', 'daire.goruntule', 'kisi.goruntule'].includes(k)) sec(tr, rolde(yid) ? 'engelle' : 'rol');
            }
            if (s === 'tumborc' && (k === 'aidat.goruntule' || k === 'daire.goruntule' || k === 'panel.goruntule')) sec(tr, rolde(yid) ? 'rol' : 'ver');
        });
        guncelle();
    }));
})();
</script>
<?php $sayfaJs = ob_get_clean(); endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
