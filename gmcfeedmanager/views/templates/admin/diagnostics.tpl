{*
  gmcfeedmanager :: pre-flight diagnostics panel.
  Included from configuration.tpl inside the "Pre-flight Diagnostics" tab.
  Badge values are populated client-side by views/js/admin.js calling
  ajaxProcessRunDiagnostics; the markup below only provides placeholders.
*}
<div class="gmc-diagnostics">
    <p class="help-block">
        {l s='Run a quick health check on the catalog before it goes live in Merchant Center.' mod='gmcfeedmanager'}
    </p>

    <button type="button" class="btn btn-primary" id="gmc-run-diagnostics">
        <i class="icon-refresh"></i> {l s='Run diagnostics' mod='gmcfeedmanager'}
    </button>
    <span id="gmc-diagnostics-loading" class="gmc-hidden">
        <i class="icon-spinner icon-spin"></i> {l s='Checking catalog...' mod='gmcfeedmanager'}
    </span>

    <div class="row gmc-diagnostics-grid">
        <div class="col-lg-3">
            <div class="gmc-diag-card" data-severity="warning">
                <div class="gmc-diag-badge" id="gmc-badge-missing-identifiers">&mdash;</div>
                <div class="gmc-diag-label">{l s='Missing GTIN / MPN' mod='gmcfeedmanager'}</div>
                <p class="gmc-diag-hint">{l s='Active products with no EAN/UPC and no reference. Google may reject or limit these.' mod='gmcfeedmanager'}</p>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="gmc-diag-card" data-severity="warning">
                <div class="gmc-diag-badge" id="gmc-badge-unmapped-categories">&mdash;</div>
                <div class="gmc-diag-label">{l s='Unmapped categories' mod='gmcfeedmanager'}</div>
                <p class="gmc-diag-hint">{l s='Default categories in use by active products that have no Google taxonomy mapping yet.' mod='gmcfeedmanager'}</p>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="gmc-diag-card" data-severity="danger">
                <div class="gmc-diag-badge" id="gmc-badge-missing-cover-image">&mdash;</div>
                <div class="gmc-diag-label">{l s='Missing cover image' mod='gmcfeedmanager'}</div>
                <p class="gmc-diag-hint">{l s='Active products with no primary/cover image -- these cannot be submitted to Google at all.' mod='gmcfeedmanager'}</p>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="gmc-diag-card" data-severity="info">
                <div class="gmc-diag-badge" id="gmc-badge-out-of-stock">&mdash;</div>
                <div class="gmc-diag-label">{l s='Out-of-stock, availability unset' mod='gmcfeedmanager'}</div>
                <p class="gmc-diag-hint">{l s='Out-of-stock products still on the "default" backorder behaviour -- decide in-stock vs backorder explicitly.' mod='gmcfeedmanager'}</p>
            </div>
        </div>
    </div>
</div>
