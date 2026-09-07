<?php
/**
 * Magento 2 Standalone Product Image Downloader
 *
 * Usage: php IMAGE.php            (interactive)
 *
 * Env:
 *   IMAGE_SOURCE_URL=https://old-origin/media/catalog/product   override the source
 *   VERBOSE=1                                                   log every image, not just problems
 *   NO_COLOR=1                                                  plain output, no ANSI
 *
 * Downloads originals listed in catalog_product_entity_media_gallery into
 * pub/media/catalog/product, rejecting placeholders and undersized files.
 */

use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

require __DIR__ . '/app/bootstrap.php';

/**
 * Terminal output.
 *
 * Keeps an N-line "sticky" block pinned at the bottom of the screen; log lines
 * printed through line() scroll above it without disturbing it. Falls back to
 * plain sequential output when stdout is not a TTY (cron, docker logs, pipes).
 */
final class Console
{
    private const STYLES = [
        'reset' => '0', 'bold' => '1', 'dim' => '2',
        'red' => '31', 'green' => '32', 'yellow' => '33',
        'blue' => '34', 'magenta' => '35', 'cyan' => '36', 'grey' => '90',
    ];

    private bool $tty;
    private bool $color;
    private int $width;

    /** @var string[] currently displayed sticky lines */
    private array $sticky = [];
    private bool $cursorHidden = false;

    public function __construct()
    {
        $this->tty = function_exists('stream_isatty') && @stream_isatty(STDOUT);
        $this->color = $this->tty && getenv('NO_COLOR') === false;
        $this->width = $this->detectWidth();
    }

