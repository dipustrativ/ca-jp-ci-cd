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

namespace CarpetAvenue\FeedExport\Api;

use CarpetAvenue\FeedExport\Api\Data\ExportResultInterface;
use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;

/**
 * Builds the feed file(s) of one profile and writes them to its destination.
 *
 * @api
 */
interface FeedExporterInterface
{
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_CRON = 'cron';
    public const TRIGGER_CLI = 'cli';

    /**
     * @param int $profileId
     * @param string $trigger One of TRIGGER_* constants
     * @return ExportResultInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function export(int $profileId, string $trigger = self::TRIGGER_MANUAL): ExportResultInterface;

    /**
     * Apply the profile (incl. an unsaved template set on it) to the given products only.
     * Nothing is written to disk or logged.
     *
     * @param ProfileInterface $profile
     * @param int[] $productIds
     * @return array<string, string> filename => content
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function test(ProfileInterface $profile, array $productIds): array;

    /**
     * Intermediate XML (objects/object) with every field available to templates, for the given products.
     *
     * @param ProfileInterface $profile
     * @param int[] $productIds
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getSourceXml(ProfileInterface $profile, array $productIds): string;
}
