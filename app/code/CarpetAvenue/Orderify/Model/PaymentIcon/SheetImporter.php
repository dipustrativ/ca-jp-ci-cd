<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Model\PaymentIcon;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the per-store-view payment-icon strips from a Google Sheet.
 *
 * Sheet is the source of truth: each listed store view's icons are *replaced*
 * with the rows resolved from the sheet. Rows whose store view is unknown, whose
 * icon file is missing, or that are disabled (enabled=0) are skipped and logged;
 * one bad row never aborts the run. Other store views (not in the sheet) and the
 * heading text are left untouched.
 *
 * Expected sheet columns (header row, case-insensitive):
 *   store_view_code  REQUIRED  join key, must equal a Magento store view code
 *   method_code      REQUIRED  resolves payment_icons/{code}.svg|png|…
 *   label            optional  alt text (falls back to method_code)
 *   position         optional  ascending order (falls back to row order)
 *   enabled          optional  1 keep / 0 skip (blank = keep)
 *   store_name       ignored   human label only
 */
class SheetImporter
{
    public const XML_PATH_SHEET_ID = 'orderify/payment_icons/sheet_id';
    public const XML_PATH_GID = 'orderify/payment_icons/gid';
    public const XML_PATH_ICONS = 'orderify/payment_icons/icons';
    public const XML_PATH_ENABLED = 'orderify/payment_icons/enabled';

    private const REQUIRED_HEADERS = ['store_view_code', 'method_code'];
    private const DISABLED_VALUES = ['0', 'no', 'false', 'n', 'off'];
    private const FETCH_TIMEOUT = 30;

    /** Skip reason for intentionally disabled rows — never treated as a failure. */
    private const REASON_DISABLED = 'disabled (enabled=0)';

    /** Max failing rows listed in an admin notification before truncating. */
    private const NOTIFY_MAX_ROWS = 20;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var WriterInterface */
    private $configWriter;

    /** @var IconRepository */
    private $iconRepository;

    /** @var Json */
    private $serializer;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var TypeListInterface */
    private $cacheTypeList;

    /** @var LoggerInterface */
    private $logger;

