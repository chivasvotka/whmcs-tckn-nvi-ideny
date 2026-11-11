<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

const NVI_IDENTITY_MODULE = 'nvi_identity';
const NVI_IDENTITY_TABLE = 'mod_nvi_identity_verifications';
const NVI_TAX_TABLE = 'mod_nvi_identity_tax_verifications';
const NVI_TAX_NUMBER_FIELD = 'Vergi Kimlik No';
const NVI_TAX_OFFICE_FIELD = 'Vergi Dairesi';
const NVI_TCKN_FIELD = 'T.C. Kimlik No';
const NVI_BIRTHDATE_FIELD = 'Kimlik Doğum Tarihi';

function nvi_identity_config()
{
    return array(
        'name' => 'NVİ Kimlik Doğrulama',
        'description' => 'T.C. kimlik ve isteğe bağlı VKN doğrulama modülü.',
        'version' => '1.1.0',
        'author' => 'Custom Module',
        'language' => 'turkish',
        'fields' => array(
            'identitymode' => array(
                'FriendlyName' => 'Kimlik bilgisi zorunluluğu',
                'Type' => 'dropdown',
                'Options' => 'Kapalı,Kayıt sırasında zorunlu,Kayıttan sonra zorunlu',
                'Default' => 'Kapalı',
                'Description' => 'Zorunlu modlar müşteri hesapları ve siparişlerde uygulanır.',
            ),
            'wsdlurl' => array(
                'FriendlyName' => 'NVİ SOAP WSDL adresi',
                'Type' => 'text',
                'Size' => '80',
                'Default' => 'https://tckimlik.nvi.gov.tr/Service/KPSPublic.asmx?WSDL',
                'Description' => 'Resmi servis erişiminiz farklı bir adres gerektiriyorsa buradan değiştirin.',
            ),
            'apikey' => array(
                'FriendlyName' => 'API anahtarı (opsiyonel)',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'NVİ kamu servisinde anahtar şartı yayımlanmamıştır. Sağlayıcınız istiyorsa doldurun.',
            ),
            'apikeyheader' => array(
                'FriendlyName' => 'API anahtar HTTP başlığı',
                'Type' => 'text',
                'Size' => '40',
                'Default' => 'X-API-Key',
                'Description' => 'Anahtar, SOAP isteğinde bu HTTP başlığıyla gönderilir.',
            ),
            'apikeyprefix' => array(
                'FriendlyName' => 'API anahtar ön eki (opsiyonel)',
                'Type' => 'text',
                'Size' => '20',
                'Default' => '',
                'Description' => 'Örneğin Authorization başlığı için Bearer.',
            ),
            'gibvknenabled' => array(
                'FriendlyName' => 'GİB VKN alanlarını göster',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Alanlar isteğe bağlıdır. Kapatılırsa müşteri tarafında gösterilmez.',
            ),
            'gibvknurl' => array(
                'FriendlyName' => 'GİB VKN doğrulama endpointi',
                'Type' => 'text',
                'Size' => '80',
                'Description' => 'GİB veya yetkili entegratör tarafından sağlanan HTTPS JSON endpointi. VKN girildiğinde zorunludur.',
            ),
            'gibapikey' => array(
                'FriendlyName' => 'GİB API anahtarı (opsiyonel)',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'Endpoint sağlayıcınız anahtar istiyorsa doldurun.',
            ),
            'gibapikeyheader' => array(
                'FriendlyName' => 'GİB API anahtar başlığı',
                'Type' => 'text',
                'Size' => '40',
                'Default' => 'Authorization',
                'Description' => 'Anahtarın gönderileceği HTTP başlığı.',
            ),
            'gibapikeyprefix' => array(
                'FriendlyName' => 'GİB API anahtar ön eki',
                'Type' => 'text',
                'Size' => '20',
                'Default' => 'Bearer',
                'Description' => 'Örneğin Bearer. Gerekmiyorsa boş bırakın.',
            ),
            'gibvknfield' => array(
                'FriendlyName' => 'GİB istek VKN alanı',
                'Type' => 'text',
                'Size' => '30',
                'Default' => 'vkn',
                'Description' => 'JSON POST gövdesindeki VKN alan adı.',
            ),
            'gibresponsepath' => array(
                'FriendlyName' => 'GİB yanıt geçerlilik yolu',
                'Type' => 'text',
                'Size' => '30',
                'Default' => 'valid',
                'Description' => 'JSON yanıtındaki boolean alanı. Örnek: valid veya data.valid.',
            ),
            'gibtimeout' => array(
                'FriendlyName' => 'GİB bağlantı zaman aşımı',
                'Type' => 'text',
                'Size' => '8',
                'Default' => '10',
                'Description' => 'Saniye cinsinden, 3 ile 30 arasında.',
            ),
        ),
    );
}

function nvi_identity_activate()
{
    try {
        if (!Capsule::schema()->hasTable(NVI_IDENTITY_TABLE)) {
            Capsule::schema()->create(NVI_IDENTITY_TABLE, function ($table) {
                $table->increments('id');
                $table->unsignedInteger('client_id')->unique();
                $table->string('identity_hash', 64)->nullable();
                $table->text('locked_tckn')->nullable();
                $table->text('locked_birthdate')->nullable();
                $table->boolean('verified')->default(false);
                $table->dateTime('verified_at')->nullable();
                $table->timestamps();
            });
        }

        nvi_identity_ensure_lock_columns();
        nvi_identity_ensure_tax_table();

        nvi_identity_ensure_custom_fields();

        return array(
            'status' => 'success',
            'description' => 'Modül etkinleştirildi. NVİ servis erişimini ve SOAP PHP eklentisini kontrol edin.',
        );
    } catch (Throwable $exception) {
        return array(
            'status' => 'error',
            'description' => 'Modül etkinleştirilemedi: ' . $exception->getMessage(),
        );
    }
}

