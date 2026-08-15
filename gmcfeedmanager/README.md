# gmcfeedmanager

Google Merchant Center Feed Manager for PrestaShop 1.7 / 8.x / 9.x.

High-performance XML feed generation (streamed with `XMLWriter`, chunked
SQL, flat memory usage on 100k+ catalogs) plus optional real-time product
sync to the Google Content API for Shopping (v2.1) via a service account.

## Install

1. Copy this `gmcfeedmanager/` folder into your PrestaShop `modules/`
   directory (or zip it and upload from the back office).
2. Install the module from **Modules > Module Manager**. This creates
   `ps_gmc_category_mapping` and `ps_gmc_product_rule`, registers the
   `AdminGmcFeedConfigurationController` tab under **Catalog**, and
   generates a random feed token.
3. Go to **Catalog > Google Merchant Feed** to configure:
   - **General Settings** — target language/currency, feed chunk size,
     Merchant Center ID, content language / target country, real-time
     Content API sync toggle, and the Service Account JSON key.
   - **Category Taxonomy Mapping** — map each PrestaShop category to a
     Google Product Taxonomy node via typeahead search.
   - **Apparel & Attribute Mapping** — pick which combination attribute
     group feeds `color`/`size`, and which feature feeds `gender`/
     `age_group`.
   - **Pre-flight Diagnostics** — catalog health checks (missing
     GTIN/MPN, unmapped categories, missing cover images, out-of-stock
     items with no explicit availability behaviour).
4. Copy the **Feed URL** shown at the top of General Settings into
   Merchant Center as a scheduled fetch.

## Real-time sync

When enabled, `actionObjectProductUpdateAfter` and `actionUpdateQuantity`
push the affected product straight to the Content API
(`GoogleContentApiService::patchProduct()`) in addition to whatever the
scheduled feed fetch would pick up. Requires a Merchant Center ID and a
valid service account JSON key with access to that account.

## Per-product overrides

Use the `ps_gmc_product_rule` table (id_product + id_product_attribute) to
exclude a product/variant from the feed, or override its title, GTIN, and
up to five `custom_label_0`..`custom_label_4` values.

## Google taxonomy cache

The Google Product Taxonomy is downloaded once and normalised into
`modules/gmcfeedmanager/var/taxonomy.tsv` (that directory must be
writable). It deliberately does **not** live under `_PS_CACHE_DIR_`,
which is environment-scoped and wiped on every Symfony cache clear. If
the download is unreachable, an existing (even stale) cache keeps being
served and retries are backed off, so the typeahead degrades instead of
hanging on every keystroke.

## Architecture notes

- `src/Service/ProductDataTransformer.php` turns a `Product` (+ optional
  `Combination`) into a flat, feed-format-agnostic array. Both the XML
  feed writer and the Content API payload builder consume this same
  shape, so field logic (pricing, GTIN fallback, truncation, apparel
  mapping...) lives in exactly one place.
- `controllers/front/feed.php` never buffers the full feed in memory: it
  streams straight to `php://output` via `XMLWriter::openURI()`, pulling
  the catalog `LIMIT`/`OFFSET` chunk by chunk (configurable chunk size).
- Namespaced classes under `GmcFeedManager\Service\` are loaded by a
  tiny `spl_autoload_register` in `src/autoload.php` — no Composer
  dependency required to install the module.
