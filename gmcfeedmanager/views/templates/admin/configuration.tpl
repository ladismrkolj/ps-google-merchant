{*
  gmcfeedmanager :: main configuration screen.
  Rendered by AdminGmcFeedConfigurationController::renderView().
*}
<div class="gmc-feed-manager">

    <div class="panel">
        <div class="panel-heading">
            <i class="icon-rss"></i> {l s='Google Merchant Feed Manager' mod='gmcfeedmanager'}
        </div>

        <ul class="nav nav-tabs" id="gmc-tabs">
            <li class="active"><a href="#gmc-general" data-toggle="tab">{l s='General Settings' mod='gmcfeedmanager'}</a></li>
            <li><a href="#gmc-categories" data-toggle="tab">{l s='Category Taxonomy Mapping' mod='gmcfeedmanager'}</a></li>
            <li><a href="#gmc-apparel" data-toggle="tab">{l s='Apparel & Attribute Mapping' mod='gmcfeedmanager'}</a></li>
            <li><a href="#gmc-shipping" data-toggle="tab">{l s='Shipping & Returns' mod='gmcfeedmanager'}</a></li>
            <li><a href="#gmc-diagnostics" data-toggle="tab" id="gmc-diagnostics-tab-link">{l s='Pre-flight Diagnostics' mod='gmcfeedmanager'}</a></li>
        </ul>

        <div class="tab-content">

            {* ============================= GENERAL ============================= *}
            <div class="tab-pane active" id="gmc-general">
                <form id="gmc-general-form" action="{$current_index}&token={$token}" method="post" class="form-horizontal">

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Feed URL' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-8">
                            <div class="input-group">
                                <input type="text" class="form-control" id="gmc-feed-url" readonly="readonly" value="{$gmc_feed_url|escape:'html':'UTF-8'}">
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-default" id="gmc-copy-feed-url">
                                        <i class="icon-copy"></i> {l s='Copy' mod='gmcfeedmanager'}
                                    </button>
                                </span>
                            </div>
                            <p class="help-block">{l s='Register this URL in Google Merchant Center as a scheduled fetch.' mod='gmcfeedmanager'}</p>
                        </div>
                        <div class="col-lg-1">
                            <button type="button" class="btn btn-default" id="gmc-regenerate-token"
                                    data-confirm="{l s='Regenerating the token will invalidate the current feed URL. Continue?' mod='gmcfeedmanager'}">
                                <i class="icon-refresh"></i> {l s='Regenerate' mod='gmcfeedmanager'}
                            </button>
                        </div>
                    </div>

                    <hr>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Target language' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-4">
                            <select name="id_lang" class="form-control">
                                {foreach from=$gmc_languages item=lang}
                                    <option value="{$lang.id_lang|intval}"{if $lang.id_lang == $gmc_id_lang} selected="selected"{/if}>
                                        {$lang.name|escape:'html':'UTF-8'}
                                    </option>
                                {/foreach}
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Target currency' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-4">
                            <select name="id_currency" class="form-control">
                                {foreach from=$gmc_currencies item=currency}
                                    <option value="{$currency.id_currency|intval}"{if $currency.id_currency == $gmc_id_currency} selected="selected"{/if}>
                                        {$currency.name|escape:'html':'UTF-8'} ({$currency.iso_code|escape:'html':'UTF-8'})
                                    </option>
                                {/foreach}
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Default condition' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-4">
                            <select name="default_condition" class="form-control">
                                {foreach from=$gmc_conditions item=condition}
                                    <option value="{$condition|escape:'html':'UTF-8'}"{if $condition == $gmc_default_condition} selected="selected"{/if}>
                                        {$condition|escape:'html':'UTF-8'}
                                    </option>
                                {/foreach}
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Feed SQL chunk size' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-4">
                            <input type="number" min="50" max="2000" step="50" name="chunk_size" class="form-control"
                                   value="{$gmc_chunk_size|intval}">
                            <p class="help-block">{l s='Rows fetched per SQL round-trip while streaming the feed. Lower it on shared hosting, raise it on dedicated DB servers.' mod='gmcfeedmanager'}</p>
                        </div>
                    </div>

                    <hr>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Real-time Content API sync' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-4">
                            <span class="switch prestashop-switch fixed-width-lg">
                                <input type="radio" name="api_sync_enabled" id="api_sync_enabled_on" value="1"{if $gmc_api_sync_enabled} checked="checked"{/if}>
                                <label for="api_sync_enabled_on">{l s='Enabled' mod='gmcfeedmanager'}</label>
                                <input type="radio" name="api_sync_enabled" id="api_sync_enabled_off" value="0"{if !$gmc_api_sync_enabled} checked="checked"{/if}>
                                <label for="api_sync_enabled_off">{l s='Disabled' mod='gmcfeedmanager'}</label>
                                <a class="slide-button btn"></a>
                            </span>
                            <p class="help-block">{l s='When enabled, product/quantity changes are pushed to the Content API immediately in addition to the scheduled feed.' mod='gmcfeedmanager'}</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Direct to cart links' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-8">
                            <span class="switch prestashop-switch fixed-width-lg">
                                <input type="radio" name="checkout_link_enabled" id="checkout_link_enabled_on" value="1"{if $gmc_checkout_link_enabled} checked="checked"{/if}>
                                <label for="checkout_link_enabled_on">{l s='Enabled' mod='gmcfeedmanager'}</label>
                                <input type="radio" name="checkout_link_enabled" id="checkout_link_enabled_off" value="0"{if !$gmc_checkout_link_enabled} checked="checked"{/if}>
                                <label for="checkout_link_enabled_off">{l s='Disabled' mod='gmcfeedmanager'}</label>
                                <a class="slide-button btn"></a>
                            </span>
                            <p class="help-block">
                                {l s='Adds [checkout_link_template] to every item, so shoppers land on your cart with the product already added.' mod='gmcfeedmanager'}
                                <br>
                                {l s='In Merchant Center, choose the option to provide the URL in your data source and leave the account-level URL field blank.' mod='gmcfeedmanager'}
                            </p>
                            <input type="text" class="form-control gmc-monospace" readonly="readonly" value="{$gmc_checkout_link_preview|escape:'html':'UTF-8'}">
                            <p class="help-block">
                                {l s='The {id} token stays literal on purpose: Google replaces it with each item\'s own id.' mod='gmcfeedmanager'}
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Merchant Center ID' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-4">
                            <input type="text" name="merchant_id" class="form-control" value="{$gmc_merchant_id|escape:'html':'UTF-8'}" placeholder="1234567890">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Content language (ISO 639-1)' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-2">
                            <input type="text" name="content_language" maxlength="5" class="form-control" value="{$gmc_content_language|escape:'html':'UTF-8'}" placeholder="en">
                        </div>
                        <label class="control-label col-lg-2">{l s='Target country (ISO 3166-1)' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-2">
                            <input type="text" name="target_country" maxlength="2" class="form-control" value="{$gmc_target_country|escape:'html':'UTF-8'}" placeholder="US">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Service Account JSON' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-8">
                            <textarea name="service_account_json" rows="8" class="form-control gmc-monospace"
                                      placeholder='{ "type": "service_account", "client_email": "...", "private_key": "..." }'>{$gmc_service_account_json|escape:'html':'UTF-8'}</textarea>
                            <p class="help-block">
                                {l s='Paste the JSON key downloaded from Google Cloud Console for a service account with access to this Merchant Center account. Left blank keeps the currently saved key.' mod='gmcfeedmanager'}
                            </p>
                        </div>
                    </div>

                    <div class="panel-footer">
                        <button type="submit" name="submitGmcGeneral" class="btn btn-default pull-right">
                            <i class="process-icon-save"></i> {l s='Save' mod='gmcfeedmanager'}
                        </button>
                    </div>
                </form>
            </div>

            {* ============================= CATEGORIES ============================= *}
            <div class="tab-pane" id="gmc-categories">
                <p class="help-block">
                    {l s='Map each PrestaShop category to a Google Product Taxonomy node. Start typing to search.' mod='gmcfeedmanager'}
                </p>
                <table class="table gmc-category-table">
                    <thead>
                        <tr>
                            <th>{l s='PrestaShop category' mod='gmcfeedmanager'}</th>
                            <th>{l s='Google product category' mod='gmcfeedmanager'}</th>
                            <th class="text-center">{l s='Action' mod='gmcfeedmanager'}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$gmc_categories item=category}
                            <tr data-id-category="{$category.id_category|intval}">
                                <td style="padding-left: {($category.level_depth - 1) * 20}px">
                                    {$category.name|escape:'html':'UTF-8'}
                                </td>
                                <td>
                                    <div class="gmc-typeahead-wrapper">
                                        <input type="text" class="form-control gmc-category-search"
                                               autocomplete="off"
                                               value="{$category.google_category_name|escape:'html':'UTF-8'}"
                                               placeholder="{l s='Search Google taxonomy...' mod='gmcfeedmanager'}">
                                        <input type="hidden" class="gmc-category-id" value="{$category.google_category_id|escape:'html':'UTF-8'}">
                                        <div class="gmc-typeahead-results"></div>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-default btn-sm gmc-save-mapping">
                                        <i class="icon-check"></i>
                                    </button>
                                    <span class="gmc-mapping-status"></span>
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>

            {* ============================= APPAREL ============================= *}
            <div class="tab-pane" id="gmc-apparel">
                <form id="gmc-apparel-form" action="{$current_index}&token={$token}" method="post" class="form-horizontal">
                    <p class="help-block">
                        {l s='Tell gmcfeedmanager which combination attribute (for color/size) or product feature (for gender/age group) supplies each apparel field.' mod='gmcfeedmanager'}
                    </p>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Color attribute group' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-4">
                            <select name="attr_group_color" class="form-control">
                                <option value="0">{l s='-- Not mapped --' mod='gmcfeedmanager'}</option>
                                {foreach from=$gmc_attribute_groups item=group}
                                    <option value="{$group.id_attribute_group|intval}"{if $group.id_attribute_group == $gmc_attr_group_color} selected="selected"{/if}>
                                        {$group.name|escape:'html':'UTF-8'}
                                    </option>
                                {/foreach}
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Size attribute group' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-4">
                            <select name="attr_group_size" class="form-control">
                                <option value="0">{l s='-- Not mapped --' mod='gmcfeedmanager'}</option>
                                {foreach from=$gmc_attribute_groups item=group}
                                    <option value="{$group.id_attribute_group|intval}"{if $group.id_attribute_group == $gmc_attr_group_size} selected="selected"{/if}>
                                        {$group.name|escape:'html':'UTF-8'}
                                    </option>
                                {/foreach}
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Gender feature' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-4">
                            <select name="feature_gender" class="form-control">
                                <option value="0">{l s='-- Not mapped --' mod='gmcfeedmanager'}</option>
                                {foreach from=$gmc_features item=feature}
                                    <option value="{$feature.id_feature|intval}"{if $feature.id_feature == $gmc_feature_gender} selected="selected"{/if}>
                                        {$feature.name|escape:'html':'UTF-8'}
                                    </option>
                                {/foreach}
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Age group feature' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-4">
                            <select name="feature_age_group" class="form-control">
                                <option value="0">{l s='-- Not mapped --' mod='gmcfeedmanager'}</option>
                                {foreach from=$gmc_features item=feature}
                                    <option value="{$feature.id_feature|intval}"{if $feature.id_feature == $gmc_feature_age_group} selected="selected"{/if}>
                                        {$feature.name|escape:'html':'UTF-8'}
                                    </option>
                                {/foreach}
                            </select>
                        </div>
                    </div>

                    <div class="panel-footer">
                        <button type="submit" name="submitGmcApparel" class="btn btn-default pull-right">
                            <i class="process-icon-save"></i> {l s='Save' mod='gmcfeedmanager'}
                        </button>
                    </div>
                </form>
            </div>

            {* ============================= SHIPPING ============================= *}
            <div class="tab-pane" id="gmc-shipping">
                <form id="gmc-shipping-form" action="{$current_index}&token={$token}" method="post" class="form-horizontal">

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Send shipping in the feed' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-8">
                            <span class="switch prestashop-switch fixed-width-lg">
                                <input type="radio" name="shipping_enabled" id="shipping_enabled_on" value="1"{if $gmc_shipping_enabled} checked="checked"{/if}>
                                <label for="shipping_enabled_on">{l s='Enabled' mod='gmcfeedmanager'}</label>
                                <input type="radio" name="shipping_enabled" id="shipping_enabled_off" value="0"{if !$gmc_shipping_enabled} checked="checked"{/if}>
                                <label for="shipping_enabled_off">{l s='Disabled' mod='gmcfeedmanager'}</label>
                                <a class="slide-button btn"></a>
                            </span>
                            <p class="help-block">
                                {l s='Turn this off if you would rather configure shipping directly in Merchant Center. Rates sent in the feed take precedence over your Merchant Center account settings.' mod='gmcfeedmanager'}
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="control-label col-lg-3">{l s='Return policy label' mod='gmcfeedmanager'}</label>
                        <div class="col-lg-4">
                            <input type="text" name="return_policy_label" class="form-control" value="{$gmc_return_policy_label|escape:'html':'UTF-8'}" placeholder="e.g. 30-day-returns">
                            <p class="help-block">
                                {l s='Optional. Must match the label of a return policy you already created in Merchant Center (Google does not accept policy text in the feed). Leave blank to use your account default.' mod='gmcfeedmanager'}
                            </p>
                        </div>
                    </div>

                    <hr>

                    <div class="alert alert-info">
                        {l s='One flat rate per destination country, applied to every product. Each row carries its own currency, so you can quote a different currency per country.' mod='gmcfeedmanager'}
                    </div>

                    <table class="table" id="gmc-shipping-table">
                        <thead>
                            <tr>
                                <th style="width:120px">{l s='Country (ISO)' mod='gmcfeedmanager'}</th>
                                <th style="width:120px">{l s='Price' mod='gmcfeedmanager'}</th>
                                <th style="width:120px">{l s='Currency' mod='gmcfeedmanager'}</th>
                                <th>{l s='Service (optional)' mod='gmcfeedmanager'}</th>
                                <th style="width:140px">{l s='Region (optional)' mod='gmcfeedmanager'}</th>
                                <th style="width:60px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach from=$gmc_shipping_rates item=rate}
                                <tr>
                                    <td><input type="text" name="rate_country[]" maxlength="2" class="form-control gmc-uppercase" value="{$rate.iso_country|escape:'html':'UTF-8'}"></td>
                                    <td><input type="text" name="rate_price[]" class="form-control" value="{$rate.price|string_format:"%.2f"}"></td>
                                    <td><input type="text" name="rate_currency[]" maxlength="3" class="form-control gmc-uppercase" value="{$rate.currency_iso|escape:'html':'UTF-8'}"></td>
                                    <td><input type="text" name="rate_service[]" class="form-control" value="{$rate.service|escape:'html':'UTF-8'}"></td>
                                    <td><input type="text" name="rate_region[]" class="form-control" value="{$rate.region|escape:'html':'UTF-8'}"></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-default btn-sm gmc-remove-rate"><i class="icon-trash"></i></button>
                                    </td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>

                    <button type="button" class="btn btn-default" id="gmc-add-rate">
                        <i class="icon-plus"></i> {l s='Add a country' mod='gmcfeedmanager'}
                    </button>

                    <div class="panel-footer">
                        <button type="submit" name="submitGmcImportCarriers" class="btn btn-default"
                                onclick="return confirm('{l s='This replaces the rates below with ones derived from your PrestaShop carriers. Continue?' mod='gmcfeedmanager' js=1}');">
                            <i class="icon-download"></i> {l s='Import from my carriers' mod='gmcfeedmanager'}
                        </button>
                        <button type="submit" name="submitGmcShipping" class="btn btn-default pull-right">
                            <i class="process-icon-save"></i> {l s='Save' mod='gmcfeedmanager'}
                        </button>
                    </div>
                </form>

                <p class="help-block">
                    {l s='Import reads your active carriers, their zones and the entry-level delivery price for each (plus the handling fee when the carrier applies one), keeps the cheapest carrier per country, and converts into your feed currency. It never modifies your PrestaShop shipping settings, and every imported row stays editable above.' mod='gmcfeedmanager'}
                </p>
            </div>

            {* ============================= DIAGNOSTICS ============================= *}
            <div class="tab-pane" id="gmc-diagnostics">
                {include file="./diagnostics.tpl"}
            </div>

        </div>
    </div>
</div>

{* Hidden form used only to submit the token-regeneration button via POST *}
<form id="gmc-regenerate-token-form" action="{$current_index}&token={$token}" method="post" style="display:none;">
    <input type="hidden" name="submitGmcRegenerateToken" value="1">
</form>

<script type="text/javascript">
    var gmcAjaxUrl = {$gmc_ajax_url|json_encode nofilter};
    var gmcAdminToken = {$token|json_encode nofilter};
</script>
