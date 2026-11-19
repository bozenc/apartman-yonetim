-- =====================================================================
--  APARTMAN YÖNETİM SİSTEMİ - Veritabanı Kurulum Dosyası
--  MySQL 5.7+ / MariaDB 10.3+  |  Karakter seti: utf8mb4
--  Varsayılan giriş:  kullanıcı adı: admin   şifre: admin123
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS dokumanlar, bakimlar, anket_oylar, anket_secenekler, anketler, talepler, onaylar, yasal_metinler, kisa_linkler, mesaj_kuyrugu, eposta_kuyrugu, kullanici_yetki, odeme_bildirimleri, islem_log, duyurular, hareketler, odemeler, borclar,
  aidat_donemleri, daireler, kategoriler, kullanicilar, rol_yetki, yetkiler, roller, ayarlar;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Genel ayarlar
-- ---------------------------------------------------------------------
CREATE TABLE ayarlar (
  anahtar VARCHAR(60) NOT NULL PRIMARY KEY,
  deger   TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

INSERT INTO ayarlar (anahtar, deger) VALUES
('site_adi',        'Yeşil Vadi Sitesi'),
('adres',           'Atatürk Mah. Çınar Sok. No:12 Kadıköy / İstanbul'),
('telefon',         '0216 000 00 00'),
('eposta',          'yonetim@yesilvadi.com'),
('vergi_no',        ''),
('iban',            'TR00 0000 0000 0000 0000 0000 00'),
('varsayilan_aidat','1500'),
('son_odeme_gunu',  '10'),
('gecikme_orani',   '5'),
('gizli_anahtar',   ''),
('db_surum',        '1.5'),
('mail_aktif', '0'), ('mail_yontem', 'smtp'), ('smtp_host', ''), ('smtp_port', '587'), ('smtp_guvenlik', 'tls'),
('smtp_kullanici', ''), ('smtp_sifre', ''), ('mail_gonderen', ''), ('mail_gonderen_ad', ''),
('mail_makbuz', '1'), ('mail_bildirim', '1'), ('mail_duyuru', '1'), ('mail_malik', '0'), ('site_url', ''), ('mail_son_calisma', '');

INSERT INTO ayarlar (anahtar, deger) VALUES
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

-- ---------------------------------------------------------------------
-- Roller & yetkiler
-- ---------------------------------------------------------------------
CREATE TABLE roller (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ad         VARCHAR(80)  NOT NULL UNIQUE,
  aciklama   VARCHAR(255) NULL,
  renk       VARCHAR(20)  NOT NULL DEFAULT 'primary',
  sistem     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = silinemez / yetkileri değiştirilemez',
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE yetkiler (
  id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  anahtar VARCHAR(60)  NOT NULL UNIQUE,
  ad      VARCHAR(150) NOT NULL,
  grup    VARCHAR(60)  NOT NULL,
  tip     ENUM('goruntule','islem') NOT NULL DEFAULT 'goruntule' COMMENT 'goruntule = salt okunur, islem = değişiklik yapar',
  sira    INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE rol_yetki (
  rol_id   INT UNSIGNED NOT NULL,
  yetki_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (rol_id, yetki_id),
  FOREIGN KEY (rol_id)   REFERENCES roller(id)   ON DELETE CASCADE,
  FOREIGN KEY (yetki_id) REFERENCES yetkiler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

INSERT INTO roller (id, ad, aciklama, renk, sistem) VALUES
(1, 'Süper Yönetici', 'Tüm yetkilere sahip sistem yöneticisi', 'danger', 1),
(2, 'Yönetici',       'Apartman yöneticisi - günlük yönetim işlemleri', 'primary', 0),
(3, 'Muhasebe',       'Aidat tahsilatı ve gelir-gider kayıtları', 'success', 0),
(4, 'Denetçi',        'Tüm kayıtları yalnızca görüntüleyebilir, değişiklik yapamaz', 'warning', 0),
(5, 'Sakin',          'Daire sakini - yalnızca kendi borçlarını görür', 'secondary', 0);

INSERT INTO yetkiler (anahtar, ad, grup, tip, sira) VALUES
('panel.goruntule',     'Kontrol panelini ve duyuruları görüntüle',              'Genel', 'goruntule', 1),
('borc.kendi',          'Yalnızca kendi dairesinin borç ve ödemelerini görüntüle','Genel', 'goruntule', 2),
('kisi.goruntule',      'Malik / sakin ad ve telefonlarını görüntüle (yoksa maskelenir)', 'Genel', 'goruntule', 3),
('daire.goruntule',     'Daire listesini görüntüle',                             'Daireler', 'goruntule', 10),
('daire.ekle',          'Daire ekle',                                            'Daireler', 'islem', 11),
('daire.duzenle',       'Daire bilgilerini düzenle',                             'Daireler', 'islem', 12),
('daire.sil',           'Daire sil',                                             'Daireler', 'islem', 13),
('aidat.goruntule',     'TÜM dairelerin aidat ve borçlarını görüntüle',          'Aidat & Borçlar', 'goruntule', 20),
('aidat.donem',         'Aidat dönemi oluştur / borçlandır / gecikme / sil',     'Aidat & Borçlar', 'islem', 21),
('borc.ekle',           'Daireye ek borç (demirbaş vb.) ekle',                   'Aidat & Borçlar', 'islem', 22),
('borc.sil',            'Borç kaydı sil',                                        'Aidat & Borçlar', 'islem', 23),
('odeme.goruntule',     'Tahsilat geçmişini görüntüle',                          'Tahsilat', 'goruntule', 30),
('odeme.al',            'Tahsilat (ödeme) al',                                   'Tahsilat', 'islem', 31),
('odeme.sil',           'Tahsilat kaydını iptal et / sil',                       'Tahsilat', 'islem', 32),
('bildirim.gonder',     'Ödeme bildirimi yap ve dekont yükle (kendi dairesi için)', 'Tahsilat', 'islem', 33),
('bildirim.onayla',     'Ödeme bildirimlerini ve dekontları onayla / reddet',   'Tahsilat', 'islem', 34),
('kasa.goruntule',      'Gelir-gider kayıtlarını ve kasa bakiyesini görüntüle',  'Gelir / Gider', 'goruntule', 40),
('kasa.ekle',           'Gelir-gider kaydı ekle',                                'Gelir / Gider', 'islem', 41),
('kasa.duzenle',        'Gelir-gider kaydı düzenle',                             'Gelir / Gider', 'islem', 42),
('kasa.sil',            'Gelir-gider kaydı sil',                                 'Gelir / Gider', 'islem', 43),
('kategori.yonet',      'Gelir-gider kategorilerini yönet',                      'Gelir / Gider', 'islem', 44),
('rapor.goruntule',     'Raporları görüntüle',                                   'Raporlar', 'goruntule', 50),
('duyuru.ekle',         'Duyuru yayınla',                                        'Duyurular', 'islem', 60),
('duyuru.duzenle',      'Duyuru düzenle',                                        'Duyurular', 'islem', 61),
('duyuru.sil',          'Duyuru sil',                                            'Duyurular', 'islem', 62),
('kullanici.goruntule', 'Kullanıcı listesini görüntüle',                         'Kullanıcılar', 'goruntule', 70),
('kullanici.ekle',      'Kullanıcı ekle',                                        'Kullanıcılar', 'islem', 71),
('kullanici.duzenle',   'Kullanıcı düzenle / pasife al / rol ata',               'Kullanıcılar', 'islem', 72),
('kullanici.sil',       'Kullanıcı sil',                                         'Kullanıcılar', 'islem', 73),
('kullanici.yetki',     'Kullanıcıya özel yetki ver / engelle',                  'Kullanıcılar', 'islem', 74),
('rol.yonet',           'Rolleri ve rol yetkilerini yönet',                      'Sistem', 'islem', 80),
('ayar.yonet',          'Sistem ayarlarını değiştir',                            'Sistem', 'islem', 81),
('log.goruntule',       'İşlem kayıtlarını görüntüle',                           'Sistem', 'goruntule', 82),
('eposta.yonet',        'E-posta ayarları, gönderim kayıtları ve manuel e-posta', 'Sistem', 'islem', 83),
('mesaj.yonet',         'SMS / WhatsApp ayarları, gönderim kayıtları ve manuel mesaj', 'Sistem', 'islem', 84),
('yasal.yonet',         'KVKK, gizlilik, çerez ve sözleşme metinlerini düzenle; onay kayıtlarını gör', 'Sistem', 'islem', 85),
('talep.gonder',        'Arıza, talep ve şikayet bildirimi oluştur (sakin)',     'Talepler & Anket', 'islem', 65),
('talep.yonet',         'Arıza ve talepleri görüntüle, yanıtla ve durumunu güncelle', 'Talepler & Anket', 'islem', 66),
('anket.yonet',         'Dijital anket oluştur, düzenle ve sonlandır',           'Talepler & Anket', 'islem', 67),
('bakim.yonet',         'Periyodik bakım takvimini ve doküman arşivini yönet',   'Daireler', 'islem', 14);

-- Süper Yönetici: tümü (sistem rolü her zaman tüm yetkilere sahiptir)
INSERT INTO rol_yetki (rol_id, yetki_id) SELECT 1, id FROM yetkiler;
-- Yönetici: sistem yetkileri ve özel yetki atama hariç tümü
INSERT INTO rol_yetki (rol_id, yetki_id) SELECT 2, id FROM yetkiler
  WHERE anahtar NOT IN ('rol.yonet','ayar.yonet','log.goruntule','borc.kendi','kullanici.yetki','bildirim.gonder','yasal.yonet','talep.gonder');
-- Muhasebe
INSERT INTO rol_yetki (rol_id, yetki_id) SELECT 3, id FROM yetkiler
  WHERE anahtar IN ('panel.goruntule','kisi.goruntule','daire.goruntule','aidat.goruntule','aidat.donem','borc.ekle',
                    'odeme.goruntule','odeme.al','bildirim.onayla','kasa.goruntule','kasa.ekle','kasa.duzenle','kategori.yonet','rapor.goruntule');
-- Denetçi: yalnızca görüntüleme yetkileri + talepleri inceleme
INSERT INTO rol_yetki (rol_id, yetki_id) SELECT 4, id FROM yetkiler
  WHERE (tip = 'goruntule' AND anahtar <> 'borc.kendi') OR anahtar = 'talep.yonet';
-- Sakin: kendi borcu + duyurular + talep gönderme
INSERT INTO rol_yetki (rol_id, yetki_id) SELECT 5, id FROM yetkiler
  WHERE anahtar IN ('panel.goruntule','borc.kendi','bildirim.gonder','talep.gonder');

-- ---------------------------------------------------------------------
-- Kullanıcılar
-- ---------------------------------------------------------------------
CREATE TABLE kullanicilar (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ad_soyad      VARCHAR(120) NOT NULL,
  kullanici_adi VARCHAR(60)  NOT NULL UNIQUE,
  eposta        VARCHAR(150) NULL,
  telefon       VARCHAR(30)  NULL,
  eposta_bildirim TINYINT(1) NOT NULL DEFAULT 1,
  mesaj_bildirim TINYINT(1) NOT NULL DEFAULT 1,
  sifre         VARCHAR(255) NOT NULL,
  rol_id        INT UNSIGNED NOT NULL,
  aktif         TINYINT(1)   NOT NULL DEFAULT 1,
  son_giris     DATETIME     NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rol_id) REFERENCES roller(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- admin / admin123  (ilk girişten sonra mutlaka değiştirin)
INSERT INTO kullanicilar (id, ad_soyad, kullanici_adi, eposta, sifre, rol_id) VALUES
(1, 'Sistem Yöneticisi', 'admin', 'admin@site.com',
 '$2y$10$v4vJAPNjENttwwKu6u1iZOkpKxrWQ8om5R6EprX/Hk4gUlWZfzQDW', 1);

-- Kullanıcıya özel yetki istisnaları (rol yetkilerinin üzerine yazılır)
-- izin = 1 → rolde olmasa da VER,  izin = 0 → rolde olsa da ENGELLE
CREATE TABLE kullanici_yetki (
  kullanici_id INT UNSIGNED NOT NULL,
  yetki_id     INT UNSIGNED NOT NULL,
  izin         TINYINT(1) NOT NULL,
  PRIMARY KEY (kullanici_id, yetki_id),
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE CASCADE,
  FOREIGN KEY (yetki_id)     REFERENCES yetkiler(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ---------------------------------------------------------------------
-- Daireler
-- ---------------------------------------------------------------------
CREATE TABLE daireler (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  blok          VARCHAR(20)  NOT NULL DEFAULT 'A',
  daire_no      VARCHAR(20)  NOT NULL,
  kat           VARCHAR(10)  NULL,
  metrekare     DECIMAL(8,2) NULL,
  durum         ENUM('dolu','bos') NOT NULL DEFAULT 'dolu',
  oturan_tipi   ENUM('malik','kiraci') NOT NULL DEFAULT 'malik',
  malik_adi     VARCHAR(120) NULL,
  malik_tel     VARCHAR(30)  NULL,
  malik_eposta  VARCHAR(150) NULL,
  sakin_adi     VARCHAR(120) NULL,
  sakin_tel     VARCHAR(30)  NULL,
  sakin_eposta  VARCHAR(150) NULL,
  plaka         VARCHAR(50)  NULL,
  mesaj_izin    TINYINT(1)   NOT NULL DEFAULT 1,
  kisi_sayisi   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  aidat_katsayi DECIMAL(5,2) NOT NULL DEFAULT 1.00,
  alacak_bakiye DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  kullanici_id  INT UNSIGNED NULL COMMENT 'Sakin kullanıcı hesabı',
  notlar        TEXT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_daire (blok, daire_no),
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ---------------------------------------------------------------------
-- Aidat dönemleri, borçlar, ödemeler
-- ---------------------------------------------------------------------
CREATE TABLE aidat_donemleri (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  donem      CHAR(7) NOT NULL UNIQUE COMMENT 'YYYY-AA',
  tutar      DECIMAL(12,2) NOT NULL,
  son_odeme  DATE NOT NULL,
  aciklama   VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE borclar (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  daire_id   INT UNSIGNED NOT NULL,
  donem_id   INT UNSIGNED NULL,
  tur        ENUM('aidat','ek','demirbas','gecikme','diger') NOT NULL DEFAULT 'aidat',
  muhatap    ENUM('sakin','malik') NOT NULL DEFAULT 'sakin',
  aciklama   VARCHAR(255) NOT NULL,
  tutar      DECIMAL(12,2) NOT NULL,
  odenen     DECIMAL(12,2) NOT NULL DEFAULT 0,
  son_odeme  DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_donem_daire (daire_id, donem_id),
  KEY idx_son_odeme (son_odeme),
  FOREIGN KEY (daire_id) REFERENCES daireler(id) ON DELETE CASCADE,
  FOREIGN KEY (donem_id) REFERENCES aidat_donemleri(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- Sakinlerin yüklediği ödeme bildirimleri / dekontlar
CREATE TABLE odeme_bildirimleri (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  daire_id     INT UNSIGNED NOT NULL,
  kullanici_id INT UNSIGNED NULL,
  tutar        DECIMAL(12,2) NOT NULL,
  tarih        DATE NOT NULL COMMENT 'Ödemenin yapıldığı tarih',
  yontem       ENUM('nakit','havale','kredi_karti','diger') NOT NULL DEFAULT 'havale',
  aciklama     VARCHAR(255) NULL,
  borc_ids     VARCHAR(255) NULL COMMENT 'Sakinin seçtiği borçlar (virgülle)',
  dekont       VARCHAR(100) NULL COMMENT 'uploads/dekontlar içindeki dosya adı',
  dekont_ad    VARCHAR(150) NULL COMMENT 'Orijinal dosya adı',
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

CREATE TABLE odemeler (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  borc_id      INT UNSIGNED NOT NULL,
  daire_id     INT UNSIGNED NOT NULL,
  tutar        DECIMAL(12,2) NOT NULL,
  tarih        DATE NOT NULL,
  yontem       ENUM('nakit','havale','kredi_karti','diger') NOT NULL DEFAULT 'nakit',
  makbuz_no    VARCHAR(30) NULL,
  aciklama     VARCHAR(255) NULL,
  dekont       VARCHAR(100) NULL COMMENT 'Ekli dekont dosyası',
  bildirim_id  INT UNSIGNED NULL,
  kullanici_id INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tarih (tarih),
  FOREIGN KEY (bildirim_id) REFERENCES odeme_bildirimleri(id) ON DELETE SET NULL,
  FOREIGN KEY (borc_id)  REFERENCES borclar(id)  ON DELETE CASCADE,
  FOREIGN KEY (daire_id) REFERENCES daireler(id) ON DELETE CASCADE,
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ---------------------------------------------------------------------
-- Gelir / Gider (kasa)
-- ---------------------------------------------------------------------
CREATE TABLE kategoriler (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ad     VARCHAR(80) NOT NULL,
  tur    ENUM('gelir','gider') NOT NULL,
  renk   VARCHAR(10) NOT NULL DEFAULT '#6366f1',
  sistem TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

INSERT INTO kategoriler (id, ad, tur, renk, sistem) VALUES
(1,  'Aidat Tahsilatı',     'gelir', '#10b981', 1),
(2,  'Kira Geliri',         'gelir', '#06b6d4', 0),
(3,  'Faiz / Banka Geliri', 'gelir', '#3b82f6', 0),
(4,  'Diğer Gelir',         'gelir', '#8b5cf6', 0),
(5,  'Elektrik',            'gider', '#f59e0b', 0),
(6,  'Su',                  'gider', '#0ea5e9', 0),
(7,  'Doğalgaz',            'gider', '#ef4444', 0),
(8,  'Temizlik',            'gider', '#14b8a6', 0),
(9,  'Asansör Bakım',       'gider', '#6366f1', 0),
(10, 'Personel / Kapıcı',   'gider', '#ec4899', 0),
(11, 'Bakım & Onarım',      'gider', '#f97316', 0),
(12, 'Bahçe',               'gider', '#22c55e', 0),
(13, 'Sigorta',             'gider', '#64748b', 0),
(14, 'Diğer Gider',         'gider', '#94a3b8', 0);

CREATE TABLE hareketler (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tur          ENUM('gelir','gider') NOT NULL,
  kategori_id  INT UNSIGNED NOT NULL,
  tutar        DECIMAL(12,2) NOT NULL,
  tarih        DATE NOT NULL,
  aciklama     VARCHAR(255) NULL,
  belge_no     VARCHAR(50) NULL,
  belge_dosya  VARCHAR(100) NULL,
  odeme_id     INT UNSIGNED NULL COMMENT 'Aidat tahsilatından otomatik oluşturulduysa',
  kullanici_id INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tarih (tarih),
  FOREIGN KEY (kategori_id)  REFERENCES kategoriler(id),
  FOREIGN KEY (odeme_id)     REFERENCES odemeler(id) ON DELETE CASCADE,
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ---------------------------------------------------------------------
-- Duyurular & işlem kayıtları
-- ---------------------------------------------------------------------
CREATE TABLE duyurular (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baslik       VARCHAR(150) NOT NULL,
  icerik       TEXT NOT NULL,
  onemli       TINYINT(1) NOT NULL DEFAULT 0,
  kullanici_id INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE eposta_kuyrugu (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alici          VARCHAR(190) NOT NULL,
  alici_ad       VARCHAR(150) NULL,
  konu           VARCHAR(255) NOT NULL,
  html           MEDIUMTEXT NOT NULL,
  metin          TEXT NULL,
  tur            VARCHAR(20) NOT NULL DEFAULT 'genel',
  ilgili_id      INT UNSIGNED NULL,
  durum          ENUM('bekliyor','gonderildi','hata','iptal') NOT NULL DEFAULT 'bekliyor',
  deneme         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  hata           VARCHAR(500) NULL,
  sonraki_deneme DATETIME NULL,
  gonderim       DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_durum (durum, sonraki_deneme)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE mesaj_kuyrugu (
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

CREATE TABLE kisa_linkler (
  kod        CHAR(8) NOT NULL PRIMARY KEY,
  token      VARCHAR(255) NOT NULL,
  tiklama    INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE yasal_metinler (
  anahtar    VARCHAR(30) NOT NULL PRIMARY KEY,
  baslik     VARCHAR(150) NOT NULL,
  icerik     MEDIUMTEXT NOT NULL,
  surum      INT UNSIGNED NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE onaylar (
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

CREATE TABLE islem_log (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kullanici_id INT UNSIGNED NULL,
  islem        VARCHAR(80) NOT NULL,
  detay        VARCHAR(500) NULL,
  ip           VARCHAR(45) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tarih (created_at),
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE talepler (
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

CREATE TABLE anketler (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baslik       VARCHAR(180) NOT NULL,
  aciklama     TEXT NULL,
  bitis_tarihi DATE NULL,
  aktif        TINYINT(1) NOT NULL DEFAULT 1,
  kullanici_id INT UNSIGNED NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (kullanici_id) REFERENCES kullanicilar(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE anket_secenekler (
  id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  anket_id INT UNSIGNED NOT NULL,
  secenek  VARCHAR(180) NOT NULL,
  FOREIGN KEY (anket_id) REFERENCES anketler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

CREATE TABLE anket_oylar (
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

CREATE TABLE bakimlar (
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

CREATE TABLE dokumanlar (
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

-- Hoş geldiniz duyurusu
INSERT INTO duyurular (baslik, icerik, onemli, kullanici_id) VALUES
('Sisteme Hoş Geldiniz', 'Apartman yönetim sistemimiz kullanıma açılmıştır. Aidat borçlarınızı ve duyuruları buradan takip edebilirsiniz.', 1, 1);

-- ---------------------------------------------------------------------
-- Örnek veriler (isteğe bağlı - canlıya alırken bu bölümü silebilirsiniz)
-- ---------------------------------------------------------------------
INSERT INTO daireler (blok, daire_no, kat, metrekare, durum, oturan_tipi, malik_adi, malik_tel, sakin_adi, sakin_tel, kisi_sayisi, aidat_katsayi) VALUES
('A','1','Zemin',95,'dolu','malik','Ahmet Yılmaz','0532 111 11 11','Ahmet Yılmaz','0532 111 11 11',3,1.00),
('A','2','1',110,'dolu','kiraci','Mehmet Demir','0533 222 22 22','Ayşe Kaya','0544 333 33 33',2,1.00),
('A','3','1',110,'dolu','malik','Fatma Şahin','0535 444 44 44','Fatma Şahin','0535 444 44 44',4,1.00),
('A','4','2',140,'dolu','malik','Ali Çelik','0536 555 55 55','Ali Çelik','0536 555 55 55',5,1.25),
('B','1','Zemin',95,'bos','malik','Zeynep Arslan','0537 666 66 66',NULL,NULL,0,1.00),
('B','2','1',110,'dolu','kiraci','Hasan Koç','0538 777 77 77','Emre Aydın','0539 888 88 88',2,1.00);
