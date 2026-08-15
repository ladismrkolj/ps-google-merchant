<?php

declare(strict_types=1);

namespace GmcFeedManager\Service;

use Db;
use PrestaShopLogger;

/**
 * Stores/searches the Google Product Taxonomy and manages the mapping
 * between PrestaShop categories and Google category nodes
 * (ps_gmc_category_mapping).
 *
 * The taxonomy flat file (~5-6k lines of "id - Full > Path > Name") is
 * downloaded once, normalised to a local "id\tname" cache, and streamed
 * line-by-line on every search so memory use stays flat regardless of
 * catalog or taxonomy size.
 */
class GoogleCategoryService
{
    private const DEFAULT_TAXONOMY_URL = 'https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt';
    private const DEFAULT_CACHE_TTL = 604800; // 7 days
    private const DOWNLOAD_RETRY_BACKOFF = 900; // 15 min between failed download retries

    private readonly string $taxonomySourceUrl;
    private readonly int $cacheTtlSeconds;

    public function __construct(
        ?string $taxonomySourceUrl = null,
        ?int $cacheTtlSeconds = null
    ) {
        $this->taxonomySourceUrl = $taxonomySourceUrl ?: self::DEFAULT_TAXONOMY_URL;
        $this->cacheTtlSeconds = $cacheTtlSeconds ?? self::DEFAULT_CACHE_TTL;
    }

