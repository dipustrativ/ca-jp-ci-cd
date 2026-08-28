<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Developify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 *
 * Derived from EW_ConfigScopeHints (ericthehacker/magento2-configscopehints).
 * See ConfigScopeHints/LICENSE.
 */

declare(strict_types=1);

namespace CarpetAvenue\Developify\ConfigScopeHints\Helper;

use Magento\Backend\Model\UrlInterface as BackendUrlInterface;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure\SearchInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;

/**
 * Builds the "this field is overridden at …" hint appended to store-config field comments.
 */
class Data extends AbstractHelper
{
    public const STORE_VIEW_SCOPE_CODE = ScopeInterface::SCOPE_STORES;
    public const WEBSITE_SCOPE_CODE = ScopeInterface::SCOPE_WEBSITES;
    public const DEFAULT_SCOPE_CODE = 'default';

    /**
     * Placeholder shown instead of the real value of an encrypted/obscured field.
     */
    private const OBSCURED_PLACEHOLDER = '******';

    /**
     * Scope tree, built once per request.
     *
     * @var array<string, array<int|string, array<string, list<int|string>>>>|null
     */
    private ?array $scopeTree = null;

    /**
     * Structure elements keyed by structure path, resolved once per request.
     *
     * @var array<string, mixed>
     */
    private array $elementCache = [];

    /**
     * @param Context $context
     * @param StoreManagerInterface $storeManager
     * @param BackendUrlInterface $urlBuilder Explicit — Context::getUrlBuilder() returns the frontend builder.
     * @param SearchInterface $configStructure
     * @param Escaper $escaper
     */
    public function __construct(
        Context $context,
        private readonly StoreManagerInterface $storeManager,
        private readonly BackendUrlInterface $urlBuilder,
        private readonly SearchInterface $configStructure,
        private readonly Escaper $escaper
    ) {
        parent::__construct($context);
    }

    /**
     * Get the store tree in a shape that is cheap to walk for config value comparison.
     *
     * @return array<string, array<int|string, array<string, list<int|string>>>>
     */
    public function getScopeTree(): array
    {
        if ($this->scopeTree !== null) {
            return $this->scopeTree;
        }

        $tree = [self::WEBSITE_SCOPE_CODE => []];

        /** @var Website $website */
        foreach ($this->storeManager->getWebsites() as $website) {
            $stores = [];

            /** @var Store $store */
            foreach ($website->getStores() as $store) {
                $stores[] = $store->getId();
            }

            $tree[self::WEBSITE_SCOPE_CODE][$website->getId()] = [self::STORE_VIEW_SCOPE_CODE => $stores];
        }

        $this->scopeTree = $tree;

        return $this->scopeTree;
    }