function nvi_identity_deactivate()
{
    try {
        Capsule::table('tbladdonmodules')
            ->where('module', NVI_IDENTITY_MODULE)
            ->where('setting', 'identitymode')
            ->update(array('value' => 'Kapalı'));
        if (Capsule::schema()->hasTable('tblcustomfields')) {
            Capsule::table('tblcustomfields')
                ->where('type', 'client')
                ->whereIn('fieldname', array(NVI_TCKN_FIELD, NVI_BIRTHDATE_FIELD, NVI_TAX_NUMBER_FIELD, NVI_TAX_OFFICE_FIELD))
                ->update(array('required' => '', 'showorder' => '', 'adminonly' => 'on'));
        }
        return array(
            'status' => 'success',
            'description' => 'Modül devre dışı bırakıldı. Doğrulama kayıtları ve müşteri alanları veri kaybını önlemek için korundu.',
        );
    } catch (Throwable $exception) {
        return array(
            'status' => 'error',
            'description' => 'Modül devre dışı bırakılamadı: ' . $exception->getMessage(),
        );
    }
}

function nvi_identity_upgrade($vars)
{
    try {
        if (Capsule::schema()->hasTable(NVI_IDENTITY_TABLE)) {
            nvi_identity_ensure_lock_columns();
        }
        nvi_identity_ensure_tax_table();
        nvi_identity_ensure_custom_fields();

        return array(
            'status' => 'success',
            'description' => 'NVİ Kimlik Doğrulama modülü veri yapısı güncellendi.',
        );
    } catch (Throwable $exception) {
        return array(
            'status' => 'error',
            'description' => 'Modül güncellenemedi: ' . $exception->getMessage(),
        );
    }
}

function nvi_identity_output($vars)
{
    nvi_identity_sync_field_settings();
    $settings = nvi_identity_settings();
    $mode = nvi_identity_mode($settings['identitymode'] ?? 'Kapalı');
    $soapAvailable = class_exists('SoapClient');
    $curlAvailable = function_exists('curl_init');
    $taxEnabled = nvi_identity_setting_enabled($settings['gibvknenabled'] ?? 'on');
    $taxEndpoint = trim((string) ($settings['gibvknurl'] ?? ''));

    echo '<h2>NVİ Kimlik Doğrulama</h2>';
    echo '<p>Çalışma modu: <strong>' . htmlspecialchars(nvi_identity_mode_label($mode), ENT_QUOTES, 'UTF-8') . '</strong></p>';
    echo '<p>SOAP eklentisi: <strong>' . ($soapAvailable ? 'Yüklü' : 'Yüklü değil') . '</strong></p>';
    echo '<p>Kimlik alanları etkinleştirme sırasında oluşturulur ve müşteri profillerinde görünür. Modül kapatılırken müşteri verileri silinmez.</p>';
    echo '<p>NVİ kamuya açık teknik servisi için anahtar şartı yayımlanmamıştır. Resmi servis erişim koşullarını NVİ’den teyit edin.</p>';
    echo '<h3>Vergi Kimlik Numarası</h3>';
    echo '<p>Vergi alanları: <strong>' . ($taxEnabled ? 'Açık ve isteğe bağlı' : 'Kapalı') . '</strong></p>';
    echo '<p>PHP cURL: <strong>' . ($curlAvailable ? 'Yüklü' : 'Yüklü değil') . '</strong></p>';
    echo '<p>HTTPS doğrulama endpointi: <strong>' . ($taxEndpoint !== '' && preg_match('#^https://#i', $taxEndpoint) ? 'Tanımlı' : 'Tanımlı değil') . '</strong></p>';
    echo '<p>GİB’in herkese açık geliştirici API sözleşmesi bulunmadığından, VKN giren kullanıcılar için GİB/yetkili sağlayıcı endpointi ayarlanmalıdır. Endpoint olmadan dış servis doğrulaması yapılamaz.</p>';
}

