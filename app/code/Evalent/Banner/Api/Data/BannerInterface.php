<?php

declare(strict_types=1);

namespace Evalent\Banner\Api\Data;

/**
 * Interface BannerInterface
 *
 * @package Evalent\Banner\Api\Data
 * @api
 */
interface BannerInterface extends \Magento\Framework\Api\ExtensibleDataInterface
{

    const IS_ENABLED = 1;
    const IS_DISABLED = 2;

    const DESKTOP_IMAGE = 'desktop_image';
    const ENABLED = 'enabled';
    const ENTITY_ID = 'entity_id';
    const BUTTON_ENABLED_MOBILE = 'button_enabled_mobile';
    const BUTTON_STYLE = 'button_style';
    const DESCRIPTION = 'description';
    const CONTENT_ALIGN_DESKTOP = 'content_align_desktop';
    const NAME = 'name';
    const BANNER_LINK_URL_NEW_WINDOW = 'banner_link_url_new_window';
    const BUTTON_TEXT = 'button_text';
    const TEXT_ALIGN_DESKTOP = 'text_align_desktop';
    const TEXT_ALIGN_MOBILE = 'text_align_mobile';
    const BANNER_LINK_URL = 'banner_link_url';
    const BUTTON_CONTENT_THEME = 'button_content_theme';
    const MOBILE_IMAGE = 'mobile_image';
    const CONTENT_ALIGN_MOBILE = 'content_align_mobile';
    const HEADING = 'heading';
    const HEADING_SECONDARY = 'heading_secondary';
    const BUTTON_ENABLED_DESKTOP = 'button_enabled_desktop';
    const HEADING_SIZE_MOBILE = 'heading_size_mobile';
    const HEADING_SIZE_DESKTOP = 'heading_size_desktop';
    const HEADING_TAG = 'heading_tag';
    const BUTTON_SIZE_MOBILE = 'button_size_mobile';
    const BUTTON_SIZE_DESKTOP = 'button_size_desktop';
    const DESCRIPTION_SIZE_MOBILE = 'description_size_mobile';
    const DESCRIPTION_SIZE_DESKTOP = 'description_size_desktop';
    const HEADING_SECONDARY_SIZE_MOBILE = 'heading_secondary_size_mobile';
    const HEADING_SECONDARY_SIZE_DESKTOP = 'heading_secondary_size_desktop';
    const CONTENT_CUSTOM_COLOR = 'content_custom_color';
    const BUTTONTEXT_CUSTOM_COLOR = 'button_text_color';
    const BUTTON_CTA_HOVER = 'button_cta_hover';
    const BUTTON_BACKGROUND_COLOR = 'button_background_color';
    const SHADOW_EFFECT  = 'shadow_effect';
    const DESCRIPTION_POSITION  = 'description_position';
    const DATE_START = 'date_start';
    const DATE_END = 'date_end';

    /**
     * Get entity_id
     * @return string|null
     */
    public function getEntityId(): ?string;

    /**
     * Set entity_id
     *
     * @param string $entityID
     *
     * @return BannerInterface
     */
    public function setEntityId($entityID): BannerInterface;

    /**
     * Get name
     * @return string
     */
    public function getName(): string;

    /**
     * Set name
     * @param string $name
     * @return BannerInterface
     */
    public function setName($name): BannerInterface;

    /**
     * Retrieve existing extension attributes object or create a new one.
     * @return \Evalent\Banner\Api\Data\BannerExtensionInterface|null
     */
    public function getExtensionAttributes();

    /**
     * Set an extension attributes object.
     * @param \Evalent\Banner\Api\Data\BannerExtensionInterface $extensionAttributes
     * @return $this
     */
    public function setExtensionAttributes(
        \Evalent\Banner\Api\Data\BannerExtensionInterface $extensionAttributes
    );

    /**
     * Get enabled
     * @return bool
     */
    public function getEnabled(): bool;

    /**
     * Set enabled
     * @param string $enabled
     * @return BannerInterface
     */
    public function setEnabled($enabled): BannerInterface;

    /**
     * Get date_start
     * @return string|null
     */
    public function getDateStart(): ?string;

    /**
     * Set date_start
     * @return string|null
     */
    public function setDateStart($date): BannerInterface;

    /**
     * Get date_end
     * @return string|null
     */
    public function getDateEnd(): ?string;

    /**
     * Set date_end
     * @return string|null
     */
    public function setDateEnd($date): BannerInterface;

    /**
     * Get desktop_image
     * @return string|null
     */
    public function getDesktopImage(): ?string;

    /**
     * Set desktop_image
     * @param string $desktopImage
     * @return BannerInterface
     */
    public function setDesktopImage($desktopImage): BannerInterface;

    /**
     * Get mobile_image
     * @return string|null
     */
    public function getMobileImage(): ?string;

    /**
     * Set mobile_image
     * @param string $mobileImage
     * @return BannerInterface
     */
    public function setMobileImage($mobileImage): BannerInterface;