    /**
     * Read the config value at the given path/scope.
     *
     * Non-scalar and unset values collapse to '' so that "unset" and "empty" compare equal,
     * matching the loose comparison the upstream module relied on.
     *
     * @param string $path
     * @param string $contextScope
     * @param string|int $contextScopeId
     * @return string
     */
    private function getConfigValue(string $path, string $contextScope, string|int $contextScopeId): string
    {
        $value = $this->scopeConfig->getValue($path, $contextScope, $contextScopeId);

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Resolve — and memoise — the config structure element for a structure path.
     *
     * @param string $path
     * @return mixed
     */
    private function getStructureElement(string $path): mixed
    {
        if (!array_key_exists($path, $this->elementCache)) {
            $this->elementCache[$path] = $this->configStructure->getElement($path);
        }

        return $this->elementCache[$path];
    }

    /**
     * Whether the field at this path stores an encrypted/obscured value.
     *
     * @param string $path
     * @return bool
     */
    public function isObscured(string $path): bool
    {
        $field = $this->getStructureElement($path);

        if (!is_object($field) || !method_exists($field, 'getData')) {
            return false;
        }

        $data = $field->getData();

        if (($data['type'] ?? '') === 'obscure') {
            return true;
        }

        $backendModel = (string)($data['backend_model'] ?? '');

        return $backendModel !== '' && is_a(ltrim($backendModel, '\\'), Encrypted::class, true);
    }

    /**
     * Get human-friendly display value(s) for the given config path.
     *
     * @param string $path
     * @param string $contextScope
     * @param string|int $contextScopeId
     * @return list<string>
     */
    public function getConfigDisplayValue(string $path, string $contextScope, string|int $contextScopeId): array
    {
        if ($this->isObscured($path)) {
            return [self::OBSCURED_PLACEHOLDER];
        }

        $value = $this->getConfigValue($path, $contextScope, $contextScopeId);

        $field = $this->getStructureElement($path);
        $options = [];

        if (is_object($field) && method_exists($field, 'getOptions')) {
            try {
                $options = $field->getOptions();
            } catch (\Throwable $e) {
                // A broken source model must not take down the whole config page.
                $options = [];
            }
        }

        if (!is_array($options) || $options === []) {
            return [$value];
        }

        $optionsByValue = [];
        foreach ($options as $option) {
            if (is_array($option) && array_key_exists('value', $option)) {
                $optionsByValue[(string)$option['value']] = (string)($option['label'] ?? $option['value']);
            }
        }

        $labels = [];
        foreach (explode(',', $value) as $valueInstance) {
            $labels[] = $optionsByValue[$valueInstance] ?? $valueInstance;
        }

        return $labels;
    }

    /**
     * Get the scopes narrower than the context scope whose value differs from it.
     *
     * Returns an empty array when nothing below the context scope overrides the value.
     *
     * @param string $path
     * @param string $contextScope
     * @param string|int $contextScopeId
     * @return list<array{scope: string, scope_id: int|string, value: string, display_value: list<string>}>
     */
    public function getOverriddenLevels(string $path, string $contextScope, string|int $contextScopeId): array
    {
        // Store view is the narrowest scope — nothing can override it.
        if ($contextScope !== self::DEFAULT_SCOPE_CODE && $contextScope !== self::WEBSITE_SCOPE_CODE) {
            return [];
        }

        $tree = $this->getScopeTree();
        $currentValue = $this->getConfigValue($path, $contextScope, $contextScopeId);
        $overridden = [];

        if ($contextScope === self::WEBSITE_SCOPE_CODE) {
            $stores = $tree[self::WEBSITE_SCOPE_CODE][$contextScopeId][self::STORE_VIEW_SCOPE_CODE] ?? [];

            foreach ($stores as $storeId) {
                $value = $this->getConfigValue($path, self::STORE_VIEW_SCOPE_CODE, $storeId);
                if ($value !== $currentValue) {
                    $overridden[] = $this->buildOverride($path, 'store', $storeId, $value);
                }
            }

            return $overridden;
        }

        foreach ($tree[self::WEBSITE_SCOPE_CODE] as $websiteId => $website) {
            $websiteValue = $this->getConfigValue($path, self::WEBSITE_SCOPE_CODE, $websiteId);
            if ($websiteValue !== $currentValue) {
                $overridden[] = $this->buildOverride($path, 'website', $websiteId, $websiteValue);
            }

            foreach ($website[self::STORE_VIEW_SCOPE_CODE] as $storeId) {
                $value = $this->getConfigValue($path, self::STORE_VIEW_SCOPE_CODE, $storeId);
                if ($value !== $currentValue && $value !== $websiteValue) {
                    $overridden[] = $this->buildOverride($path, 'store', $storeId, $value);
                }
            }
        }

        return $overridden;
    }

    /**
     * Assemble one override row.
     *
     * @param string $path
     * @param string $scope
     * @param int|string $scopeId
     * @param string $value
     * @return array{scope: string, scope_id: int|string, value: string, display_value: list<string>}
     */
    private function buildOverride(string $path, string $scope, int|string $scopeId, string $value): array
    {
        $valueScope = $scope === 'website' ? self::WEBSITE_SCOPE_CODE : self::STORE_VIEW_SCOPE_CODE;
        $obscured = $this->isObscured($path);

        return [
            'scope' => $scope,
            'scope_id' => $scopeId,
            // Never carry a decrypted secret around — scopeConfig returns encrypted fields in clear.
            'value' => $obscured ? self::OBSCURED_PLACEHOLDER : $value,
            'display_value' => $this->getConfigDisplayValue($path, $valueScope, $scopeId),
        ];
    }

    /**
     * Render the value label(s) of a single override row.
     *
     * Inline elements only — this HTML lands inside the `<p class="note"><span>` the core
     * field renderer wraps the comment in, and a block-level tag there would break the `<p>`.
     *
     * @param list<string> $labels
     * @return string
     */
    private function getFormattedValueLabels(array $labels): string
    {
        if (count($labels) === 1) {
            return '<span class="override-value-hint-label">'
                . nl2br($this->escaper->escapeHtml(reset($labels)))
                . '</span>';
        }

        $formattedLabels = '';
        foreach ($labels as $label) {
            $formattedLabels .= '<span class="override-value-hint-label list">'
                . nl2br($this->escaper->escapeHtml($label))
                . '</span>';
        }

        return '<span class="override-value-hint-labels">' . $formattedLabels . '</span>';
    }

    /**
     * Build the scope label (with link) of a single override row.
     *
     * @param string $section
     * @param string $scope
     * @param int|string $scopeId
     * @return string
     */
    private function getScopeLabelHtml(string $section, string $scope, int|string $scopeId): string
    {
        try {
            if ($scope === 'website') {
                $url = $this->urlBuilder->getUrl('*/*/*', ['section' => $section, 'website' => $scopeId]);
                $kind = __('Website');
                $name = (string)$this->storeManager->getWebsite($scopeId)->getName();
            } else {
                $store = $this->storeManager->getStore($scopeId);
                $url = $this->urlBuilder->getUrl('*/*/*', ['section' => $section, 'store' => $store->getId()]);
                $website = $this->storeManager->getWebsite($store->getWebsiteId());
                $kind = __('Store view');
                $name = $website->getName() . ' / ' . $store->getName();
            }
        } catch (NoSuchEntityException | LocalizedException $e) {
            // Scope vanished between the config read and the render — fall back to the raw id.
            return '<span class="override-scope-kind">' . $this->escaper->escapeHtml($scope) . '</span>'
                . '<span class="override-scope-name">' . $this->escaper->escapeHtml((string)$scopeId) . '</span>';
        }

        return '<span class="override-scope-kind">' . $this->escaper->escapeHtml((string)$kind) . '</span>'
            . $this->link($url, $name);
    }

    /**
     * Build an escaped anchor.
     *
     * @param string $url
     * @param string $label
     * @return string
     */
    private function link(string $url, string $label): string
    {
        return '<a class="override-scope-name" href="' . $this->escaper->escapeUrl($url) . '">'
            . $this->escaper->escapeHtml($label)
            . '</a>';
    }

    /**
     * Get the HTML for the override hint UI.
     *
     * @param string $section
     * @param array $overridden
     * @return string
     * @phpstan-param list<array{scope: string, scope_id: int|string, value: string,
     *     display_value: list<string>}> $overridden
     */
    public function formatOverriddenScopes(string $section, array $overridden): string
    {
        $rows = '';

        foreach ($overridden as $overriddenScope) {
            $scope = (string)$overriddenScope['scope'];

            $rows .= '<span class="override-scope ' . $this->escaper->escapeHtmlAttr($scope) . '"'
                . ' role="button" tabindex="0" aria-expanded="false" title="'
                . $this->escaper->escapeHtmlAttr((string)__('Click to see overridden value')) . '">'
                . $this->getScopeLabelHtml($section, $scope, $overriddenScope['scope_id'])
                . '</span>'
                . '<span class="override-value">'
                . $this->getFormattedValueLabels($overriddenScope['display_value'])
                . '</span>';
        }

        return '<span class="overridden-hint-wrapper">'
            . '<span class="lead-text">'
            . $this->escaper->escapeHtml((string)__('This config field is overridden at the following scope(s):'))
            . '</span>'
            . '<span class="overridden-hint-list">' . $rows . '</span>'
            . '</span>';
    }
}
