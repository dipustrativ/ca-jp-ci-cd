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

namespace CarpetAvenue\Developify\ConfigScopeHints\Plugin\Framework\Data\Form\Element;

use CarpetAvenue\Developify\ConfigScopeHints\Helper\Data as ConfigScopeHintsHelper;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\Element\Fieldset as OriginalFieldset;

/**
 * Appends the scope-override hint to the comment of every store-config field that has one.
 */
class Fieldset
{
    /**
     * @param ConfigScopeHintsHelper $helper
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly ConfigScopeHintsHelper $helper,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * If the field is overridden at a narrower scope, append that info to its comment.
     *
     * @param OriginalFieldset $subject
     * @param string $elementId
     * @param string $type
     * @param array $config
     * @param bool $after
     * @param bool $isAdvanced
     * @return array
     * @phpstan-param array<string, mixed> $config
     * @phpstan-return array{0: string, 1: string, 2: array<string, mixed>, 3: bool, 4: bool}
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeAddField(
        OriginalFieldset $subject,
        $elementId,
        $type,
        $config,
        $after = false,
        $isAdvanced = false
    ) {
        if (!is_array($config)) {
            return [$elementId, $type, $config, $after, $isAdvanced];
        }

        $path = $this->getConfigPath($config);

        if ($path === null) {
            return [$elementId, $type, $config, $after, $isAdvanced];
        }

        /** @var scalar $scopeId already validated by getConfigPath() */
        $scopeId = $config['scope_id'];

        $overriddenLevels = $this->helper->getOverriddenLevels(
            $path,
            $this->toText($config['scope']),
            is_int($scopeId) ? $scopeId : (string)$scopeId
        );

        if ($overriddenLevels !== []) {
            $config['comment'] = $this->toText($config['comment'] ?? '')
                . $this->helper->formatOverriddenScopes($this->getSection(), $overriddenLevels);
        }

        return [$elementId, $type, $config, $after, $isAdvanced];
    }

    /**
     * Resolve the config structure path of a hintable field, or null when the field is not one.
     *
     * Only store-config fields resolve — every other admin form that uses a fieldset omits
     * `field_config`/`scope`.
     *
     * `showInWebsite`/`showInStore` are deliberately NOT consulted: a global-only field with a
     * stray scoped row in `core_config_data` still overrides the value the frontend reads, and
     * that is exactly the case an admin has no other way to spot.
     *
     * @param array $config
     * @return string|null
     * @phpstan-param array<string, mixed> $config
     */
    private function getConfigPath(array $config): ?string
    {
        $fieldConfig = $config['field_config'] ?? null;

        if (!is_array($fieldConfig)
            || !isset($fieldConfig['path'], $fieldConfig['id'], $config['scope'])
            || !array_key_exists('scope_id', $config)
            || !is_scalar($fieldConfig['path'])
            || !is_scalar($fieldConfig['id'])
            || !is_scalar($config['scope'])
            || !is_scalar($config['scope_id'])
        ) {
            return null;
        }

        return (string)$fieldConfig['path'] . '/' . (string)$fieldConfig['id'];
    }

    /**
     * The config section currently being edited.
     *
     * @return string
     */
    private function getSection(): string
    {
        return $this->toText($this->request->getParam('section', ''));
    }

    /**
     * Flatten a value that may be a string, a Phrase, or neither.
     *
     * @param mixed $value
     * @return string
     */
    private function toText(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? (string)$value : '';
    }
}
