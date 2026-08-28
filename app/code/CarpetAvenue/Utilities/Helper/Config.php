<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Reads Utilities admin settings.
 * CLI options always override these values — this helper only provides defaults.
 */
class Config
{
    private const XML_PREFIX = 'carpetavenue_utilities';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DirectoryList        $directoryList,
    ) {
    }

    // ── General / Global ─────────────────────────────────────────────────────

    public function isMasterEnabled(): bool
    {
        return (bool) $this->get('general/enabled');
    }

    public function getGcsBucket(): string
    {
        return (string) ($this->get('general/gcs_bucket') ?? '');
    }

    /**
     * Returns the full absolute path to the GCS credentials JSON file.
     * If the stored value is already an absolute path (legacy text field), it is returned as-is.
     * If it is a filename uploaded via the file field, it is resolved under var/carpetavenue/gcs/.
     */
    public function getGcsCredentials(): string
    {
        $value = (string) ($this->get('general/gcs_credentials') ?? '');
        if ($value === '') {
            return '';
        }
        if (str_starts_with($value, '/')) {
            return $value;
        }
        return $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/carpetavenue/gcs/' . ltrim($value, '/');
    }

    // ── Manual Media Backup ──────────────────────────────────────────────────

    public function isManualBackupEnabled(): bool
    {
        return (bool) $this->get('media_cleanup/manual_backup/enabled');
    }

    public function getManualBackupGcsPrefix(): string
    {
        $v = trim((string) ($this->get('media_cleanup/manual_backup/gcs_prefix') ?? ''));
        return $v !== '' ? trim($v, '/') : 'manual_backup';
    }

    // ── Disabled Product Cache Cleanup ───────────────────────────────────────

    public function isDisabledCacheEnabled(): bool
    {
        return (bool) $this->get('media_cleanup/disabled_cache/enabled');
    }

    public function getDisabledCacheIdFrom(): int
    {
        return max(1, (int) ($this->get('media_cleanup/disabled_cache/id_from') ?? 1));
    }

    public function getDisabledCacheIdTo(): int
    {
        return max(1, (int) ($this->get('media_cleanup/disabled_cache/id_to') ?? 170000));
    }

    public function getDisabledCacheLimit(): int
    {
        return max(0, (int) ($this->get('media_cleanup/disabled_cache/limit') ?? 0));
    }

    public function isDisabledCacheZipEnabled(): bool
    {
        return (bool) $this->get('media_cleanup/disabled_cache/enable_zip');
    }

    public function getDisabledCacheStagingDir(): string
    {
        return (string) ($this->get('media_cleanup/disabled_cache/staging_dir') ?? '');
    }

    // ── Orphan Cache Cleanup ─────────────────────────────────────────────────

    public function isOrphanCacheEnabled(): bool
    {
        return (bool) $this->get('media_cleanup/orphan_cache/enabled');
    }

    public function getOrphanCacheLimit(): int
    {
        return max(0, (int) ($this->get('media_cleanup/orphan_cache/limit') ?? 0));
    }

    public function isOrphanCacheZipEnabled(): bool
    {
        return (bool) $this->get('media_cleanup/orphan_cache/enable_zip');
    }

    // ── Disabled Product Image Resize ────────────────────────────────────────

    public function isDisabledResizeEnabled(): bool
    {
        return (bool) $this->get('media_cleanup/disabled_resize/enabled');
    }

    public function getDisabledResizeIdFrom(): int
    {
        return max(1, (int) ($this->get('media_cleanup/disabled_resize/id_from') ?? 1));
    }

    public function getDisabledResizeIdTo(): int
    {
        return max(1, (int) ($this->get('media_cleanup/disabled_resize/id_to') ?? 170000));
    }

    public function getDisabledResizeLimit(): int
    {
        return max(0, (int) ($this->get('media_cleanup/disabled_resize/limit') ?? 0));
    }

    public function getDisabledResizeMaxWidth(): int
    {
        return max(1, (int) ($this->get('media_cleanup/disabled_resize/max_width') ?? 1200));
    }

    public function getDisabledResizeMaxHeight(): int
    {
        return max(1, (int) ($this->get('media_cleanup/disabled_resize/max_height') ?? 1200));
    }

    public function getDisabledResizeQuality(): int
    {
        return max(1, min(100, (int) ($this->get('media_cleanup/disabled_resize/quality') ?? 82)));
    }

    public function getDisabledResizeDriver(): string
    {
        return (string) ($this->get('media_cleanup/disabled_resize/driver') ?? 'auto');
    }

    public function isDisabledResizeZipEnabled(): bool
    {
        return (bool) $this->get('media_cleanup/disabled_resize/enable_zip');
    }

    public function getDisabledResizeStagingDir(): string
    {
        return (string) ($this->get('media_cleanup/disabled_resize/staging_dir') ?? '');
    }

    /**
     * When enabled, images already within max dimensions are re-saved at the configured
     * JPEG quality instead of being skipped. CLI --requalify overrides this.
     */
    public function isDisabledResizeRequalifySmall(): bool
    {
        return (bool) $this->get('media_cleanup/disabled_resize/requalify_small');
    }

    public function getDisabledResizeSoldBefore(): string
    {
        return (string) ($this->get('media_cleanup/disabled_resize/sold_before') ?? '');
    }

    // ── Attribute Override: Price ────────────────────────────────────────────

    public function isAttributeOverridePriceEnabled(): bool
    {
        return (bool) $this->get('attribute_override/price/enabled');
    }

    public function getAttributeOverridePriceAttributes(): array
    {
        $val = (string) ($this->get('attribute_override/price/attributes') ?? '');
        return $val !== '' ? explode(',', $val) : [];
    }

    // ── Attribute Override: Image ────────────────────────────────────────────

    public function isAttributeOverrideImageEnabled(): bool
    {
        return (bool) $this->get('attribute_override/image/enabled');
    }

    public function isAttributeOverrideImageRoles(): bool
    {
        return (bool) $this->get('attribute_override/image/override_roles');
    }

    public function isAttributeOverrideImagePosition(): bool
    {
        return (bool) $this->get('attribute_override/image/override_position');
    }

    // ── Attribute Override: Custom ───────────────────────────────────────────

    public function isAttributeOverrideCustomEnabled(): bool
    {
        return (bool) $this->get('attribute_override/custom/enabled');
    }

    public function getAttributeOverrideCustomAttributes(): array
    {
        $val = (string) ($this->get('attribute_override/custom/attributes') ?? '');
        return $val !== '' ? explode(',', $val) : [];
    }

    // ── Detect Settings ──────────────────────────────────────────────────────

    public function isDetectPriceEnabled(): bool
    {
        return (bool) $this->get('attribute_override/detect/price_enabled');
    }

    public function getDetectPriceAttributes(): array
    {
        $val = (string) ($this->get('attribute_override/detect/price_attributes') ?? '');
        return $val !== '' ? explode(',', $val) : [];
    }

    public function isDetectImageEnabled(): bool
    {
        return (bool) $this->get('attribute_override/detect/image_enabled');
    }

    public function isDetectImageRoles(): bool
    {
        return (bool) $this->get('attribute_override/detect/image_roles');
    }

    public function isDetectImagePosition(): bool
    {
        return (bool) $this->get('attribute_override/detect/image_position');
    }

    public function isDetectCustomEnabled(): bool
    {
        return (bool) $this->get('attribute_override/detect/custom_enabled');
    }

    public function getDetectCustomAttributes(): array
    {
        $val = (string) ($this->get('attribute_override/detect/custom_attributes') ?? '');
        return $val !== '' ? explode(',', $val) : [];
    }

    // ── Auto Fix ─────────────────────────────────────────────────────────────

    public function isAutoFixEnabled(): bool
    {
        return (bool) $this->get('attribute_override/auto_fix/enabled');
    }

    public function isAutoFixLogOnlyWhenDisabled(): bool
    {
        return (bool) $this->get('attribute_override/auto_fix/log_only_when_disabled');
    }

    // ── Protected Stores ─────────────────────────────────────────────────────

    /**
     * Store view IDs that must never be modified by detect/fix/snapshot.
     *
     * @return int[]
     */
    public function getProtectedStoreIds(): array
    {
        $val = trim((string) ($this->get('attribute_override/protected_stores') ?? ''));
        if ($val === '') {
            return [];
        }
        return array_map('intval', explode(',', $val));
    }

    // ── Notification ─────────────────────────────────────────────────────────

    public function isEmailNotificationEnabled(): bool
    {
        return (bool) $this->get('attribute_override/notification/email_enabled');
    }

    public function isSlackNotificationEnabled(): bool
    {
        return (bool) $this->get('attribute_override/notification/slack_enabled');
    }

    public function getNotificationEmailTo(): array
    {
        $val = trim((string) ($this->get('attribute_override/notification/email_to') ?? ''));
        return $val !== '' ? array_map('trim', explode(',', $val)) : [];
    }

    public function getNotificationEmailFrom(): string
    {
        return trim((string) ($this->get('attribute_override/notification/email_from') ?? ''));
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private function get(string $path): mixed
    {
        return $this->scopeConfig->getValue(self::XML_PREFIX . '/' . $path);
    }
}
