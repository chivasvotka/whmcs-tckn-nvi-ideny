<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/nvi_identity.php';

add_hook('ClientAreaPrimaryNavbar', 1, function ($menu) {
    $settings = nvi_identity_settings();
    $mode = nvi_identity_mode($settings['identitymode'] ?? 'Kapalı');
    $taxEnabled = nvi_identity_setting_enabled($settings['gibvknenabled'] ?? 'on');
    if ($mode === 'disabled' && !$taxEnabled) {
        return;
    }
    $account = $menu->getChild('Account');
    if ($account) {
        $account->addChild('Kimlik Bilgileri', array(
            'uri' => 'index.php?m=nvi_identity',
            'order' => 90,
        ));
    }
});

add_hook('ClientAreaPageRegister', 1, function ($vars) {
    nvi_identity_sync_field_settings();
    return array();
});

add_hook('ClientAreaPage', 1, function ($vars) {
    nvi_identity_sync_field_settings();
    return array();
});

add_hook('ClientDetailsValidation', 1, function ($vars) {
    $isRegistration = !nvi_identity_resolve_client_id($vars);
    return array_merge(
        nvi_identity_validate_submitted($vars, $isRegistration),
        nvi_identity_validate_tax_submission($vars)
    );
});

add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    nvi_identity_sync_field_settings();
    $taxErrors = nvi_identity_validate_tax_submission($vars);
    $settings = nvi_identity_settings();
    $mode = nvi_identity_mode($settings['identitymode'] ?? 'Kapalı');
    if ($mode === 'disabled') {
        return array_merge($taxErrors, nvi_identity_validate_saved_tax_client(nvi_identity_current_client_id()));
    }

    $clientId = nvi_identity_current_client_id();
    if ($clientId) {
        return array_merge($taxErrors, nvi_identity_validate_saved_client($clientId), nvi_identity_validate_saved_tax_client($clientId));
    }

    $errors = nvi_identity_validate_submitted($vars, !$clientId, true);
    if ($errors) {
        $errors[] = 'Kimlik doğrulamasını müşteri panelindeki Kimlik Bilgileri sayfasından tamamlayabilirsiniz.';
    }
    return array_merge($taxErrors, $errors);
});

add_hook('ClientAdd', 1, function ($vars) {
    $clientId = (int) ($vars['client_id'] ?? $vars['userid'] ?? 0);
    $mode = nvi_identity_mode(nvi_identity_settings()['identitymode'] ?? 'Kapalı');
    if (!$clientId) {
        return;
    }
    try {
        if ($mode !== 'disabled' && !nvi_identity_is_verified($clientId)) {
            nvi_identity_verify_saved_client($clientId);
        }
        nvi_identity_record_tax_verification($clientId);
    } catch (Throwable $exception) {
        logActivity('NVİ Kimlik Doğrulama: Yeni müşterinin vergi/kimlik bilgisi doğrulanamadı. Client ID: ' . $clientId);
    }
});

add_hook('ClientEdit', 1, function ($vars) {
    $clientId = (int) ($vars['userid'] ?? 0);
    if (!$clientId) {
        return;
    }

    $record = nvi_identity_record($clientId);
    $values = nvi_identity_get_client_fields($clientId);
    if ($record && $record->verified) {
        $fingerprint = nvi_identity_fingerprint($values['tckn'], $values['birthdate']);
        if (!hash_equals((string) $record->identity_hash, $fingerprint)) {
            $snapshot = nvi_identity_locked_values($record, $clientId);
            if (nvi_identity_restore_locked_values($clientId, $snapshot)) {
                logActivity('NVİ Kimlik Doğrulama: Doğrulanmış kimlik alanı değişikliği geri alındı. Client ID: ' . $clientId);
            } else {
                logActivity('NVİ Kimlik Doğrulama: Doğrulanmış kimlik alanı değişikliği tespit edildi fakat geri alınamadı. Client ID: ' . $clientId);
            }
        }
        try {
            nvi_identity_record_tax_verification($clientId);
        } catch (Throwable $exception) {
            logActivity('NVİ Kimlik Doğrulama: Müşterinin VKN bilgisi doğrulanamadı. Client ID: ' . $clientId);
        }
        return;
    }

    try {
        nvi_identity_record_tax_verification($clientId);
    } catch (Throwable $exception) {
        logActivity('NVİ Kimlik Doğrulama: Müşterinin VKN bilgisi doğrulanamadı. Client ID: ' . $clientId);
    }

    $mode = nvi_identity_mode(nvi_identity_settings()['identitymode'] ?? 'Kapalı');
    if ($mode === 'disabled') {
        return;
    }

    try {
        nvi_identity_verify_saved_client($clientId);
    } catch (Throwable $exception) {
        logActivity('NVİ Kimlik Doğrulama: Müşteri kimliği doğrulanamadı. Client ID: ' . $clientId);
    }
});
