<?php
/**
 * KVKK, gizlilik, çerez ve sözleşme metinleri; kullanıcı onayları ve çerez bildirimi.
 *
 * Metin biçimi (sade işaretleme):  "## Başlık", "- madde", boş satırla ayrılan paragraflar, **kalın**
 * Yer tutucular: {site_adi} {adres} {eposta} {telefon} {vergi_no} {kvkk_sorumlu} {kvkk_eposta} {kvkk_kep} {log_saklama_gun} {tarih}
 */

const YASAL_SIRA = ['kvkk', 'gizlilik', 'cerez', 'sozlesme', 'acik_riza'];
/** Giriş yapan herkesin onaylaması gereken metinler (açık rıza isteğe bağlıdır) */
const YASAL_ZORUNLU = ['sozlesme', 'kvkk'];

function yasal_varsayilan(): array
{
    return [
        'kvkk' => ['KVKK Aydınlatma Metni', <<<'MD'
Bu aydınlatma metni, 6698 sayılı Kişisel Verilerin Korunması Kanunu'nun ("KVKK") 10. maddesi ve Aydınlatma Yükümlülüğünün Yerine Getirilmesinde Uyulacak Usul ve Esaslar Hakkında Tebliğ uyarınca, **{kvkk_sorumlu}** tarafından, apartman/site yönetim sistemini kullanan kat malikleri, kiracılar ve yönetim görevlileri için hazırlanmıştır.

## 1. Veri Sorumlusu
- **Unvan:** {kvkk_sorumlu}
- **Adres:** {adres}
- **E-posta:** {kvkk_eposta}
- **Telefon:** {telefon}

## 2. İşlenen Kişisel Veriler
- **Kimlik:** ad, soyad, kullanıcı adı
- **İletişim:** cep telefonu, e-posta adresi, blok ve daire bilgisi
- **Gayrimenkul:** daire numarası, kat, metrekare, malik/kiracı durumu, oturan kişi sayısı
- **Finans:** aidat ve diğer borç kayıtları, ödeme tutarları ve tarihleri, ödeme yöntemi, makbuzlar, yüklediğiniz banka dekontları
- **İşlem güvenliği:** şifre özeti (şifrenizin kendisi saklanmaz), IP adresi, tarayıcı bilgisi, giriş ve işlem kayıtları
- **Tercihler:** bildirim tercihleri, verdiğiniz onay ve rızalar

## 3. İşleme Amaçları
- 634 sayılı Kat Mülkiyeti Kanunu kapsamında ortak giderlerin ve aidatların belirlenmesi, paylaştırılması ve tahsil edilmesi
- Ödemelerin kaydedilmesi, makbuz düzenlenmesi, borç ve alacak takibi, gerektiğinde yasal takip
- Yönetim duyurularının ve ödeme bilgilendirmelerinin e-posta, SMS veya WhatsApp ile iletilmesi
- Gelir-gider kayıtlarının tutulması, kat malikleri kuruluna ve denetçilere hesap verilmesi
- Sistem güvenliğinin sağlanması, yetkisiz erişimin önlenmesi, işlemlerin denetlenebilir olması
- Yetkili kamu kurum ve kuruluşlarının ve mahkemelerin bilgi taleplerinin karşılanması

## 4. Hukuki Sebepler
Kişisel verileriniz KVKK'nın 5. maddesinin 2. fıkrasında yer alan şu sebeplere dayanılarak işlenir:
- (a) Kanunlarda açıkça öngörülmesi — 634 sayılı Kat Mülkiyeti Kanunu, 213 sayılı Vergi Usul Kanunu
- (c) Kat mülkiyetinden doğan ilişkinin kurulması ve ifası için gerekli olması
- (ç) Veri sorumlusunun hukuki yükümlülüğünü yerine getirebilmesi
- (e) Bir hakkın tesisi, kullanılması veya korunması (ör. aidat alacağının takibi)
- (f) İlgili kişinin temel hak ve özgürlüklerine zarar vermemek kaydıyla veri sorumlusunun meşru menfaati (ör. sistem güvenliği kayıtları)

Sunucuları yurt dışında bulunan hizmetler (WhatsApp, bazı e-posta ve SMS sağlayıcıları) üzerinden yapılan bildirimler için KVKK'nın 9. maddesi kapsamında ayrıca **açık rızanız** istenir. Açık rıza vermemeniz, yönetimle olan ilişkinizi etkilemez.

## 5. Aktarılan Taraflar
Kişisel verileriniz, yukarıdaki amaçlarla sınırlı olarak:
- Kat malikleri kurulu, yönetim kurulu üyeleri ve denetçilere (yalnızca görevleri için gerekli olduğu ölçüde)
- Yetkili kamu kurum ve kuruluşları, mahkemeler ve icra dairelerine
- Hukuki destek alınan avukatlara ve mali müşavirlere
- Barındırma (hosting), e-posta, SMS ve WhatsApp hizmeti veren tedarikçilere — yalnızca hizmetin sunulması için gereken veriler (ad, telefon, e-posta, mesaj içeriği)
aktarılabilir. Yurt dışına aktarım yalnızca açık rızanızla veya KVKK'nın 9. maddesindeki diğer şartlar çerçevesinde yapılır.

## 6. Toplama Yöntemi
Verileriniz; yönetime yazılı veya sözlü bildiriminiz, sistemdeki formlar, yüklediğiniz dekontlar ve sistemin otomatik kayıtları aracılığıyla, kısmen otomatik ve kısmen otomatik olmayan yollarla toplanır.

## 7. Saklama Süresi
- Finansal kayıtlar (borç, ödeme, makbuz, gelir-gider): ilgili mevzuatta öngörülen süre boyunca, en az 10 yıl
- Sistem işlem kayıtları: {log_saklama_gun} gün
- E-posta, SMS ve WhatsApp gönderim kayıtları: en fazla 365 gün
Süresi dolan veriler silinir, yok edilir veya anonim hâle getirilir.

## 8. Haklarınız (KVKK Madde 11)
- Kişisel verilerinizin işlenip işlenmediğini öğrenme ve işlenmişse bilgi talep etme
- İşlenme amacını ve amacına uygun kullanılıp kullanılmadığını öğrenme
- Yurt içinde veya yurt dışında aktarıldığı üçüncü kişileri bilme
- Eksik veya yanlış işlenmişse düzeltilmesini isteme
- KVKK'nın 7. maddesi çerçevesinde silinmesini veya yok edilmesini isteme
- Düzeltme ve silme işlemlerinin aktarılan üçüncü kişilere bildirilmesini isteme
- Münhasıran otomatik sistemlerle analiz edilmesi sonucu aleyhinize bir sonuç çıkmasına itiraz etme
- Kanuna aykırı işleme nedeniyle zarara uğramanız hâlinde zararın giderilmesini talep etme

## 9. Başvuru
Taleplerinizi, Veri Sorumlusuna Başvuru Usul ve Esasları Hakkında Tebliğ'e uygun olarak yazılı şekilde **{adres}** adresine, kayıtlı elektronik posta (KEP) ile **{kvkk_kep}** adresine ya da sistemde kayıtlı e-posta adresinizden **{kvkk_eposta}** adresine iletebilirsiniz. Başvurular en geç 30 gün içinde ücretsiz olarak sonuçlandırılır. Sistemdeki kişisel verilerinizin bir kopyasını **Profilim › Gizlilik** bölümünden dilediğiniz an indirebilirsiniz.

Son güncelleme: {tarih}
MD],

        'gizlilik' => ['Gizlilik Politikası', <<<'MD'
**{site_adi}** yönetim sistemi, sakinlerin aidat, ödeme ve iletişim bilgilerini yönetmek için kullanılır. Bu politika, verilerinizin sistemde nasıl korunduğunu ve kimlerin görebildiğini açıklar. Kişisel verilerin işlenmesine ilişkin ayrıntılı bilgi KVKK Aydınlatma Metni'nde yer alır.

## Kim Neyi Görebilir?
- **Sakinler**, yönetimin verdiği yetkiye göre yalnızca kendi dairelerinin borç, ödeme ve makbuzlarını görür.
- **Yönetim görevlileri**, görevlerinin gerektirdiği kadar bilgiye erişir. Her kullanıcının yetkileri yönetici tarafından ayrı ayrı belirlenir.
- Kişi adları ve telefon numaraları, ilgili yetkisi olmayan kullanıcılara **maskelenerek** gösterilir (ör. "Ay*** Yı***", "0532 *** ** 33").
- E-posta, SMS ve WhatsApp ile gönderilen bağlantılar yalnızca ilgili makbuz veya duyuruyu açar, süreli ve imzalıdır; başka bir bilgiye erişim sağlamaz.

## Güvenlik Önlemleri
- Şifreler geri döndürülemez biçimde (bcrypt) saklanır; yönetim dahil kimse şifrenizi göremez.
- Art arda hatalı girişlerde hesap geçici olarak kilitlenir.
- Formlar sahte isteklere (CSRF) karşı korunur; oturum çerezleri yalnızca sunucu tarafından okunabilir.
- SMTP, SMS ve WhatsApp erişim anahtarları veritabanında şifrelenmiş olarak tutulur.
- Yüklenen dekontların dosya türü denetlenir ve doğrudan internetten erişime kapalıdır.
- Kim, ne zaman, hangi işlemi yaptıysa işlem kayıtlarında saklanır.

## Üçüncü Taraflar
Sistem reklam içermez, verilerinizi satmaz veya pazarlama amacıyla paylaşmaz. Bildirimler için kullanılan e-posta, SMS ve WhatsApp sağlayıcıları verilerinize yalnızca mesajı iletmek için erişir. Sayfa tasarımı için kullanılan yazı tipi ve simge dosyaları içerik dağıtım ağlarından (Google Fonts, jsDelivr) yüklenir; bu sırada IP adresiniz bu hizmetlere iletilir.

## Bildirimler
Makbuz, ödeme bildirimi sonucu ve duyurular bilgilendirme amaçlıdır; ticari ileti değildir. E-posta ve SMS/WhatsApp bildirimlerini **Profilim** sayfasından kapatabilirsiniz.

## Değişiklikler
Bu politika güncellendiğinde yeni sürüm sistemde yayımlanır; önemli değişikliklerde bir sonraki girişinizde size gösterilir.

## İletişim
{kvkk_sorumlu} · {adres} · {eposta} · {telefon}

Son güncelleme: {tarih}
MD],

        'cerez' => ['Çerez Politikası', <<<'MD'
Çerezler, ziyaret ettiğiniz internet sitesinin tarayıcınıza kaydettiği küçük metin dosyalarıdır. **{site_adi}** yönetim sistemi reklam, analiz veya takip çerezi **kullanmaz**.

## Zorunlu Çerezler
Bu çerezler sistemin çalışması için gereklidir ve kapatılamaz. Hukuki sebebi, KVKK md. 5/2-c (hizmetin sunulması) ve 5/2-f (meşru menfaat — güvenlik) hükümleridir.
- **APTYS** — Oturum çerezi. Giriş yaptığınızı hatırlar ve formları sahte isteklere karşı korur. Tarayıcı kapatıldığında silinir.
- **cerez_tercih** — Bu bildirimde yaptığınız seçimi hatırlar. 1 yıl saklanır.

## Tercih (İşlevsel) Depolama
- **tema** — Açık/koyu görünüm tercihiniz, tarayıcınızın yerel depolamasında (localStorage) tutulur ve sunucuya gönderilmez. Yalnızca "Tümünü kabul et" seçeneğini işaretlediyseniz kaydedilir; aksi hâlde tercih yalnızca açık sekme için (sessionStorage) tutulur ve sekmeyi kapattığınızda silinir.

## Üçüncü Taraf Kaynaklar
Yazı tipleri (Google Fonts) ve arayüz kütüphaneleri (jsDelivr) dış sunuculardan yüklenir. Bu hizmetler, dosyaları iletebilmek için IP adresinizi ve tarayıcı bilgilerinizi görebilir; sistemimiz bu hizmetlere çerez yerleştirtmez.

## Tercihlerinizi Yönetme
Seçiminizi değiştirmek için sayfa altındaki **Çerez tercihleri** bağlantısını kullanabilirsiniz. Tarayıcı ayarlarınızdan çerezleri silebilir veya engelleyebilirsiniz; ancak zorunlu çerezleri engellerseniz sisteme giriş yapamazsınız.

Son güncelleme: {tarih}
MD],

        'sozlesme' => ['Kullanım Koşulları', <<<'MD'
Bu koşullar, **{kvkk_sorumlu}** ("Yönetim") tarafından sunulan çevrim içi apartman/site yönetim sisteminin ("Sistem") kat malikleri, kiracılar ve yönetim görevlileri ("Kullanıcı") tarafından kullanımını düzenler. Sisteme giriş yapan ve bu koşulları onaylayan her kullanıcı aşağıdaki hükümleri kabul etmiş sayılır.

## 1. Hizmetin Kapsamı
Sistem; aidat ve ortak gider borçlarının görüntülenmesi, ödeme bildirimi ve dekont yüklenmesi, makbuzların görüntülenmesi, duyuruların takibi ve yönetim işlemlerinin yürütülmesi amacıyla sunulur. Kullanıcının erişebileceği bilgi ve işlemler, Yönetim tarafından tanımlanan yetkilerle sınırlıdır.

## 2. Hesap ve Güvenlik
- Hesap bilgileri kişiye özeldir; başkalarıyla paylaşılamaz.
- Kullanıcı, şifresinin gizliliğinden ve hesabıyla yapılan işlemlerden sorumludur.
- Hesabının yetkisiz kullanıldığını fark eden kullanıcı, durumu derhâl Yönetime bildirir.
- Yönetim, güvenliği tehdit eden durumlarda hesabı geçici olarak askıya alabilir.

## 3. Kullanıcının Yükümlülükleri
- Sisteme girdiği bilgilerin ve yüklediği dekontların doğru ve kendisine ait olması
- Sistemi hukuka, genel ahlaka ve kat mülkiyeti yönetim planına uygun kullanması
- Diğer kullanıcıların kişisel verilerine yetkisiz erişmeye çalışmaması, edindiği bilgileri üçüncü kişilerle paylaşmaması
- Sistemin işleyişini bozacak, güvenliğini aşmaya yönelik girişimlerde bulunmaması

## 4. Ödemeler ve Kayıtlar
- Sistemde yapılan ödeme bildirimi tek başına ödeme yerine geçmez; ödeme, Yönetim tarafından hesaba geçtiği teyit edilip onaylandığında kayda alınır.
- Sistemin ürettiği makbuzlar Yönetimin tahsilat kaydının bir örneğidir. Uyuşmazlık hâlinde banka kayıtları ve Yönetimin resmi defterleri esas alınır.
- Borç tutarları, kat malikleri kurulu kararları ve yönetim planı doğrultusunda Yönetim tarafından belirlenir; itirazlar Yönetime yazılı olarak yapılır.
- Gecikme tazminatı, 634 sayılı Kat Mülkiyeti Kanunu'nun 20. maddesi ve yönetim planı hükümlerine göre uygulanır.

## 5. Bildirimler
Kullanıcı, makbuz, ödeme sonucu ve duyuru bildirimlerinin sistemde kayıtlı e-posta ve telefon bilgilerine gönderilebileceğini kabul eder. Bu bildirimler bilgilendirme amaçlıdır; Kullanıcı bunları Profilim sayfasından kapatabilir. Sistem üzerinden yapılan duyurular, yasal tebligat yerine geçmez.

## 6. Sorumluluğun Sınırlandırılması
Yönetim, Sistemin kesintisiz ve hatasız çalışması için makul özeni gösterir; ancak bakım, altyapı arızaları, internet kesintileri veya üçüncü taraf hizmet sağlayıcılardan kaynaklanan aksaklıklardan ve Kullanıcının hatalı bilgi girişinden doğan sonuçlardan sorumlu tutulamaz.

## 7. Kişisel Veriler
Kişisel veriler, KVKK Aydınlatma Metni, Gizlilik Politikası ve Çerez Politikası'na uygun olarak işlenir. Bu metinler Kullanım Koşulları'nın ayrılmaz parçasıdır.

## 8. Değişiklikler ve Sona Erme
Yönetim bu koşulları güncelleyebilir; güncel sürüm Kullanıcıya bir sonraki girişinde gösterilir ve onayı istenir. Kullanıcının daire ile ilişkisi sona erdiğinde (taşınma, satış vb.) hesabı pasif hâle getirilir; geçmiş finansal kayıtlar yasal saklama süreleri boyunca korunur.

## 9. Uygulanacak Hukuk
Bu koşullar Türkiye Cumhuriyeti hukukuna tabidir. Uyuşmazlıklarda gayrimenkulün bulunduğu yer mahkemeleri ve icra daireleri yetkilidir.

Son güncelleme: {tarih}
MD],

        'acik_riza' => ['Açık Rıza Metni', <<<'MD'
**{kvkk_sorumlu}** tarafından KVKK Aydınlatma Metni kapsamında bilgilendirildim.

Makbuz, ödeme bildirimi sonucu ve duyuru bildirimlerinin bana iletilebilmesi amacıyla; adım-soyadım, cep telefonu numaram, e-posta adresim, daire bilgim ve bildirim içeriğinin, sunucuları **yurt dışında** bulunan aşağıdaki hizmet sağlayıcılara aktarılmasına ve onlar tarafından işlenmesine, KVKK'nın 5. ve 9. maddeleri uyarınca **açık rızam ile onay veriyorum**:
- WhatsApp mesajları için Meta Platforms Ireland Ltd. (WhatsApp Business) veya Twilio Inc.
- Yönetimin kullandığı e-posta veya SMS hizmetinin sunucuları yurt dışında ise ilgili hizmet sağlayıcı

Bu rıza **isteğe bağlıdır**. Vermemem veya daha sonra geri almam hâlinde bana yurt dışı sunucu kullanan kanallardan (ör. WhatsApp) bildirim gönderilmez; yurt içi kanallar, sistem içi bildirimler ve yönetimle olan diğer ilişkilerim bundan etkilenmez. Rızamı dilediğim an **Profilim › Gizlilik** bölümünden geri alabilirim.

Son güncelleme: {tarih}
MD],
    ];
}