    /**
     * Typeahead search over the cached taxonomy.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        if (!$this->isCacheFresh() && $this->shouldAttemptDownload()) {
            $this->downloadTaxonomy();
        }

        // A stale cache still beats no cache: the taxonomy changes a few
        // times a year, so serving slightly old entries is far better than
        // an empty typeahead when the download is unavailable.
        $path = $this->getCacheFilePath();
        if (!is_file($path)) {
            return [];
        }

        $results = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }

            [$id, $name] = array_pad(explode("\t", $line, 2), 2, null);
            if ($id === null || $name === null) {
                continue;
            }

            if (mb_stripos($name, $term) !== false) {
                $results[] = ['id' => (int) $id, 'name' => $name];
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        fclose($handle);

        return $results;
    }

    /**
     * Downloads the taxonomy file and rewrites it as a normalised
     * "id\tname" cache. Streams both source and destination so peak memory
     * stays independent of file size.
     */
    public function downloadTaxonomy(): bool
    {
        $this->ensureCacheDirectoryExists();

        // Stamp the attempt before doing it, so a hanging or failing
        // download cannot be retried by the next keystroke.
        @touch($this->getDownloadAttemptFilePath());

        $tmpFile = $this->getCacheDirectory() . 'taxonomy_raw_' . uniqid('', true) . '.txt';

        $curlHandle = curl_init();
        $fileHandle = fopen($tmpFile, 'wb');
        if ($fileHandle === false) {
            return false;
        }

        curl_setopt_array($curlHandle, [
            CURLOPT_URL => $this->taxonomySourceUrl,
            CURLOPT_FILE => $fileHandle,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $success = curl_exec($curlHandle);
        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $error = curl_error($curlHandle);
        curl_close($curlHandle);
        fclose($fileHandle);

        if ($success === false || $httpCode !== 200) {
            @unlink($tmpFile);
            PrestaShopLogger::addLog(
                'gmcfeedmanager: taxonomy download failed - ' . ($error ?: ('HTTP ' . $httpCode)),
                2
            );

            return false;
        }

        $normalised = $this->normaliseTaxonomyFile($tmpFile);
        @unlink($tmpFile);

        return $normalised;
    }

    /**
     * Converts "id - Category > Path > Name" lines into "id\tCategory > Path > Name".
     */
    private function normaliseTaxonomyFile(string $rawPath): bool
    {
        $source = fopen($rawPath, 'rb');
        if ($source === false) {
            return false;
        }

        $destination = fopen($this->getCacheFilePath(), 'wb');
        if ($destination === false) {
            fclose($source);

            return false;
        }

        while (($line = fgets($source)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $parts = explode(' - ', $line, 2);
            if (count($parts) !== 2 || !ctype_digit($parts[0])) {
                continue;
            }

            fwrite($destination, $parts[0] . "\t" . $parts[1] . "\n");
        }

        fclose($source);
        fclose($destination);

        return true;
    }

    private function isCacheFresh(): bool
    {
        $path = $this->getCacheFilePath();
        if (!is_file($path)) {
            return false;
        }

        return (time() - (int) filemtime($path)) < $this->cacheTtlSeconds;
    }

    /**
     * The module's own var/ directory -- deliberately NOT _PS_CACHE_DIR_,
     * which is environment-scoped (var/cache/<env>/) and wiped every time
     * the Symfony cache is cleared. The taxonomy is a multi-megabyte
     * download that changes a few times a year: treating it as clearable
     * cache means re-fetching it after every cache clear and leaving the
     * typeahead dead whenever the download is unavailable.
     */
    private function getCacheDirectory(): string
    {
        return dirname(__DIR__, 2) . '/var/';
    }

    private function getCacheFilePath(): string
    {
        return $this->getCacheDirectory() . 'taxonomy.tsv';
    }

    /**
     * Marker recording when a download was last attempted, so a failing
     * or unreachable source is retried on a backoff rather than on every
     * single typeahead keystroke.
     */
    private function getDownloadAttemptFilePath(): string
    {
        return $this->getCacheDirectory() . 'taxonomy.attempt';
    }

    private function shouldAttemptDownload(): bool
    {
        $marker = $this->getDownloadAttemptFilePath();
        if (!is_file($marker)) {
            return true;
        }

        return (time() - (int) filemtime($marker)) >= self::DOWNLOAD_RETRY_BACKOFF;
    }

    private function ensureCacheDirectoryExists(): void
    {
        $dir = $this->getCacheDirectory();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * @return array{id: int, name: string}|null
     */
    public function getMapping(int $idCategory, int $idShop): ?array
    {
        $row = Db::getInstance()->getRow(
            'SELECT `google_category_id`, `google_category_name`
             FROM `' . _DB_PREFIX_ . 'gmc_category_mapping`
             WHERE `id_category` = ' . (int) $idCategory . '
               AND `id_shop` = ' . (int) $idShop
        );

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['google_category_id'],
            'name' => (string) $row['google_category_name'],
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}> keyed by id_category
     */
    public function getAllMappings(int $idShop): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT `id_category`, `google_category_id`, `google_category_name`
             FROM `' . _DB_PREFIX_ . 'gmc_category_mapping`
             WHERE `id_shop` = ' . (int) $idShop
        );

        $mappings = [];
        foreach (($rows ?: []) as $row) {
            $mappings[(int) $row['id_category']] = [
                'id' => (int) $row['google_category_id'],
                'name' => (string) $row['google_category_name'],
            ];
        }

        return $mappings;
    }

    public function saveMapping(int $idCategory, int $idShop, int $googleCategoryId, string $googleCategoryName): bool
    {
        return Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'gmc_category_mapping`
                (`id_category`, `id_shop`, `google_category_id`, `google_category_name`)
             VALUES (
                ' . (int) $idCategory . ',
                ' . (int) $idShop . ',
                ' . (int) $googleCategoryId . ',
                \'' . pSQL($googleCategoryName) . '\'
             )
             ON DUPLICATE KEY UPDATE
                `google_category_id` = ' . (int) $googleCategoryId . ',
                `google_category_name` = \'' . pSQL($googleCategoryName) . '\''
        );
    }

    public function deleteMapping(int $idCategory, int $idShop): bool
    {
        return Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'gmc_category_mapping`
             WHERE `id_category` = ' . (int) $idCategory . '
               AND `id_shop` = ' . (int) $idShop
        );
    }
}