function nvi_identity_clientarea($vars)
{
    $clientId = nvi_identity_current_client_id();
    if (!$clientId) {
        return array('pagetitle' => 'Kimlik Bilgisi Doğrulama', 'templatefile' => 'clientarea', 'requirelogin' => true, 'vars' => array());
    }

    $settings = nvi_identity_settings();
    $mode = nvi_identity_mode($settings['identitymode'] ?? 'Kapalı');
    $identityDisabled = $mode === 'disabled';
    $values = nvi_identity_get_client_fields($clientId);
    $error = '';
    $success = '';
    $taxError = '';
    $taxSuccess = '';

    $identityPost = isset($_POST['nvi_tckn']) || isset($_POST['nvi_birthdate']);
    $taxPost = isset($_POST['nvi_tax_number']) || isset($_POST['nvi_tax_office']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $identityPost && !$identityDisabled && nvi_identity_is_verified($clientId)) {
        $error = 'Kimlik bilgileriniz daha önce doğrulandı ve değiştirilemez.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $identityPost && !$identityDisabled) {
        try {
            if (function_exists('check_token')) {
                check_token('WHMCS.default');
            }

            $tckn = nvi_identity_normalize_tckn($_POST['nvi_tckn'] ?? '');
            $birthDate = trim((string) ($_POST['nvi_birthdate'] ?? ''));
            $date = DateTime::createFromFormat('!Y-m-d', $birthDate);

            if (!$date || $date->format('Y-m-d') !== $birthDate || $date > new DateTime('today')) {
                throw new InvalidArgumentException('Doğum tarihini YYYY-AA-GG biçiminde ve geçerli bir tarih olarak girin.');
            }

            nvi_identity_assert_tckn($tckn);
            $client = Capsule::table('tblclients')->where('id', $clientId)->first();
            if (!$client) {
                throw new RuntimeException('Müşteri hesabı bulunamadı.');
            }

            $verification = nvi_identity_verify($tckn, $client->firstname, $client->lastname, (int) $date->format('Y'), $settings);
            if ($verification === false) {
                throw new InvalidArgumentException('Kimlik bilgileri NVİ kayıtlarıyla eşleşmedi. Ad, soyad, T.C. kimlik numarası ve doğum yılını kontrol edin.');
            }

            nvi_identity_store_client_fields($clientId, $tckn, $birthDate);
            nvi_identity_mark_verified($clientId, $tckn, $birthDate);
            $values = array('tckn' => $tckn, 'birthdate' => $birthDate);
            $success = 'Kimlik bilgileriniz doğrulandı ve değiştirilemez olarak kaydedildi.';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            $error = 'Kimlik doğrulama servisine şu anda ulaşılamıyor. Bilgileriniz kaydedilmedi; lütfen daha sonra tekrar deneyin.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $taxPost) {
        try {
            if (function_exists('check_token')) {
                check_token('WHMCS.default');
            }
            $taxNumber = nvi_identity_normalize_tckn($_POST['nvi_tax_number'] ?? '');
            $taxOffice = trim((string) ($_POST['nvi_tax_office'] ?? ''));
            if ($taxNumber === '') {
                Capsule::table(NVI_TAX_TABLE)->where('client_id', $clientId)->delete();
                nvi_identity_store_tax_fields($clientId, '', $taxOffice);
                $values['tax_number'] = '';
                $values['tax_office'] = $taxOffice;
                $taxSuccess = 'Vergi numarası kaldırıldı; vergi dairesi kaydedildi.';
            } else {
                if (!nvi_identity_verify_vkn($taxNumber, $settings)) {
                    throw new InvalidArgumentException('Vergi numarası doğrulanamadı.');
                }
                nvi_identity_store_tax_fields($clientId, $taxNumber, $taxOffice);
                Capsule::table(NVI_TAX_TABLE)->updateOrInsert(
                    array('client_id' => $clientId),
                    array(
                        'tax_hash' => nvi_identity_tax_hash($taxNumber),
                        'verified_at' => date('Y-m-d H:i:s'),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    )
                );
                $values['tax_number'] = $taxNumber;
                $values['tax_office'] = $taxOffice;
                $taxSuccess = 'Vergi bilgileriniz doğrulanıp kaydedildi.';
            }
        } catch (InvalidArgumentException $exception) {
            $taxError = $exception->getMessage();
        } catch (Throwable $exception) {
            $taxError = 'Vergi bilgileri kaydedilemedi. ' . $exception->getMessage();
        }
    }

    return array(
        'pagetitle' => 'Kimlik Bilgisi Doğrulama',
        'breadcrumb' => array('index.php?m=' . NVI_IDENTITY_MODULE => 'Kimlik Bilgisi Doğrulama'),
        'templatefile' => 'clientarea',
        'requirelogin' => true,
        'forcessl' => true,
        'vars' => array(
            'identityDisabled' => $identityDisabled,
            'identityMode' => $mode,
            'identityVerified' => nvi_identity_is_verified($clientId),
            'identityTckn' => $values['tckn'],
            'identityBirthdate' => $values['birthdate'],
            'identityError' => $error,
            'identitySuccess' => $success,
            'taxFieldsEnabled' => nvi_identity_setting_enabled($settings['gibvknenabled'] ?? 'on'),
            'taxNumber' => $values['tax_number'],
            'taxOffice' => $values['tax_office'],
            'taxVerified' => nvi_identity_is_tax_verified($clientId),
            'taxError' => $taxError,
            'taxSuccess' => $taxSuccess,
            'identityToken' => $vars['token'] ?? (function_exists('generate_token') ? generate_token('plain') : ''),
        ),
    );
}

function nvi_identity_settings()
{
    try {
        return Capsule::table('tbladdonmodules')
            ->where('module', NVI_IDENTITY_MODULE)
            ->pluck('value', 'setting')
            ->all();
    } catch (Throwable $exception) {
        return array();
    }
}

function nvi_identity_mode($value)
{
    $value = function_exists('mb_strtolower')
        ? mb_strtolower(trim((string) $value), 'UTF-8')
        : strtolower(trim((string) $value));
    if (in_array($value, array('kayıt sırasında zorunlu', 'register_required', 'register'), true)) {
        return 'register';
    }
    if (in_array($value, array('kayıttan sonra zorunlu', 'after_registration_required', 'after_registration'), true)) {
        return 'after_registration';
    }
    return 'disabled';
}

function nvi_identity_mode_label($mode)
{
    if ($mode === 'register') {
        return 'Kayıt sırasında zorunlu';
    }
    if ($mode === 'after_registration') {
        return 'Kayıttan sonra zorunlu';
    }
    return 'Kapalı';
}

function nvi_identity_ensure_custom_fields()
{
    $fields = array(
        NVI_TCKN_FIELD => array('description' => 'NVİ kimlik doğrulama modülü alanı: 11 haneli T.C. kimlik numarası.', 'regexpr' => '^\\d{11}$'),
        NVI_BIRTHDATE_FIELD => array('description' => 'NVİ kimlik doğrulama modülü alanı: YYYY-AA-GG biçiminde doğum tarihi.', 'regexpr' => '^\\d{4}-\\d{2}-\\d{2}$'),
        NVI_TAX_NUMBER_FIELD => array('description' => 'İsteğe bağlı VKN. Girilirse yapılandırılmış doğrulama endpointinde doğrulanır.', 'regexpr' => '^[0-9]{10}$'),
        NVI_TAX_OFFICE_FIELD => array('description' => 'İsteğe bağlı vergi dairesi adı.', 'regexpr' => ''),
    );

    foreach ($fields as $name => $details) {
        $field = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->where('fieldname', $name)
            ->first();

        if (!$field) {
            Capsule::table('tblcustomfields')->insert(array(
                'type' => 'client',
                'relid' => 0,
                'fieldname' => $name,
                'fieldtype' => 'text',
                'description' => $details['description'],
                'fieldoptions' => '',
                'regexpr' => $details['regexpr'],
                'adminonly' => '',
                'required' => '',
                'showorder' => '',
                'showinvoice' => '',
                'sortorder' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ));
        }
    }
}

function nvi_identity_sync_field_settings()
{
    static $synced = false;
    if ($synced || !Capsule::schema()->hasTable('tblcustomfields')) {
        return;
    }
    $synced = true;

    $mode = nvi_identity_mode(nvi_identity_settings()['identitymode'] ?? 'Kapalı');
    $settings = nvi_identity_settings();
    $required = $mode === 'register' ? 'on' : '';
    $showOrder = $mode === 'register' ? 'on' : '';
    $adminOnly = $mode === 'disabled' ? 'on' : '';

    Capsule::table('tblcustomfields')
        ->where('type', 'client')
        ->whereIn('fieldname', array(NVI_TCKN_FIELD, NVI_BIRTHDATE_FIELD))
        ->update(array('required' => $required, 'showorder' => $showOrder, 'adminonly' => $adminOnly));

    $taxEnabled = nvi_identity_setting_enabled($settings['gibvknenabled'] ?? 'on');
    Capsule::table('tblcustomfields')
        ->where('type', 'client')
        ->whereIn('fieldname', array(NVI_TAX_NUMBER_FIELD, NVI_TAX_OFFICE_FIELD))
        ->update(array(
            'required' => '',
            'showorder' => $taxEnabled ? 'on' : '',
            'adminonly' => $taxEnabled ? '' : 'on',
        ));
}

function nvi_identity_setting_enabled($value)
{
    return !in_array(strtolower(trim((string) $value)), array('', '0', 'off', 'no', 'false'), true);
}

function nvi_identity_ensure_tax_table()
{
    if (Capsule::schema()->hasTable(NVI_TAX_TABLE)) {
        return;
    }

    Capsule::schema()->create(NVI_TAX_TABLE, function ($table) {
        $table->increments('id');
        $table->unsignedInteger('client_id')->unique();
        $table->string('tax_hash', 64);
        $table->dateTime('verified_at');
        $table->timestamps();
    });
}

function nvi_identity_field_ids()
{
    $fields = Capsule::table('tblcustomfields')
        ->where('type', 'client')
        ->whereIn('fieldname', array(NVI_TCKN_FIELD, NVI_BIRTHDATE_FIELD, NVI_TAX_NUMBER_FIELD, NVI_TAX_OFFICE_FIELD))
        ->pluck('id', 'fieldname')
        ->all();

    return array(
        'tckn' => isset($fields[NVI_TCKN_FIELD]) ? (int) $fields[NVI_TCKN_FIELD] : 0,
        'birthdate' => isset($fields[NVI_BIRTHDATE_FIELD]) ? (int) $fields[NVI_BIRTHDATE_FIELD] : 0,
        'tax_number' => isset($fields[NVI_TAX_NUMBER_FIELD]) ? (int) $fields[NVI_TAX_NUMBER_FIELD] : 0,
        'tax_office' => isset($fields[NVI_TAX_OFFICE_FIELD]) ? (int) $fields[NVI_TAX_OFFICE_FIELD] : 0,
    );
}

function nvi_identity_get_client_fields($clientId)
{
    $ids = nvi_identity_field_ids();
    $values = array('tckn' => '', 'birthdate' => '', 'tax_number' => '', 'tax_office' => '');
    foreach ($ids as $key => $fieldId) {
        if (!$fieldId) {
            continue;
        }
        $value = Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $clientId)
            ->value('value');
        $values[$key] = (string) ($value ?? '');
    }
    return $values;
}

