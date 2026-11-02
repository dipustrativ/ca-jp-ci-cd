<?php

declare(strict_types=1);

namespace IXPF\Syncer\ViewModel\Reviews;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\ReviewLogger;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Turns /reviews?token=X&sku=Y&rating=N into the iframe URL for the hosted review form:
 * https://review.carpetavenue.com/r/X?sku=Y&rating=N&parent=<store origin>
 *
 * The value ends up in a src attribute, so two rules keep it safe: the host comes from
 * configuration and never from the request, and parameters are copied through an allowlist
 * rather than forwarded. Without either, a crafted link turns /reviews into an iframe host for
 * an arbitrary site.
 *
 * Framing itself is granted by the other side - the form must send a frame-ancestors listing our
 * origins and no X-Frame-Options. Nothing here can grant it, so a blank iframe is a question for
 * that response's headers, not for this class.
 */
class FormEmbed implements ArgumentInterface
{
    /** URL-safe base64, bounded both ends. Anything else is a tampered link. */
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{16,128}$/';

    /** Narrower than Magento allows: this is echoed into a URL, so nothing needing encoding. */
    private const SKU_PATTERN = '/^[A-Za-z0-9._\/-]{1,64}$/';

    private const PATH_PREFIX = '/r/';

    private ?string $token = null;
    private bool $tokenResolved = false;
    private ?string $baseUrl = null;
    private bool $baseUrlResolved = false;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly ReviewLogger $logger
    ) {
    }

    /**
     * The controller turns false into a 404: a bad or missing token is an invalid link, not a
     * fault the customer can act on.
     */
    public function isAvailable(): bool
    {
        return $this->config->isReviewFormEnabled()
            && $this->getToken() !== null
            && $this->getBaseUrl() !== null;
    }

    /**
     * `parent` lets the form target its height messages at one origin instead of broadcasting
     * them with '*'. It is a convenience for the form, not a trust signal - it is expected to
     * check the value against its own allowlist.
     */
    public function getFormUrl(): string
    {
        $baseUrl = $this->getBaseUrl();
        $token = $this->getToken();

        if ($baseUrl === null || $token === null) {
            return '';
        }

        $params = ['parent' => $this->getStoreOrigin()];

        $sku = $this->scalarParam('sku');
        if ($sku !== '' && preg_match(self::SKU_PATTERN, $sku)) {
            $params['sku'] = $sku;
        }

        $rating = (int)$this->scalarParam('rating');
        if ($rating >= 1 && $rating <= 5) {
            $params['rating'] = $rating;
        }

        return $baseUrl . self::PATH_PREFIX . rawurlencode($token) . '?' . http_build_query($params);
    }

    /**
     * Origin the height messages must come from, for the listener's event.origin check. The port
     * is part of an origin, so it has to survive here - without it a host on a non-standard port
     * fails the comparison and the iframe silently keeps its fallback height.
     */
    public function getExpectedOrigin(): string
    {
        $baseUrl = $this->getBaseUrl();

        return $baseUrl === null ? '' : $this->toOrigin($baseUrl);
    }

    /**
     * Configured host without a trailing slash, or null when unusable.
     *
     * Resolved once: three public methods need it, and a misconfiguration would otherwise log
     * the same line three times per request.
     *
     * https is required rather than preferred - the framing page is https, so a cleartext child
     * is blocked as mixed content anyway. Failing here turns a blank iframe into a named cause.
     */
    private function getBaseUrl(): ?string
    {
        if ($this->baseUrlResolved) {
            return $this->baseUrl;
        }
        $this->baseUrlResolved = true;

        $baseUrl = trim($this->config->getReviewFormBaseUrl());

        if ($baseUrl === '') {
            return null;
        }

        if (stripos($baseUrl, 'https://') !== 0) {
            // writeDaily() switches on Monolog's integer levels, so a string like 'error' would
            // silently log as info; the module's convention is an ERROR: prefix instead.
            $this->logger->writeDaily(
                sprintf('ERROR: review form base URL must be https, got "%s" - embed disabled.', $baseUrl)
            );

            return null;
        }

        $this->baseUrl = rtrim($baseUrl, '/');

        return $this->baseUrl;
    }

    private function getToken(): ?string
    {
        if (!$this->tokenResolved) {
            $this->tokenResolved = true;

            $token = $this->scalarParam('token');
            $this->token = preg_match(self::TOKEN_PATTERN, $token) ? $token : null;
        }

        return $this->token;
    }

    private function getStoreOrigin(): string
    {
        return $this->toOrigin(
            (string)$this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB, true)
        );
    }

    /** scheme://host[:port] - the exact shape a browser reports in event.origin. */
    private function toOrigin(string $url): string
    {
        $parts = parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** ?sku[]=x arrives as an array; casting that is an "Array to string conversion" 500. */
    private function scalarParam(string $key): string
    {
        $value = $this->request->getParam($key);

        return is_scalar($value) ? (string)$value : '';
    }
}
