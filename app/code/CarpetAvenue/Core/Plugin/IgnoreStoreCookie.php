<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Core\Plugin;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Api\StoreCookieManagerInterface;

/**
 * Resolve the store view from the URL only, never from the `store` cookie, so a
 * prefix-less URL always renders its website's default store view.
 *
 * Exempt: /stores/store/* — Magento\Quote\Plugin\UpdateQuoteStore reads the old
 * cookie there to move the visitor's quote after a switch.
 *
 * @see docs/Issues/ISSUE-004-fpc-store-vary-collapse-use-store-in-url.md
 */
class IgnoreStoreCookie
{
    private const STORE_CONTROLLER_PATH = '/stores/store/';

    /**
     * @param HttpRequest $request
     */
    public function __construct(private readonly HttpRequest $request)
    {
    }

    /**
     * @param StoreCookieManagerInterface $subject
     * @param string|null $result
     * @return string|null
     */
    public function afterGetStoreCodeFromCookie(
        StoreCookieManagerInterface $subject,
        ?string $result
    ): ?string {
        $path = (string)strtok((string)$this->request->getRequestUri(), '?');

        return str_contains($path, self::STORE_CONTROLLER_PATH) ? $result : null;
    }
}