function nvi_identity_store_client_fields($clientId, $tckn, $birthDate)
{
    $ids = nvi_identity_field_ids();
    foreach (array('tckn' => $tckn, 'birthdate' => $birthDate) as $key => $value) {
        $fieldId = $ids[$key];
        if (!$fieldId) {
            throw new RuntimeException('Kimlik alanları bulunamadı. Modülü devre dışı bırakıp yeniden etkinleştirin.');
        }
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
            array('fieldid' => $fieldId, 'relid' => $clientId),
            array('value' => $value)
        );
    }
}

function nvi_identity_store_tax_fields($clientId, $taxNumber, $taxOffice)
{
    $ids = nvi_identity_field_ids();
    foreach (array('tax_number' => $taxNumber, 'tax_office' => $taxOffice) as $key => $value) {
        if (!$ids[$key]) {
            throw new RuntimeException('Vergi alanları bulunamadı. Modülü güncelleyin veya yeniden etkinleştirin.');
        }
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
            array('fieldid' => $ids[$key], 'relid' => (int) $clientId),
            array('value' => $value)
        );
    }
}

function nvi_identity_tax_hash($taxNumber)
{
    return hash('sha256', $taxNumber);
}

function nvi_identity_is_tax_verified($clientId)
{
    if (!Capsule::schema()->hasTable(NVI_TAX_TABLE)) {
        return false;
    }
    $record = Capsule::table(NVI_TAX_TABLE)->where('client_id', (int) $clientId)->first();
    if (!$record) {
        return false;
    }

    $values = nvi_identity_get_client_fields($clientId);
    return $values['tax_number'] !== ''
        && hash_equals((string) $record->tax_hash, nvi_identity_tax_hash($values['tax_number']));
}

