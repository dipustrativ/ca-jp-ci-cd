<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model;

use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;
use CarpetAvenue\FeedExport\Model\Export\FileWriter;
use CarpetAvenue\FeedExport\Model\Export\XslRenderer;
use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\Exception\CronException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Rejects profiles that would fail at export time: bad XSL, unsafe destination, broken JSON or cron.
 */
class ProfileValidator
{
    public function __construct(
        private readonly XslRenderer $xslRenderer,
        private readonly FileWriter $fileWriter,
        private readonly ScheduleFactory $scheduleFactory,
        private readonly Taxonomy $taxonomy
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function validate(ProfileInterface $profile): void
    {
        if (trim($profile->getName()) === '') {
            throw new LocalizedException(__('Profile name is required.'));
        }
        $profile->setDestinationPath($this->fileWriter->normalizePath($profile->getDestinationPath()));
        $this->xslRenderer->validate($profile->getXslTemplate());

        $mapping = trim((string)$profile->getData(ProfileInterface::CATEGORY_MAPPING));
        if ($mapping !== '' && !is_array(json_decode($mapping, true))) {
            throw new LocalizedException(__('Category mapping must be a JSON object: {"12":"Home & Garden > Rugs"}'));
        }

        $taxonomySource = $profile->getTaxonomySource();
        if ($taxonomySource !== '' && !$this->taxonomy->isValidSource($taxonomySource)) {
            throw new LocalizedException(__('Unknown taxonomy "%1".', $taxonomySource));
        }

        if ($profile->isCronEnabled() && !$profile->getCronExpressions()) {
            throw new LocalizedException(__('Cron is enabled but no cron expression is set.'));
        }
        foreach ($profile->getCronExpressions() as $expression) {
            try {
                $this->scheduleFactory->create()->setCronExpr($expression);
            } catch (CronException $e) {
                throw new LocalizedException(__('Invalid cron expression "%1".', $expression));
            }
        }
    }
}
