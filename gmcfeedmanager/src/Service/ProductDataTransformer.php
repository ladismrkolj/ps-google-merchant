<?php

declare(strict_types=1);

namespace GmcFeedManager\Service;

use Combination;
use Configuration;
use Context;
use Currency;
use Db;
use Manufacturer;
use Product;
use StockAvailable;
use Validate;

/**
 * Turns a PrestaShop Product (optionally scoped to one Combination) into a
 * flat, feed-format-agnostic array of Google Merchant Center attributes.
 *
 * Keys mirror the RSS <g:xxx> attribute names (snake_case, e.g.
 * "item_group_id", "additional_image_link"); controllers/front/feed.php
 * writes them straight into XML, GoogleContentApiService reshapes them into
 * the Content API's camelCase JSON schema.
 *
 * The caller is responsible for loading $product with the Language it wants
 * (`new Product($id, false, $idLang)`) so name/description are already
 * localised.
 */
class ProductDataTransformer
{
    private const DEFAULT_TITLE_MAX_LENGTH = 150;
    private const DEFAULT_DESCRIPTION_MAX_LENGTH = 5000;
    private const MAX_ADDITIONAL_IMAGES = 10;

    public function __construct(
        private readonly GoogleCategoryService $categoryService = new GoogleCategoryService(),
        private readonly int $titleMaxLength = self::DEFAULT_TITLE_MAX_LENGTH,
        private readonly int $descriptionMaxLength = self::DEFAULT_DESCRIPTION_MAX_LENGTH
    ) {
    }

    /**
     * @return array<string, mixed> Flat feed attribute map. Includes an
     *                               internal "excluded" boolean (true when a
     *                               product rule marks the item excluded)
     *                               that callers must check before emitting
     *                               the item.
     */
    public function transform(
        Product $product,
        int $idLang,
        int $idCurrency,
        ?Combination $combination = null,
        ?int $idShop = null
    ): array {
        $idProductAttribute = ($combination instanceof Combination && Validate::isLoadedObject($combination))
            ? (int) $combination->id
            : 0;
        $idShop ??= (int) Context::getContext()->shop->id;

        $rule = $this->getProductRule((int) $product->id, $idProductAttribute);

        if ($rule !== null && (bool) $rule['is_excluded']) {
            return ['excluded' => true, 'id' => $this->buildId($product, $idProductAttribute)];
        }

        $data = [
            'excluded' => false,
            'id' => $this->buildId($product, $idProductAttribute),
            'title' => $this->buildTitle($product, $combination, $idLang, $rule),
            'description' => $this->buildDescription($product),
            'link' => $this->buildLink($product, $idLang, $idShop, $idProductAttribute),
            'condition' => (string) Configuration::get('GMCFEEDMANAGER_CONDITION') ?: 'new',
            'availability' => $this->buildAvailability($product, $idProductAttribute),
        ];

        if ($idProductAttribute > 0) {
            $data['item_group_id'] = (string) $product->id;
        }

        $images = $this->buildImages($product, $idProductAttribute);
        $data['image_link'] = $images['cover'];
        if ($images['additional'] !== []) {
            $data['additional_image_link'] = $images['additional'];
        }

        $isoCode = Currency::getIsoCodeById($idCurrency) ?: 'EUR';
        $prices = $this->buildPrices($product, $idProductAttribute, $isoCode);
        $data = array_merge($data, $prices);

        $identifiers = $this->buildIdentifiers($product, $combination, $rule);
        $data = array_merge($data, $identifiers);

        $brand = Manufacturer::getNameById((int) $product->id_manufacturer);
        if ($brand) {
            $data['brand'] = $brand;
        }

        $googleCategory = $this->categoryService->getMapping((int) $product->id_category_default, $idShop);
        if ($googleCategory !== null) {
            $data['google_product_category'] = $googleCategory['name'];
        }

        $productType = $this->buildProductType($product, $idLang);
        if ($productType !== '') {
            $data['product_type'] = $productType;
        }

        $apparel = $this->buildApparelAttributes($product, $idProductAttribute, $idLang);
        $data = array_merge($data, $apparel);

        $data = array_merge($data, $this->buildCustomLabels($rule));

        return $data;
    }

    private function buildId(Product $product, int $idProductAttribute): string
    {
        return $idProductAttribute > 0
            ? $product->id . '_' . $idProductAttribute
            : (string) $product->id;
    }

    /**
     * @param array<string, mixed>|null $rule
     */
    private function buildTitle(Product $product, ?Combination $combination, int $idLang, ?array $rule): string
    {
        if ($rule !== null && !empty($rule['custom_title'])) {
            return $this->truncate($this->cleanText((string) $rule['custom_title']), $this->titleMaxLength);
        }

        $title = $this->cleanText((string) $product->name);

        if ($combination instanceof Combination && Validate::isLoadedObject($combination)) {
            $attributeNames = array_map(
                static fn (array $attr): string => (string) $attr['name'],
                $combination->getAttributesName($idLang)
            );

            if ($attributeNames !== []) {
                $title .= ' - ' . implode(', ', $attributeNames);
            }
        }

        return $this->truncate($title, $this->titleMaxLength);
    }

