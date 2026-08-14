<?php

declare(strict_types=1);

namespace GmcFeedManager\Service;

use Configuration;
use PrestaShopLogger;
use RuntimeException;

/**
 * Real-time sync with the Google Content API for Shopping (v2.1) using a
 * Service Account (JWT bearer / OAuth2 "two-legged" flow) -- no user
 * consent screen, no refresh token to store.
 *
 * @see https://developers.google.com/shopping-content/reference/rest/v2.1/products
 */
class GoogleContentApiService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const API_BASE_URL = 'https://shoppingcontent.googleapis.com/content/v2.1/';
    private const SCOPE = 'https://www.googleapis.com/auth/content';
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    /** @var array<string, mixed> */
    private readonly array $credentials;

    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;

    public function __construct(
        private readonly string $merchantId,
        string $serviceAccountJson
    ) {
        $credentials = json_decode($serviceAccountJson, true);

        if (
            !is_array($credentials)
            || empty($credentials['client_email'])
            || empty($credentials['private_key'])
        ) {
            throw new RuntimeException('gmcfeedmanager: invalid Google service account JSON payload.');
        }

        $this->credentials = $credentials;
    }

    /**
     * Upserts a single product. $data is the flat array produced by
     * ProductDataTransformer::transform().
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> Decoded API response
     */
    public function insertProduct(array $data): array
    {
        $payload = $this->mapToApiPayload($data);

        return $this->request('POST', $this->merchantId . '/products', $payload);
    }

    /**
     * The Content API "products" resource has no partial-PATCH verb --
     * every write replaces the resource wholesale via products.insert
     * (upsert on offerId/channel/contentLanguage/targetCountry). This
     * method exists as the name real-time hooks call; it simply forwards
     * to insertProduct() so callers don't need to know that distinction.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function patchProduct(array $data): array
    {
        if (!empty($data['excluded'])) {
            return $this->deleteProduct($this->buildRestProductId((string) $data['id']))
                ? ['status' => 'deleted']
                : ['status' => 'delete_failed'];
        }

        return $this->insertProduct($data);
    }

    /**
     * @param string $productId Either the bare offerId (e.g. "102_45") or
     *                           the full Content API resource id
     *                           ("online:en:US:102_45").
     */
    public function deleteProduct(string $productId): bool
    {
        $restId = str_contains($productId, ':') ? $productId : $this->buildRestProductId($productId);

        try {
            $this->request('DELETE', $this->merchantId . '/products/' . rawurlencode($restId));

            return true;
        } catch (RuntimeException $e) {
            PrestaShopLogger::addLog('gmcfeedmanager: deleteProduct failed - ' . $e->getMessage(), 2);

            return false;
        }
    }

    private function buildRestProductId(string $offerId): string
    {
        $channel = 'online';
        $contentLanguage = (string) Configuration::get('GMCFEEDMANAGER_CONTENT_LANGUAGE') ?: 'en';
        $targetCountry = (string) Configuration::get('GMCFEEDMANAGER_TARGET_COUNTRY') ?: 'US';

        return sprintf('%s:%s:%s:%s', $channel, $contentLanguage, $targetCountry, $offerId);
    }

    /**
     * Reshapes ProductDataTransformer's flat, RSS-flavoured array into the
     * Content API's camelCase JSON schema.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function mapToApiPayload(array $data): array
    {
        $contentLanguage = (string) Configuration::get('GMCFEEDMANAGER_CONTENT_LANGUAGE') ?: 'en';
        $targetCountry = (string) Configuration::get('GMCFEEDMANAGER_TARGET_COUNTRY') ?: 'US';

        $payload = [
            'offerId' => (string) $data['id'],
            'channel' => 'online',
            'contentLanguage' => $contentLanguage,
            'targetCountry' => $targetCountry,
            'title' => (string) ($data['title'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'link' => (string) ($data['link'] ?? ''),
            'imageLink' => (string) ($data['image_link'] ?? ''),
            'condition' => (string) ($data['condition'] ?? 'new'),
            'availability' => (string) ($data['availability'] ?? 'in stock'),
            'price' => $this->toPriceObject((string) ($data['price'] ?? '0.00 EUR')),
        ];

        if (!empty($data['item_group_id'])) {
            $payload['itemGroupId'] = (string) $data['item_group_id'];
        }

        if (!empty($data['additional_image_link'])) {
            $payload['additionalImageLinks'] = array_values((array) $data['additional_image_link']);
        }

        if (!empty($data['sale_price'])) {
            $payload['salePrice'] = $this->toPriceObject((string) $data['sale_price']);
        }

        if (!empty($data['sale_price_effective_date'])) {
            $payload['salePriceEffectiveDate'] = (string) $data['sale_price_effective_date'];
        }

        if (!empty($data['gtin'])) {
            $payload['gtin'] = (string) $data['gtin'];
        }

        if (!empty($data['mpn'])) {
            $payload['mpn'] = (string) $data['mpn'];
        }

        if (!empty($data['identifier_exists'])) {
            $payload['identifierExists'] = $data['identifier_exists'] !== 'no';
        }

        if (!empty($data['brand'])) {
            $payload['brand'] = (string) $data['brand'];
        }

        if (!empty($data['google_product_category'])) {
            $payload['googleProductCategory'] = (string) $data['google_product_category'];
        }

        if (!empty($data['product_type'])) {
            $payload['productTypes'] = [(string) $data['product_type']];
        }

        foreach (['color', 'size', 'gender', 'age_group'] as $apparelField) {
            if (!empty($data[$apparelField])) {
                $camelKey = $apparelField === 'age_group' ? 'ageGroup' : $apparelField;
                $payload[$camelKey] = (string) $data[$apparelField];
            }
        }

        for ($i = 0; $i < 5; ++$i) {
            $key = 'custom_label_' . $i;
            if (!empty($data[$key])) {
                $payload['customLabel' . $i] = (string) $data[$key];
            }
        }

        return $payload;
    }

    /**
     * @return array{value: string, currency: string}
     */
    private function toPriceObject(string $formatted): array
    {
        [$value, $currency] = array_pad(explode(' ', trim($formatted), 2), 2, '');

        return ['value' => $value, 'currency' => $currency];
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $token = $this->getAccessToken();

        $curlHandle = curl_init();
        $options = [
            CURLOPT_URL => self::API_BASE_URL . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($curlHandle, $options);

        $response = curl_exec($curlHandle);
        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $error = curl_error($curlHandle);
        curl_close($curlHandle);

        if ($response === false) {
            throw new RuntimeException('gmcfeedmanager: Content API request failed - ' . $error);
        }

        $decoded = json_decode((string) $response, true);

        if ($httpCode >= 400) {
            $message = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : ('HTTP ' . $httpCode);

            PrestaShopLogger::addLog('gmcfeedmanager: Content API error - ' . $message, 3);

            throw new RuntimeException('gmcfeedmanager: Content API error - ' . $message);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Signs and exchanges a JWT bearer assertion for a short-lived OAuth2
     * access token, caching it in memory for the lifetime of this instance
     * (a request handles at most one product, so no cross-request cache is
     * needed here).
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt - 30) {
            return $this->accessToken;
        }

        $assertion = $this->buildSignedJwt();

        $curlHandle = curl_init();
        curl_setopt_array($curlHandle, [
            CURLOPT_URL => self::TOKEN_URL,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => self::GRANT_TYPE,
                'assertion' => $assertion,
            ]),
        ]);

        $response = curl_exec($curlHandle);
        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        curl_close($curlHandle);

        $decoded = json_decode((string) $response, true);

        if ($httpCode !== 200 || !is_array($decoded) || empty($decoded['access_token'])) {
            throw new RuntimeException('gmcfeedmanager: unable to obtain Google OAuth2 access token.');
        }

        $this->accessToken = (string) $decoded['access_token'];
        $this->accessTokenExpiresAt = time() + (int) ($decoded['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    /**
     * Builds and RS256-signs a JWT bearer assertion per Google's
     * service-account flow.
     *
     * @see https://developers.google.com/identity/protocols/oauth2/service-account
     */
    private function buildSignedJwt(): string
    {
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claimSet = [
            'iss' => (string) $this->credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => (string) ($this->credentials['token_uri'] ?? self::TOKEN_URL),
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $segments = [
            $this->base64UrlEncode((string) json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode((string) json_encode($claimSet, JSON_THROW_ON_ERROR)),
        ];

        $signingInput = implode('.', $segments);

        $privateKey = openssl_pkey_get_private((string) $this->credentials['private_key']);
        if ($privateKey === false) {
            throw new RuntimeException('gmcfeedmanager: could not load service account private key.');
        }

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (!$signed) {
            throw new RuntimeException('gmcfeedmanager: failed to sign JWT assertion.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
