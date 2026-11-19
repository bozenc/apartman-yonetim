# Apartman Yönetim Sistemi (PHP + MySQL)

Aidat, tahsilat, gelir-gider, raporlama ve rol tabanlı kullanıcı yönetimi içeren apartman / site yönetim paneli.

## Gereksinimler
- PHP 8.0+ (PDO MySQL eklentisi)
- MySQL 5.7+ veya MariaDB 10.3+
- Apache / Nginx (XAMPP, WAMP, Laragon veya paylaşımlı hosting yeterlidir)

## Kurulum (Otomatik Sihirbaz & Hosting Kurucu)

### Yöntem 1: Hosting Üzerine Tek Pakette Otomatik Kurulum (Önerilen)
1. `deploy/apartman-paket.zip` ve `deploy/installer.php` dosyalarını hostinginizin kök klasörüne (`public_html` veya `httpdocs`) yükleyin.
2. Tarayıcıdan `https://alanadiniz.com/installer.php` adresini açın ve **"Paketi Çıkart ve Kurulum Sihirbazını Başlat"** düğmesine tıklayın.
3. `installer.php` arşivi otomatik çıkartır, klasör izinlerini ayarlar, `.zip` ve `installer.php` dosyalarını silip `kurulum.php` sihirbazına yönlendirir.

### Yöntem 2: Doğrudan Klasör Yükleme / Yerel Sunucu (XAMPP, WAMP, Laragon)
1. Klasörü sunucunuza yükleyin (ör. XAMPP için `C:\xampp\htdocs\apartman`).
2. MySQL/MariaDB'nin çalıştığından emin olun (XAMPP Kontrol Paneli → MySQL → Start).
3. Tarayıcıda siteyi açın (ör. `http://localhost/apartman`). **Kurulum sihirbazı otomatik başlar:**
   - **Gereksinimler:** PHP sürümü ve eklentiler kontrol edilir.
   - **Veritabanı:** sunucu, veritabanı adı, kullanıcı ve şifre girilir. Veritabanı yoksa otomatik oluşturulur.
   - **Site & Yönetici:** site bilgileri ve kendi Süper Yönetici hesabınız (şifre en az 8 karakter).
4. Sihirbaz bilgileri ve kriptografik `GIZLI_ANAHTAR` değerini `config.local.php` dosyasına yazar ve `kurulum.kilit` dosyasıyla kendini kilitler.

### Yöntem 3: Komut Satırı (CLI / SSH / VPS) ile Otomatik Kurulum
SSH erişiminiz varsa tek komutla etkileşimsiz kurulum yapabilirsiniz:
```bash
php cli_kurulum.php --db-host=localhost --db-name=apartman_db --db-user=root --db-pass="Sifre" \
  --site-adi="Yeşil Vadi Sitesi" --site-url="https://yesilvadi.com" \
  --admin-user=admin --admin-pass="GucluSifre123!" --admin-email="yonetim@yesilvadi.com"
```

> Daha önce v1.0–v1.4 kurduysanız sihirbaz bunu algılar ve **verilerinizi koruyarak** güncelleme seçeneği sunar.
> Yeniden kurmak için ana klasördeki `kurulum.kilit` dosyasını silin.
> Hosting'de veritabanı oluşturma yetkiniz yoksa veritabanını önce cPanel/Plesk'ten oluşturun.

**Elle kurulum (isteğe bağlı):** `kurulum.sql` dosyasını phpMyAdmin'den içe aktarıp `config.php` içindeki bilgileri düzenleyebilirsiniz. Bu durumda varsayılan giriş `admin` / `admin123` olur.

