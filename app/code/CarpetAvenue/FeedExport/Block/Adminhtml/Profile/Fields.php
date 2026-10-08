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

namespace CarpetAvenue\FeedExport\Block\Adminhtml\Profile;

use CarpetAvenue\FeedExport\Api\FeedExporterInterface;
use CarpetAvenue\FeedExport\Api\ProfileRepositoryInterface;
use DOMElement;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\LocalizedException;

/**
 * Every field of one product as the XSL template receives it (objects/object/...).
 */
class Fields extends Template
{
    private const MAX_VALUE_LENGTH = 300;

    private ?string $error = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profileRepository,
        private readonly FeedExporterInterface $feedExporter,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getTestId(): int
    {
        return (int)$this->getRequest()->getParam('test_id');
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getRawXmlUrl(): string
    {
        return $this->getUrl('*/*/fieldsXml', [
            'profile_id' => (int)$this->getRequest()->getParam('profile_id'),
            'test_id' => $this->getTestId(),
        ]);
    }

    /**
     * The <object> element of the test product, or null with getError() set.
     */
    public function getObject(): ?DOMElement
    {
        if (!$this->getTestId()) {
            $this->error = __(
                'No test ID specified. Enter a product ID next to the "Show fields available for export" button.'
            )->render();
            return null;
        }
        try {
            $xml = $this->feedExporter->getSourceXml(
                $this->profileRepository->getById((int)$this->getRequest()->getParam('profile_id')),
                [$this->getTestId()]
            );
        } catch (LocalizedException $e) {
            $this->error = $e->getMessage();
            return null;
        }
        $document = new \DOMDocument();
        $document->loadXML($xml, LIBXML_PARSEHUGE | LIBXML_NONET);
        $object = $document->getElementsByTagName('object')->item(0);
        return $object instanceof DOMElement ? $object : null;
    }

    /**
     * Nested list: elements with children collapse, leaves show their value; title = path to use in XSL.
     */
    public function renderElement(DOMElement $element, string $path = ''): string
    {
        $html = '<ul>';
        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $childPath = ltrim($path . '/' . $child->nodeName, '/');
            $title = $this->escapeHtmlAttr($childPath);
            $name = $this->escapeHtml($child->nodeName);
            if ($this->hasElementChildren($child)) {
                $html .= '<li><details><summary title="' . $title . '">' . $name . '</summary>'
                    . $this->renderElement($child, $childPath) . '</details></li>';
                continue;
            }
            $value = $child->textContent;
            if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_VALUE_LENGTH) . '…';
            }
            $html .= '<li><span class="feedexport-field-name" title="' . $title . '">' . $name . '</span>'
                . ' <span class="feedexport-field-value">' . $this->escapeHtml($value) . '</span></li>';
        }
        return $html . '</ul>';
    }

    private function hasElementChildren(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return true;
            }
        }
        return false;
    }
}
