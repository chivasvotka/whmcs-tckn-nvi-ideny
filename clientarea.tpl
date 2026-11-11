{if $identityError}
    {include file="$template/includes/alert.tpl" type="error" msg=$identityError}
{/if}
{if $identitySuccess}
    {include file="$template/includes/alert.tpl" type="success" msg=$identitySuccess}
{/if}
{if $taxError}
    {include file="$template/includes/alert.tpl" type="error" msg=$taxError}
{/if}
{if $taxSuccess}
    {include file="$template/includes/alert.tpl" type="success" msg=$taxSuccess}
{/if}

<div class="card">
    <div class="card-body">
        <h3 class="card-title">Kimlik Bilgileri</h3>

        {if $identityDisabled}
            <div class="alert alert-info">
                Kimlik doğrulama özelliği şu anda etkin değil.
            </div>
        {elseif $identityVerified}
            <div class="alert alert-success">
                Kimlik bilgileriniz doğrulanmış ve kilitlenmiştir.
            </div>
            <dl class="row mb-0">
                <dt class="col-sm-4">T.C. Kimlik No</dt>
                <dd class="col-sm-8">{$identityTckn|escape}</dd>
                <dt class="col-sm-4">Doğum Tarihi</dt>
                <dd class="col-sm-8">{$identityBirthdate|escape}</dd>
            </dl>
        {else}
            <form method="post" action="index.php?m=nvi_identity" class="form-horizontal">
                {if $identityToken}<input type="hidden" name="token" value="{$identityToken|escape}" />{/if}
                <div class="form-group">
                    <label for="nviTckn" class="control-label">T.C. Kimlik No</label>
                    <input type="text" name="nvi_tckn" id="nviTckn" class="form-control" value="{$identityTckn|escape}" inputmode="numeric" maxlength="11" autocomplete="off" required />
                </div>
                <div class="form-group">
                    <label for="nviBirthdate" class="control-label">Doğum Tarihi</label>
                    <input type="date" name="nvi_birthdate" id="nviBirthdate" class="form-control" value="{$identityBirthdate|escape}" required />
                </div>
                <p class="text-muted">NVİ servisi kimlik numarası, ad, soyad ve doğum yılını kontrol eder. Girilen tam tarih ayrıca geçerli tarih olarak kontrol edilir.</p>
                <button type="submit" class="btn btn-primary">Kimlik Bilgilerini Doğrula</button>
            </form>
        {/if}
    </div>
</div>

{if $taxFieldsEnabled}
<div class="card mt-3">
    <div class="card-body">
        <h3 class="card-title">Vergi Bilgileri</h3>
        {if $taxVerified}
            <div class="alert alert-success">Vergi numarası GİB doğrulamasından geçti.</div>
        {else}
            <p class="text-muted">Bu alanlar isteğe bağlıdır. Yalnızca Vergi Kimlik No GİB/yetkili doğrulama servisine gönderilir.</p>
        {/if}
        <form method="post" action="index.php?m=nvi_identity" class="form-horizontal">
            {if $identityToken}<input type="hidden" name="token" value="{$identityToken|escape}" />{/if}
            <div class="form-group">
                <label for="nviTaxNumber" class="control-label">Vergi Kimlik No</label>
                <input type="text" name="nvi_tax_number" id="nviTaxNumber" class="form-control" value="{$taxNumber|escape}" inputmode="numeric" maxlength="10" autocomplete="off" />
            </div>
            <div class="form-group">
                <label for="nviTaxOffice" class="control-label">Vergi Dairesi</label>
                <input type="text" name="nvi_tax_office" id="nviTaxOffice" class="form-control" value="{$taxOffice|escape}" maxlength="150" autocomplete="organization" />
            </div>
            <button type="submit" class="btn btn-primary">Vergi Bilgilerini Doğrula</button>
        </form>
    </div>
</div>
{/if}