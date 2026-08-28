<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Data;


use Evalent\Banner\Api\Data\BannerExtensionInterface;use Evalent\Banner\Api\Data\BannerInterface;
use Evalent\Banner\Model\UploaderPool;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;use Magento\Framework\Data\Collection\AbstractDb;use Magento\Framework\Model\AbstractExtensibleModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;use Magento\Framework\Registry;

/**
 * Class Banner
 *
 * @package Evalent\Banner\Model\Data
 */
class Banner extends AbstractExtensibleModel implements BannerInterface
{

    /**
     * @var \Evalent\Banner\Model\UploaderPool
     */
    private $uploaderPool;


    public function __construct(
        UploaderPool $uploaderPool,
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $extensionFactory,
            $customAttributeFactory, $resource, $resourceCollection, $data);
        $this->uploaderPool = $uploaderPool;
    }


    /**
     * {@inheritdoc}
     */
    public function getEntityId(): ?string
    {
        return $this->getData(self::ENTITY_ID);
    }

    /**
     * {@inheritdoc}
     */
    public function setEntityId($entityID): BannerInterface
    {
        return $this->setData(self::ENTITY_ID, $entityID);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->getData(self::NAME);
    }

    /**
     * {@inheritdoc}
     */
    public function setName($name): BannerInterface
    {
        return $this->setData(self::NAME, $name);
    }

    /**
     * Get date_start
     * @return string|null
     */
    public function getDateStart(): ?string
    {
        return $this->getData(self::DATE_START);
    }

    /**
     * Set date_start
     * @return string|null
     */
    public function setDateStart($date): BannerInterface
    {
        return $this->setData(self::DATE_START, $date);
    }

    /**
     * Get date_end
     * @return string|null
     */
    public function getDateEnd(): ?string
    {
        return $this->getData(self::DATE_END);
    }

    /**
     * Set date_end
     * @return string|null
     */
    public function setDateEnd($date): BannerInterface
    {
        return $this->setData(self::DATE_END, $date);
    }

    /**
     * {@inheritdoc}
     */
    public function getExtensionAttributes()
    {
        return $this->getDataExtensionAttributes();
    }

    /**
     * {@inheritdoc}
     */
    public function setExtensionAttributes(
        BannerExtensionInterface $extensionAttributes
    ) {
        return $this->_setExtensionAttributes($extensionAttributes);
    }

    /**
     * {@inheritdoc}
     */
    public function getEnabled(): bool
    {
        return $this->getData(self::ENABLED) == self::IS_ENABLED;
    }

    /**
     * {@inheritdoc}
     */
    public function setEnabled($enabled): BannerInterface
    {
        return $this->setData(self::ENABLED, $enabled);
    }

    /**
     * {@inheritdoc}
     */
    public function getDesktopImage(): ?string
    {
        return $this->getData(self::DESKTOP_IMAGE);
    }

    /**
     * {@inheritdoc}
     */
    public function setDesktopImage($desktopImage): BannerInterface
    {
        return $this->setData(self::DESKTOP_IMAGE, $desktopImage);
    }

    /**
     * {@inheritdoc}
     */
    public function getMobileImage(): ?string
    {
        return $this->getData(self::MOBILE_IMAGE);
    }

    /**
     * {@inheritdoc}
     */
    public function setMobileImage($mobileImage): BannerInterface
    {
        return $this->setData(self::MOBILE_IMAGE, $mobileImage);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeading(): ?string
    {
        return $this->getData(self::HEADING);
    }

    /**
     * {@inheritdoc}
     */
    public function setHeading($heading): BannerInterface
    {
        return $this->setData(self::HEADING, $heading);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeadingSecondary(): ?string
    {
        return $this->getData(self::HEADING_SECONDARY);
    }

    /**
     * {@inheritdoc}
     */
    public function setHeadingSecondary($heading2): BannerInterface
    {
        return $this->setData(self::HEADING_SECONDARY, $heading2);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeadingSecondarySizeDesktop(): ?string
    {
        return $this->getData(self::HEADING_SECONDARY_SIZE_DESKTOP);
    }

    /**
     * {@inheritdoc}
     */
    public function setHeadingSecondarySizeDesktop($headingSize): BannerInterface
    {
        return $this->setData(self::HEADING_SECONDARY_SIZE_DESKTOP, $headingSize);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeadingSecondarySizeMobile(): ?string
    {
        return $this->getData(self::HEADING_SECONDARY_SIZE_MOBILE);
    }

    /**
     * {@inheritdoc}
     */
    public function setHeadingSecondarySizeMobile($headingSize): BannerInterface
    {
        return $this->setData(self::HEADING_SECONDARY_SIZE_MOBILE, $headingSize);
    }

    /**
     * BUTTON SIZE
     */

    /**
     * {@inheritdoc}
     */
    public function getButtonSizeMobile(): ?string
    {
        return $this->getData(self::BUTTON_SIZE_MOBILE);
    }

    /**
     * {@inheritdoc}
     */
    public function setButtonSizeMobile($buttonSize): BannerInterface
    {
        return $this->setData(self::BUTTON_SIZE_MOBILE, $buttonSize);
    }

    /**
     * {@inheritdoc}
     */
    public function getButtonSizeDesktop(): ?string
    {
        return $this->getData(self::BUTTON_SIZE_DESKTOP);
    }

    /**
     * {@inheritdoc}
     */
    public function setButtonSizeDesktop($buttonSize): BannerInterface
    {
        return $this->setData(self::BUTTON_SIZE_DESKTOP, $buttonSize);
    }

    /**
     * DESCRIPTION SIZE
     */

    /**
     * {@inheritdoc}
     */
    public function getDescriptionSizeDesktop(): ?string
    {
        return $this->getData(self::DESCRIPTION_SIZE_DESKTOP);
    }

    /**
     * {@inheritdoc}
     */
    public function setDescriptionSizeDesktop($descriptionSize): BannerInterface
    {
        return $this->setData(self::DESCRIPTION_SIZE_DESKTOP, $descriptionSize);
    }

    /**
     * {@inheritdoc}
     */
    public function getDescriptionSizeMobile(): ?string
    {
        return $this->getData(self::DESCRIPTION_SIZE_MOBILE);
    }

    /**
     * {@inheritdoc}
     */
    public function setDescriptionSizeMobile($descriptionSize): BannerInterface
    {
        return $this->setData(self::DESCRIPTION_SIZE_MOBILE, $descriptionSize);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeadingTag(): ?string
    {
        return $this->getData(self::HEADING_TAG);
    }

    /**
     * {@inheritdoc}
     */
    public function setHeadingTag($headingTag): BannerInterface
    {
        return $this->setData(self::HEADING_TAG, $headingTag);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeadingSizeDesktop(): ?string
    {
        return $this->getData(self::HEADING_SIZE_DESKTOP);
    }

    /**
     * {@inheritdoc}
     */
    public function setHeadingSizeDesktop($headingSize): BannerInterface
    {
        return $this->setData(self::HEADING_SIZE_DESKTOP, $headingSize);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeadingSizeMobile(): ?string
    {
        return $this->getData(self::HEADING_SIZE_MOBILE);
    }

    /**
     * {@inheritdoc}
     */
    public function setHeadingSizeMobile($headingSize): BannerInterface
    {
        return $this->setData(self::HEADING_SIZE_MOBILE, $headingSize);
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): ?string
    {
        return $this->getData(self::DESCRIPTION);
    }

    /**
     * {@inheritdoc}
     */
    public function setDescription($description): BannerInterface
    {
        return $this->setData(self::DESCRIPTION, $description);
    }

    /**
     * {@inheritdoc}
     */
    public function getButtonText(): ?string
    {
        return $this->getData(self::BUTTON_TEXT);
    }

    /**
     * {@inheritdoc}
     */
    public function setButtonText($buttonText): BannerInterface
    {
        return $this->setData(self::BUTTON_TEXT, $buttonText);
    }

    /**
     * {@inheritdoc}
     */
    public function getButtonEnabledDesktop(): bool
    {
        return $this->getData(self::BUTTON_ENABLED_DESKTOP) == 1;
    }

    /**
     * {@inheritdoc}
     */
    public function setButtonEnabledDesktop($buttonEnabledDesktop): BannerInterface
    {
        return $this->setData(self::BUTTON_ENABLED_DESKTOP, $buttonEnabledDesktop);
    }

    /**
     * {@inheritdoc}
     */
    public function getButtonEnabledMobile(): bool
    {
        return $this->getData(self::BUTTON_ENABLED_MOBILE) == 1;
    }

    /**
     * {@inheritdoc}
     */
    public function setButtonEnabledMobile($buttonEnabledMobile): BannerInterface
    {
        return $this->setData(self::BUTTON_ENABLED_MOBILE, $buttonEnabledMobile);
    }

    /**
     * {@inheritdoc}
     */
    public function getButtonStyle(): ?string
    {
        return $this->getData(self::BUTTON_STYLE);
    }

    /**
     * {@inheritdoc}
     */
    public function setButtonStyle($buttonStyle): BannerInterface
    {
        return $this->setData(self::BUTTON_STYLE, $buttonStyle);
    }

    /**
     * {@inheritdoc}
     */
    public function getShadowEffect(): ?string
    {
        return $this->getData(self::SHADOW_EFFECT);
    }

    /**
     * {@inheritdoc}
     */
    public function setShadowEffect($shadowEffect): BannerInterface
    {
        return $this->setData(self::SHADOW_EFFECT, $shadowEffect);
    }

    /**
     * {@inheritdoc}
     */
    public function getButtonContentTheme(): ?string
    {
        return $this->getData(self::BUTTON_CONTENT_THEME);
    }

    /**
     * {@inheritdoc}
     */
    public function setButtonContentTheme($buttonContentTheme): BannerInterface
    {
        return $this->setData(self::BUTTON_CONTENT_THEME, $buttonContentTheme);
    }

    /**
     * {@inheritdoc}
     */
    public function getContentCustomColor(): ?string
    {
        return $this->getData(self::CONTENT_CUSTOM_COLOR);
    }

    /**
     * {@inheritdoc}
     */
    public function setContentCustomColor($customColor): BannerInterface
    {
        return $this->setData(self::CONTENT_CUSTOM_COLOR, $customColor);
    }

    /**
     * {@inheritdoc}
     */
    public function getButtonTextColor(): ?string
    {
        return $this->getData(self::BUTTONTEXT_CUSTOM_COLOR);
    }

    /**
     * {@inheritdoc}
     */
    public function setButtonTextColor($customButtonTextColor): BannerInterface
    {
        return $this->setData(self::BUTTONTEXT_CUSTOM_COLOR, $customButtonTextColor);
    }

    /**
     * {@inheritdoc}
     */
    public function getButtonCtaHover(): ?string
    {
        return $this->getData(self::BUTTON_CTA_HOVER);
    }

    /**
     * {@inheritdoc}
     */
    public function setButtonCtaHover($buttonCtaHover): BannerInterface
    {
        return $this->setData(self::BUTTON_CTA_HOVER, $buttonCtaHover);
    }

    /**
     * {@inheritdoc}
     */
    public function getButtonBackgroundColor(): ?string
    {
        return $this->getData(self::BUTTON_BACKGROUND_COLOR);
    }

    /**
     * {@inheritdoc}
     */
    public function setButtonBackgroundColor($buttonBackgroundColor): BannerInterface
    {
        return $this->setData(self::BUTTON_BACKGROUND_COLOR, $buttonBackgroundColor);
    }

    /**
     * {@inheritdoc}
     */
    public function getContentAlignMobile(): ?string
    {
        return $this->getData(self::CONTENT_ALIGN_MOBILE);
    }

    /**
     * {@inheritdoc}
     */
    public function setContentAlignMobile($contentAlignMobile): BannerInterface
    {
        return $this->setData(self::CONTENT_ALIGN_MOBILE, $contentAlignMobile);
    }

    /**
     * {@inheritdoc}
     */
    public function getContentAlignDesktop(): ?string
    {
        return $this->getData(self::CONTENT_ALIGN_DESKTOP);
    }

    /**
     * {@inheritdoc}
     */
    public function setContentAlignDesktop($contentAlignDesktop): BannerInterface
    {
        return $this->setData(self::CONTENT_ALIGN_DESKTOP, $contentAlignDesktop);
    }

    /**
     * {@inheritdoc}
     */
    public function getTextAlignMobile(): ?string
    {
        return $this->getData(self::TEXT_ALIGN_MOBILE);
    }

    /**
     * {@inheritdoc}
     */
    public function setTextAlignMobile($textAlignMobile): BannerInterface
    {
        return $this->setData(self::TEXT_ALIGN_MOBILE, $textAlignMobile);
    }

    /**
     * {@inheritdoc}
     */
    public function getTextAlignDesktop(): ?string
    {
        return $this->getData(self::TEXT_ALIGN_DESKTOP);
    }

    /**
     * {@inheritdoc}
     */
    public function setTextAlignDesktop($textAlignDesktop): BannerInterface
    {
        return $this->setData(self::TEXT_ALIGN_DESKTOP, $textAlignDesktop);
    }

    /**
     * {@inheritdoc}
     */
    public function getDescriptionPosition(): ?string
    {
        return $this->getData(self::DESCRIPTION_POSITION);
    }

    /**
     * {@inheritdoc}
     */
    public function setDescriptionPosition($descriptionPosition): BannerInterface
    {
        return $this->setData(self::DESCRIPTION_POSITION, $descriptionPosition);
    }

    /**
     * {@inheritdoc}
     */
    public function getBannerLinkUrl(): ?string
    {
        return $this->getData(self::BANNER_LINK_URL);
    }

    /**
     * {@inheritdoc}
     */
    public function setBannerLinkUrl($bannerLinkUrl): BannerInterface
    {
        return $this->setData(self::BANNER_LINK_URL, $bannerLinkUrl);
    }

    /**
     * {@inheritdoc}
     */
    public function getBannerLinkUrlNewWindow(): ?string
    {
        return $this->getData(self::BANNER_LINK_URL_NEW_WINDOW);
    }

    /**
     * {@inheritdoc}
     */
    public function setBannerLinkUrlNewWindow($bannerLinkUrlNewWindow): BannerInterface
    {
        return $this->setData(self::BANNER_LINK_URL_NEW_WINDOW, $bannerLinkUrlNewWindow);
    }

    /**
     * {@inheritdoc}
     */
    public function getImageUrl(string $imageString = self::DESKTOP_IMAGE, ?int $height = null, ?int $width = null): ?string
    {
        if ($imageString != self::DESKTOP_IMAGE && $imageString != self::MOBILE_IMAGE) {
            return null;
        }

        if ($this->getData($imageString) === null) {
            return null;
        }

        try {
            $imageUploader = $this->uploaderPool->getUploader('image');
        } catch (\Exception $e) {
            return null;
        }

        return $imageUploader->getBaseUrl(). $imageUploader->getBasePath() . $this->getData($imageString);
    }

    public function setDescriptionTextColor(string $descriptionTextColor): BannerInterface
    {
        return $this->setData('description_text_color', $descriptionTextColor);
    }

    public function getDescriptionTextColor(): ?string
    {
        return $this->getData('description_text_color');
    }

    public function setDescriptionBackgroundColor(string $descriptionBackgroundColor): BannerInterface
    {
        return $this->setData('description_background_color', $descriptionBackgroundColor);
    }

    public function getDescriptionBackgroundColor(): ?string
    {
        return $this->getData('description_background_color');
    }
}
