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

namespace CarpetAvenue\FeedExport\Controller\Adminhtml\Profile;

use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;
use CarpetAvenue\FeedExport\Api\ProfileRepositoryInterface;
use CarpetAvenue\FeedExport\Model\ProfileFactory;
use CarpetAvenue\FeedExport\Model\ProfileValidator;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_FeedExport::profile';

    /** Form fields stored as comma separated lists. */
    private const LIST_FIELDS = [
        ProfileInterface::EXCLUDED_PRODUCT_TYPES,
        ProfileInterface::PRODUCT_VISIBILITY,
    ];

    private const EDITABLE_FIELDS = [
        ProfileInterface::NAME,
        ProfileInterface::ENABLED,
        ProfileInterface::STORE_ID,
        ProfileInterface::CUSTOMER_GROUP_ID,
        ProfileInterface::EXCLUDED_PRODUCT_TYPES,
        ProfileInterface::PRODUCT_STATUS,
        ProfileInterface::PRODUCT_VISIBILITY,
        ProfileInterface::INSTOCK_ONLY,
        ProfileInterface::SKU_FILTER,
        ProfileInterface::REPLACE_NEWLINES,
        ProfileInterface::STRIP_TAGS,
        ProfileInterface::URL_REMOVE_STORE,
        ProfileInterface::REMOVE_PUB_FROM_URLS,
        ProfileInterface::XSL_TEMPLATE,
        ProfileInterface::CATEGORY_MAPPING,
        ProfileInterface::TAXONOMY_SOURCE,
        ProfileInterface::DESTINATION_PATH,
        ProfileInterface::CRON_ENABLED,
        ProfileInterface::CRON_EXPRESSION,
    ];

    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profileRepository,
        private readonly ProfileFactory $profileFactory,
        private readonly ProfileValidator $profileValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();
        if (!$data) {
            return $redirect->setPath('*/*/index');
        }

        $profileId = (int)($data[ProfileInterface::PROFILE_ID] ?? 0);
        try {
            $profile = $profileId ? $this->profileRepository->getById($profileId) : $this->profileFactory->create();
            foreach (self::EDITABLE_FIELDS as $field) {
                if (!array_key_exists($field, $data)) {
                    // Unselected multiselects are not posted at all.
                    if (in_array($field, self::LIST_FIELDS, true)) {
                        $profile->setData($field, '');
                    }
                    continue;
                }
                $value = $data[$field];
                if (in_array($field, self::LIST_FIELDS, true)) {
                    $value = implode(',', array_filter((array)$value, 'strlen'));
                }
                $profile->setData($field, is_string($value) ? trim($value) : $value);
            }

            $this->profileValidator->validate($profile);
            $this->profileRepository->save($profile);
            $this->messageManager->addSuccessMessage(__('Feed profile saved.'));
            $this->_getSession()->setFormData(false);

            if ($this->getRequest()->getParam('back')) {
                return $redirect->setPath('*/*/edit', ['profile_id' => $profile->getProfileId()]);
            }
            return $redirect->setPath('*/*/index');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Something went wrong while saving the feed profile.'));
        }

        $this->_getSession()->setFormData($data);
        return $profileId
            ? $redirect->setPath('*/*/edit', ['profile_id' => $profileId])
            : $redirect->setPath('*/*/new');
    }
}