/** Metni getir (yoksa varsayılanı veritabanına yazar) */
function yasal_metin(string $anahtar): ?array
{
    global $pdo;
    static $onbellek = [];
    if (isset($onbellek[$anahtar])) return $onbellek[$anahtar];
    $v = yasal_varsayilan();
    if (!isset($v[$anahtar])) return null;
    $st = $pdo->prepare('SELECT * FROM yasal_metinler WHERE anahtar = ?');
    $st->execute([$anahtar]);
    $m = $st->fetch();
    if (!$m) {
        $pdo->prepare('INSERT IGNORE INTO yasal_metinler (anahtar, baslik, icerik, surum) VALUES (?, ?, ?, 1)')->execute([$anahtar, $v[$anahtar][0], $v[$anahtar][1]]);
        $st->execute([$anahtar]);
        $m = $st->fetch();
    }
    return $onbellek[$anahtar] = $m;
}

/** Yer tutucuları doldur */
function yasal_doldur(string $metin, ?string $tarih = null): string
{
    $site = ayar('site_adi', 'Apartman Yönetimi');
    $yok = '—';
    return strtr($metin, [
        '{site_adi}'        => $site,
        '{adres}'           => ayar('adres') ?: $yok,
        '{eposta}'          => ayar('eposta') ?: $yok,
        '{telefon}'         => ayar('telefon') ?: $yok,
        '{vergi_no}'        => ayar('vergi_no') ?: $yok,
        '{kvkk_sorumlu}'    => ayar('kvkk_sorumlu') ?: $site . ' Yönetimi',
        '{kvkk_eposta}'     => ayar('kvkk_eposta') ?: (ayar('eposta') ?: $yok),
        '{kvkk_kep}'        => ayar('kvkk_kep') ?: $yok,
        '{log_saklama_gun}' => ayar('log_saklama_gun', '730'),
        '{tarih}'           => $tarih ? tarih($tarih) : date('d.m.Y'),
    ]);
}

