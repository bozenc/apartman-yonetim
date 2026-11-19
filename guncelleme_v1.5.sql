-- =====================================================================
--  APARTMAN YÖNETİM SİSTEMİ - v1.5 (v2.0 Özellik Paketi) Güncellemesi
-- =====================================================================

SET NAMES utf8mb4;

-- 1. Daireler tablosuna araç plakası ve alacak (avans) bakiyesi ekle
ALTER TABLE daireler ADD COLUMN plaka VARCHAR(50) NULL AFTER sakin_eposta;
ALTER TABLE daireler ADD COLUMN alacak_bakiye DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER aidat_katsayi;

-- 2. Borçlar tablosuna muhatap (sakin / malik ayrımı) ekle
ALTER TABLE borclar ADD COLUMN muhatap ENUM('sakin','malik') NOT NULL DEFAULT 'sakin' AFTER tur;

-- 3. Kasa hareketleri tablosuna fatura/belge dosyası alanı ekle
ALTER TABLE hareketler ADD COLUMN belge_dosya VARCHAR(100) NULL AFTER belge_no;

-- 4. Arıza, Talep ve Şikayet (Ticket) tablosu
CREATE TABLE IF NOT EXISTS talepler (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  daire_id     INT UNSIGNED NULL,
  kullanici_id INT UNSIGNED NULL,
  kategori     ENUM('ariza','temizlik','guvenlik','oneri','diger') NOT NULL DEFAULT 'ariza',
  baslik       VARCHAR(150) NOT NULL,
  aciklama     TEXT NOT NULL,
  fotograf     VARCHAR(100) NULL,
  oncelik      ENUM('normal','acil') NOT NULL DEFAULT 'normal',
  durum        ENUM('bekliyor','islemde','cozuldu','iptal') NOT NULL DEFAULT 'bekliyor',
  yonetici_not TEXT NULL,
  isleyen_id   INT UNSIGNED NULL,
  cozum_tarihi DATETIME NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_durum (durum),
  FOREIGN KEY (daire_id)     REFERENCES daireler(id)     ON DELETE SET NULL,
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL,
  FOREIGN KEY (isleyen_id)   REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- 5. Dijital Anket ve Oylama tabloları
CREATE TABLE IF NOT EXISTS anketler (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baslik       VARCHAR(180) NOT NULL,
  aciklama     TEXT NULL,
  bitis_tarihi DATE NULL,
  aktif        TINYINT(1) NOT NULL DEFAULT 1,
  kullanici_id INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS anket_secenekler (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  anket_id INT UNSIGNED NOT NULL,
  secenek  VARCHAR(180) NOT NULL,
  FOREIGN KEY (anket_id) REFERENCES anketler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE IF NOT EXISTS anket_oylar (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  anket_id     INT UNSIGNED NOT NULL,
  secenek_id   INT UNSIGNED NOT NULL,
  daire_id     INT UNSIGNED NOT NULL,
  kullanici_id INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_anket_daire (anket_id, daire_id),
  FOREIGN KEY (anket_id)     REFERENCES anketler(id)         ON DELETE CASCADE,
  FOREIGN KEY (secenek_id)   REFERENCES anket_secenekler(id) ON DELETE CASCADE,
  FOREIGN KEY (daire_id)     REFERENCES daireler(id)         ON DELETE CASCADE,
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- 6. Demirbaş & Periyodik Bakım Takvimi tablosu
CREATE TABLE IF NOT EXISTS bakimlar (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baslik         VARCHAR(150) NOT NULL,
  kategori       VARCHAR(60)  NOT NULL DEFAULT 'Asansör',
  firma          VARCHAR(120) NULL,
  telefon        VARCHAR(30)  NULL,
  periyot_ay     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  son_bakim      DATE NULL,
  sonraki_bakim  DATE NOT NULL,
  tutar          DECIMAL(12,2) NULL,
  notlar         TEXT NULL,
  aktif          TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_sonraki (sonraki_bakim)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- 7. Dijital Doküman ve Karar Arşivi tablosu
CREATE TABLE IF NOT EXISTS dokumanlar (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baslik       VARCHAR(150) NOT NULL,
  kategori     ENUM('karar','yonetim_plani','sozlesme','rapor','diger') NOT NULL DEFAULT 'karar',
  aciklama     VARCHAR(255) NULL,
  dosya        VARCHAR(100) NOT NULL,
  dosya_ad     VARCHAR(150) NOT NULL,
  boyut        INT UNSIGNED NOT NULL DEFAULT 0,
  herkese_acik TINYINT(1) NOT NULL DEFAULT 1,
  kullanici_id INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- 8. Yeni yetkiler
INSERT IGNORE INTO yetkiler (anahtar, ad, grup, tip, sira) VALUES
('talep.gonder', 'Arıza, talep ve şikayet bildirimi oluştur (sakin)', 'Talepler & Anket', 'islem', 65),
('talep.yonet',  'Arıza ve talepleri görüntüle, yanıtla ve durumunu güncelle', 'Talepler & Anket', 'islem', 66),
('anket.yonet',  'Dijital anket oluştur, düzenle ve sonlandır', 'Talepler & Anket', 'islem', 67),
('bakim.yonet',  'Periyodik bakım takvimini ve doküman arşivini yönet', 'Daireler', 'islem', 14);

INSERT IGNORE INTO rol_yetki (rol_id, yetki_id)
  SELECT 1, id FROM yetkiler WHERE anahtar IN ('talep.gonder','talep.yonet','anket.yonet','bakim.yonet');
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id)
  SELECT 2, id FROM yetkiler WHERE anahtar IN ('talep.yonet','anket.yonet','bakim.yonet');
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id)
  SELECT 4, id FROM yetkiler WHERE anahtar IN ('talep.yonet');
INSERT IGNORE INTO rol_yetki (rol_id, yetki_id)
  SELECT 5, id FROM yetkiler WHERE anahtar IN ('talep.gonder');

INSERT INTO ayarlar (anahtar, deger) VALUES ('db_surum', '1.5')
  ON DUPLICATE KEY UPDATE deger = '1.5';