    public function isTty(): bool
    {
        return $this->tty;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function c(string $text, string $style): string
    {
        if (!$this->color || !isset(self::STYLES[$style])) {
            return $text;
        }
        return "\033[" . self::STYLES[$style] . "m{$text}\033[0m";
    }

    /**
     * Print a line above the sticky block.
     *
     * Clipped to the usable width: a line that wraps becomes two physical rows,
     * which breaks the sticky block's "walk up N rows" erase arithmetic and
     * shreds the panel.
     */
    public function line(string $text = ''): void
    {
        $keep = $this->sticky;
        $this->eraseSticky();
        echo ($this->tty ? $this->clip($text) : $text) . "\n";
        $this->sticky = $keep;
        $this->paintSticky();
    }

    /**
     * Replace the pinned block.
     *
     * @param string[] $lines
     */
    public function setSticky(array $lines): void
    {
        if (!$this->tty) {
            return;
        }
        $this->eraseSticky();
        $this->sticky = $lines;
        $this->hideCursor();
        $this->paintSticky();
    }

    /** Remove the block for good (before the final summary). */
    public function dropSticky(): void
    {
        $this->eraseSticky();
        $this->sticky = [];
        $this->showCursor();
    }

    private function paintSticky(): void
    {
        if (!$this->tty || !$this->sticky) {
            return;
        }
        // Leave the cursor parked at the end of the last line, no trailing newline,
        // so the block occupies exactly count($sticky) rows.
        echo implode("\n", $this->sticky);
    }

    private function eraseSticky(): void
    {
        if (!$this->tty || !$this->sticky) {
            return;
        }
        $n = count($this->sticky);
        echo "\r\033[2K";                       // clear the row the cursor sits on
        for ($i = 1; $i < $n; $i++) {
            echo "\033[1A\r\033[2K";            // walk up, clearing each row
        }
    }

    private function hideCursor(): void
    {
        if ($this->tty && !$this->cursorHidden) {
            echo "\033[?25l";
            $this->cursorHidden = true;
        }
    }

    public function showCursor(): void
    {
        if ($this->tty && $this->cursorHidden) {
            echo "\033[?25h";
            $this->cursorHidden = false;
        }
    }

    public function rule(string $title = ''): void
    {
        if ($title === '') {
            $this->line($this->c(str_repeat('─', $this->width), 'grey'));
            return;
        }
        $pad = max(0, $this->width - $this->visibleWidth($title) - 4);
        $this->line(
            $this->c('── ', 'grey') . $this->c($title, 'bold') . ' ' . $this->c(str_repeat('─', $pad), 'grey')
        );
    }

    // ─── Width-aware helpers (ANSI codes must not count toward layout) ───────

    public function stripAnsi(string $s): string
    {
        return preg_replace('/\033\[[0-9;?]*[a-zA-Z]/', '', $s) ?? $s;
    }

    public function visibleWidth(string $s): int
    {
        return mb_strwidth($this->stripAnsi($s), 'UTF-8');
    }

    /** Pad a (possibly coloured) string to $width visible columns. */
    public function pad(string $s, int $width): string
    {
        $diff = $width - $this->visibleWidth($s);
        return $diff > 0 ? $s . str_repeat(' ', $diff) : $s;
    }

    /** Truncate to $width visible columns, ellipsising the FRONT (tails are more useful for paths). */
    public function truncateStart(string $s, int $width): string
    {
        if (mb_strwidth($s, 'UTF-8') <= $width) {
            return $s;
        }
        $out = '';
        for ($i = mb_strlen($s) - 1; $i >= 0; $i--) {
            $ch = mb_substr($s, $i, 1);
            if (mb_strwidth($out . $ch, 'UTF-8') > $width - 1) {
                break;
            }
            $out = $ch . $out;
        }
        return '…' . $out;
    }

    /**
     * Clip a coloured string to $max visible columns, keeping ANSI codes intact
     * (they cost zero columns) and closing with a reset.
     */
    public function clip(string $s, ?int $max = null): string
    {
        $max ??= $this->width;
        if ($this->visibleWidth($s) <= $max) {
            return $s;
        }

        $parts = preg_split('/(\033\[[0-9;?]*[a-zA-Z])/', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = '';
        $w = 0;

        foreach ($parts as $part) {
            if ($part !== '' && $part[0] === "\033") {
                $out .= $part;
                continue;
            }
            foreach (preg_split('//u', $part, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                $cw = mb_strwidth($ch, 'UTF-8');
                if ($w + $cw > $max - 1) {
                    return $out . '…' . "\033[0m";
                }
                $out .= $ch;
                $w += $cw;
            }
        }

        return $out . "\033[0m";
    }

    public function truncate(string $s, int $width): string
    {
        if (mb_strwidth($s, 'UTF-8') <= $width) {
            return $s;
        }
        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if (mb_strwidth($out . $ch, 'UTF-8') > $width - 1) {
                break;
            }
            $out .= $ch;
        }
        return $out . '…';
    }

    /**
     * Usable width, always one column short of the real terminal: writing the
     * final column triggers the terminal's deferred auto-wrap and costs an extra
     * physical row, which corrupts the sticky block.
     */
    private function detectWidth(): int
    {
        $cols = (int)(getenv('COLUMNS') ?: 0);

        if ($cols <= 0 && $this->tty) {
            $cols = (int)@shell_exec('tput cols 2>/dev/null');
        }
        if ($cols <= 0 && $this->tty) {
            // Containers often lack a terminfo database; stty is usually still there.
            $size = @shell_exec('stty size 2>/dev/null');
            $cols = (int)(explode(' ', trim((string)$size))[1] ?? 0);
        }

        return max(60, min($cols ?: 80, 120)) - 1;
    }
}

class ProductImageDownloader
{
    /** Anything smaller than this is a placeholder / error page, not a real product image. */
    private const MIN_IMAGE_BYTES = 10240; // 10 KB

    /** Braille spinner — every glyph is one column wide, so the box never shifts. */
    private const SPINNER = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /** Repaint at most this often; a fast local cache run would otherwise spend its time drawing. */
    private const REDRAW_INTERVAL = 0.05; // seconds

    private $objectManager;
    private $connection;
    private $mediaPath;
    private string $cdnBaseUrl;
    private Console $out;
    private bool $verbose;

    /** md5 of the body the source returns for a missing image (the placeholder), if it 200s. */
    private ?string $placeholderHash = null;

    /** @var array<int, array{sku:string, path:string, reason:string}> */
    private array $failed = [];

    /** Image results for the product being processed, flushed as one group when it finishes. */
    private array $productEvents = [];

    private string $runLabel = '';
    private int $totalProducts = 0;
    private int $doneProducts = 0;
    private string $currentSku = '';
    private int $currentImageIndex = 0;
    private int $currentImageTotal = 0;
    private string $lastEvent = '';
    private float $startTime = 0.0;
    private float $lastRender = 0.0;
    private int $spinnerFrame = 0;

    private array $stats = ['downloaded' => 0, 'skipped' => 0, 'failed' => 0, 'bytes' => 0];
    private int $suppressedDiagnostics = 0;

    public function __construct()
    {
        $this->out = new Console();
        $this->verbose = getenv('VERBOSE') !== false;

        // Restore the cursor even if we die mid-run.
        register_shutdown_function(fn() => $this->out->showCursor());

        // Override when the CDN's origin is this (empty) server — point at the old origin instead:
        //   IMAGE_SOURCE_URL=https://old-origin.example.com/media/catalog/product php IMAGE.php
        $this->cdnBaseUrl = rtrim(
            getenv('IMAGE_SOURCE_URL') ?: 'https://cdn.carpetavenue.net/media/catalog/product',
            '/'
        );

        $bootstrap = Bootstrap::create(BP, $_SERVER);
        $this->objectManager = $bootstrap->getObjectManager();

        $state = $this->objectManager->get(State::class);
        try {
            $state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $e) {
            // Area code already set
        }

        $resource = $this->objectManager->get(\Magento\Framework\App\ResourceConnection::class);
        $this->connection = $resource->getConnection();

        $this->mediaPath = BP . '/pub/media/catalog/product';

        $this->installErrorHandler();

        $this->out->rule('Configuration');
        $this->kv('Source', $this->cdnBaseUrl);
        $this->kv('Target', $this->mediaPath);
        $this->kv('Min size', number_format(self::MIN_IMAGE_BYTES) . ' bytes — anything smaller counts as failed');
        $this->kv('Verbose', $this->verbose ? 'on' : 'off  (VERBOSE=1 logs every image)');

        $this->detectPlaceholder();
        $this->out->line();
    }

    /**
     * PHP 8.5 + Magento 2.4.9 vendor code emits deprecations mid-run. Printed straight
     * to stdout they land inside the sticky panel and wreck its redraw arithmetic, so
     * route every non-fatal diagnostic through the console (above the panel) and show
     * each distinct one only once — the same deprecation can fire thousands of times.
     */
    private function installErrorHandler(): void
    {
        $seen = [];

        set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0) use (&$seen): bool {
            // Respect the @ operator and the configured error_reporting level.
            if (!(error_reporting() & $no)) {
                return true;
            }

            $key = $no . '|' . $file . '|' . $line;
            if (isset($seen[$key])) {
                $this->suppressedDiagnostics++;
                return true;
            }
            $seen[$key] = true;

            $label = match (true) {
                (bool)($no & (E_DEPRECATED | E_USER_DEPRECATED)) => 'deprecated',
                (bool)($no & (E_WARNING | E_USER_WARNING | E_CORE_WARNING | E_COMPILE_WARNING)) => 'warning',
                default => 'notice',
            };

            $this->out->line(
                '  ' . $this->out->c($label, 'yellow') . '  '
                . $this->out->c($this->out->truncate($msg, max(20, $this->out->width() - 40)), 'grey')
                . $this->out->c('  ' . basename($file) . ':' . $line, 'grey')
            );

            return true; // handled — PHP must not print it itself
        });
    }