/** Sade işaretlemeyi güvenli HTML'e çevir */
function yasal_html(string $metin): string
{
    $satirlar = preg_split('/\R/', trim($metin));
    $html = '';
    $liste = false;
    $paragraf = [];
    $satirIci = fn($s) => preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', e($s));
    $paragrafBitir = function () use (&$paragraf, &$html, $satirIci) {
        if ($paragraf) { $html .= '<p>' . implode('<br>', array_map($satirIci, $paragraf)) . '</p>'; $paragraf = []; }
    };
    foreach ($satirlar as $s) {
        $s = rtrim($s);
        if (preg_match('/^- (.*)$/u', $s, $m)) {
            $paragrafBitir();
            if (!$liste) { $html .= '<ul>'; $liste = true; }
            $html .= '<li>' . $satirIci($m[1]) . '</li>';
            continue;
        }
        if ($liste) { $html .= '</ul>'; $liste = false; }
        if ($s === '') { $paragrafBitir(); continue; }
        if (preg_match('/^#{2,3} (.*)$/u', $s, $m)) { $paragrafBitir(); $html .= '<h5>' . $satirIci($m[1]) . '</h5>'; continue; }
        $paragraf[] = $s;
    }
    $paragrafBitir();
    if ($liste) $html .= '</ul>';
    return $html;
}

