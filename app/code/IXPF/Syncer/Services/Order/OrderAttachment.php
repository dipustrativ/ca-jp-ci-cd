<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Order;

use CarpetAvenue\Orderify\Model\Attachment\AttachmentManager;
use IXPF\Syncer\Helper\Helpers as ProductHelper;
use IXPF\Syncer\Helper\Logger\OrderActionLogger;
use IXPF\Syncer\Services\PhotoStudio\ProcessGoogleBucketImages;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Sales\Model\Order;
use RuntimeException;
use Throwable;

/**
 * Order attachment (CarpetAvenue_Orderify AttachmentManager) handling for the Order Update API.
 *
 * Files are stored exactly where the admin "Attach new file" form stores them, so the admin
 * download link and GET /V1/orders/:orderId/attachment keep working unchanged:
 *
 *   var/persistent/order_attachments/<order_entity_id>/<basename>.pdf
 *
 * ca_attachment_file holds the path relative to var/. The PDF is pulled from the configured
 * Google bucket with the same service-account auth the product gallery uses.
 *
 * Nothing here saves the order — the caller saves once and calls rollback()/deleteFile()
 * depending on the outcome, so a failed save never leaves the order pointing at a missing file.
 */
class OrderAttachment
{
    public const COLUMN_FILE  = AttachmentManager::COLUMN_FILE;
    public const COLUMN_EXTRA = AttachmentManager::COLUMN_EXTRA;

    public const STORAGE_ROOT = AttachmentManager::STORAGE_ROOT;
    public const TMP_ROOT     = 'tmp/ixpf_order_attachments';
    public const MAX_SIZE     = AttachmentManager::MAX_SIZE;

    private ?WriteInterface $varDirectory = null;

    public function __construct(
        private readonly ProcessGoogleBucketImages $bucketDownloader,
        private readonly ProductHelper $productHelper,
        private readonly Filesystem $filesystem,
        private readonly OrderActionLogger $logger
    ) {
    }

    /**
     * @throws InputException
     */
    public function setExtraInstructions(Order $order, mixed $value): bool
    {
        $flag = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($flag === null) {
            throw new InputException(__("'extra_shipping_instructions' must be a boolean."));
        }

        $order->setData(self::COLUMN_EXTRA, $flag ? 1 : 0);
        $this->logger->writeDaily(
            "[order_attachment] Order #{$order->getIncrementId()} extra_shipping_instructions set to " . ($flag ? '1' : '0')
        );

        return $flag;
    }