    private function kv(string $key, string $value): void
    {
        $this->out->line('  ' . $this->out->c(str_pad($key, 11), 'grey') . $value);
    }

    /**
     * Ask the source for an image that cannot exist. If it answers 200 instead of 404,
     * that body is the placeholder — fingerprint it so we can reject it everywhere.
     */
    private function detectPlaceholder(): void
    {
        $probe = $this->cdnBaseUrl . '/__missing_probe_' . bin2hex(random_bytes(8)) . '.jpg';
        [$body, $httpCode] = $this->httpGet($probe);

        if ($httpCode === 200 && $body !== null && $body !== '') {
            $this->placeholderHash = md5($body);
            $this->kv('Probe', $this->out->c('placeholder detected', 'yellow') . $this->out->c(
                ' — source answers 200 + ' . $this->bytes(strlen($body))
                . ' for missing images (md5 ' . substr($this->placeholderHash, 0, 8) . '); those count as failures',
                'grey'
            ));
        } else {
            $this->kv('Probe', $this->out->c('clean', 'green')
                . $this->out->c(" — source answers HTTP {$httpCode} for missing images", 'grey'));
        }
    }

    // ─── Entry points ────────────────────────────────────────────────────────

    public function downloadProductImages($skuList): void
    {
        $skus = array_values(array_filter(array_map('trim', explode(',', $skuList))));
        $this->begin(count($skus), 'SKU list');

        foreach ($skus as $sku) {
            $productId = $this->getProductIdBySku($sku);
            if (!$productId) {
                $this->currentSku = $sku;
                $this->productEvents = [];
                $this->recordFail($sku, '', 'product not found for SKU');
                $this->finishProduct($sku, 0);
                continue;
            }
            $this->processProductById((int)$productId, $sku);
        }
    }

    public function downloadProductImagesByCategory(int $categoryId): void
    {
        $select = $this->connection->select()
            ->from(['ccp' => 'catalog_category_product'], [])
            ->join(
                ['cpe' => 'catalog_product_entity'],
                'ccp.product_id = cpe.entity_id',
                ['entity_id', 'sku']
            )
            ->where('ccp.category_id = ?', $categoryId)
            ->order('cpe.entity_id ASC');

        $this->runProducts($this->connection->fetchAll($select), "category {$categoryId}");
    }