## Modüller
| Modül | Açıklama |
|---|---|
| Kontrol Paneli | Kasa bakiyesi, aylık gelir-gider, bekleyen alacak, 12 aylık grafik, gider dağılımı, en borçlu daireler |
| Daireler | Blok/daire, malik ve kiracı bilgileri, aidat katsayısı, sakin kullanıcı hesabı bağlama |
| Aidat Dönemleri | Aylık dönem oluşturma, tüm dairelere toplu borçlandırma, katsayı desteği, gecikme bedeli uygulama |
| Borç & Tahsilat | Borç listesi, kısmi ödeme, daire bazlı toplu tahsilat (en eski borçtan başlar), ek/demirbaş borcu |
| Ödeme Bildirimleri | Sakin dekont (PDF/JPG/PNG) yükler, yönetici önizleyip onaylar veya gerekçeyle reddeder; onayda borçlar otomatik kapanır |
| Tahsilat Geçmişi | Tarih/yöntem filtreleri, **PDF makbuz** (tutar yazıyla, doğrulama kodlu), dekont eki, tahsilat iptali |
| Gelirler / Giderler | Kategori bazlı kayıt, CSV (Excel) dışa aktarım, kategori özeti. Aidat tahsilatları otomatik gelir olarak işlenir |
| Raporlar | Yıllık gelir-gider tablosu ve kasa seyri, kategori × ay dökümü, daire bazlı borç durumu |
| Duyurular | Önemli işaretli duyurular; tüm kullanıcılara panelde gösterilir |
| Kullanıcılar | Ekle / düzenle / sil / pasife al, rol atama, daire bağlama, kullanıcıya özel yetki |
| Roller & Yetkiler | Sınırsız rol, 36 ayrıntılı yetki (görüntüleme / değiştirme), yetki matrisi |
| E-posta | Sakinlere otomatik makbuz, dekont onay/red ve duyuru e-postaları; SMTP ayarları, test, gönderim kayıtları |
| SMS & WhatsApp | Netgsm / Twilio / özel HTTP ile SMS; Meta WhatsApp Cloud API / Twilio ile WhatsApp; şablonlar, test, gönderim kayıtları |
| Yasal Metinler | KVKK aydınlatma, gizlilik, çerez, kullanım koşulları, açık rıza; sürüm yönetimi ve onay kayıtları |
| İşlem Kayıtları | Tüm önemli işlemlerin kullanıcı ve IP ile denetim kaydı |
| Ayarlar | Site bilgileri, IBAN, varsayılan aidat, son ödeme günü, gecikme oranı |

## Yetki Sistemi (v1.1)
Her işlem ayrı bir yetkidir ve **Görüntüleme** (salt okunur) ya da **Değiştirme** olarak etiketlenir.
Örneğin daireler için: *görüntüle*, *ekle*, *düzenle*, *sil* ayrı ayrı açılıp kapatılabilir.

Bir kullanıcının yetkisi iki katmanda belirlenir:
1. **Rol** – roldeki herkese uygulanan varsayılan yetkiler (Roller & Yetkiler sayfası).
2. **Kullanıcıya özel istisna** – Kullanıcılar sayfasında 🛡 düğmesi. Her yetki için:
   - **Rolden**: rolün ayarı geçerli
   - **İzin ver**: rolde olmasa da açık
   - **Engelle**: rolde olsa da kapalı

Hızlı şablonlar: *Salt okunur yap*, *Yalnızca kendi borcunu görsün*, *Tüm dairelerin borçlarını görsün*, *Rol varsayılanına dön*.

Örnek senaryolar:
| İstenen | Yapılacak |
|---|---|
| Sakin yalnızca kendi borcunu görsün | Sakin rolü (varsayılan) + kullanıcıya daire bağla |
| Sakin tüm dairelerin borç durumunu görsün ama değiştiremesin | `aidat.goruntule` → İzin ver |
| Borçları görsün ama isim/telefonlar gizlensin | `kisi.goruntule` kapalı bırakın (A*** Y*** şeklinde maskelenir) |
| Bir sakin (ör. kat temsilcisi) tahsilat alabilsin | `odeme.al` → İzin ver |
| Denetçi kasayı görmesin | `kasa.goruntule` → Engelle |

