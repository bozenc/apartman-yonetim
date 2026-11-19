-- =====================================================================
--  v1.0 → v1.1 GÜNCELLEMESİ (verileriniz silinmez)
--  Yalnızca v1.0'ı daha önce kurduysanız çalıştırın. Yeni kurulumda
--  doğrudan kurulum.sql yeterlidir.
--  Eklenenler: ayrıntılı (görüntüle / ekle / düzenle / sil) yetkiler,
--  kullanıcıya özel yetki istisnaları, kişisel bilgi maskeleme.
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE yetkiler
  MODIFY ad VARCHAR(150) NOT NULL,
  ADD COLUMN tip ENUM('goruntule','islem') NOT NULL DEFAULT 'goruntule' AFTER grup;

CREATE TABLE IF NOT EXISTS kullanici_yetki (
  kullanici_id INT UNSIGNED NOT NULL,
  yetki_id     INT UNSIGNED NOT NULL,
  izin         TINYINT(1) NOT NULL,
  PRIMARY KEY (kullanici_id, yetki_id),
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE,
  FOREIGN KEY (yetki_id)     REFERENCES yetkiler(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Yeni yetkiler
INSERT IGNORE INTO yetkiler (anahtar, ad, grup, tip, sira) VALUES
('kisi.goruntule',    'Malik / sakin ad ve telefonlarını görüntüle (yoksa maskelenir)', 'Genel', 'goruntule', 3),
('daire.ekle',        'Daire ekle',                                        'Daireler', 'islem', 11),
('daire.duzenle',     'Daire bilgilerini düzenle',                         'Daireler', 'islem', 12),
('daire.sil',         'Daire sil',                                         'Daireler', 'islem', 13),
('aidat.donem',       'Aidat dönemi oluştur / borçlandır / gecikme / sil', 'Aidat & Borçlar', 'islem', 21),
('borc.ekle',         'Daireye ek borç (demirbaş vb.) ekle',               'Aidat & Borçlar', 'islem', 22),
('borc.sil',          'Borç kaydı sil',                                    'Aidat & Borçlar', 'islem', 23),
('odeme.goruntule',   'Tahsilat geçmişini görüntüle',                      'Tahsilat', 'goruntule', 30),
('kasa.ekle',         'Gelir-gider kaydı ekle',                            'Gelir / Gider', 'islem', 41),
('kasa.duzenle',      'Gelir-gider kaydı düzenle',                         'Gelir / Gider', 'islem', 42),
('kasa.sil',          'Gelir-gider kaydı sil',                             'Gelir / Gider', 'islem', 43),
('duyuru.ekle',       'Duyuru yayınla',                                    'Duyurular', 'islem', 60),
('duyuru.duzenle',    'Duyuru düzenle',                                    'Duyurular', 'islem', 61),
('duyuru.sil',        'Duyuru sil',                                        'Duyurular', 'islem', 62),
('kullanici.ekle',    'Kullanıcı ekle',                                    'Kullanıcılar', 'islem', 71),
('kullanici.duzenle', 'Kullanıcı düzenle / pasife al / rol ata',           'Kullanıcılar', 'islem', 72),
('kullanici.sil',     'Kullanıcı sil',                                     'Kullanıcılar', 'islem', 73),
('kullanici.yetki',   'Kullanıcıya özel yetki ver / engelle',              'Kullanıcılar', 'islem', 74);

-- Eski "yönet" yetkisine sahip rollere yeni karşılıklarını ver
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id)
SELECT ry.rol_id, yn.id FROM rol_yetki ry
JOIN yetkiler ye ON ye.id = ry.yetki_id
JOIN yetkiler yn ON (
     (ye.anahtar = 'daire.yonet'     AND yn.anahtar IN ('daire.ekle','daire.duzenle','daire.sil'))
  OR (ye.anahtar = 'aidat.yonet'     AND yn.anahtar IN ('aidat.donem','borc.ekle','borc.sil'))
  OR (ye.anahtar = 'aidat.goruntule' AND yn.anahtar IN ('odeme.goruntule','kisi.goruntule'))
  OR (ye.anahtar = 'kasa.yonet'      AND yn.anahtar IN ('kasa.ekle','kasa.duzenle','kasa.sil'))
  OR (ye.anahtar = 'duyuru.yonet'    AND yn.anahtar IN ('duyuru.ekle','duyuru.duzenle','duyuru.sil'))
  OR (ye.anahtar = 'kullanici.yonet' AND yn.anahtar IN ('kullanici.ekle','kullanici.duzenle','kullanici.sil'))
);
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id) SELECT r.id, y.id FROM roller r, yetkiler y WHERE r.sistem = 1;

-- Eski yetkileri kaldır
DELETE FROM yetkiler WHERE anahtar IN ('daire.yonet','aidat.yonet','kasa.yonet','duyuru.yonet','kullanici.yonet');

-- Tip ve isim güncellemeleri
UPDATE yetkiler SET tip = 'islem' WHERE anahtar IN ('odeme.al','odeme.sil','kategori.yonet','rol.yonet','ayar.yonet');
UPDATE yetkiler SET ad = 'Yalnızca kendi dairesinin borç ve ödemelerini görüntüle' WHERE anahtar = 'borc.kendi';
UPDATE yetkiler SET ad = 'TÜM dairelerin aidat ve borçlarını görüntüle', grup = 'Aidat & Borçlar', sira = 20 WHERE anahtar = 'aidat.goruntule';
UPDATE yetkiler SET grup = 'Tahsilat', sira = 31 WHERE anahtar = 'odeme.al';
UPDATE yetkiler SET grup = 'Tahsilat', sira = 32, ad = 'Tahsilat kaydını iptal et / sil' WHERE anahtar = 'odeme.sil';
UPDATE yetkiler SET sira = 40 WHERE anahtar = 'kasa.goruntule';
UPDATE yetkiler SET sira = 44 WHERE anahtar = 'kategori.yonet';
UPDATE yetkiler SET sira = 50 WHERE anahtar = 'rapor.goruntule';
UPDATE yetkiler SET sira = 70 WHERE anahtar = 'kullanici.goruntule';
UPDATE yetkiler SET grup = 'Sistem', sira = 80 WHERE anahtar = 'rol.yonet';
UPDATE yetkiler SET sira = 81 WHERE anahtar = 'ayar.yonet';
UPDATE yetkiler SET sira = 82 WHERE anahtar = 'log.goruntule';