    public function downloadProductImagesByIdRange(int $fromId, int $toId): void
    {
        $select = $this->connection->select()
            ->from('catalog_product_entity', ['entity_id', 'sku'])
            ->where('entity_id >= ?', $fromId)
            ->where('entity_id <= ?', $toId)
            ->order('entity_id ASC');

        $label = $fromId === $toId ? "product ID {$fromId}" : "product IDs {$fromId}–{$toId}";
        $this->runProducts($this->connection->fetchAll($select), $label);
    }

    private function runProducts(array $products, string $label): void
    {
        if (empty($products)) {
            $this->out->line('  ' . $this->out->c('✗', 'red') . " No products found for {$label}.");
            return;
        }

        $this->begin(count($products), $label);

        foreach ($products as $product) {
            $this->processProductById((int)$product['entity_id'], (string)$product['sku']);
        }
    }

    private function begin(int $total, string $label): void
    {
        $this->runLabel = $label;
        $this->totalProducts = $total;
        $this->doneProducts = 0;
        $this->startTime = microtime(true);

        $this->out->rule('Downloading');
        $this->out->line('  ' . $this->out->c((string)$total, 'bold') . ' product(s) from '
            . $this->out->c($label, 'cyan'));
        $this->out->line();
        $this->render(true);
    }

    // ─── Per-product / per-image work ────────────────────────────────────────

    private function processProductById(int $productId, string $sku): void
    {
        $this->currentSku = $sku;
        $this->currentImageIndex = 0;
        $this->currentImageTotal = 0;
        $this->productEvents = [];
        $this->render();

        $galleryImages = $this->getProductGalleryImages($productId);

        if (empty($galleryImages)) {
            $this->recordFail($sku, '', 'no gallery images in DB');
            $this->finishProduct($sku, 0);
            return;
        }

        $this->currentImageTotal = count($galleryImages);

        foreach ($galleryImages as $i => $imagePath) {
            $this->currentImageIndex = $i + 1;
            $this->render();
            $this->downloadImage($imagePath, $sku);
        }

        $this->finishProduct($sku, count($galleryImages));
    }

    private function finishProduct(string $sku = '', int $imageCount = 0): void
    {
        if ($sku !== '') {
            $this->flushProduct($sku, $imageCount);
        }

        $this->doneProducts++;
        $this->currentImageIndex = 0;
        $this->currentImageTotal = 0;
        $this->render(true);

        // Non-TTY (cron / docker logs): periodic checkpoint lines instead of a live panel.
        if (!$this->out->isTty() && ($this->doneProducts % 25 === 0 || $this->doneProducts === $this->totalProducts)) {
            $this->out->line(sprintf(
                '  [%s/%s] %s  ok=%d skip=%d fail=%d  %s  elapsed %s',
                $this->doneProducts,
                $this->totalProducts,
                $this->pct() . '%',
                $this->stats['downloaded'],
                $this->stats['skipped'],
                $this->stats['failed'],
                $this->bytes($this->stats['bytes']),
                $this->duration(microtime(true) - $this->startTime)
            ));
        }
    }

    private function downloadImage($imagePath, $sku)
    {
        $imagePath = ltrim((string)$imagePath, '/');
        $sourceUrl = $this->cdnBaseUrl . '/' . $imagePath;
        $localPath = $this->mediaPath . '/' . $imagePath;
        $this->lastEvent = $imagePath;

        $directory = dirname($localPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->recordFail($sku, $imagePath, 'cannot create directory ' . $directory);
            return false;
        }

        // An existing file that is too small is a previously-saved placeholder — retry it.
        if (file_exists($localPath)) {
            $existingSize = (int)filesize($localPath);
            if ($existingSize >= self::MIN_IMAGE_BYTES) {
                $this->stats['skipped']++;
                $this->recordEvent('skip', $imagePath, 'exists, ' . $this->bytes($existingSize));
                return true;
            }
            $this->recordEvent(
                'refetch',
                $imagePath,
                'existing ' . $this->bytes($existingSize) . ' < 10 KB — refetching'
            );
            @unlink($localPath);
        }

        [$reason, $size] = $this->curlDownload($sourceUrl, $localPath);

        if ($reason === null) {
            $this->stats['downloaded']++;
            $this->stats['bytes'] += $size;
            $this->recordEvent('ok', $imagePath, $this->bytes($size));
            return true;
        }

        $this->recordFail($sku, $imagePath, $reason);
        return false;
    }