Güvenlik kuralları: Süper Yönetici dışındaki yöneticiler kendilerinde olmayan bir yetkiyi başkasına veremez, kendi yetkilerini değiştiremez ve Süper Yönetici atayamaz.

## Hazır Roller
- **Süper Yönetici** – sistem rolü, tüm yetkiler (silinemez)
- **Yönetici** – sistem ayarları, rol yönetimi ve özel yetki atama hariç tüm işlemler
- **Muhasebe** – aidat, tahsilat, gelir-gider, raporlar
- **Denetçi** – yalnızca görüntüleme yetkileri, hiçbir değişiklik yapamaz
- **Sakin** – yalnızca kendi borçlarını ve makbuzlarını görür

## Dekont ve PDF Makbuz (v1.2)
1. Sakin **Borçlarım** sayfasında *Ödeme Bildir / Dekont Yükle* ile ödediği borçları seçer, tutarı ve banka dekontunu (PDF, JPG veya PNG, en fazla 5 MB) yükler.
2. Yöneticinin panelinde uyarı ve menüde bekleyen sayısı görünür. **Ödeme Bildirimleri** sayfasında dekont önizlenir; tutar kontrol edilip onaylanır ya da gerekçe yazılarak reddedilir.
3. Onaylanan tutar önce sakinin seçtiği borçlara, kalanı en eski borçlara dağıtılır ve kasaya gelir olarak işlenir.
4. Sakin ve yönetici **PDF makbuzu** indirebilir. Makbuzda tutar yazıyla, ödeme kalemleri, kalan borç ve sahteciliğe karşı doğrulama kodu bulunur.

Yönetici, elden/havale tahsilatlarında da isteğe bağlı dekont ekleyebilir. Dekontlar `uploads/dekontlar` klasöründe tutulur, doğrudan erişime kapalıdır ve yalnızca yetkili kişilere gösterilir.
Yetkiler: `bildirim.gonder` (Sakin), `bildirim.onayla` (Yönetici, Muhasebe).

## E-posta Bildirimleri (v1.3)
**Sistem › E-posta** sayfasından açılır. Gmail, Outlook, Yandex ve hosting e-postaları için hazır ayar düğmeleri vardır.

| Ne zaman | Kime | E-postadaki bağlantı |
|---|---|---|
| Tahsilat alındığında (yönetici "Sakine makbuz e-postası gönder" işaretliyse) | Dairenin sakini | Makbuz detayı + PDF indirme |
| Dekont / ödeme bildirimi onaylandığında | Dairenin sakini | Makbuz detayı + PDF indirme |
| Ödeme bildirimi reddedildiğinde | Dairenin sakini | Red nedeni, açık borçlar, IBAN |
| Duyuru yayınlandığında ("e-posta ile gönder" işaretliyse) | E-postası kayıtlı tüm sakinler | Duyurunun tamamı |

- **Alıcılar:** daireye bağlı kullanıcı hesabının e-postası, dairedeki *Sakin E-posta* ve (malik oturuyorsa ya da ayar açıksa) *Malik E-posta*. Sakin, Profilim sayfasından e-posta almayı kapatabilir.
- **Bağlantılar:** E-postadaki bağlantı imzalıdır; sakin şifre girmeden yalnızca o makbuzu/duyuruyu görür (makbuz 180, duyuru 120 gün geçerli). Giriş yapmış kullanıcı doğrudan panel içindeki detay sayfasına yönlendirilir. Bağlantı değiştirilirse geçersiz olur.
- **Gönderim:** E-postalar sıraya alınır ve sayfa yanıtı gönderildikten sonra arka planda iletilir; yönetici beklemez. Başarısız gönderimler 3 kez yeniden denenir. Hostingte düzenli gönderim için isteğe bağlı Cron: `php /yol/apartman/cron_eposta.php` (5 dakikada bir).
- **Gmail için:** 2 Adımlı Doğrulama'yı açıp *Uygulama şifresi* oluşturun ve SMTP şifresi olarak onu girin.
- **Site Adresi:** Bağlantıların sakinlerin evinden açılabilmesi için sitenin internetten erişilebilen adresi girilmelidir (ör. `https://yesilvadi.com`). `localhost` adresleri yalnızca aynı bilgisayarda çalışır.
- SMTP şifresi veritabanında şifrelenmiş olarak saklanır.