/** Kullanıcının her metin için son kaydı [metin => satır] */
function kullanici_onaylari(int $kullaniciId): array
{
    global $pdo;
    $st = $pdo->prepare('SELECT o.* FROM onaylar o JOIN (SELECT metin, MAX(id) id FROM onaylar WHERE kullanici_id = ? GROUP BY metin) s ON s.id = o.id');
    $st->execute([$kullaniciId]);
    $r = [];
    foreach ($st->fetchAll() as $o) $r[$o['metin']] = $o;
    return $r;
}

/** Güncel sürümü henüz onaylanmamış zorunlu metinler */
function yasal_bekleyenler(int $kullaniciId): array
{
    $onay = kullanici_onaylari($kullaniciId);
    $bekleyen = [];
    foreach (YASAL_ZORUNLU as $a) {
        $m = yasal_metin($a);
        $o = $onay[$a] ?? null;
        if (!$o || $o['durum'] !== 'onay' || (int)$o['surum'] < (int)$m['surum']) $bekleyen[] = $a;
    }
    return $bekleyen;
}

function onay_kaydet(int $kullaniciId, string $metin, string $durum = 'onay'): void
{
    global $pdo;
    $m = yasal_metin($metin);
    $pdo->prepare('INSERT INTO onaylar (kullanici_id, metin, surum, durum, ip, tarayici) VALUES (?,?,?,?,?,?)')
        ->execute([$kullaniciId, $metin, (int)$m['surum'], $durum, $_SERVER['REMOTE_ADDR'] ?? null, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'cli', 0, 255)]);
}