    private function buildDescription(Product $product): string
    {
        $description = $product->description_short ?: $product->description;

        return $this->truncate($this->cleanText((string) $description), $this->descriptionMaxLength);
    }

    /**
     * Strips tags/entities and collapses whitespace so feed text is plain
     * and predictable.
     */
    private function cleanText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Truncates on a word boundary where possible instead of cutting
     * mid-word.
     */
    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($cut, ' ');

        if ($lastSpace !== false && $lastSpace > (int) ($maxLength * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut);
    }

    private function buildLink(Product $product, int $idLang, int $idShop, int $idProductAttribute): string
    {
        return Context::getContext()->link->getProductLink(
            $product,
            null,
            null,
            null,
            $idLang,
            $idShop,
            $idProductAttribute,
            false,
            false,
            true
        );
    }

    /**
     * @return array{cover: string, additional: array<int, string>}
     */
    private function buildImages(Product $product, int $idProductAttribute): array
    {
        $link = Context::getContext()->link;
        $idImageCover = null;

        if ($idProductAttribute > 0) {
            $idImageCover = Db::getInstance()->getValue(
                'SELECT `id_image`
                 FROM `' . _DB_PREFIX_ . 'product_attribute_image`
                 WHERE `id_product_attribute` = ' . (int) $idProductAttribute
            );
        }

        if (!$idImageCover) {
            $cover = Product::getCover((int) $product->id);
            $idImageCover = $cover['id_image'] ?? null;
        }

        $coverUrl = $idImageCover
            ? $link->getImageLink($product->link_rewrite, (int) $product->id . '-' . (int) $idImageCover, 'large_default')
            : '';

        $additional = [];
        $allImages = $product->getImages((int) Context::getContext()->language->id);

        foreach ($allImages as $image) {
            if ((int) $image['id_image'] === (int) $idImageCover) {
                continue;
            }

            $additional[] = $link->getImageLink(
                $product->link_rewrite,
                (int) $product->id . '-' . (int) $image['id_image'],
                'large_default'
            );

            if (count($additional) >= self::MAX_ADDITIONAL_IMAGES) {
                break;
            }
        }

        return ['cover' => $coverUrl, 'additional' => $additional];
    }

    private function buildAvailability(Product $product, int $idProductAttribute): string
    {
        $quantity = StockAvailable::getQuantityAvailableByProduct((int) $product->id, $idProductAttribute);

        if ($quantity > 0) {
            return 'in stock';
        }

        return Product::isAvailableWhenOutOfStock((int) $product->out_of_stock) ? 'backorder' : 'out of stock';
    }

    /**
     * @return array<string, string>
     */
    private function buildPrices(Product $product, int $idProductAttribute, string $isoCode): array
    {
        $priceTaxIncl = (float) Product::getPriceStatic(
            (int) $product->id,
            true,
            $idProductAttribute > 0 ? $idProductAttribute : null,
            2,
            null,
            false,
            false
        );

        $priceWithReduction = (float) Product::getPriceStatic(
            (int) $product->id,
            true,
            $idProductAttribute > 0 ? $idProductAttribute : null,
            2,
            null,
            false,
            true
        );

        $result = ['price' => $this->formatPrice($priceTaxIncl, $isoCode)];

        if ($priceWithReduction < $priceTaxIncl) {
            $result['sale_price'] = $this->formatPrice($priceWithReduction, $isoCode);

            $dateRange = $this->buildSalePriceEffectiveDate((int) $product->id, $idProductAttribute);
            if ($dateRange !== null) {
                $result['sale_price_effective_date'] = $dateRange;
            }
        }

        return $result;
    }

    private function formatPrice(float $amount, string $isoCode): string
    {
        return number_format($amount, 2, '.', '') . ' ' . $isoCode;
    }

    private function buildSalePriceEffectiveDate(int $idProduct, int $idProductAttribute): ?string
    {
        $row = Db::getInstance()->getRow(
            'SELECT `from`, `to`
             FROM `' . _DB_PREFIX_ . 'specific_price`
             WHERE `id_product` = ' . $idProduct . '
               AND `id_product_attribute` IN (0, ' . $idProductAttribute . ')
               AND `from` != "0000-00-00 00:00:00"
               AND `to` != "0000-00-00 00:00:00"
             ORDER BY `id_product_attribute` DESC
             LIMIT 1'
        );

        if (!$row || empty($row['from']) || empty($row['to'])) {
            return null;
        }

        $from = str_replace(' ', 'T', $row['from']);
        $to = str_replace(' ', 'T', $row['to']);

        return $from . '/' . $to;
    }