    /**
     * @return array{0: ?string, 1: int} [failure reason or null, bytes written]
     */
    private function curlDownload($sourceUrl, $localPath): array
    {
        [$imageData, $httpCode, $error, $contentType] = $this->httpGet($sourceUrl);

        if ($error !== '') {
            return ["cURL error: {$error}", 0];
        }
        if ($httpCode !== 200) {
            return ["HTTP {$httpCode}", 0];
        }
        if ($imageData === null || $imageData === '') {
            return ['empty response body', 0];
        }

        $size = strlen($imageData);

        // Placeholder served with a 200 — the whole reason this script looked "successful".
        if ($this->placeholderHash !== null && md5($imageData) === $this->placeholderHash) {
            return ['placeholder returned by source (' . $this->bytes($size) . ')', 0];
        }

        // Anything under 10 KB is not a real product image.
        if ($size < self::MIN_IMAGE_BYTES) {
            return ['too small: ' . $this->bytes($size) . ' < ' . $this->bytes(self::MIN_IMAGE_BYTES), 0];
        }

        // Reject HTML error pages / JSON that came back with a 200.
        if (!$this->looksLikeImage($imageData)) {
            return ['not an image (content-type: ' . ($contentType ?: 'unknown') . ')', 0];
        }

        if (file_put_contents($localPath, $imageData) === false) {
            return ['failed to write file', 0];
        }

        return [null, $size];
    }