    /**
     * Get heading
     * @return string|null
     */
    public function getHeading(): ?string;

    /**
     * Get secondary heading
     * @return string|null
     */
    public function getHeadingSecondary(): ?string;

    /**
     * Set heading
     * @param string $heading
     * @return BannerInterface
     */
    public function setHeading($heading): BannerInterface;

    /**
     * Set secondary heading
     * @param string $heading2
     * @return BannerInterface
     */
    public function setHeadingSecondary($heading2): BannerInterface;

    /**
     * Get heading_tag
     * @return string|null
     */
    public function getHeadingTag(): ?string;
    /**
     * Set heading_size_desktop
     *
     * @param string $headingTag
     *
     * @return BannerInterface
     */
    public function setHeadingTag($headingTag): BannerInterface;

    /**
     * Get heading_size_desktop
     * @return string|null
     */
    public function getHeadingSizeDesktop(): ?string;
    /**
     * Set heading_secondary_size_desktop
     *
     * @param string $headingSize
     *
     * @return BannerInterface
     */
    public function setHeadingSizeDesktop($headingSize): BannerInterface;

    /**
     * Get heading_size_desktop
     * @return string|null
     */
    public function getHeadingSecondarySizeDesktop(): ?string;

    /**
     * Get heading_secondary_size_desktop
     *
     * @param string $headingSize
     *
     * @return BannerInterface
     */
    public function setHeadingSecondarySizeDesktop($headingSize): BannerInterface;

    /**
     * Get heading_size_mobile
     * @return string|null
     */
    public function getHeadingSizeMobile(): ?string;

    /**
     * Set heading_size_mobile
     *
     * @param string $headingSize
     *
     * @return BannerInterface
     */
    public function setHeadingSizeMobile($headingSize): BannerInterface;

    /**
     * Get heading_secondary_size_mobile
     * @return string|null
     */
    public function getHeadingSecondarySizeMobile(): ?string;

    /**
     * Set heading_secondary_size_mobile
     *
     * @param string $headingSize
     *
     * @return BannerInterface
     */
    public function setHeadingSecondarySizeMobile($headingSize): BannerInterface;

    /**
     * Get description
     * @return string|null
     */
    public function getDescription(): ?string;

    /**
     * Set description
     * @param string $description
     * @return BannerInterface
     */
    public function setDescription($description): BannerInterface;

    /**
     * Get button_text
     * @return string|null
     */
    public function getButtonText(): ?string;

    /**
     * Set button_text
     * @param string $buttonText
     * @return BannerInterface
     */
    public function setButtonText($buttonText): BannerInterface;

    /**
     * Get button_enabled_desktop
     * @return bool
     */
    public function getButtonEnabledDesktop(): bool;

    /**
     * Set button_enabled_desktop
     * @param string $buttonEnabledDesktop
     * @return BannerInterface
     */
    public function setButtonEnabledDesktop($buttonEnabledDesktop): BannerInterface;

    /**
     * Get button_enabled_mobile
     * @return bool
     */
    public function getButtonEnabledMobile(): bool;

    /**
     * Set button_enabled_mobile
     * @param string $buttonEnabledMobile
     * @return BannerInterface
     */
    public function setButtonEnabledMobile($buttonEnabledMobile): BannerInterface;

    /**
     * Get button_style
     * @return string|null
     */
    public function getButtonStyle(): ?string;

    /**
     * Set button_style
     * @param string $buttonStyle
     * @return BannerInterface
     */
    public function setButtonStyle($buttonStyle): BannerInterface;

    /**
     * Get shadow_effect
     * @return string|null
     */
    public function getShadowEffect(): ?string;

    /**
     * Set shadow_effect
     * @param string $buttonStyle
     * @return BannerInterface
     */
    public function setShadowEffect($shadowEffect): BannerInterface;

    /**
     * Get button_content_theme
     * @return string|null
     */
    public function getButtonContentTheme(): ?string;

    /**
     * Set button_content_theme
     * @param string $buttonContentTheme
     * @return BannerInterface
     */
    public function setButtonContentTheme($buttonContentTheme): BannerInterface;

    /**
     * Set content_custom_color
     * @param string $customColor
     * @return BannerInterface
     */
    public function setContentCustomColor($customColor): BannerInterface;

    /**
     * Get content_custom_color
     * @return string|null
     */
    public function getContentCustomColor(): ?string;

    // CUSTOM BUTTON TEXT COLOR

    /**
     * Set button_cta_hover
     * @param string $buttonCtaHover
     * @return BannerInterface
     */
    public function setButtonCtaHover($buttonCtaHover): BannerInterface;

    /**
     * Get content_custom_color
     * @return string|null
     */
    public function getButtonCtaHover(): ?string;

    /**
     * Set button_background_color
     * @param string $buttonBackgroundColor
     * @return BannerInterface
     */
    public function setButtonBackgroundColor($buttonBackgroundColor): BannerInterface;

