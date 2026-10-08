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

namespace CarpetAvenue\FeedExport\Block\Adminhtml\Profile\Edit;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;

abstract class GenericButton
{
    public function __construct(
        protected readonly RequestInterface $request,
        protected readonly UrlInterface $urlBuilder,
        protected readonly AuthorizationInterface $authorization
    ) {
    }

    protected function getProfileId(): int
    {
        return (int)$this->request->getParam('profile_id');
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function getUrl(string $route, array $params = []): string
    {
        return $this->urlBuilder->getUrl($route, $params);
    }
}
