-- =====================================================================
--  v1.2 → v1.3 GÜNCELLEMESİ (verileriniz silinmez)
--  Normalde elle çalıştırmanıza gerek yoktur; site açıldığında otomatik uygulanır.
--  Eklenenler: e-posta bildirimleri (makbuz, dekont sonucu, duyuru), e-posta kuyruğu.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS eposta_kuyrugu (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alici          VARCHAR(190) NOT NULL,
  alici_ad       VARCHAR(150) NULL,
  konu           VARCHAR(255) NOT NULL,
  html           MEDIUMTEXT NOT NULL,
  metin          TEXT NULL,
  tur            VARCHAR(20) NOT NULL DEFAULT 'genel' COMMENT 'makbuz | bildirim | duyuru | test | genel',
  ilgili_id      INT UNSIGNED NULL,
  durum          ENUM('bekliyor','gonderildi','hata','iptal') NOT NULL DEFAULT 'bekliyor',
  deneme         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  hata           VARCHAR(500) NULL,
  sonraki_deneme DATETIME NULL,
  gonderim       DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_durum (durum, sonraki_deneme)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

ALTER TABLE daireler ADD COLUMN malik_eposta VARCHAR(150) NULL AFTER malik_tel;
ALTER TABLE daireler ADD COLUMN sakin_eposta VARCHAR(150) NULL AFTER sakin_tel;
ALTER TABLE kullanicilar ADD COLUMN eposta_bildirim TINYINT(1) NOT NULL DEFAULT 1 AFTER telefon;

INSERT IGNORE INTO yetkiler (anahtar, ad, grup, tip, sira) VALUES
('eposta.yonet', 'E-posta ayarları, gönderim kayıtları ve manuel e-posta', 'Sistem', 'islem', 83);
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id) SELECT r.id, y.id FROM roller r, yetkiler y WHERE r.sistem = 1 AND y.anahtar = 'eposta.yonet';
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id)
SELECT ry.rol_id, y.id FROM rol_yetki ry JOIN yetkiler yo ON yo.id = ry.yetki_id AND yo.anahtar = 'duyuru.ekle'
JOIN yetkiler y ON y.anahtar = 'eposta.yonet';

INSERT IGNORE INTO ayarlar (anahtar, deger) VALUES
('mail_aktif', '0'), ('mail_yontem', 'smtp'), ('smtp_host', ''), ('smtp_port', '587'), ('smtp_guvenlik', 'tls'),
('smtp_kullanici', ''), ('smtp_sifre', ''), ('mail_gonderen', ''), ('mail_gonderen_ad', ''),
('mail_makbuz', '1'), ('mail_bildirim', '1'), ('mail_duyuru', '1'), ('mail_malik', '0'), ('site_url', ''), ('mail_son_calisma', '');
INSERT INTO ayarlar (anahtar, deger) VALUES ('db_surum', '1.3') ON DUPLICATE KEY UPDATE deger = '1.3';
