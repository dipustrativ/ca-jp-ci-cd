<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Reviews;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Image\Factory as ImageFactory;

/**
 * Where review media lives on disk, and what shapes it is served in.
 *
 * Layout under pub/media, keyed by review_id so the diff apply in UpsertReviewGroup can keep
 * an unchanged review's files without knowing anything about entity_ids:
 *
 *   ixpf/reviews/<review_id>/<basename>          original as downloaded, never served
 *   ixpf/reviews/<review_id>/80/<basename>       80px square, review card thumbnails
 *   ixpf/reviews/<review_id>/800/<basename>      800px longest edge, photo grid
 *
 * local_path / thumbnail_local store the ORIGINAL relative path. Derivatives are derived from
 * it by derivativePath(), so the columns stay stable if the sizes ever change.
 */
class ReviewMediaStorage
{
    public const ROOT = 'ixpf/reviews';

    public const SIZE_THUMB = 80;
    public const SIZE_GRID  = 800;

    private ?WriteInterface $mediaDirectory = null;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ImageFactory $imageFactory
    ) {
    }

    public function getMediaDirectory(): WriteInterface
    {
        if ($this->mediaDirectory === null) {
            $this->mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        }

        return $this->mediaDirectory;
    }

    /**
     * Media-relative path the original is stored at, e.g. ixpf/reviews/abc123/photo.jpg
     */
    public function relativePath(string $reviewId, string $bucketPath): string
    {
        return self::ROOT . '/' . $this->directoryName($reviewId) . '/' . $this->basename($bucketPath);
    }

    /**
     * Directory name a review's files live under.
     *
     * Public because the cleanup cron compares directory names against review_ids from the
     * database and must apply the same transform to both sides — otherwise an id containing a
     * character that gets replaced here would look orphaned and have its files deleted while
     * the review is still live.
     */
    public function directoryName(string $reviewId): string
    {
        return (string)preg_replace('/[^\w\-]/', '_', $reviewId);
    }

    /**
     * Media-relative path of a derivative of an already-stored original.
     */
    public function derivativePath(string $originalRelativePath, int $size): string
    {
        return dirname($originalRelativePath) . '/' . $size . '/' . basename($originalRelativePath);
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->getMediaDirectory()->getAbsolutePath($relativePath);
    }

    public function exists(string $relativePath): bool
    {
        return $this->getMediaDirectory()->isFile($relativePath);
    }

    /**
     * A bought-item thumbnail usually points at the same bucket object the product gallery
     * already pulled, so check the gallery before spending a download. Magento disperses
     * gallery files as /<char1>/<char2>/<filename>.
     *
     * @return string|null media-relative path of the existing gallery file, if there is one
     */
    public function findExistingGalleryFile(string $bucketPath): ?string
    {
        $filename = strtolower($this->basename($bucketPath));
        if ($filename === '') {
            return null;
        }

        $candidate = sprintf(
            'catalog/product/%s/%s/%s',
            $filename[0] ?? 'a',
            $filename[1] ?? 'a',
            $filename
        );

        return $this->exists($candidate) ? $candidate : null;
    }

    /**
     * Writes the two served sizes next to the original. The 2000x1500 original is never served.
     *
     * Failures here are the caller's to log: a missing derivative degrades the card, it does
     * not invalidate the download.
     */
    public function generateDerivatives(string $originalRelativePath): void
    {
        $source = $this->absolutePath($originalRelativePath);

        // Square crop for the card.
        $thumb = $this->imageFactory->create($source);
        $thumb->keepAspectRatio(true);
        $thumb->keepFrame(true);
        $thumb->keepTransparency(true);
        $thumb->constrainOnly(false);
        $thumb->backgroundColor([255, 255, 255]);
        $thumb->quality(85);
        $thumb->resize(self::SIZE_THUMB, self::SIZE_THUMB);
        $thumb->save($this->absolutePath($this->derivativePath($originalRelativePath, self::SIZE_THUMB)));

        // Longest edge for the grid: pass only one dimension and let the adapter derive the
        // other, so portrait photos are not upscaled on their long side.
        [$width, $height] = $this->dimensions($source);
        $grid = $this->imageFactory->create($source);
        $grid->keepAspectRatio(true);
        $grid->keepFrame(false);
        $grid->keepTransparency(true);
        $grid->constrainOnly(true);
        $grid->quality(85);
        if ($width >= $height) {
            $grid->resize(self::SIZE_GRID, null);
        } else {
            $grid->resize(null, self::SIZE_GRID);
        }
        $grid->save($this->absolutePath($this->derivativePath($originalRelativePath, self::SIZE_GRID)));
    }

    /**
     * Removes everything stored for one review. Used by the cleanup cron.
     */
    public function deleteReviewDirectory(string $reviewId): void
    {
        $directory = self::ROOT . '/' . $this->directoryName($reviewId);
        if ($this->getMediaDirectory()->isDirectory($directory)) {
            $this->getMediaDirectory()->delete($directory);
        }
    }

    /**
     * review_id of every directory currently under the media root.
     *
     * @return string[]
     */
    public function listStoredReviewIds(): array
    {
        $media = $this->getMediaDirectory();
        if (!$media->isDirectory(self::ROOT)) {
            return [];
        }

        $ids = [];
        foreach ($media->read(self::ROOT) as $path) {
            if ($media->isDirectory($path)) {
                $ids[] = basename($path);
            }
        }

        return $ids;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(string $absolutePath): array
    {
        $size = @getimagesize($absolutePath);

        return $size === false ? [0, 0] : [(int)$size[0], (int)$size[1]];
    }

    private function basename(string $bucketPath): string
    {
        // Bucket paths can carry a query string; strip it before it becomes a filename.
        $name = basename((string)preg_replace('/[?#].*$/', '', $bucketPath));

        return (string)preg_replace('/[^\w.\-]/', '_', $name);
    }
}
