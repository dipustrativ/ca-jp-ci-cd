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

use CarpetAvenue\FeedExport\Model\Taxonomy;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Category mapping autocomplete: taxonomy rows containing the typed term.
 */
class SearchTaxonomy extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_FeedExport::profile';

    public function __construct(
        Context $context,
        private readonly Taxonomy $taxonomy
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        try {
            return $result->setData($this->taxonomy->search(
                (string)$this->getRequest()->getParam('source'),
                (string)$this->getRequest()->getParam('term')
            ));
        } catch (LocalizedException $e) {
            return $result->setData(['error' => $e->getMessage()]);
        }
    }
}