/** Giriş yapan kullanıcıyı gerekirse onay sayfasına yönlendirir (giris_zorunlu içinden çağrılır) */
function yasal_kapi(array $u): void
{
    static $kontrol = false;
    if ($kontrol) return;
    $kontrol = true;
    if (ayar('yasal_onay_zorunlu', '1') !== '1') return;
    if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['onay.php', 'logout.php', 'yasal.php', 'veri_indir.php'], true)) return;
    if (PHP_SAPI === 'cli') return;
    if (!empty($_SESSION['yasal_tamam']) && $_SESSION['yasal_tamam'] === yasal_surum_imzasi()) return;
    if (yasal_bekleyenler((int)$u['id'])) {
        if (empty($_SESSION['donus'])) $_SESSION['donus'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('onay.php');
    }
    $_SESSION['yasal_tamam'] = yasal_surum_imzasi();
}

/** Zorunlu metinlerin sürüm imzası (oturumda önbellek için) */
function yasal_surum_imzasi(): string
{
    $p = [];
    foreach (YASAL_ZORUNLU as $a) $p[] = $a . (yasal_metin($a)['surum'] ?? 0);
    return implode('|', $p);
}

/** Yasal bağlantılar (alt bilgi için) */
function yasal_linkler(string $sinif = ''): string
{
    $l = ['kvkk' => 'KVKK', 'gizlilik' => 'Gizlilik', 'cerez' => 'Çerezler', 'sozlesme' => 'Kullanım Koşulları'];
    $h = [];
    foreach ($l as $k => $ad) $h[] = '<a href="' . url('yasal.php?metin=' . $k) . '" class="' . $sinif . '">' . $ad . '</a>';
    if (ayar('cerez_bildirimi', '1') === '1') $h[] = '<a href="#" class="' . $sinif . '" data-cerez-ayar>Çerez tercihleri</a>';
    return implode(' · ', $h);
}