function nvi_identity_assert_vkn_format($taxNumber)
{
    if (!preg_match('/^[0-9]{10}$/', $taxNumber)) {
        throw new InvalidArgumentException('Vergi kimlik numarası 10 rakamdan oluşmalıdır.');
    }

    $sum = 0;
    for ($position = 0; $position < 9; $position++) {
        $digit = (int) $taxNumber[$position];
        $weight = 9 - $position;
        $value = ($digit + $weight) % 10;
        $value = ($value * (2 ** $weight)) % 9;
        if ($value === 0 && $digit !== 0) {
            $value = 9;
        }
        $sum += $value;
    }

    $checkDigit = (10 - ($sum % 10)) % 10;
    if ($checkDigit !== (int) $taxNumber[9]) {
        throw new InvalidArgumentException('Vergi kimlik numarasının kontrol basamağı geçersiz.');
    }
}

function nvi_identity_json_path($data, $path)
{
    $value = $data;
    foreach (explode('.', trim((string) $path, '.')) as $part) {
        if ($part === '') {
            continue;
        }
        if (is_object($value) && property_exists($value, $part)) {
            $value = $value->{$part};
        } elseif (is_array($value) && array_key_exists($part, $value)) {
            $value = $value[$part];
        } else {
            return null;
        }
    }
    return $value;
}

function nvi_identity_verify_vkn($taxNumber, $settings)
{
    nvi_identity_assert_vkn_format($taxNumber);

    if (!function_exists('curl_init')) {
        throw new RuntimeException('VKN doğrulaması için PHP cURL eklentisi gereklidir.');
    }

    $url = trim((string) ($settings['gibvknurl'] ?? ''));
    if ($url === '' || !preg_match('#^https://#i', $url)) {
        throw new RuntimeException('VKN için GİB/yetkili sağlayıcı HTTPS doğrulama endpointi modül ayarlarında tanımlı değil.');
    }

    $vknField = trim((string) ($settings['gibvknfield'] ?? 'vkn'));
    $responsePath = trim((string) ($settings['gibresponsepath'] ?? 'valid'));
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $vknField) || $responsePath === '') {
        throw new RuntimeException('GİB endpoint alan eşlemeleri geçersiz yapılandırılmış.');
    }

    $timeout = (int) ($settings['gibtimeout'] ?? 10);
    $timeout = max(3, min(30, $timeout));
    $headers = array('Accept: application/json', 'Content-Type: application/json');
    $apiKey = trim((string) ($settings['gibapikey'] ?? ''));
    $headerName = trim((string) ($settings['gibapikeyheader'] ?? 'Authorization'));
    $prefix = trim((string) ($settings['gibapikeyprefix'] ?? 'Bearer'));
    if ($apiKey !== '') {
        if (!preg_match('/^[A-Za-z0-9-]+$/', $headerName) || preg_match('/[\r\n]/', $apiKey . $prefix)) {
            throw new RuntimeException('GİB API anahtarı başlık yapılandırması geçersiz.');
        }
        $headers[] = $headerName . ': ' . ($prefix !== '' ? $prefix . ' ' : '') . $apiKey;
    }

    $payload = array(
        $vknField => $taxNumber,
    );
    $handle = curl_init($url);
    curl_setopt_array($handle, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));
    $responseBody = curl_exec($handle);
    $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);

    if ($responseBody === false || $curlError !== '') {
        throw new RuntimeException('VKN doğrulama servisine ulaşılamadı.');
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException('VKN doğrulama servisi HTTP ' . $httpStatus . ' yanıtı verdi.');
    }

    $response = json_decode($responseBody);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('VKN doğrulama servisi geçerli JSON yanıtı döndürmedi.');
    }
    $valid = nvi_identity_json_path($response, $responsePath);
    if (!is_bool($valid)) {
        throw new RuntimeException('VKN servis yanıt alanı boolean değil veya eşleme yanlış.');
    }
    return $valid;
}

function nvi_identity_validate_tax_submission($vars)
{
    $settings = nvi_identity_settings();
    if (!nvi_identity_setting_enabled($settings['gibvknenabled'] ?? 'on')) {
        return array();
    }

    $ids = nvi_identity_field_ids();
    $submitted = array();
    foreach (array('customFields', 'customfields', 'customfield') as $key) {
        if (isset($vars[$key]) && is_array($vars[$key])) {
            $submitted = array_replace($submitted, $vars[$key]);
        }
    }
    if (isset($_POST['customfield']) && is_array($_POST['customfield'])) {
        $submitted = array_replace($submitted, $_POST['customfield']);
    }

    $taxNumber = nvi_identity_normalize_tckn($submitted[$ids['tax_number']] ?? $submitted[(string) $ids['tax_number']] ?? $submitted[NVI_TAX_NUMBER_FIELD] ?? $_POST['nvi_tax_number'] ?? '');
    if ($taxNumber === '') {
        return array();
    }

    try {
        if (!nvi_identity_verify_vkn($taxNumber, $settings)) {
            return array('Vergi numarası GİB doğrulama servisi tarafından eşleştirilemedi.');
        }
        $validatedHash = nvi_identity_tax_hash($taxNumber);
        $clientId = nvi_identity_resolve_client_id($vars);
        if ($clientId) {
            $current = nvi_identity_get_client_fields($clientId);
            if ($current['tax_number'] !== $taxNumber) {
                $GLOBALS['nviIdentityPendingTaxVerified'][(int) $clientId] = $validatedHash;
            }
        } else {
            $GLOBALS['nviIdentityPendingRegistrationTaxHash'] = $validatedHash;
        }
        return array();
    } catch (InvalidArgumentException $exception) {
        return array($exception->getMessage());
    } catch (Throwable $exception) {
        return array('Vergi numarası yazdığınız için doğrulama gerekli; doğrulama servisi kullanılamadığından bilgiler kaydedilmedi.');
    }
}

