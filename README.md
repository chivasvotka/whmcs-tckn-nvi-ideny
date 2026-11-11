# WHMCS NVİ Kimlik ve Vergi Numarası Doğrulama

Bu addon, WHMCS 9.x üzerinde müşteri kayıtlarında ve müşteri panelinde T.C. kimlik numarası ile doğum tarihi doğrulaması sağlar. WHMCS çekirdek veya tema dosyalarını değiştirmez.

## Kurulum

1. `modules/addons/nvi_identity` klasörünü WHMCS kurulumundaki aynı konuma yükleyin.
2. Yönetici panelinde **Setup > Addon Modules** bölümünden **NVİ Kimlik Doğrulama** modülünü etkinleştirin.
3. Modül ayarlarından çalışma modunu seçin ve gerekiyorsa WSDL/API ayarlarını kaydedin.
4. NVİ doğrulaması için PHP `soap`; VKN doğrulaması kullanılacaksa PHP `curl` eklentisini etkinleştirin.

## Çalışma modları

- **Kapalı:** Kimlik bilgileri zorunlu değildir.
- **Kayıt sırasında zorunlu:** Kayıt formunda kimlik alanları görünür ve doğrulama başarısızsa hesap oluşturulmaz.
- **Kayıttan sonra zorunlu:** Kayıt formunda bu alanlar gösterilmez; hesap oluşturulabilir. Müşteri hizmet/sipariş almadan önce kimlik bilgilerini müşteri panelindeki Kimlik Bilgileri sayfasından tamamlamalıdır.

Başarılı doğrulama sonrasında kimlik alanları hash ile kilitlenir. Sonraki müşteri/admin/API düzenleme denemeleri WHMCS doğrulama hook'u tarafından reddedilir; hook sonrasına kadar ilerleyen bir güncelleme olursa şifreli kilitli değerler geri yüklenir. Alanlar WHMCS müşteri özel alanı olarak oluşturulduğu için müşteri profilinde ve admin müşteri ekranında görüntülenir.

## NVİ servisi hakkında

Modül varsayılan olarak resmi `KPSPublic.asmx` SOAP WSDL adresini kullanır. NVİ’nin kamuya açık `TCKimlikNoDogrula` operasyonu T.C. kimlik numarası, ad, soyad ve doğum yılını kontrol eder. Servis teknik olarak tam doğum gününü/ayını doğrulamadığı için modül tam tarihi ayrıca biçim/geçerlilik açısından kontrol eder ve saklar; tam gün/ay doğrulaması NVİ yanıtı olarak kabul edilmez.

Resmi servis tarafında yayımlanmış zorunlu bir API anahtarı bulunmadığı için anahtar alanı opsiyoneldir. Özel bir erişim katmanı anahtar istiyorsa modül ayarından anahtarı, HTTP başlığını ve gerekiyorsa `Bearer` gibi başlık ön ekini tanımlayabilirsiniz.

## Güncelleme ve veri koruma

Modül yalnızca `modules/addons/nvi_identity` altında çalışır. WHMCS güncellemelerinde çekirdek dosyalarına yapılan değişikliklere bağlı değildir. Modül devre dışı bırakılırken müşteri özel alanları ve doğrulama kayıtları silinmez; yeniden etkinleştirme mevcut alanları tekrar kullanır.

TCKN kişisel veri olduğundan HTTPS, erişim yetkileri, yedekler ve KVKK aydınlatma/saklama politikası ayrıca işletme tarafından düzenlenmelidir.

## İsteğe bağlı VKN ve vergi dairesi

Addon, kayıt formunda, müşteri profilinde ve admin müşteri ekranında **Vergi Kimlik No** ile **Vergi Dairesi** alanlarını isteğe bağlı gösterir. İki alan da boş bırakılabilir. Vergi dairesi yalnızca bilgi amaçlı saklanır ve doğrulamaya dahil edilmez. VKN yazılırsa 10 rakam ve kontrol basamağı açısından ön kontrolden geçer, ardından yalnızca VKN yapılandırılmış HTTPS endpointinde doğrulanır. Dış doğrulama başarısızsa VKN kaydedilmez. VKN alanları zorunlu modlardan etkilenmez.

NVİ’deki TCKN SOAP hizmetinden farklı olarak, GİB İnteraktif/Dijital Vergi Dairesi için herkese açık, geliştirici dokümanlı VKN doğrulama API sözleşmesi yayımlanmış değildir. IVD web uygulamasının oturum/CSRF kullanan dahili çağrılarını API gibi kullanmak desteklenebilir ve güvenilir bir entegrasyon değildir. Bu nedenle addon sahte bir GİB adresi varsaymaz. VKN girildiğinde admin ayarlarında GİB veya yetkili entegratör tarafından verilmiş HTTPS JSON endpointi, API anahtarı (gerekiyorsa), VKN istek alanı ve yanıttaki boolean geçerlilik yolu yapılandırılmalıdır. Öntanımlı JSON sözleşmesi `{"vkn":"..."}` isteği ve `{"valid":true}` yanıtıdır; sağlayıcının sözleşmesine göre VKN alan adı değiştirilebilir.

Endpoint ayarlanmadan VKN alanı boş bırakılarak kayıt ve hesap düzenleme yapılabilir. VKN giren kullanıcıda endpoint yoksa modül doğrulama yapamadığı için kaydı reddeder; yalnızca biçim/checksum kontrolü GİB doğrulaması olarak kabul edilmez. Endpoint devre dışı bırakılırsa alanlar müşteri tarafında gizlenir; daha önce girilmiş özel alan verileri silinmez. VKN doğrulama tablosunda numaranın kendisi değil yalnızca VKN hash’i ve doğrulama zamanı tutulur.