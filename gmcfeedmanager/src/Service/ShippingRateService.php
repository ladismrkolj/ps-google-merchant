<?php

declare(strict_types=1);

namespace GmcFeedManager\Service;

use Db;

/**
 * CRUD over ps_gmc_shipping_rate: one flat rate per destination country,
 * applied identically to every product in the feed and every Content API
 * offer. Deliberately not derived from PrestaShop's carrier/zone/weight
 * price-range system -- that models real checkout cost calculation, which
 * has no single "price for country X" answer once weight tiers or multiple
 * carriers are involved. Google's g:shipping wants one flat, predictable
 * number per destination, which is exactly what most merchants configure
 * directly in Merchant Center's own shipping settings; this mirrors that
 * rather than trying to reverse-engineer it from checkout logic.
 */
class ShippingRateService
{
    /**
     * @return array<int, array{id: int, iso_country: string, region: string, service: string, price: float, currency_iso: string}>
     */
    public function getAllRates(int $idShop): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_shipping_rate`, `iso_country`, `region`, `service`, `price`, `currency_iso`
             FROM `' . _DB_PREFIX_ . 'gmc_shipping_rate`
             WHERE `id_shop` = ' . (int) $idShop . '
             ORDER BY `iso_country` ASC'
        );

        $rates = [];
        foreach (($rows ?: []) as $row) {
            $rates[] = [
                'id' => (int) $row['id_shipping_rate'],
                'iso_country' => (string) $row['iso_country'],
                'region' => (string) ($row['region'] ?? ''),
                'service' => (string) ($row['service'] ?? ''),
                'price' => (float) $row['price'],
                'currency_iso' => (string) $row['currency_iso'],
            ];
        }

        return $rates;
    }

    /**
     * Replaces every rate row for the shop in one go -- this is edited as a
     * small table on a settings screen, not incrementally, so "delete all,
     * insert the submitted set" is simpler and safer than diffing.
     *
     * @param array<int, array{iso_country: string, region?: string, service?: string, price: float, currency_iso: string}> $rates
     */
    public function replaceAllRates(int $idShop, array $rates): bool
    {
        $db = Db::getInstance();

        if (!$db->execute('DELETE FROM `' . _DB_PREFIX_ . 'gmc_shipping_rate` WHERE `id_shop` = ' . (int) $idShop)) {
            return false;
        }

        $seenCountries = [];

        foreach ($rates as $rate) {
            $isoCountry = strtoupper(trim((string) ($rate['iso_country'] ?? '')));
            if ($isoCountry === '' || strlen($isoCountry) !== 2) {
                continue;
            }

            // The unique key is (id_shop, iso_country): silently keep the
            // first row for a country rather than letting a duplicate blow
            // up the whole save with a constraint violation.
            if (isset($seenCountries[$isoCountry])) {
                continue;
            }
            $seenCountries[$isoCountry] = true;

            $price = (float) ($rate['price'] ?? 0);
            $currencyIso = strtoupper(trim((string) ($rate['currency_iso'] ?? '')));
            if ($currencyIso === '') {
                continue;
            }

            $region = trim((string) ($rate['region'] ?? ''));
            $service = trim((string) ($rate['service'] ?? ''));

            // $null_values = true: Db::insert() only writes SQL NULL for a
            // PHP null when this is set. Without it, null is stringified to
            // '' instead, which is not the same thing for an optional
            // sub-attribute Google should not see at all.
            $ok = $db->insert('gmc_shipping_rate', [
                'id_shop' => (int) $idShop,
                'iso_country' => pSQL($isoCountry),
                'region' => $region !== '' ? pSQL($region) : null,
                'service' => $service !== '' ? pSQL($service) : null,
                'price' => (float) $price,
                'currency_iso' => pSQL($currencyIso),
            ], true);

            if (!$ok) {
                return false;
            }
        }

        return true;
    }
}