function nvi_identity_record_tax_verification($clientId)
{
    $values = nvi_identity_get_client_fields($clientId);
    if ($values['tax_number'] === '') {
        Capsule::table(NVI_TAX_TABLE)->where('client_id', (int) $clientId)->delete();
        return;
    }

    $settings = nvi_identity_settings();
    if (!nvi_identity_setting_enabled($settings['gibvknenabled'] ?? 'on')) {
        return;
    }

    if (nvi_identity_is_tax_verified($clientId)) {
        return;
    }

    $pendingHash = $GLOBALS['nviIdentityPendingTaxVerified'][(int) $clientId]
        ?? $GLOBALS['nviIdentityPendingRegistrationTaxHash']
        ?? null;
    $verified = $pendingHash
        && hash_equals($pendingHash, nvi_identity_tax_hash($values['tax_number']))
        ? true
        : nvi_identity_verify_vkn($values['tax_number'], $settings);
    if (!$verified) {
        throw new RuntimeException('Kayıtlı VKN doğrulaması geçersiz.');
    }

    Capsule::table(NVI_TAX_TABLE)->updateOrInsert(
        array('client_id' => (int) $clientId),
        array(
            'tax_hash' => nvi_identity_tax_hash($values['tax_number']),
            'verified_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        )
    );
}

function nvi_identity_extract_submitted($vars = array())
{
    $ids = nvi_identity_field_ids();
    $submitted = array();
    foreach (array('customFields', 'customfields', 'customfield') as $key) {
        if (isset($vars[$key]) && is_array($vars[$key])) {
            $submitted = array_replace($submitted, $vars[$key]);
        }
    }
    $requestCustomFields = $_POST['customfield'] ?? array();
    if (is_array($requestCustomFields)) {
        $submitted = array_replace($submitted, $requestCustomFields);
    }

    $tckn = $submitted[$ids['tckn']] ?? $submitted[(string) $ids['tckn']] ?? $submitted[NVI_TCKN_FIELD] ?? $_POST['nvi_tckn'] ?? null;
    $birthDate = $submitted[$ids['birthdate']] ?? $submitted[(string) $ids['birthdate']] ?? $submitted[NVI_BIRTHDATE_FIELD] ?? $_POST['nvi_birthdate'] ?? null;

    if ($tckn === null && $birthDate === null) {
        return null;
    }

    return array(
        'tckn' => nvi_identity_normalize_tckn($tckn),
        'birthdate' => trim((string) $birthDate),
    );
}

function nvi_identity_normalize_tckn($value)
{
    return preg_replace('/\\s+/', '', (string) $value);
}

function nvi_identity_assert_tckn($tckn)
{
    if (!preg_match('/^[1-9][0-9]{10}$/', $tckn)) {
        throw new InvalidArgumentException('T.C. kimlik numarası 11 haneli olmalı ve 0 ile başlamamalıdır.');
    }

    $digits = array_map('intval', str_split($tckn));
    $odd = $digits[0] + $digits[2] + $digits[4] + $digits[6] + $digits[8];
    $even = $digits[1] + $digits[3] + $digits[5] + $digits[7];
    if (((($odd * 7 - $even) % 10 + 10) % 10) !== $digits[9]) {
        throw new InvalidArgumentException('T.C. kimlik numarasının kontrol basamakları geçersiz.');
    }
    if ((array_sum(array_slice($digits, 0, 10)) % 10) !== $digits[10]) {
        throw new InvalidArgumentException('T.C. kimlik numarasının kontrol basamakları geçersiz.');
    }
}

function nvi_identity_validate_saved_tax_client($clientId)
{
    $values = nvi_identity_get_client_fields($clientId);
    if ($values['tax_number'] === '' && $values['tax_office'] === '') {
        return array();
    }
    if ($values['tax_number'] === '') {
        return array();
    }
    if (nvi_identity_is_tax_verified($clientId)) {
        return array();
    }

    try {
        nvi_identity_record_tax_verification($clientId);
        return array();
    } catch (Throwable $exception) {
        return array('Kayıtlı vergi bilgileriniz doğrulanamıyor. Lütfen hesap bilgilerinizi kontrol edin veya destek ekibiyle iletişime geçin.');
    }
}

function nvi_identity_verify($tckn, $firstName, $lastName, $birthYear, $settings = array())
{
    if (!class_exists('SoapClient')) {
        throw new RuntimeException('PHP SOAP eklentisi yüklü değil.');
    }
    $wsdl = trim((string) ($settings['wsdlurl'] ?? ''));
    if ($wsdl === '' || !preg_match('#^https://#i', $wsdl)) {
        throw new RuntimeException('WSDL adresi HTTPS olmalıdır.');
    }

    $headers = array();
    $apiKey = trim((string) ($settings['apikey'] ?? ''));
    $headerName = trim((string) ($settings['apikeyheader'] ?? 'X-API-Key'));
    $apiKeyPrefix = trim((string) ($settings['apikeyprefix'] ?? ''));
    if ($apiKey !== '' && preg_match('/^[A-Za-z0-9-]+$/', $headerName) && !preg_match('/[\r\n]/', $apiKey . $apiKeyPrefix)) {
        $headers[] = $headerName . ': ' . ($apiKeyPrefix !== '' ? $apiKeyPrefix . ' ' : '') . $apiKey;
    }

    $context = stream_context_create(array('http' => array(
        'header' => implode("\r\n", $headers),
        'timeout' => 10,
    )));
    $client = new SoapClient($wsdl, array(
        'connection_timeout' => 8,
        'exceptions' => true,
        'cache_wsdl' => WSDL_CACHE_BOTH,
        'stream_context' => $context,
        'user_agent' => 'WHMCS NVİ Kimlik Doğrulama/1.0',
    ));

    $result = $client->__soapCall('TCKimlikNoDogrula', array(array(
        'TCKimlikNo' => (int) $tckn,
        'Ad' => nvi_identity_upper($firstName),
        'Soyad' => nvi_identity_upper($lastName),
        'DogumYili' => (int) $birthYear,
    )));

    if (is_object($result) && isset($result->TCKimlikNoDogrulaResult)) {
        return (bool) $result->TCKimlikNoDogrulaResult;
    }
    if (is_array($result) && array_key_exists('TCKimlikNoDogrulaResult', $result)) {
        return (bool) $result['TCKimlikNoDogrulaResult'];
    }
    throw new RuntimeException('NVİ servisi tanınmayan bir yanıt döndürdü.');
}

function nvi_identity_upper($value)
{
    $value = trim(preg_replace('/\\s+/', ' ', (string) $value));
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function nvi_identity_fingerprint($tckn, $birthDate)
{
    return hash('sha256', $tckn . '|' . $birthDate);
}

function nvi_identity_mark_verified($clientId, $tckn, $birthDate)
{
    $lockedTckn = nvi_identity_encrypt($tckn);
    $lockedBirthdate = nvi_identity_encrypt($birthDate);
    Capsule::table(NVI_IDENTITY_TABLE)->updateOrInsert(
        array('client_id' => (int) $clientId),
        array(
            'identity_hash' => nvi_identity_fingerprint($tckn, $birthDate),
            'locked_tckn' => $lockedTckn,
            'locked_birthdate' => $lockedBirthdate,
            'verified' => true,
            'verified_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        )
    );
}

function nvi_identity_ensure_lock_columns()
{
    if (!Capsule::schema()->hasColumn(NVI_IDENTITY_TABLE, 'locked_tckn')) {
        Capsule::schema()->table(NVI_IDENTITY_TABLE, function ($table) {
            $table->text('locked_tckn')->nullable();
        });
    }
    if (!Capsule::schema()->hasColumn(NVI_IDENTITY_TABLE, 'locked_birthdate')) {
        Capsule::schema()->table(NVI_IDENTITY_TABLE, function ($table) {
            $table->text('locked_birthdate')->nullable();
        });
    }
}

function nvi_identity_encrypt($value)
{
    if (!function_exists('encrypt')) {
        throw new RuntimeException('WHMCS şifreleme fonksiyonu kullanılamıyor.');
    }
    return encrypt((string) $value);
}

function nvi_identity_get_locked_values($record)
{
    if (!function_exists('decrypt') || empty($record->locked_tckn) || empty($record->locked_birthdate)) {
        return null;
    }
    try {
        return array(
            'tckn' => decrypt($record->locked_tckn),
            'birthdate' => decrypt($record->locked_birthdate),
        );
    } catch (Throwable $exception) {
        return null;
    }
}

function nvi_identity_record($clientId)
{
    if (!Capsule::schema()->hasTable(NVI_IDENTITY_TABLE)) {
        return null;
    }
    return Capsule::table(NVI_IDENTITY_TABLE)->where('client_id', (int) $clientId)->first();
}

function nvi_identity_is_verified($clientId)
{
    $record = nvi_identity_record($clientId);
    return $record && (bool) $record->verified;
}

function nvi_identity_current_client_id()
{
    if (!empty($_SESSION['uid'])) {
        return (int) $_SESSION['uid'];
    }
    return 0;
}

function nvi_identity_resolve_client_id($vars = array())
{
    foreach (array('userid', 'client_id', 'id') as $key) {
        if (!empty($vars[$key]) && ctype_digit((string) $vars[$key])) {
            return (int) $vars[$key];
        }
    }

    return nvi_identity_current_client_id();
}

function nvi_identity_snapshot_locked_values($clientId)
{
    $GLOBALS['nviIdentityLockedSnapshot'][(int) $clientId] = nvi_identity_get_client_fields($clientId);
}

function nvi_identity_restore_locked_values($clientId, $values)
{
    if (!is_array($values) || !isset($values['tckn'], $values['birthdate'])) {
        return false;
    }

    $ids = nvi_identity_field_ids();
    foreach (array('tckn' => 'tckn', 'birthdate' => 'birthdate') as $key => $valueKey) {
        if (!$ids[$key]) {
            return false;
        }
        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
            array('fieldid' => $ids[$key], 'relid' => (int) $clientId),
            array('value' => $values[$valueKey])
        );
    }

    return true;
}

function nvi_identity_locked_values($record, $clientId)
{
    $snapshot = $GLOBALS['nviIdentityLockedSnapshot'][(int) $clientId] ?? null;
    if (is_array($snapshot) && isset($snapshot['tckn'], $snapshot['birthdate'])) {
        return $snapshot;
    }
    return nvi_identity_get_locked_values($record);
}

function nvi_identity_validate_submitted($vars, $isRegistration, $forceRequired = false)
{
    nvi_identity_sync_field_settings();
    $mode = nvi_identity_mode(nvi_identity_settings()['identitymode'] ?? 'Kapalı');
    $submitted = nvi_identity_extract_submitted($vars);
    $clientId = nvi_identity_resolve_client_id($vars);

    // A successful identity remains immutable even after the module is switched off.
    if ($clientId && nvi_identity_is_verified($clientId)) {
        nvi_identity_snapshot_locked_values($clientId);
        if ($submitted === null) {
            return array();
        }
        $record = nvi_identity_record($clientId);
        if (!hash_equals((string) $record->identity_hash, nvi_identity_fingerprint($submitted['tckn'], $submitted['birthdate']))) {
            return array('Daha önce doğrulanmış kimlik bilgileri değiştirilemez.');
        }
        return array();
    }

    if ($mode === 'disabled') {
        return array();
    }
    if ($isRegistration && $mode !== 'register' && !$forceRequired) {
        return array();
    }
    if (!$submitted || ($submitted['tckn'] === '' && $submitted['birthdate'] === '')) {
        return ($isRegistration && $mode === 'register') || $forceRequired
            ? array('Kayıt için T.C. kimlik numarası ve doğum tarihi zorunludur.')
            : array();
    }
    if ($submitted['tckn'] === '' || $submitted['birthdate'] === '') {
        return array('T.C. kimlik numarası ve doğum tarihi birlikte girilmelidir.');
    }

    try {
        nvi_identity_assert_tckn($submitted['tckn']);
        $date = DateTime::createFromFormat('!Y-m-d', $submitted['birthdate']);
        if (!$date || $date->format('Y-m-d') !== $submitted['birthdate'] || $date > new DateTime('today')) {
            return array('Doğum tarihi YYYY-AA-GG biçiminde ve geçerli bir tarih olmalıdır.');
        }

        $firstName = $vars['firstname'] ?? '';
        $lastName = $vars['lastname'] ?? '';
        if ($clientId) {
            $client = Capsule::table('tblclients')->where('id', $clientId)->first();
            if ($client) {
                $firstName = $client->firstname;
                $lastName = $client->lastname;
            }
        }

        $valid = nvi_identity_verify($submitted['tckn'], $firstName, $lastName, (int) $date->format('Y'), nvi_identity_settings());
        return $valid ? array() : array('Girdiğiniz kimlik bilgileri NVİ kayıtlarıyla eşleşmedi. T.C. kimlik numarası ve doğum tarihini kontrol edin.');
    } catch (InvalidArgumentException $exception) {
        return array($exception->getMessage());
    } catch (Throwable $exception) {
        return array('NVİ kimlik doğrulama servisine ulaşılamıyor. Lütfen daha sonra tekrar deneyin.');
    }
}

function nvi_identity_verify_saved_client($clientId)
{
    $values = nvi_identity_get_client_fields($clientId);
    if ($values['tckn'] === '' || $values['birthdate'] === '') {
        return;
    }

    nvi_identity_assert_tckn($values['tckn']);
    $date = DateTime::createFromFormat('!Y-m-d', $values['birthdate']);
    if (!$date || $date->format('Y-m-d') !== $values['birthdate']) {
        return;
    }

    $client = Capsule::table('tblclients')->where('id', (int) $clientId)->first();
    if (!$client) {
        return;
    }

    if (nvi_identity_verify($values['tckn'], $client->firstname, $client->lastname, (int) $date->format('Y'), nvi_identity_settings())) {
        nvi_identity_mark_verified($clientId, $values['tckn'], $values['birthdate']);
    }
}

function nvi_identity_validate_saved_client($clientId)
{
    if (!$clientId) {
        return array('Kimlik doğrulaması için müşteri hesabıyla giriş yapmalısınız.');
    }
    if (nvi_identity_is_verified($clientId)) {
        return array();
    }

    $values = nvi_identity_get_client_fields($clientId);
    if ($values['tckn'] === '' || $values['birthdate'] === '') {
        return array('Hizmet almadan önce T.C. kimlik numarası ve doğum tarihinizi Kimlik Bilgileri sayfasından doldurup doğrulamalısınız.');
    }

    try {
        nvi_identity_assert_tckn($values['tckn']);
        $date = DateTime::createFromFormat('!Y-m-d', $values['birthdate']);
        if (!$date || $date->format('Y-m-d') !== $values['birthdate'] || $date > new DateTime('today')) {
            return array('Kayıtlı doğum tarihi geçersiz. Kimlik Bilgileri sayfasından düzeltin.');
        }

        $client = Capsule::table('tblclients')->where('id', (int) $clientId)->first();
        if (!$client) {
            return array('Müşteri hesabı bulunamadı.');
        }

        if (!nvi_identity_verify($values['tckn'], $client->firstname, $client->lastname, (int) $date->format('Y'), nvi_identity_settings())) {
            return array('Kayıtlı kimlik bilgileri NVİ kayıtlarıyla eşleşmedi. Kimlik Bilgileri sayfasından kontrol edin.');
        }

        nvi_identity_mark_verified($clientId, $values['tckn'], $values['birthdate']);
        return array();
    } catch (InvalidArgumentException $exception) {
        return array($exception->getMessage());
    } catch (Throwable $exception) {
        return array('NVİ kimlik doğrulama servisine ulaşılamıyor. Lütfen daha sonra tekrar deneyin.');
    }
}