    /** Magic-byte check for JPEG / PNG / GIF / WebP. */
    private function looksLikeImage(string $data): bool
    {
        return str_starts_with($data, "\xFF\xD8\xFF")                       // JPEG
            || str_starts_with($data, "\x89PNG\r\n\x1a\n")                  // PNG
            || str_starts_with($data, 'GIF8')                               // GIF
            || (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WEBP');
    }

    /**
     * @return array{0: ?string, 1: int, 2: string, 3: string}  [body, httpCode, curlError, contentType]
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'Magento2-Image-Downloader/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_MAXREDIRS => 5,
        ]);

        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        // No curl_close(): a no-op since PHP 8.0, deprecated in 8.5. The handle is
        // freed when $ch goes out of scope.
        unset($ch);

        return [$body === false ? null : $body, $httpCode, $error, $contentType];
    }

    // ─── DB ──────────────────────────────────────────────────────────────────

    private function getProductIdBySku($sku)
    {
        $select = $this->connection->select()
            ->from('catalog_product_entity', 'entity_id')
            ->where('sku = ?', $sku);

        return $this->connection->fetchOne($select);
    }

    private function getProductGalleryImages($productId)
    {
        $select = $this->connection->select()
            ->from(['mg' => 'catalog_product_entity_media_gallery'], 'value')
            ->join(
                ['mgv' => 'catalog_product_entity_media_gallery_value_to_entity'],
                'mg.value_id = mgv.value_id',
                []
            )
            ->where('mgv.entity_id = ?', $productId)
            ->where('mg.disabled = ?', 0);

        return $this->connection->fetchCol($select);
    }

    // ─── Logging ─────────────────────────────────────────────────────────────

    /** Buffer an image result; nothing prints until the product is done. */
    private function recordEvent(string $level, string $path, string $note): void
    {
        $this->productEvents[] = ['level' => $level, 'path' => $path, 'note' => $note];
    }

    private function recordFail(string $sku, string $path, string $reason): void
    {
        $this->stats['failed']++;
        $this->failed[] = ['sku' => $sku, 'path' => $path, 'reason' => $reason];
        $this->recordEvent('fail', $path, $reason);
    }

    /**
     * One line per product, not per image. Detail lines are printed underneath only
     * for images that need attention (failures, refetches) — or for everything when
     * VERBOSE=1.
     */
    private function flushProduct(string $sku, int $imageCount): void
    {
        $counts = ['ok' => 0, 'skip' => 0, 'refetch' => 0, 'fail' => 0];
        foreach ($this->productEvents as $e) {
            $counts[$e['level']]++;
        }

        $failed = $counts['fail'] > 0;
        $header = '  ' . $this->out->c($failed ? '✗' : '✓', $failed ? 'red' : 'green') . '  '
            . $this->out->c($this->out->pad($this->out->truncate($sku, 26), 27), 'bold')
            . $this->out->c($this->out->pad(number_format($imageCount) . ' img', 9), 'grey');

        $parts = [];
        if ($counts['ok']) {
            $parts[] = $this->out->c('● ' . $counts['ok'], 'green');
        }
        if ($counts['skip']) {
            $parts[] = $this->out->c('○ ' . $counts['skip'], 'grey');
        }
        if ($counts['refetch']) {
            $parts[] = $this->out->c('♻ ' . $counts['refetch'], 'yellow');
        }
        if ($counts['fail']) {
            $parts[] = $this->out->c('✗ ' . $counts['fail'], 'red');
        }

        $this->out->line($header . implode('  ', $parts));

        foreach ($this->productEvents as $e) {
            if (!$this->verbose && $e['level'] !== 'fail') {
                continue;
            }
            $icon = ['ok' => '●', 'skip' => '○', 'refetch' => '♻', 'fail' => '✗'][$e['level']];
            $color = ['ok' => 'green', 'skip' => 'grey', 'refetch' => 'yellow', 'fail' => 'red'][$e['level']];

            $this->out->line(
                '       ' . $this->out->c($icon, $color) . '  '
                . ($e['path'] !== ''
                    ? $this->out->c($this->out->pad($this->out->truncateStart(basename($e['path']), 40), 42), 'grey')
                    : '')
                . $this->out->c($e['note'], $e['level'] === 'fail' ? 'red' : 'grey')
            );
        }

        $this->productEvents = [];
    }

    // ─── Sticky dashboard ────────────────────────────────────────────────────

    private function render(bool $force = false): void
    {
        if (!$this->out->isTty() || $this->totalProducts === 0) {
            return;
        }

        $now = microtime(true);
        if (!$force && ($now - $this->lastRender) < self::REDRAW_INTERVAL) {
            return;
        }
        $this->lastRender = $now;
        $this->spinnerFrame++;

        $this->out->setSticky($this->buildPanel());
    }

    /**
     * The pinned panel. Every glyph used inside the box is one column wide so the
     * right-hand border never drifts — no emoji in here, they are double-width.
     *
     * @return string[]
     */
    private function buildPanel(): array
    {
        $w = $this->out->width();
        $inner = $w - 4;                       // "│ " + content + " │"
        $g = fn(string $s) => $this->out->c($s, 'grey');

        $done = $this->doneProducts;
        $total = $this->totalProducts;
        $ratio = $total > 0 ? $done / $total : 0.0;

        $elapsed = $now = microtime(true) - $this->startTime;
        $eta = $done > 0 ? ($elapsed / $done) * ($total - $done) : 0.0;
        $speed = $elapsed > 0 ? (int)($this->stats['bytes'] / $elapsed) : 0;

        // ── line: current item ───────────────────────────────────────────────
        $spin = $done >= $total
            ? $this->out->c('✓', 'green')
            : $this->out->c(self::SPINNER[$this->spinnerFrame % count(self::SPINNER)], 'cyan');

        $imageCounter = $this->currentImageTotal > 0
            ? "image {$this->currentImageIndex}/{$this->currentImageTotal}"
            : '';
        $skuRoom = $inner - 2 - $this->out->visibleWidth($imageCounter) - 2;
        $left = $spin . ' ' . $this->out->c($this->out->truncate($this->currentSku ?: '—', max(8, $skuRoom)), 'bold');
        $current = $this->out->pad($left, $inner - $this->out->visibleWidth($imageCounter)) . $g($imageCounter);

        // ── line: progress bar ───────────────────────────────────────────────
        $tail = sprintf('%5.1f%%  %s/%s', $ratio * 100, number_format($done), number_format($total));
        $barWidth = max(10, $inner - $this->out->visibleWidth($tail) - 2);
        $filled = (int)round($ratio * $barWidth);
        $bar = $this->out->c(str_repeat('█', $filled), 'cyan')
            . $g(str_repeat('░', $barWidth - $filled));
        $progress = $bar . '  ' . $this->out->c(substr($tail, 0, 6), 'bold') . $g(substr($tail, 6));

        // ── line: counters ───────────────────────────────────────────────────
        $counters = $this->out->c('● ' . number_format($this->stats['downloaded']), 'green') . $g(' downloaded')
            . $g('   ○ ' . number_format($this->stats['skipped']) . ' skipped')
            . '   ' . $this->out->c('✗ ' . number_format($this->stats['failed']), $this->stats['failed'] ? 'red' : 'grey')
            . $this->out->c(' failed', $this->stats['failed'] ? 'red' : 'grey');
        $volume = $this->out->c($this->bytes($this->stats['bytes']), 'magenta');
        $counters = $this->out->pad($counters, $inner - $this->out->visibleWidth($volume)) . $volume;

        // ── line: timings ────────────────────────────────────────────────────
        $timing = $g('elapsed ') . $this->duration($elapsed)
            . $g('   eta ') . ($done > 0 ? $this->duration($eta) : '—')
            . $g('   ') . $this->bytes($speed) . $g('/s');
        $lastLabel = $this->lastEvent !== '' ? $this->out->truncateStart($this->lastEvent, 34) : '';
        $timing = $this->out->pad($timing, $inner - $this->out->visibleWidth($lastLabel)) . $g($lastLabel);

        // ── borders ──────────────────────────────────────────────────────────
        $title = ' Product Image Downloader · ' . $this->out->truncate($this->runLabel, 30) . ' ';
        $topFill = max(0, $w - 2 - $this->out->visibleWidth($title) - 1);
        $top = $g('╭') . $g('─') . $this->out->c($title, 'bold') . $g(str_repeat('─', $topFill)) . $g('╮');
        $bottom = $g('╰' . str_repeat('─', $w - 2) . '╯');

        $row = fn(string $content) => $g('│ ') . $this->out->pad($content, $inner) . $g(' │');

        return [
            $top,
            $row($current),
            $row($progress),
            $row($counters),
            $row($timing),
            $bottom,
        ];
    }

    private function pct(): string
    {
        return $this->totalProducts === 0
            ? '0.0'
            : number_format($this->doneProducts / $this->totalProducts * 100, 1);
    }

    private function bytes(int $b): string
    {
        if ($b < 1024) {
            return $b . ' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $i = -1;
        $v = $b;
        do {
            $v /= 1024;
            $i++;
        } while ($v >= 1024 && $i < 2);

        return number_format($v, $v >= 100 ? 0 : 1) . ' ' . $units[$i];
    }

    private function duration(float $seconds): string
    {
        $seconds = max(0, (int)round($seconds));
        if ($seconds >= 3600) {
            return sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }
        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    // ─── Summary ─────────────────────────────────────────────────────────────

    public function reportFailures(): void
    {
        $this->out->dropSticky();

        $elapsed = $this->startTime > 0 ? microtime(true) - $this->startTime : 0.0;
        $speed = $elapsed > 0 ? (int)($this->stats['bytes'] / $elapsed) : 0;
        $images = $this->stats['downloaded'] + $this->stats['skipped'] + $this->stats['failed'];

        $this->out->line();
        $this->out->rule('Summary');
        $this->kv('Products', number_format($this->doneProducts) . ' / ' . number_format($this->totalProducts));
        $this->kv('Images', number_format($images) . ' inspected');
        $this->kv('Downloaded', $this->out->c(number_format($this->stats['downloaded']), 'green')
            . $this->out->c('  ' . $this->bytes($this->stats['bytes']), 'grey'));
        $this->kv('Skipped', number_format($this->stats['skipped'])
            . $this->out->c('  already present and ≥ 10 KB', 'grey'));
        $this->kv('Failed', $this->stats['failed']
            ? $this->out->c(number_format($this->stats['failed']), 'red')
            : $this->out->c('0', 'green'));
        $this->kv('Elapsed', $this->duration($elapsed) . $this->out->c('  ' . $this->bytes($speed) . '/s', 'grey'));
        if ($this->suppressedDiagnostics > 0) {
            $this->kv('Notices', number_format($this->suppressedDiagnostics)
                . $this->out->c('  repeat PHP warnings/deprecations collapsed (each shown once above)', 'grey'));
        }
        $this->out->line();

        if (!$this->failed) {
            $this->out->line('  ' . $this->out->c('✓  All images downloaded.', 'green'));
            $this->out->line();
            return;
        }

        // Group failures by reason so a 3000-image disaster reads as five lines.
        $byReason = [];
        foreach ($this->failed as $f) {
            $key = preg_replace('/[\d,.]+\s?(B|KB|MB|GB)/', 'N', $f['reason']);
            $byReason[$key] = ($byReason[$key] ?? 0) + 1;
        }
        arsort($byReason);

        $this->out->rule('Failures by reason');
        foreach ($byReason as $reason => $count) {
            $this->out->line(
                '  ' . $this->out->c(str_pad(number_format($count), 7, ' ', STR_PAD_LEFT), 'red')
                . '  ' . $reason
            );
        }
        $this->out->line();

        // Which SKUs are affected — the list you actually need to re-run.
        $bySku = [];
        foreach ($this->failed as $f) {
            $bySku[$f['sku']][] = $f['reason'];
        }

        $this->out->rule('Failed SKUs (' . number_format(count($bySku)) . ')');
        $shown = 0;
        foreach ($bySku as $sku => $reasons) {
            if ($shown++ >= 50) {
                $this->out->line('  ' . $this->out->c('… ' . number_format(count($bySku) - 50)
                    . ' more — see the CSV', 'grey'));
                break;
            }
            $this->out->line(
                '  ' . $this->out->c('✗', 'red') . '  '
                . $this->out->c($this->out->pad($this->out->truncate((string)$sku, 26), 27), 'bold')
                . $this->out->c($this->out->pad(count($reasons) . ' img', 9), 'grey')
                . $this->out->c($this->out->truncate(
                    count(array_unique($reasons)) === 1 ? $reasons[0] : implode(' · ', array_unique($reasons)),
                    $this->out->width() - 42
                ), 'red')
            );
        }
        $this->out->line();

        // Bare SKU list, ready to paste back into this script's SKU mode. Long lists
        // would be clipped at the terminal edge, so those go to a file instead.
        $retry = implode(',', array_keys($bySku));
        $skuFile = BP . '/var/failed_skus.txt';
        file_put_contents($skuFile, $retry . "\n");

        $this->out->line('  ' . $this->out->c('Retry these SKUs:', 'grey'));
        if ($this->out->visibleWidth($retry) <= $this->out->width() - 4) {
            $this->out->line('  ' . $this->out->c($retry, 'yellow'));
        } else {
            $this->out->line('  ' . $this->out->c($skuFile, 'yellow'));
        }
        $this->out->line();

        $csv = BP . '/var/failed_images.csv';
        $fh = fopen($csv, 'w');
        fputcsv($fh, ['sku', 'image_path', 'reason'], ',', '"', '');
        foreach ($this->failed as $f) {
            fputcsv($fh, [$f['sku'], $f['path'], $f['reason']], ',', '"', '');
        }
        fclose($fh);

        $this->out->line('  ' . $this->out->c('Full list:', 'grey') . ' ' . $csv);
        $this->out->line();
    }
}

// ─── Helpers ────────────────────────────────────────────────────────────────

function prompt(string $message): string
{
    echo $message;
    $line = fgets(STDIN);
    return $line === false ? '' : trim($line);
}

function promptRequired(string $message): string
{
    while (true) {
        $value = prompt($message);
        if ($value !== '') {
            return $value;
        }
        echo "  Input cannot be empty.\n";
    }
}

function promptInt(string $message): int
{
    while (true) {
        $value = prompt($message);
        if (is_numeric($value) && (int)$value > 0) {
            return (int)$value;
        }
        echo "  Enter a positive integer.\n";
    }
}

// ─── Main execution ──────────────────────────────────────────────────────────

try {
    $ui = new Console();
    $w = $ui->width();

    $ui->line();
    $ui->line('  ' . $ui->c('╭' . str_repeat('─', $w - 6) . '╮', 'cyan'));
    $ui->line('  ' . $ui->c('│', 'cyan')
        . $ui->pad('  ' . $ui->c('Magento 2', 'bold') . $ui->c(' · Product Image Downloader', 'cyan'), $w - 6)
        . $ui->c('│', 'cyan'));
    $ui->line('  ' . $ui->c('╰' . str_repeat('─', $w - 6) . '╯', 'cyan'));
    $ui->line();

    $ui->line('  ' . $ui->c('1', 'bold') . '  By SKU         ' . $ui->c('single or comma-separated list', 'grey'));
    $ui->line('  ' . $ui->c('2', 'bold') . '  By Product ID  ' . $ui->c('single id, or a from → to range', 'grey'));
    $ui->line('  ' . $ui->c('3', 'bold') . '  By Category    ' . $ui->c('every product in a category', 'grey'));
    $ui->line();

    $choice = '';
    while (!in_array($choice, ['1', '2', '3'], true)) {
        $choice = prompt('  Choice [1/2/3]: ');
        if (!in_array($choice, ['1', '2', '3'], true)) {
            echo '  ' . $ui->c('Invalid choice — enter 1, 2 or 3.', 'yellow') . "\n";
        }
    }

    echo "\n";
    $downloader = new ProductImageDownloader();

    switch ($choice) {
        // ── Mode 1: SKU ──────────────────────────────────────────────────────
        case '1':
            $skuList = promptRequired('  SKU(s), comma-separated: ');
            echo "\n";
            $downloader->downloadProductImages($skuList);
            break;

        // ── Mode 2: Product ID ───────────────────────────────────────────────
        case '2':
            $sub = '';
            while (!in_array($sub, ['a', 'b'], true)) {
                $sub = strtolower(prompt('  (a) single id   (b) range   [a/b]: '));
                if (!in_array($sub, ['a', 'b'], true)) {
                    echo '  ' . $ui->c('Enter a or b.', 'yellow') . "\n";
                }
            }

            if ($sub === 'a') {
                $id = promptInt('  Product ID: ');
                echo "\n";
                $downloader->downloadProductImagesByIdRange($id, $id);
            } else {
                $fromId = promptInt('  From Product ID: ');
                $toId   = promptInt('  To   Product ID: ');
                if ($fromId > $toId) {
                    echo '  ' . $ui->c('From > To — swapping.', 'yellow') . "\n";
                    [$fromId, $toId] = [$toId, $fromId];
                }
                echo "\n";
                $downloader->downloadProductImagesByIdRange($fromId, $toId);
            }
            break;

        // ── Mode 3: Category ─────────────────────────────────────────────────
        case '3':
            $categoryId = promptInt('  Category ID: ');
            echo "\n";
            $downloader->downloadProductImagesByCategory($categoryId);
            break;
    }

    $downloader->reportFailures();

} catch (Throwable $e) {
    echo "\033[?25h\n";  // make sure the cursor comes back
    echo '  Fatal error: ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
