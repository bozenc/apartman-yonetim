-- =====================================================================
--  v1.3 → v1.4 GÜNCELLEMESİ (verileriniz silinmez, site açılınca otomatik uygulanır)
--  Eklenenler: SMS ve WhatsApp bildirimleri, kısa bağlantılar,
--              KVKK / gizlilik / çerez / sözleşme metinleri ve onay kayıtları.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mesaj_kuyrugu (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kanal          ENUM('sms','whatsapp') NOT NULL,
  telefon        VARCHAR(20) NOT NULL COMMENT '905XXXXXXXXX',
  alici_ad       VARCHAR(150) NULL,
  metin          TEXT NOT NULL,
  tur            VARCHAR(20) NOT NULL DEFAULT 'genel',
  ilgili_id      INT UNSIGNED NULL,
  durum          ENUM('bekliyor','gonderildi','hata','iptal') NOT NULL DEFAULT 'bekliyor',
  deneme         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  hata           VARCHAR(500) NULL,
  saglayici_id   VARCHAR(100) NULL COMMENT 'Sağlayıcının mesaj / iş numarası',
  sonraki_deneme DATETIME NULL,
  gonderim       DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_durum (durum, sonraki_deneme)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS kisa_linkler (
  kod        CHAR(8) NOT NULL PRIMARY KEY,
  token      VARCHAR(255) NOT NULL,
  tiklama    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS yasal_metinler (
  anahtar    VARCHAR(30) NOT NULL PRIMARY KEY,
  baslik     VARCHAR(150) NOT NULL,
  icerik     MEDIUMTEXT NOT NULL,
  surum      INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS onaylar (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kullanici_id INT UNSIGNED NOT NULL,
  metin        VARCHAR(30) NOT NULL,
  surum        INT UNSIGNED NOT NULL,
  durum        ENUM('onay','geri_alindi') NOT NULL DEFAULT 'onay',
  ip           VARCHAR(45) NULL,
  tarayici     VARCHAR(255) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_kullanici (kullanici_id, metin),
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

ALTER TABLE kullanicilar ADD COLUMN mesaj_bildirim TINYINT(1) NOT NULL DEFAULT 1 AFTER eposta_bildirim;
ALTER TABLE daireler ADD COLUMN mesaj_izin TINYINT(1) NOT NULL DEFAULT 1 AFTER sakin_eposta;

INSERT IGNORE INTO yetkiler (anahtar, ad, grup, tip, sira) VALUES
('mesaj.yonet', 'SMS / WhatsApp ayarları, gönderim kayıtları ve manuel mesaj', 'Sistem', 'islem', 84),
('yasal.yonet', 'KVKK, gizlilik, çerez ve sözleşme metinlerini düzenle; onay kayıtlarını gör', 'Sistem', 'islem', 85);
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id) SELECT r.id, y.id FROM roller r, yetkiler y WHERE r.sistem = 1 AND y.anahtar IN ('mesaj.yonet', 'yasal.yonet');
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id)
SELECT ry.rol_id, y.id FROM rol_yetki ry JOIN yetkiler yo ON yo.id = ry.yetki_id AND yo.anahtar = 'eposta.yonet'
JOIN yetkiler y ON y.anahtar = 'mesaj.yonet';

INSERT IGNORE INTO ayarlar (anahtar, deger) VALUES
('sms_aktif', '0'), ('sms_saglayici', 'netgsm'), ('sms_baslik', ''), ('sms_turkce', '0'),
('netgsm_kullanici', ''), ('netgsm_sifre', ''),
('twilio_sid', ''), ('twilio_token', ''), ('twilio_sms_no', ''), ('twilio_wa_no', ''),
('wa_aktif', '0'), ('wa_saglayici', 'meta'), ('wa_meta_token', ''), ('wa_meta_telefon_id', ''), ('wa_meta_sablon', ''), ('wa_meta_dil', 'tr'), ('wa_meta_tip', 'sablon'),
('ozel_sms_url', ''), ('ozel_sms_yontem', 'GET'), ('ozel_sms_govde', ''), ('ozel_sms_tip', 'application/json'), ('ozel_sms_basari', ''),
('ozel_wa_url', ''), ('ozel_wa_yontem', 'POST'), ('ozel_wa_govde', ''), ('ozel_wa_tip', 'application/json'), ('ozel_wa_basari', ''),
('sms_makbuz', '1'), ('sms_bildirim', '1'), ('sms_duyuru', '0'),
('wa_makbuz', '1'), ('wa_bildirim', '1'), ('wa_duyuru', '1'),
('msj_sablon_makbuz', '{site}: {daire} için {tutar} ödemeniz alındı. Makbuz: {link}'),
('msj_sablon_onay', '{site}: {tarih} tarihli {tutar} ödeme bildiriminiz onaylandı. Makbuz: {link}'),
('msj_sablon_red', '{site}: {tutar} ödeme bildiriminiz onaylanmadı. Neden: {neden}. Detay: {link}'),
('msj_sablon_duyuru', '{site} duyuru: {baslik}. Detay: {link}'),
('yasal_onay_zorunlu', '1'), ('cerez_bildirimi', '1'), ('log_saklama_gun', '730'), ('log_temizlik', ''),
('kvkk_sorumlu', ''), ('kvkk_eposta', ''), ('kvkk_kep', '');
INSERT INTO ayarlar (anahtar, deger) VALUES ('db_surum', '1.4') ON DUPLICATE KEY UPDATE deger = '1.4';