## SMS ve WhatsApp Bildirimleri (v1.5)
**Sistem › SMS & WhatsApp** sayfasından açılır. E-postayla aynı olaylarda (makbuz, dekont onay/red, duyuru) kısa mesaj gider; her kanal ve olay ayrı ayrı açılıp kapatılır.

| Kanal | Sağlayıcı | Gerekenler |
|---|---|---|
| SMS | **Netgsm** | Kullanıcı kodu, API şifresi, onaylı SMS başlığı |
| SMS | **Twilio** | Account SID, Auth Token, gönderici numara |
| SMS | **Özel HTTP** | Herhangi bir firmanın API adresi; `{telefon}` `{telefon10}` `{mesaj}` yer tutucuları |
| WhatsApp | **Meta WhatsApp Cloud API** (resmi) | Telefon numarası kimliği, kalıcı erişim anahtarı, onaylı şablon (ör. gövdesi `{{1}}` olan *Utility* şablonu) |
| WhatsApp | **Twilio WhatsApp** | Twilio bilgileri + WhatsApp gönderici numarası |

- Mesaj metinleri düzenlenebilir: `{site} {daire} {tutar} {tarih} {neden} {baslik} {link}`. SMS'te maliyeti düşürmek için **kısa bağlantı** (`goster.php?k=XXXXXXXX`) kullanılır; bağlantı şifresiz olarak yalnızca ilgili makbuzu/duyuruyu açar.
- Türkçe karakter seçeneği kapalıyken ç→c, ş→s yapılır (SMS başına 160 karakter); sayfada her şablonun kaç SMS tutacağı gösterilir.
- Alıcılar: daire sakininin telefonu ve bağlı kullanıcının telefonu (ayar açıksa kiracılı dairelerde malik de). Daire kartındaki *SMS / WhatsApp bildirimi* kutusu veya sakinin Profilim sayfasındaki tercih kapalıysa mesaj gitmez. **Açık rızasını vermediğini/geri aldığını bildiren kullanıcılara WhatsApp gönderilmez.**
- Mesajlar e-postalar gibi sıraya alınır, arka planda gönderilir, hata alınırsa 3 kez yeniden denenir. Yetki/ayar hatasında (ör. süresi dolmuş token) kanal o tur için durdurulur ve hata mesajı kayıtlarda görünür.
- API'siz seçenek: makbuz ve duyuru sayfalarındaki **"WhatsApp'ta aç"** düğmesi, mesajı bağlantısıyla hazır yazılmış olarak WhatsApp'ı açar (ücretsiz, elle gönderim).
- Makbuz, ödeme sonucu ve yönetim duyuruları *bilgilendirme* iletisidir; İYS onayı gerektirmez. Reklam içeriği eklemeyin.