    /** @var NotifierInterface */
    private $notifier;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        IconRepository $iconRepository,
        Json $serializer,
        StoreManagerInterface $storeManager,
        TypeListInterface $cacheTypeList,
        LoggerInterface $logger,
        NotifierInterface $notifier
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->iconRepository = $iconRepository;
        $this->serializer = $serializer;
        $this->storeManager = $storeManager;
        $this->cacheTypeList = $cacheTypeList;
        $this->logger = $logger;
        $this->notifier = $notifier;
    }

    /**
     * Configured Google Sheet document id.
     *
     * @return string
     */
    public function getSheetId(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_SHEET_ID));
    }

    /**
     * Configured Google Sheet grid (tab) id. Empty when not set.
     *
     * @return string
     */
    public function getGid(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_GID));
    }

    /**
     * CSV export URL for the configured sheet/tab.
     *
     * The gid is only appended when it is a real, non-zero tab id. gid=0 (the
     * implicit first tab) is omitted because uploaded .xlsx files — which have
     * no native gid=0 — reject the explicit "&gid=0" with HTTP 400, while the
     * gid-less URL returns the first tab for both native and uploaded sheets.
     *
     * @param bool $withGid
     * @return string
     */
    public function getExportUrl(bool $withGid = true): string
    {
        $url = sprintf(
            'https://docs.google.com/spreadsheets/d/%s/export?format=csv',
            rawurlencode($this->getSheetId())
        );
        $gid = $this->getGid();
        if ($withGid && $gid !== '' && $gid !== '0') {
            $url .= '&gid=' . rawurlencode($gid);
        }
        return $url;
    }

    /**
     * Run the import, raising an admin notification on any failure.
     *
     * A hard failure (bad config, unreachable sheet, missing columns) raises a
     * critical admin notice and rethrows. Per-row failures (unknown store,
     * missing icon file) raise a major admin notice but do not abort. Intentional
     * `enabled=0` rows are never treated as failures. Dry runs never notify.
     *
     * @param bool $dryRun When true, resolves everything but writes nothing.
     * @return array{
     *     dry_run:bool,
     *     export_url:string,
     *     data_rows:int,
     *     written_stores:int,
     *     written_icons:int,
     *     stores:array<string,array{icons:int}>,
     *     skipped:array<int,array{row:int,store:string,method:string,reason:string}>,
     *     failures:array<int,array{row:int,store:string,method:string,reason:string}>
     * }
     * @throws LocalizedException
     */
    public function import(bool $dryRun = false): array
    {
        try {
            return $this->runImport($dryRun);
        } catch (\Throwable $e) {
            $this->logger->error('[payment-icons] import aborted: ' . $e->getMessage());
            if (!$dryRun) {
                $this->notifier->addCritical(
                    (string)__('Payment icons import failed'),
                    (string)__(
                        'The payment-icon strips could not be updated from the Google Sheet: %1. '
                        . 'Full log: var/log/carpetavenue/orderify/payment_icons_%2.log',
                        $e->getMessage(),
                        date('Y-m-d')
                    )
                );
            }
            throw $e;
        }
    }

    /**
     * @param bool $dryRun
     * @return array
     * @throws LocalizedException
     */
    private function runImport(bool $dryRun): array
    {
        $sheetId = $this->getSheetId();
        if ($sheetId === '') {
            throw new LocalizedException(
                __('Payment Icons Google Sheet ID is not configured (Stores > Configuration > Carpet Avenue > Orderify > Payment Icons).')
            );
        }

        $exportUrl = $this->getExportUrl();
        try {
            $csv = $this->fetchCsv($exportUrl);
        } catch (LocalizedException $e) {
            // Uploaded .xlsx sheets can reject an explicit gid; retry on the
            // gid-less URL (first tab) before giving up.
            $fallbackUrl = $this->getExportUrl(false);
            if ($fallbackUrl === $exportUrl) {
                throw $e;
            }
            $csv = $this->fetchCsv($fallbackUrl);
            $exportUrl = $fallbackUrl;
        }
        $rows = $this->parseCsv($csv);
        if (empty($rows)) {
            throw new LocalizedException(__('The payment-icons sheet returned no data rows.'));
        }

        $this->logger->info(sprintf(
            '[payment-icons] %simport started — fetched %d data row(s) from %s',
            $dryRun ? 'DRY-RUN ' : '',
            count($rows),
            $exportUrl
        ));

        $storeCodeToId = $this->buildStoreCodeMap();

        $perStore = [];   // storeId => list of [position, order, label, icon]
        $storeCodeById = []; // storeId => code (for summary)
        $disabledStoreIds = []; // storeId => code for stores explicitly disabled by the sheet
        $enabledStoreIds = []; // storeId => code for stores with at least one non-disabled sheet row
        $skipped = [];
        $order = 0;

        foreach ($rows as $i => $row) {
            $order++;
            $rowNo = $i + 2; // +1 header, +1 to 1-based
            $storeCode = strtolower(trim((string)($row['store_view_code'] ?? '')));
            $methodCode = trim((string)($row['method_code'] ?? ''));

            if ($storeCode === '') {
                $skipped[] = $this->skip($rowNo, $storeCode, $methodCode, 'empty store_view_code');
                continue;
            }
            if (!isset($storeCodeToId[$storeCode])) {
                $skipped[] = $this->skip($rowNo, $storeCode, $methodCode, 'unknown store view code');
                continue;
            }
            if ($this->isDisabled($row)) {
                $disabledStoreIds[$storeCodeToId[$storeCode]] = $storeCode;
                $skipped[] = $this->skip($rowNo, $storeCode, $methodCode, self::REASON_DISABLED);
                continue;
            }
            $enabledStoreIds[$storeCodeToId[$storeCode]] = $storeCode;
            if ($methodCode === '') {
                $skipped[] = $this->skip($rowNo, $storeCode, $methodCode, 'empty method_code');
                continue;
            }

            $iconPath = $this->iconRepository->resolveByCode($methodCode);
            if ($iconPath === null) {
                $skipped[] = $this->skip($rowNo, $storeCode, $methodCode, 'no icon file in library');
                continue;
            }

            $storeId = $storeCodeToId[$storeCode];
            $storeCodeById[$storeId] = $storeCode;
            $label = trim((string)($row['label'] ?? ''));
            $perStore[$storeId][] = [
                'position' => $this->parsePosition($row, $order),
                'order' => $order,
                'label' => $label !== '' ? $label : $methodCode,
                'icon' => $iconPath,
            ];
        }

        $updated = [];     // store_view_code => icon count (value changed → written)
        $unchanged = [];   // store_view_code => icon count (identical → write skipped)
        $writtenIcons = 0;
        foreach ($perStore as $storeId => $items) {
            // Stable sort: position asc, then original row order.
            usort($items, static function ($a, $b) {
                return [$a['position'], $a['order']] <=> [$b['position'], $b['order']];
            });

            $iconsConfig = [];
            foreach ($items as $idx => $item) {
                $iconsConfig['_' . $idx] = [
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                ];
            }

            $code = $storeCodeById[$storeId];
            $count = count($items);
            $newValue = $this->serializer->serialize($iconsConfig);
            $currentValue = (string)$this->scopeConfig->getValue(
                self::XML_PATH_ICONS,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            $currentlyEnabled = $this->scopeConfig->isSetFlag(
                self::XML_PATH_ENABLED,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );

            // Idempotent: only write when the icon set OR the enabled flag differs.
            if ($newValue === $currentValue && $currentlyEnabled) {
                $unchanged[$code] = $count;
                $this->logger->info(sprintf(
                    '[payment-icons] store "%s" unchanged (%d icon(s)) — write skipped.',
                    $code,
                    $count
                ));
                continue;
            }

            if (!$dryRun) {
                $this->configWriter->save(self::XML_PATH_ICONS, $newValue, ScopeInterface::SCOPE_STORES, $storeId);
                if (!$currentlyEnabled) {
                    $this->configWriter->save(self::XML_PATH_ENABLED, '1', ScopeInterface::SCOPE_STORES, $storeId);
                }
            }

            $updated[$code] = $count;
            $writtenIcons += $count;
            $this->logger->info(sprintf(
                '[payment-icons] store "%s" %s — %d icon(s): %s',
                $code,
                $dryRun ? 'would update' : 'updated',
                $count,
                implode(', ', array_column($items, 'icon'))
            ));
        }

        foreach (array_diff_key($disabledStoreIds, $enabledStoreIds) as $storeId => $code) {
            $currentValue = (string)$this->scopeConfig->getValue(
                self::XML_PATH_ICONS,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            $currentlyEnabled = $this->scopeConfig->isSetFlag(
                self::XML_PATH_ENABLED,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            $emptyValue = $this->serializer->serialize([]);

            if (!$currentlyEnabled && ($currentValue === '' || $currentValue === $emptyValue)) {
                $unchanged[$code] = 0;
                $this->logger->info(sprintf(
                    '[payment-icons] store "%s" already disabled — write skipped.',
                    $code
                ));
                continue;
            }

            if (!$dryRun) {
                $this->configWriter->save(self::XML_PATH_ICONS, $emptyValue, ScopeInterface::SCOPE_STORES, $storeId);
                if ($currentlyEnabled) {
                    $this->configWriter->save(self::XML_PATH_ENABLED, '0', ScopeInterface::SCOPE_STORES, $storeId);
                }
            }

            $updated[$code] = 0;
            $this->logger->info(sprintf(
                '[payment-icons] store "%s" %s — strip disabled by sheet.',
                $code,
                $dryRun ? 'would update' : 'updated'
            ));
        }

        if (!$dryRun) {
            if ($updated) {
                $this->cacheTypeList->cleanType('config');
                $this->cacheTypeList->cleanType('full_page');
                $this->cacheTypeList->cleanType('block_html');
                $this->logger->info('[payment-icons] caches flushed (config, full_page, block_html).');
            } else {
                $this->logger->info('[payment-icons] nothing changed — no writes, caches not flushed.');
            }
        }

        // Categorise the non-imported rows:
        //  - disabled  : enabled=0, deliberate exclusion (not an error)
        //  - failures  : data problems (unknown store, missing icon, blank) → errors
        $disabled = array_values(array_filter($skipped, static function ($s) {
            return $s['reason'] === self::REASON_DISABLED;
        }));
        $failures = array_values(array_filter($skipped, static function ($s) {
            return $s['reason'] !== self::REASON_DISABLED;
        }));

        foreach ($failures as $f) {
            $this->logger->warning(sprintf(
                '[payment-icons] FAILED row %d — store "%s", method "%s": %s',
                $f['row'],
                $f['store'] !== '' ? $f['store'] : '(blank)',
                $f['method'] !== '' ? $f['method'] : '(blank)',
                $f['reason']
            ));
        }

        $result = [
            'dry_run' => $dryRun,
            'export_url' => $exportUrl,
            'data_rows' => count($rows),
            'updated' => $updated,
            'unchanged' => $unchanged,
            'updated_stores' => count($updated),
            'unchanged_stores' => count($unchanged),
            'written_icons' => $writtenIcons,
            'disabled' => $disabled,
            'failures' => $failures,
            'skipped' => $skipped,
        ];

        $this->logger->info(sprintf(
            '[payment-icons] %sfinished — %d updated, %d unchanged, %d disabled, %d FAILED (%d icon(s) written).',
            $dryRun ? 'DRY-RUN ' : '',
            count($updated),
            count($unchanged),
            count($disabled),
            count($failures),
            $writtenIcons
        ));

        if (!$dryRun && $failures) {
            $this->notify($result);
        }

        return $result;
    }

    /**
     * Raise an elaborate major admin notification describing the run and the
     * rows that failed. Only called when there is at least one failure.
     *
     * @param array $result Result array from runImport().
     * @return void
     */
    private function notify(array $result): void
    {
        $failures = $result['failures'];

        $lines = [];
        foreach (array_slice($failures, 0, self::NOTIFY_MAX_ROWS) as $f) {
            $lines[] = sprintf(
                'Row %d: store "%s", method "%s" — %s',
                $f['row'],
                $f['store'] !== '' ? $f['store'] : '(blank)',
                $f['method'] !== '' ? $f['method'] : '(blank)',
                $f['reason']
            );
        }
        $extra = count($failures) - self::NOTIFY_MAX_ROWS;
        if ($extra > 0) {
            $lines[] = sprintf('…and %d more.', $extra);
        }

        $description = sprintf(
            'Payment-icon strips synced from the Google Sheet. '
            . 'Result: %d store(s) updated, %d unchanged, %d disabled, %d FAILED. '
            . 'Failed rows — %s. '
            . 'Tip: a "no icon file" failure means the method_code has no matching file in '
            . 'pub/media/payment_icons/ (upload {code}.svg|png) — an "unknown store view code" '
            . 'means the store_view_code does not exist. Full log: '
            . 'var/log/carpetavenue/orderify/payment_icons_%s.log',
            $result['updated_stores'],
            $result['unchanged_stores'],
            count($result['disabled']),
            count($failures),
            implode(' | ', $lines),
            date('Y-m-d')
        );

        $this->notifier->addMajor(
            (string)__('Payment icons import: %1 row(s) failed to import', count($failures)),
            $description
        );
    }

    /**
     * Fetch the sheet CSV over HTTPS.
     *
     * @param string $url
     * @return string
     * @throws LocalizedException
     */
    private function fetchCsv(string $url): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; CarpetAvenueOrderify/1.0)');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::FETCH_TIMEOUT);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: text/csv, text/plain, */*']);

        $body = curl_exec($ch);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        unset($ch);

        if ($errNo !== 0 || $body === false) {
            throw new LocalizedException(__('Could not fetch the sheet: %1', $errMsg ?: 'cURL error ' . $errNo));
        }
        if ($httpCode !== 200) {
            throw new LocalizedException(
                __('Could not fetch the sheet (HTTP %1). Check the Sheet ID/Grid ID and that link sharing is "Anyone with the link".', $httpCode)
            );
        }
        if (stripos($contentType, 'text/html') !== false) {
            throw new LocalizedException(
                __('The sheet URL returned HTML, not CSV. The sheet is likely not shared publicly (set sharing to "Anyone with the link").')
            );
        }
        if (trim((string)$body) === '') {
            throw new LocalizedException(__('The sheet returned an empty response.'));
        }

        return (string)$body;
    }

    /**
     * Parse CSV into a list of header-keyed rows. Quote- and newline-safe.
     *
     * @param string $csv
     * @return array<int,array<string,string>>
     * @throws LocalizedException
     */
    private function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv; // strip UTF-8 BOM
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $headers = null;
        $rows = [];
        while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($headers === null) {
                $headers = array_map(static function ($h) {
                    return strtolower(trim((string)$h));
                }, $cells);
                $missing = array_diff(self::REQUIRED_HEADERS, $headers);
                if (!empty($missing)) {
                    fclose($handle);
                    throw new LocalizedException(
                        __('The sheet is missing required column(s): %1. Expected at least: store_view_code, method_code.', implode(', ', $missing))
                    );
                }
                continue;
            }

            // Skip fully blank lines.
            if (count($cells) === 1 && trim((string)$cells[0]) === '') {
                continue;
            }

            $row = [];
            foreach ($headers as $idx => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = isset($cells[$idx]) ? (string)$cells[$idx] : '';
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Map every non-admin store view code to its id.
     *
     * @return array<string,int>
     */
    private function buildStoreCodeMap(): array
    {
        $map = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $map[strtolower((string)$store->getCode())] = (int)$store->getId();
        }
        return $map;
    }

    /**
     * Whether a row is explicitly disabled.
     *
     * @param array<string,string> $row
     * @return bool
     */
    private function isDisabled(array $row): bool
    {
        if (!array_key_exists('enabled', $row)) {
            return false;
        }
        $val = strtolower(trim((string)$row['enabled']));
        if ($val === '') {
            return false;
        }
        return in_array($val, self::DISABLED_VALUES, true);
    }

    /**
     * Parse the position cell; non-numeric falls back to row order.
     *
     * @param array<string,string> $row
     * @param int $fallback
     * @return int
     */
    private function parsePosition(array $row, int $fallback): int
    {
        $raw = trim((string)($row['position'] ?? ''));
        return is_numeric($raw) ? (int)$raw : $fallback;
    }

    /**
     * @param int $rowNo
     * @param string $store
     * @param string $method
     * @param string $reason
     * @return array{row:int,store:string,method:string,reason:string}
     */
    private function skip(int $rowNo, string $store, string $method, string $reason): array
    {
        $entry = ['row' => $rowNo, 'store' => $store, 'method' => $method, 'reason' => $reason];
        $this->logger->debug('[payment-icons] skipped row', $entry);
        return $entry;
    }
}