    /**
     * @param array<string, mixed>|null $rule
     *
     * @return array<string, string>
     */
    private function buildIdentifiers(Product $product, ?Combination $combination, ?array $rule): array
    {
        $ean13 = $combination?->ean13 ?: $product->ean13;
        $upc = $combination?->upc ?: $product->upc;
        $gtin = $ean13 ?: $upc;

        if ($rule !== null && !empty($rule['custom_gtin'])) {
            $gtin = (string) $rule['custom_gtin'];
        }

        $mpn = ($combination?->reference ?: $product->reference) ?: '';

        $result = [];

        if ($gtin) {
            $result['gtin'] = (string) $gtin;
        }

        if ($mpn) {
            $result['mpn'] = (string) $mpn;
        }

        if (!$gtin && !$mpn) {
            $result['identifier_exists'] = 'no';
        }

        return $result;
    }

    private function buildProductType(Product $product, int $idLang): string
    {
        $path = Db::getInstance()->getRow(
            'SELECT cl.`name`, c.`nleft`, c.`nright`, c.`level_depth`
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . (int) $idLang . '
             WHERE c.`id_category` = ' . (int) $product->id_category_default
        );

        if (!$path) {
            return '';
        }

        $ancestors = Db::getInstance()->executeS(
            'SELECT cl.`name`
             FROM `' . _DB_PREFIX_ . 'category` c
             INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON cl.`id_category` = c.`id_category` AND cl.`id_lang` = ' . (int) $idLang . '
             WHERE c.`nleft` < ' . (int) $path['nleft'] . '
               AND c.`nright` > ' . (int) $path['nright'] . '
               AND c.`level_depth` > 0
             ORDER BY c.`level_depth` ASC'
        );

        $names = array_map(static fn (array $row): string => (string) $row['name'], $ancestors ?: []);
        $names[] = (string) $path['name'];

        return implode(' > ', $names);
    }

    /**
     * @return array<string, string>
     */
    private function buildApparelAttributes(Product $product, int $idProductAttribute, int $idLang): array
    {
        $result = [];

        $colorGroup = (int) Configuration::get('GMCFEEDMANAGER_ATTR_GROUP_COLOR');
        $sizeGroup = (int) Configuration::get('GMCFEEDMANAGER_ATTR_GROUP_SIZE');

        if ($idProductAttribute > 0 && $colorGroup > 0) {
            $color = $this->getAttributeValue($idProductAttribute, $colorGroup, $idLang);
            if ($color !== null) {
                $result['color'] = $color;
            }
        }

        if ($idProductAttribute > 0 && $sizeGroup > 0) {
            $size = $this->getAttributeValue($idProductAttribute, $sizeGroup, $idLang);
            if ($size !== null) {
                $result['size'] = $size;
            }
        }

        $genderFeature = (int) Configuration::get('GMCFEEDMANAGER_FEATURE_GENDER');
        if ($genderFeature > 0) {
            $gender = $this->getFeatureValue((int) $product->id, $genderFeature, $idLang);
            if ($gender !== null) {
                $result['gender'] = $gender;
            }
        }

        $ageGroupFeature = (int) Configuration::get('GMCFEEDMANAGER_FEATURE_AGE_GROUP');
        if ($ageGroupFeature > 0) {
            $ageGroup = $this->getFeatureValue((int) $product->id, $ageGroupFeature, $idLang);
            if ($ageGroup !== null) {
                $result['age_group'] = $ageGroup;
            }
        }

        return $result;
    }

    private function getAttributeValue(int $idProductAttribute, int $idAttributeGroup, int $idLang): ?string
    {
        $value = Db::getInstance()->getValue(
            'SELECT al.`name`
             FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
             INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.`id_attribute` = pac.`id_attribute`
             INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                ON al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . (int) $idLang . '
             WHERE pac.`id_product_attribute` = ' . (int) $idProductAttribute . '
               AND a.`id_attribute_group` = ' . (int) $idAttributeGroup . '
             LIMIT 1'
        );

        return $value ?: null;
    }

    private function getFeatureValue(int $idProduct, int $idFeature, int $idLang): ?string
    {
        $value = Db::getInstance()->getValue(
            'SELECT fvl.`value`
             FROM `' . _DB_PREFIX_ . 'feature_product` fp
             INNER JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl
                ON fvl.`id_feature_value` = fp.`id_feature_value` AND fvl.`id_lang` = ' . (int) $idLang . '
             WHERE fp.`id_product` = ' . (int) $idProduct . '
               AND fp.`id_feature` = ' . (int) $idFeature . '
             LIMIT 1'
        );

        return $value ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getProductRule(int $idProduct, int $idProductAttribute): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT *
             FROM `' . _DB_PREFIX_ . 'gmc_product_rule`
             WHERE `id_product` = ' . $idProduct . '
               AND `id_product_attribute` = ' . $idProductAttribute
        );

        return $row ?: null;
    }

    /**
     * @param array<string, mixed>|null $rule
     *
     * @return array<string, string>
     */
    private function buildCustomLabels(?array $rule): array
    {
        if ($rule === null) {
            return [];
        }

        $labels = [];
        for ($i = 0; $i < 5; ++$i) {
            $key = 'custom_label_' . $i;
            if (!empty($rule[$key])) {
                $labels[$key] = (string) $rule[$key];
            }
        }

        return $labels;
    }
}
