<?php
/**
 * Magento 2 Standalone Product Image Downloader
 *
 * Usage: php download_product_images.php "sku1,sku2,sku3"
 *
 * This script will:
 * 1. Get product SKUs from command line argument
 * 2. Query MySQL for gallery paths
 * 3. Download images from CDN
 * 4. Save them in proper directory structure under pub/media/catalog/product
 */

// Include Magento bootstrap
use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

require __DIR__ . '/app/bootstrap.php';

class ProductImageDownloader
{
    private $objectManager;
    private $connection;
    private $mediaPath;
    private string $cdnBaseUrl = 'https://cdn.carpetavenue.net/media/catalog/product';

    public function __construct()
    {
        // Initialize Magento
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        $this->objectManager = $bootstrap->getObjectManager();

        // Set area code
        $state = $this->objectManager->get(State::class);
        try {
            $state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $e) {
            // Area code already set
        }

        // Get database connection
        $resource = $this->objectManager->get(\Magento\Framework\App\ResourceConnection::class);
        $this->connection = $resource->getConnection();

        // Set media path
        $this->mediaPath = BP . '/pub/media/catalog/product';

        echo "Initialized Magento 2 Product Image Downloader\n";
        echo "Media Path: $this->mediaPath\n";
        echo "CDN Base URL: $this->cdnBaseUrl\n\n";
    }

    public function downloadProductImages($skuList): void
    {
        $skus = array_map('trim', explode(',', $skuList));
        echo "Processing " . count($skus) . " SKUs...\n\n";

        foreach ($skus as $sku) {
            $this->processProductSku($sku);
        }

        echo "\nDownload process completed!\n";
    }

    public function downloadProductImagesByCategory(int $categoryId): void
    {
        echo "Fetching products from category ID: {$categoryId}...\n\n";

        $select = $this->connection->select()
            ->from(['ccp' => 'catalog_category_product'], [])
            ->join(
                ['cpe' => 'catalog_product_entity'],
                'ccp.product_id = cpe.entity_id',
                ['entity_id', 'sku']
            )
            ->where('ccp.category_id = ?', $categoryId)
            ->order('cpe.entity_id ASC');

        $products = $this->connection->fetchAll($select);

        if (empty($products)) {
            echo "❌ No products found in category ID: {$categoryId}\n";
            return;
        }

        echo "Found " . count($products) . " products.\n\n";

        foreach ($products as $product) {
            $this->processProductById((int)$product['entity_id'], $product['sku']);
        }

        echo "\nDownload process completed!\n";
    }

    public function downloadProductImagesByIdRange(int $fromId, int $toId): void
    {
        echo "Fetching products with entity_id from {$fromId} to {$toId}...\n\n";

        $select = $this->connection->select()
            ->from('catalog_product_entity', ['entity_id', 'sku'])
            ->where('entity_id >= ?', $fromId)
            ->where('entity_id <= ?', $toId)
            ->order('entity_id ASC');

        $products = $this->connection->fetchAll($select);

        if (empty($products)) {
            echo "❌ No products found in the given ID range.\n";
            return;
        }

        echo "Found " . count($products) . " products.\n\n";

        foreach ($products as $product) {
            $this->processProductById((int)$product['entity_id'], $product['sku']);
        }

        echo "\nDownload process completed!\n";
    }

    private function processProductById(int $productId, string $sku): void
    {
        echo "Processing Product ID: {$productId} | SKU: {$sku}\n";

        $galleryImages = $this->getProductGalleryImages($productId);
        if (empty($galleryImages)) {
            echo "  ❌ No gallery images found\n\n";
            return;
        }

        echo "  🖼️  Found " . count($galleryImages) . " gallery images\n";

        foreach ($galleryImages as $imagePath) {
            $this->downloadImage($imagePath, $sku);
        }

        echo "  ✅ Completed\n\n";
    }

    private function processProductSku($sku)
    {
        echo "Processing SKU: {$sku}\n";

        // Get product ID from SKU
        $productId = $this->getProductIdBySku($sku);
        if (!$productId) {
            echo "  ❌ Product not found for SKU: {$sku}\n\n";
            return;
        }

        echo "  📦 Product ID: {$productId}\n";

        // Get gallery images for this product
        $galleryImages = $this->getProductGalleryImages($productId);
        if (empty($galleryImages)) {
            echo "  ❌ No gallery images found for SKU: {$sku}\n\n";
            return;
        }

        echo "  🖼️  Found " . count($galleryImages) . " gallery images\n";

        // Download each image
        foreach ($galleryImages as $imagePath) {
            $this->downloadImage($imagePath, $sku);
        }

        echo "  ✅ Completed processing SKU: {$sku}\n\n";
    }

    private function getProductIdBySku($sku)
    {
        $select = $this->connection->select()
            ->from('catalog_product_entity', 'entity_id')
            ->where('sku = ?', $sku);

        return $this->connection->fetchOne($select);
    }