## KVKK, Gizlilik, Çerez ve Sözleşme (v1.5)
- **Sistem › Yasal Metinler**: KVKK Aydınlatma Metni, Gizlilik Politikası, Çerez Politikası, Kullanım Koşulları ve Açık Rıza Metni hazır taslak olarak gelir; `{site_adi} {adres} {kvkk_sorumlu} {kvkk_eposta} {kvkk_kep}` gibi alanlar ayarlardan doldurulur. Düzenleyici canlı önizleme gösterir.
- **"Yeni sürüm olarak yayımla"** dendiğinde, Kullanım Koşulları ve KVKK metni için tüm kullanıcılardan bir sonraki girişte yeniden onay istenir. Yazım düzeltmeleri "Kaydet" ile sürüm değiştirmeden yapılır.
- **Onay ekranı:** İlk girişte kullanıcı, Kullanım Koşulları'nı kabul eder ve KVKK metnini okuduğunu onaylar; açık rıza isteğe bağlıdır. Her onay tarih, sürüm, IP ve tarayıcı bilgisiyle `onaylar` tablosuna yazılır ve yönetim sayfasında listelenir.
- **Çerez bildirimi:** Yalnızca zorunlu oturum çerezi kullanılır. "Yalnızca zorunlu" seçilirse tema tercihi kalıcı saklanmaz (sekme kapanınca silinir). Sayfa altında *Çerez tercihleri* bağlantısıyla seçim değiştirilebilir.
- **Kullanıcı hakları:** Profilim › Gizlilik bölümünden onay geçmişi görülür, açık rıza verilir/geri alınır, **kişisel veriler JSON olarak indirilir** (KVKK md. 11).
- **Saklama/imha:** İşlem kayıtları ayarlanan süreden (varsayılan 730 gün), bildirim kayıtları 365 günden eski olunca her gün otomatik silinir.
- Tüm sayfaların, giriş ekranının ve bildirim e-postalarının altında yasal metin bağlantıları bulunur.
- ⚠️ Hazır metinler genel taslaktır; yayına almadan önce bir hukukçuya kontrol ettirin.

## Sürüm güncelleme
Yeni sürüm dosyalarını eskisinin üzerine kopyalayın (`config.local.php`, `kurulum.kilit` ve `uploads/` klasörünü silmeyin) ve siteyi açın. **Veritabanı güncellemesi otomatik uygulanır**, verileriniz korunur.

## Güvenlik
- PDO hazır sorgular (SQL enjeksiyonuna karşı) ve tahsilat işlemlerinde `FOR UPDATE` satır kilitleme
- `password_hash` ile şifre saklama (minimum 8 karakter zorunluluğu)
- Tüm formlarda ve çıkış işleminde (`logout.php`) CSRF koruması
- XSS'e ve CSV Formula Injection'a karşı çıktı kaçışı
- Veritabanı ve IP/kullanıcı bazlı kaba kuvvet (brute-force) koruması (15 dk'da 5 hatalı deneme)
- Oturum sabitleme koruması, 60 dk hareketsizlik zaman aşımı, Reverse Proxy / Cloudflare uyumlu HttpOnly + Secure çerezler
- Son Süper Yönetici silinemez / pasife alınamaz
- `.htaccess` (ve Nginx yapılandırma örneği) ile `includes/`, `uploads/`, `config.local.php` ve `.sql` dosyalarına doğrudan erişim engeli
- SMTP, Netgsm, Twilio ve Meta anahtarları `config.local.php` içindeki `GIZLI_ANAHTAR` ile AES-256-GCM kullanılarak kimlik doğrulamalı şifreli saklanır
- Özel HTTP entegrasyonlarında SSRF (yerel/özel ağ IP) koruması
- Yüklenen dosyalar uzantıya değil içerik imzasına göre doğrulanır, rastgele adla saklanır ve CSP `sandbox` başlığıyla sunulur

## Dosya Yapısı
```
apartman/
├── deploy/               Hosting otomatik kurucu (installer.php, apartman-paket.zip, paketle.php, nginx.conf.example)
├── kurulum.php           Web kurulum sihirbazı (ilk açılışta otomatik)
├── cli_kurulum.php       Komut satırı (CLI/SSH) otomatik kurulum betiği
├── config.php            Varsayılan ayarlar (config.local.php'yi yükler)
├── kurulum.sql           Veritabanı şeması + başlangıç verileri
├── includes/             Çekirdek (init, auth, functions, pdf, eposta, mesaj, yasal, guncelleme, header, footer)
├── uploads/dekontlar/    Yüklenen dekontlar (web erişimine kapalı)
├── assets/css, assets/js Tema ve betikler
└── *.php                 Sayfalar
```

Arayüz Bootstrap 5, Bootstrap Icons ve Chart.js'i CDN üzerinden yükler; açık/koyu tema ve mobil uyumludur.