    /**
     * Get button_background_color
     * @return string|null
     */
    public function getButtonBackgroundColor(): ?string;

    // CUSTOM BUTTON TEXT COLOR

    /**
     * Set buttontext_custom_color
     * @param string $customButtonTextColor
     * @return BannerInterface
     */
    public function setButtonTextColor($customButtonTextColor): BannerInterface;

    /**
     * Get buttontext_custom_color
     * @return string|null
     */
    public function getButtonTextColor(): ?string;

    /**
     * Set description_text_color
     * @param string $descriptionTextColor
     * @return BannerInterface
     */
    public function setDescriptionTextColor(string $descriptionTextColor): BannerInterface;

    /**
     * Get description_text_color
     * @return string|null
     */
    public function getDescriptionTextColor(): ?string;

    /**
     * Set description_background_color
     * @param string $descriptionBackgroundColor
     * @return BannerInterface
     */
    public function setDescriptionBackgroundColor(string $descriptionBackgroundColor): BannerInterface;

    /**
     * Get description_background_color
     * @return string|null
     */
    public function getDescriptionBackgroundColor(): ?string;

    /**
     * Get content_align_mobile
     * @return string|null
     */
    public function getContentAlignMobile(): ?string;

    /**
     * Set content_align_mobile
     * @param string $contentAlignMobile
     * @return BannerInterface
     */
    public function setContentAlignMobile($contentAlignMobile): BannerInterface;

    /**
     * Get content_align_desktop
     * @return string|null
     */
    public function getContentAlignDesktop(): ?string;

    /**
     * Set content_align_desktop
     * @param string $contentAlignDesktop
     * @return BannerInterface
     */
    public function setContentAlignDesktop($contentAlignDesktop): BannerInterface;

    /**
     * Get text_align_mobile
     * @return string|null
     */
    public function getTextAlignMobile(): ?string;

    /**
     * Set text_align_mobile
     * @param string $textAlignMobile
     * @return BannerInterface
     */
    public function setTextAlignMobile($textAlignMobile): BannerInterface;

    /**
     * Get text_align_desktop
     * @return string|null
     */
    public function getTextAlignDesktop(): ?string;

    /**
     * Set text_align_desktop
     * @param string $textAlignDesktop
     * @return BannerInterface
     */
    public function setTextAlignDesktop($textAlignDesktop): BannerInterface;

    /**
     * Get banner_link_url
     * @return string|null
     */
    public function getBannerLinkUrl(): ?string;

    /**
     * Set banner_link_url
     * @param string $bannerLinkUrl
     * @return BannerInterface
     */
    public function setBannerLinkUrl($bannerLinkUrl): BannerInterface;

    /**
     * Get banner_link_url_new_window
     * @return string|null
     */
    public function getBannerLinkUrlNewWindow(): ?string;

    /**
     * Set banner_link_url_new_window
     * @param string $bannerLinkUrlNewWindow
     * @return BannerInterface
     */
    public function setBannerLinkUrlNewWindow($bannerLinkUrlNewWindow): BannerInterface;

    /**
     * Set button_size_desktop
     *
     * @param string $buttonSize
     *
     * @return BannerInterface
     */
    public function setButtonSizeDesktop($buttonSize): BannerInterface;

    /**
     * Get button_size_desktop
     * @return string|null
     */
    public function getButtonSizeDesktop(): ?string;

    /**
     * Set button_size_mobile
     *
     * @param string $buttonSize
     *
     * @return BannerInterface
     */
    public function setButtonSizeMobile($buttonSize): BannerInterface;

    /**
     * Get button_size_mobile
     * @return string|null
     */
    public function getButtonSizeMobile(): ?string;

    /**
     * DESCRIPTION
     */

    /**
     * Set description_size_desktop
     *
     * @param string $descriptionSize
     *
     * @return BannerInterface
     */
    public function setDescriptionSizeDesktop($descriptionSize): BannerInterface;

    /**
     * Get button_size_desktop
     * @return string|null
     */
    public function getDescriptionSizeDesktop(): ?string;

    /**
     * Set description_size_mobile
     *
     * @param string $descriptionSize
     *
     * @return BannerInterface
     */
    public function setDescriptionSizeMobile($descriptionSize): BannerInterface;

    /**
     * Get button_size_mobile
     * @return string|null
     */
    public function getDescriptionSizeMobile(): ?string;

    /*
     * DESCRIPTION POSITION
     */

    /**
     * Set description_position
     *
     * @param string $descriptionPosition
     *
     * @return BannerInterface
     */
    public function setDescriptionPosition($descriptionPosition): BannerInterface;

    /**
     * Get description_position
     * @return string|null
     */
    public function getDescriptionPosition(): ?string;

    /**
     * Get the carled image from the banner
     *
     * @param string $imageString
     * @param null|int   $height
     * @param null|int   $width
     *
     * @return string|null
     */
    public function getImageUrl(
        string $imageString = 'desktop_image',
        ?int $height = null,
        ?int $width = null
    ): ?string;
}
