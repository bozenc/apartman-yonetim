-- =====================================================================
--  v1.1 → v1.2 GÜNCELLEMESİ (verileriniz silinmez)
--  Normalde elle çalıştırmanıza gerek yoktur: dosyaları güncelleyip siteyi
--  açtığınızda sistem bu güncellemeyi otomatik uygular.
--  Eklenenler: ödeme bildirimi / dekont yükleme, PDF makbuz.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS odeme_bildirimleri (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  daire_id     INT UNSIGNED NOT NULL,
  kullanici_id INT UNSIGNED NULL,
  tutar        DECIMAL(12,2) NOT NULL,
  tarih        DATE NOT NULL,
  yontem       ENUM('nakit','havale','kredi_karti','diger') NOT NULL DEFAULT 'havale',
  aciklama     VARCHAR(255) NULL,
  borc_ids     VARCHAR(255) NULL,
  dekont       VARCHAR(100) NULL,
  dekont_ad    VARCHAR(150) NULL,
  durum        ENUM('bekliyor','onaylandi','reddedildi') NOT NULL DEFAULT 'bekliyor',
  red_neden    VARCHAR(255) NULL,
  isleyen_id   INT UNSIGNED NULL,
  islem_tarihi DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_durum (durum),
  FOREIGN KEY (daire_id)     REFERENCES daireler(id)     ON DELETE CASCADE,
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL,
  FOREIGN KEY (isleyen_id)   REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

ALTER TABLE odemeler ADD COLUMN dekont VARCHAR(100) NULL AFTER aciklama;
ALTER TABLE odemeler ADD COLUMN bildirim_id INT UNSIGNED NULL AFTER dekont;
ALTER TABLE odemeler ADD CONSTRAINT fk_odeme_bildirim FOREIGN KEY (bildirim_id) REFERENCES odeme_bildirimleri(id) ON DELETE SET NULL;

INSERT IGNORE INTO yetkiler (anahtar, ad, grup, tip, sira) VALUES
('bildirim.gonder', 'Ödeme bildirimi yap ve dekont yükle (kendi dairesi için)', 'Tahsilat', 'islem', 33),
('bildirim.onayla', 'Ödeme bildirimlerini ve dekontları onayla / reddet',     'Tahsilat', 'islem', 34);

-- Süper Yönetici: tümü
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id) SELECT r.id, y.id FROM roller r, yetkiler y WHERE r.sistem = 1;
-- Tahsilat alabilen roller bildirimleri onaylayabilsin
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id)
SELECT ry.rol_id, y.id FROM rol_yetki ry JOIN yetkiler yo ON yo.id = ry.yetki_id AND yo.anahtar = 'odeme.al'
JOIN yetkiler y ON y.anahtar = 'bildirim.onayla';
-- Kendi borcunu görebilen roller (Sakin) dekont yükleyebilsin
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id)
SELECT ry.rol_id, y.id FROM rol_yetki ry JOIN yetkiler yo ON yo.id = ry.yetki_id AND yo.anahtar = 'borc.kendi'
JOIN yetkiler y ON y.anahtar = 'bildirim.gonder';

INSERT IGNORE INTO ayarlar (anahtar, deger) VALUES ('gizli_anahtar', '');
INSERT INTO ayarlar (anahtar, deger) VALUES ('db_surum', '1.2') ON DUPLICATE KEY UPDATE deger = '1.2';
