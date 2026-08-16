<?php

declare(strict_types=1);

namespace GmcFeedManager\Service;

use Configuration;
use Currency;
use Db;

/**
 * Derives flat per-country shipping rates from PrestaShop's own carrier
 * configuration (Shipping > Carriers) so the rates do not have to be
 * retyped by hand.
 *
 * Read-only with respect to PrestaShop: this reads ps_carrier / ps_delivery
 * / ps_zone / ps_country and writes only into the module's own
 * ps_gmc_shipping_rate table. Nothing in the shop's shipping configuration
 * is modified, and the imported rows stay editable afterwards -- the import
 * is a starting point, not a live binding.
 *
 * Why a snapshot rather than computing per product at feed time:
 * PrestaShop prices shipping per cart (weight/price ranges, free-shipping
 * thresholds, per-carrier rules), so there is no single honest "shipping
 * cost for country X" for an individual product. Google's g:shipping wants
 * exactly one predictable number per destination, so this takes the
 * entry-level range for each carrier -- what a small order actually pays --
 * and keeps the cheapest carrier per country.
 */
class CarrierShippingImporter
{
    /**
     * Builds the rate rows without persisting them, so the caller can show
     * a preview or hand them to ShippingRateService::replaceAllRates().
     *
     * @return array<int, array{iso_country: string, region: string, service: string, price: float, currency_iso: string}>
     */
    public function buildRatesFromCarriers(int $idShop, int $idCurrency): array
    {
        $currency = new Currency($idCurrency);
        $currencyIso = $currency->iso_code ?: (Currency::getIsoCodeById((int) Configuration::get('PS_CURRENCY_DEFAULT')) ?: 'EUR');

        // ps_delivery prices are stored in the shop's default currency.
        $conversionRate = (float) ($currency->conversion_rate ?: 1.0);

        $handlingFee = (float) Configuration::get('PS_SHIPPING_HANDLING');

        $rows = Db::getInstance()->executeS(
            'SELECT c.`id_carrier`, c.`name` AS carrier_name, c.`is_free`, c.`shipping_handling`,
                    cz.`id_zone`, co.`iso_code`
             FROM `' . _DB_PREFIX_ . 'carrier` c
             INNER JOIN `' . _DB_PREFIX_ . 'carrier_zone` cz
                ON cz.`id_carrier` = c.`id_carrier`
             INNER JOIN `' . _DB_PREFIX_ . 'country` co
                ON co.`id_zone` = cz.`id_zone` AND co.`active` = 1
             INNER JOIN `' . _DB_PREFIX_ . 'zone` z
                ON z.`id_zone` = cz.`id_zone` AND z.`active` = 1
             WHERE c.`active` = 1 AND c.`deleted` = 0
             ORDER BY co.`iso_code` ASC'
        );

        if (!$rows) {
            return [];
        }

        /** @var array<string, array{price: float, service: string}> $cheapestByCountry */
        $cheapestByCountry = [];

        foreach ($rows as $row) {
            $isoCountry = strtoupper((string) $row['iso_code']);
            $idCarrier = (int) $row['id_carrier'];
            $idZone = (int) $row['id_zone'];

            if ((bool) $row['is_free']) {
                $price = 0.0;
            } else {
                $basePrice = $this->getEntryLevelPrice($idCarrier, $idZone, $idShop);
                if ($basePrice === null) {
                    // Carrier serves the zone but has no delivery row for
                    // it: it cannot actually ship there, so skip it rather
                    // than publish a 0.00 "free shipping" claim.
                    continue;
                }

                $price = $basePrice;
                if ((bool) $row['shipping_handling']) {
                    $price += $handlingFee;
                }
            }

            $price = round($price * $conversionRate, 2);

            if (!isset($cheapestByCountry[$isoCountry]) || $price < $cheapestByCountry[$isoCountry]['price']) {
                $cheapestByCountry[$isoCountry] = [
                    'price' => $price,
                    'service' => $this->buildServiceLabel($isoCountry, (string) $row['carrier_name']),
                ];
            }
        }

        ksort($cheapestByCountry);

        $rates = [];
        foreach ($cheapestByCountry as $isoCountry => $entry) {
            $rates[] = [
                'iso_country' => $isoCountry,
                'region' => '',
                'service' => $entry['service'],
                'price' => $entry['price'],
                'currency_iso' => $currencyIso,
            ];
        }

        return $rates;
    }

    /**
     * The price of the carrier's *lowest* range for this zone.
     *
     * Deliberately not MIN(price): carriers commonly have a 0.00 top range
     * ("free over 100"), and taking the minimum would advertise free
     * shipping to everyone. The lowest range is what a small order pays,
     * which is the honest flat figure to publish.
     */
    private function getEntryLevelPrice(int $idCarrier, int $idZone, int $idShop): ?float
    {
        $price = Db::getInstance()->getValue(
            'SELECT d.`price`
             FROM `' . _DB_PREFIX_ . 'delivery` d
             LEFT JOIN `' . _DB_PREFIX_ . 'range_price` rp ON rp.`id_range_price` = d.`id_range_price`
             LEFT JOIN `' . _DB_PREFIX_ . 'range_weight` rw ON rw.`id_range_weight` = d.`id_range_weight`
             WHERE d.`id_carrier` = ' . $idCarrier . '
               AND d.`id_zone` = ' . $idZone . '
               AND (d.`id_shop` IS NULL OR d.`id_shop` = 0 OR d.`id_shop` = ' . $idShop . ')
             ORDER BY IFNULL(rp.`delimiter1`, rw.`delimiter1`) ASC, d.`price` ASC'
        );

        return ($price === false || $price === null) ? null : (float) $price;
    }

    /**
     * Mirrors the shape of the labels other platforms generate (e.g.
     * "[2e58] Google for WooCommerce generated service - NL") so the origin
     * of the rate is obvious in Merchant Center. The short hash keeps the
     * label stable per shop/country/carrier without being noisy.
     */
    private function buildServiceLabel(string $isoCountry, string $carrierName): string
    {
        $hash = substr(md5($isoCountry . '|' . $carrierName), 0, 4);

        return sprintf('[%s] gmcfeedmanager generated service - %s', $hash, $isoCountry);
    }
}