    /**
     * Download a PDF from the bucket and point the order at it.
     *
     * @return array{path: string, previous: ?string}
     * @throws InputException on a bad bucket_url, a non-PDF or an oversized file (422)
     * @throws RuntimeException on transient download / filesystem failures (500)
     */
    public function attachFromBucket(Order $order, string $bucketUrl): array
    {
        $objectPath = $this->normalizeObjectPath($bucketUrl);
        $basename   = $this->basename($objectPath);

        if (strtolower(pathinfo($basename, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new InputException(__("Attachment must be a .pdf file, got '%1'.", $basename));
        }

        $var     = $this->getVarDirectory();
        $tmpPath = self::TMP_ROOT . '/' . uniqid((string)$order->getId() . '_', true) . '.pdf';
        $target  = self::STORAGE_ROOT . '/' . (int)$order->getId() . '/' . $basename;

        $var->create(self::TMP_ROOT);

        try {
            $this->bucketDownloader->downloadObject($objectPath, $var->getAbsolutePath($tmpPath));
        } catch (RuntimeException $e) {
            // downloadObject() carries the HTTP status as the exception code: 4xx = bad path,
            // missing object, no access or bucket not configured — retrying will not help.
            $code = (int)$e->getCode();
            if ($code >= 400 && $code < 500) {
                throw new InputException(__('Attachment download failed: %1', $e->getMessage()));
            }
            throw $e;
        }

        try {
            $this->validatePdf($tmpPath);

            $var->create(dirname($target));
            if ($var->isFile($target)) {
                $var->delete($target);
            }
            $var->renameFile($tmpPath, $target);
        } catch (Throwable $e) {
            $this->deleteQuietly($tmpPath);
            throw $e;
        }

        $previous = $order->getData(self::COLUMN_FILE) ?: null;
        $order->setData(self::COLUMN_FILE, $target);

        $this->logger->writeDaily(
            "[order_attachment] Order #{$order->getIncrementId()} attachment downloaded from bucket '$objectPath' to '$target' ("
            . $var->stat($target)['size'] . ' bytes)'
            . ($previous ? ", previous: '$previous'" : '')
        );

        return ['path' => $target, 'previous' => $previous];
    }

    /**
     * Clear the attachment column. The file itself is removed by deleteFile() once the order is saved.
     *
     * @return string|null var-relative path of the detached file
     */
    public function detach(Order $order): ?string
    {
        $previous = $order->getData(self::COLUMN_FILE) ?: null;
        $order->setData(self::COLUMN_FILE, null);

        $this->logger->writeDaily(
            "[order_attachment] Order #{$order->getIncrementId()} attachment detached"
            . ($previous ? " (was '$previous')" : ' (none set)')
        );

        return $previous;
    }

    /**
     * Delete a stored attachment. Only paths under STORAGE_ROOT are touched.
     */
    public function deleteFile(?string $relativePath): void
    {
        if (!$relativePath || !$this->isManagedPath($relativePath)) {
            return;
        }

        $this->deleteQuietly($relativePath);
        $this->logger->writeDaily("[order_attachment] Deleted attachment file '$relativePath'");
    }

    /**
     * Undo attachFromBucket() after a failed order save. Leaves the file in place when it is
     * also the previous attachment, since the order still references it.
     */
    public function rollback(string $newPath, ?string $previousPath): void
    {
        if ($newPath === $previousPath) {
            return;
        }

        $this->deleteFile($newPath);
    }

    /**
     * Accepts a plain object path, gs://<bucket>/<path> or a storage.googleapis.com URL and
     * returns the object path inside the configured bucket.
     *
     * @throws InputException
     */
    private function normalizeObjectPath(string $bucketUrl): string
    {
        $bucketUrl = trim($bucketUrl);
        if ($bucketUrl === '') {
            throw new InputException(__("'attachment.bucket_url' is required."));
        }

        $configured = $this->productHelper->getGoogleBucketName();
        $bucket     = null;
        $path       = $bucketUrl;

        if (preg_match('#^gs://([^/]+)/(.+)$#i', $bucketUrl, $m)) {
            [$bucket, $path] = [$m[1], $m[2]];
        } elseif (preg_match('#^https?://#i', $bucketUrl)) {
            $host = (string)parse_url($bucketUrl, PHP_URL_HOST);
            $urlPath = ltrim(rawurldecode((string)parse_url($bucketUrl, PHP_URL_PATH)), '/');

            if (in_array($host, ['storage.googleapis.com', 'storage.cloud.google.com'], true)) {
                [$bucket, $path] = array_pad(explode('/', $urlPath, 2), 2, '');
            } elseif (str_ends_with($host, '.storage.googleapis.com')) {
                $bucket = substr($host, 0, -strlen('.storage.googleapis.com'));
                $path   = $urlPath;
            } else {
                throw new InputException(__("Unsupported attachment URL host '%1'. Use a Google bucket path.", $host));
            }
        }

        if ($bucket !== null && $configured !== '' && $bucket !== $configured) {
            throw new InputException(__("Attachment bucket '%1' does not match the configured bucket.", $bucket));
        }

        $path = ltrim((string)preg_replace('/[?#].*$/', '', $path), '/');
        if ($path === '' || in_array('..', explode('/', $path), true)) {
            throw new InputException(__("Invalid attachment bucket path '%1'.", $bucketUrl));
        }

        return $path;
    }

    /**
     * @throws InputException
     */
    private function validatePdf(string $relativePath): void
    {
        $var  = $this->getVarDirectory();
        $size = (int)($var->stat($relativePath)['size'] ?? 0);

        if ($size === 0) {
            throw new InputException(__('Attachment file is empty.'));
        }
        if ($size > self::MAX_SIZE) {
            throw new InputException(__(
                'Attachment is %1 MB, maximum allowed is %2 MB.',
                round($size / 1048576, 2),
                self::MAX_SIZE / 1048576
            ));
        }

        $file  = $var->openFile($relativePath, 'r');
        $magic = $file->read(5);
        $file->close();

        if ($magic !== '%PDF-') {
            throw new InputException(__('Attachment is not a valid PDF file.'));
        }
    }

    private function isManagedPath(string $relativePath): bool
    {
        return str_starts_with($relativePath, self::STORAGE_ROOT . '/')
            && !in_array('..', explode('/', $relativePath), true);
    }

    private function deleteQuietly(string $relativePath): void
    {
        try {
            $var = $this->getVarDirectory();
            if ($var->isFile($relativePath)) {
                $var->delete($relativePath);
            }
        } catch (Throwable $e) {
            $this->logger->writeDaily(
                "[order_attachment] Failed to delete '$relativePath': " . $e->getMessage(),
                'error'
            );
        }
    }

    private function basename(string $objectPath): string
    {
        return (string)preg_replace('/[^\w.\-]/', '_', basename($objectPath));
    }

    private function getVarDirectory(): WriteInterface
    {
        if ($this->varDirectory === null) {
            $this->varDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        }

        return $this->varDirectory;
    }
}
