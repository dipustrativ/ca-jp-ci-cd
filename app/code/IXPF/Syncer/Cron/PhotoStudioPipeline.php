<?php
declare(strict_types=1);

namespace IXPF\Syncer\Cron;

use Exception;
use IXPF\Syncer\Helper\Helpers;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Services\PhotoStudio\DirectGallerySync;
use IXPF\Syncer\Services\PhotoStudio\ProcessGoogleBucketImages;
use IXPF\Syncer\Services\PhotoStudio\ProcessImages;
use Magento\Framework\App\State;

/**
 * Runs the new PhotoStudio pipeline on a schedule: downloads new Google Bucket
 * images (gcs:images:download) then processes the image queue into product
 * galleries (photo-studio:queue:run).
 *
 * Gated by the "Enable Cron" admin toggle and only runs while the previous
 * PhotoStudio functionality is disabled (the new pipeline replaces it).
 */
class PhotoStudioPipeline
{
    private const DOWNLOAD_LIMIT = 600;
    private const DOWNLOAD_CONCURRENCY = 10;
    private const QUEUE_LIMIT = 50;

    public function __construct(
        protected readonly State $state,
        protected readonly PhotoStudioLogger $logger,
        protected ProcessGoogleBucketImages $processGoogleBucketImages,
        protected ProcessImages $processImages,
        protected DirectGallerySync $directGallerySync,
        protected Helpers $helpers
    ) {
    }

    public function execute(): void
    {
        try {
            if (!$this->helpers->isPhotoStudioCronEnabled()) {
                return;
            }

            // The new pipeline only runs when the previous functionality is off.
            if ($this->helpers->getEnablePhotoStudioPreviousFunctionality()) {
                return;
            }

            set_time_limit(0);

            // Cron area is already set; emulate frontend so image/media resolution works.
            $this->state->emulateAreaCode('frontend', [$this, 'runPipeline']);

        } catch (Exception $e) {
            $this->logger->writeDaily('Error in PhotoStudioPipeline cron: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Callback executed inside the emulated frontend area.
     */
    public function runPipeline(): void
    {
        // 1) Download new images from the Google Bucket.
        $this->processGoogleBucketImages->process(self::DOWNLOAD_CONCURRENCY, null, self::DOWNLOAD_LIMIT);

        // 2) Process the pending queue into product galleries.
        if ($this->helpers->isRawDbSaveMethod()) {
            $this->directGallerySync->process(self::QUEUE_LIMIT);
        } else {
            $this->processImages->process(self::QUEUE_LIMIT);
        }
    }
}