    private function getProductGalleryImages($productId)
    {
        // Query to get gallery images
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

    private function downloadImage($imagePath, $sku)
    {
        // Clean the image path (remove leading slash if present)
        $imagePath = ltrim($imagePath, '/');

        // Create full CDN URL
        $sourceUrl = $this->cdnBaseUrl . '/' . $imagePath;

        // Create local file path
        $localPath = $this->mediaPath . '/' . $imagePath;

        echo "    📥 Downloading: {$imagePath}\n";
        echo "       Source: {$sourceUrl}\n";
        echo "       Target: {$localPath}\n";

        // Create directory if it doesn't exist
        $directory = dirname($localPath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                echo "    ❌ Failed to create directory: {$directory}\n";
                return false;
            }
            echo "    📁 Created directory: {$directory}\n";
        }

        // Skip if file already exists
        if (file_exists($localPath)) {
            echo "    ⏭️  File already exists, skipping\n";
            return true;
        }

        // Download the image using cURL
        $success = $this->curlDownload($sourceUrl, $localPath);

        if ($success) {
            echo "    ✅ Downloaded successfully\n";
            return true;
        } else {
            echo "    ❌ Download failed\n";
            return false;
        }
    }

    private function curlDownload($sourceUrl, $localPath)
    {
        $ch = curl_init();

        // Set cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $sourceUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Magento2-Image-Downloader/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_MAXREDIRS => 5
        ]);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        // Check for cURL errors
        if ($error) {
            echo "    ❌ cURL Error: {$error}\n";
            return false;
        }

        // Check HTTP status
        if ($httpCode !== 200) {
            echo "    ❌ HTTP Error: {$httpCode}\n";
            return false;
        }

        // Check if we got valid image data
        if (!$imageData || strlen($imageData) < 100) {
            echo "    ❌ Invalid image data received\n";
            return false;
        }

        // Save the image
        $bytesWritten = file_put_contents($localPath, $imageData);

        if ($bytesWritten === false) {
            echo "    ❌ Failed to write file\n";
            return false;
        }

        echo "    💾 Saved " . number_format($bytesWritten) . " bytes\n";
        return true;
    }
}

// ─── Helpers ────────────────────────────────────────────────────────────────

function prompt(string $message): string
{
    echo $message;
    return trim(fgets(STDIN));
}

function promptRequired(string $message): string
{
    while (true) {
        $value = prompt($message);
        if ($value !== '') {
            return $value;
        }
        echo "  ⚠️  Input cannot be empty. Please try again.\n";
    }
}

function promptInt(string $message): int
{
    while (true) {
        $value = prompt($message);
        if (is_numeric($value) && (int)$value > 0) {
            return (int)$value;
        }
        echo "  ⚠️  Please enter a valid positive integer.\n";
    }
}

// ─── Main execution ──────────────────────────────────────────────────────────

try {
    echo "╔══════════════════════════════════════╗\n";
    echo "║  Magento 2 Product Image Downloader  ║\n";
    echo "╚══════════════════════════════════════╝\n\n";

    echo "Please choose a download mode:\n";
    echo "  1. By SKU (single or comma-separated list)\n";
    echo "  2. By Product ID (single or range from → to)\n";
    echo "  3. By Category\n\n";

    $choice = '';
    while (!in_array($choice, ['1', '2', '3'], true)) {
        $choice = prompt("Enter your choice [1/2/3]: ");
        if (!in_array($choice, ['1', '2', '3'], true)) {
            echo "  ⚠️  Invalid choice. Please enter 1, 2 or 3.\n";
        }
    }

    echo "\n";
    $downloader = new ProductImageDownloader();

    switch ($choice) {
        // ── Mode 1: SKU ──────────────────────────────────────────────────────
        case '1':
            echo "Enter one SKU or multiple SKUs separated by commas.\n";
            $skuList = promptRequired("SKU(s): ");
            $downloader->downloadProductImages($skuList);
            break;

        // ── Mode 2: Product ID ───────────────────────────────────────────────
        case '2':
            echo "Do you want to download a single product or a range?\n";
            echo "  a. Single product ID\n";
            echo "  b. Range (from ID → to ID)\n";
            $sub = '';
            while (!in_array($sub, ['a', 'b', 'A', 'B'], true)) {
                $sub = prompt("Enter your choice [a/b]: ");
                if (!in_array($sub, ['a', 'b', 'A', 'B'], true)) {
                    echo "  ⚠️  Invalid choice. Please enter a or b.\n";
                }
            }

            if (strtolower($sub) === 'a') {
                $id = promptInt("Product ID: ");
                $downloader->downloadProductImagesByIdRange($id, $id);
            } else {
                $fromId = promptInt("From Product ID: ");
                $toId   = promptInt("To   Product ID: ");
                if ($fromId > $toId) {
                    echo "  ⚠️  From ID ({$fromId}) is greater than To ID ({$toId}). They will be swapped.\n\n";
                    [$fromId, $toId] = [$toId, $fromId];
                }
                $downloader->downloadProductImagesByIdRange($fromId, $toId);
            }
            break;

        // ── Mode 3: Category ─────────────────────────────────────────────────
        case '3':
            $categoryId = promptInt("Category ID: ");
            $downloader->downloadProductImagesByCategory($categoryId);
            break;
    }

} catch (Exception $e) {
    echo "❌ Fatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

