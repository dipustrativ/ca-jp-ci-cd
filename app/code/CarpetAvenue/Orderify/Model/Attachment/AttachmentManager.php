<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Model\Attachment;

use Exception;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\MediaStorage\Model\File\UploaderFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Order attachment (one PDF per order + "extra shipping instructions" flag).
 *
 * Files live in var/persistent/order_attachments/<order_entity_id>/<basename>.pdf and
 * sales_order.ca_attachment_file holds the path relative to var/. IXPF_Syncer's Order Update
 * API writes the same columns and directory, so both sides stay interchangeable.
 */
class AttachmentManager
{
    public const COLUMN_FILE  = 'ca_attachment_file';
    public const COLUMN_EXTRA = 'ca_attachment_check_extra';

    public const STORAGE_ROOT = 'persistent/order_attachments';
    public const MAX_SIZE     = 10 * 1024 * 1024;

    private ?WriteInterface $varDirectory = null;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly UploaderFactory $uploaderFactory,
        private readonly OrderResource $orderResource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * var-relative path of the order's attachment, or null when none is set.
     */
    public function getPath(Order $order): ?string
    {
        return $order->getData(self::COLUMN_FILE) ?: null;
    }

    public function getFileName(Order $order): ?string
    {
        $path = $this->getPath($order);

        return $path !== null ? basename($path) : null;
    }

    public function hasExtraInstructions(Order $order): bool
    {
        return (int)$order->getData(self::COLUMN_EXTRA) === 1;
    }

    /**
     * True when the order references a file that is still on disk.
     */
    public function fileExists(Order $order): bool
    {
        $path = $this->getPath($order);

        return $path !== null && $this->isManagedPath($path) && $this->getVarDirectory()->isFile($path);
    }

    public function setExtraInstructions(Order $order, bool $flag): void
    {
        $order->setData(self::COLUMN_EXTRA, $flag ? 1 : 0);
        $this->orderResource->saveAttribute($order, self::COLUMN_EXTRA);
    }

    /**
     * Store an uploaded PDF ($_FILES[$fileId]) and point the order at it. Replaces and deletes
     * the previous attachment once the new path is saved.
     *
     * @return string var-relative path of the stored file
     * @throws LocalizedException
     */
    public function attachUpload(Order $order, string $fileId): string
    {
        $orderId = (int)$order->getId();
        $var     = $this->getVarDirectory();

        $uploader = $this->uploaderFactory->create(['fileId' => $fileId]);
        $uploader->setAllowedExtensions(['pdf']);
        $uploader->setAllowCreateFolders(true);
        // Rename instead of overwrite: a rejected upload must never clobber the current file.
        $uploader->setAllowRenameFiles(true);
        $uploader->setFilesDispersion(false);

        $result = $uploader->save($var->getAbsolutePath(self::STORAGE_ROOT . '/' . $orderId));
        if (!$result || empty($result['file'])) {
            throw new LocalizedException(__('The attachment could not be saved.'));
        }

        $target = self::STORAGE_ROOT . '/' . $orderId . '/' . $result['file'];

        try {
            $this->validatePdf($target);
        } catch (LocalizedException $e) {
            $this->deleteFile($target);
            throw $e;
        }

        $previous = $this->getPath($order);
        $order->setData(self::COLUMN_FILE, $target);

        try {
            $this->orderResource->saveAttribute($order, self::COLUMN_FILE);
        } catch (Exception $e) {
            $order->setData(self::COLUMN_FILE, $previous);
            if ($target !== $previous) {
                $this->deleteFile($target);
            }
            throw new LocalizedException(__('The attachment could not be saved: %1', $e->getMessage()), $e);
        }

        if ($previous !== null && $previous !== $target) {
            $this->deleteFile($previous);
        }

        return $target;
    }

    /**
     * Clear the order's attachment and delete its file.
     */
    public function detach(Order $order): void
    {
        $previous = $this->getPath($order);
        if ($previous === null) {
            return;
        }

        $order->setData(self::COLUMN_FILE, null);
        $this->orderResource->saveAttribute($order, self::COLUMN_FILE);
        $this->deleteFile($previous);
    }

    /**
     * Delete a stored attachment. Only paths under STORAGE_ROOT are touched.
     */
    public function deleteFile(?string $relativePath): void
    {
        if (!$relativePath || !$this->isManagedPath($relativePath)) {
            return;
        }

        try {
            $var = $this->getVarDirectory();
            if ($var->isFile($relativePath)) {
                $var->delete($relativePath);
            }
        } catch (Throwable $e) {
            $this->logger->error(
                sprintf('[order_attachment] Failed to delete "%s": %s', $relativePath, $e->getMessage())
            );
        }
    }

    /**
     * @throws LocalizedException when the file is empty, too large or not a PDF
     */
    public function validatePdf(string $relativePath): void
    {
        $var  = $this->getVarDirectory();
        $size = (int)($var->stat($relativePath)['size'] ?? 0);

        if ($size === 0) {
            throw new LocalizedException(__('Attachment file is empty.'));
        }
        if ($size > self::MAX_SIZE) {
            throw new LocalizedException(__(
                'Attachment is %1 MB, maximum allowed is %2 MB.',
                round($size / 1048576, 2),
                self::MAX_SIZE / 1048576
            ));
        }

        $file  = $var->openFile($relativePath, 'r');
        $magic = $file->read(5);
        $file->close();

        if ($magic !== '%PDF-') {
            throw new LocalizedException(__('Attachment is not a valid PDF file.'));
        }
    }

    public function isManagedPath(string $relativePath): bool
    {
        return str_starts_with($relativePath, self::STORAGE_ROOT . '/')
            && !in_array('..', explode('/', $relativePath), true);
    }

    private function getVarDirectory(): WriteInterface
    {
        if ($this->varDirectory === null) {
            $this->varDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        }

        return $this->varDirectory;
    }
}