/** Çerez bildirimi (alt bilgiden önce basılır) */
function cerez_bandi(): string
{
    if (ayar('cerez_bildirimi', '1') !== '1') return '';
    $gizli = isset($_COOKIE['cerez_tercih']) ? ' d-none' : '';
    return '<div id="cerezBandi" class="cerez-bandi' . $gizli . '" role="dialog" aria-live="polite" aria-label="Çerez bildirimi">'
        . '<div class="cerez-icerik"><i class="bi bi-cookie"></i><div><strong>Çerezler</strong><br>'
        . 'Bu sistem yalnızca oturumunuz için <strong>zorunlu çerezler</strong> kullanır. Tema tercihinizi tarayıcınızda saklamak için izninizi isteriz. Reklam veya takip çerezi kullanılmaz. '
        . '<a href="' . url('yasal.php?metin=cerez') . '">Çerez Politikası</a></div></div>'
        . '<div class="cerez-dugmeler"><button type="button" class="btn btn-light btn-sm" data-cerez="zorunlu">Yalnızca zorunlu</button>'
        . '<button type="button" class="btn btn-primary btn-sm" data-cerez="tumu">Tümünü kabul et</button></div></div>'
        . '<script>window.CEREZ_YOL=' . json_encode(rtrim(BASE_URL, '/') . '/') . ';</script>';
}
