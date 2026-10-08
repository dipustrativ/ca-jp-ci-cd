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

namespace CarpetAvenue\FeedExport\Model\Export;

use DOMDocument;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use SimpleXMLElement;
use XSLTProcessor;

/**
 * Applies a profile template to the intermediate XML.
 *
 * Template format (Xtento compatible):
 * <files>
 *   <file filename="feed_%Y%%m%%d%.xml" encoding="" search="," replace=" " quotes="" addUtf8Bom="0">
 *     <xsl:stylesheet ...>...</xsl:stylesheet>
 *   </file>
 * </files>
 */
class XslRenderer
{
    /**
     * PHP functions callable from templates through php:function / php:functionString.
     * Kept to pure string/number helpers: no file, exec or include capable functions.
     */
    private const ALLOWED_PHP_FUNCTIONS = [
        'abs', 'addslashes', 'basename', 'ceil', 'chr', 'ctype_digit', 'date', 'floatval', 'floor',
        'html_entity_decode', 'htmlspecialchars', 'htmlspecialchars_decode', 'iconv', 'intval', 'json_encode',
        'ltrim', 'max', 'mb_convert_case', 'mb_strtoupper', 'mb_substr', 'md5', 'mktime', 'nl2br',
        'number_format', 'preg_match', 'preg_replace', 'rawurlencode', 'round', 'rtrim', 'sprintf', 'str_pad',
        'str_repeat', 'str_replace', 'strcspn', 'strip_tags', 'stripos', 'strlen', 'strpos', 'strrchr',
        'strripos', 'strrpos', 'strtolower', 'strtotime', 'strtoupper', 'substr', 'time', 'trim', 'ucfirst',
        'ucwords', 'uniqid',
    ];

    private const BLOCKED_EXTENSIONS = ['.php', '.phtml', '.phar', '.htaccess'];

    /** @var string[] */
    private array $searchCharacters = [];

    /** @var string[] */
    private array $replaceCharacters = [];

    public function __construct(
        private readonly TimezoneInterface $timezone,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly Escaper $escaper
    ) {
    }

    /**
     * Validate template structure without data (used on profile save).
     *
     * @throws LocalizedException
     */
    public function validate(string $template): void
    {
        foreach ($this->getFileDefinitions($template) as $definition) {
            $this->loadStylesheet($definition['stylesheet']);
        }
    }

    /**
     * @param array<string, string|int> $variables collectioncount, exportid, rootCategoryId
     * @return array<string, string> filename => content
     * @throws LocalizedException
     */
    public function render(string $template, string $xml, array $variables = []): array
    {
        if (!class_exists(XSLTProcessor::class)) {
            throw new LocalizedException(__('PHP XSL extension is missing.'));
        }
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $output = [];
            foreach ($this->getFileDefinitions($template) as $definition) {
                $sourceXml = $this->applyCharacterReplacement($xml, $definition);
                $document = new DOMDocument();
                if (!$document->loadXML($sourceXml, LIBXML_PARSEHUGE | LIBXML_NONET)) {
                    $this->throwXmlException(__('Could not load the generated product XML.'));
                }

                $processor = new XSLTProcessor();
                $processor->registerPHPFunctions(self::ALLOWED_PHP_FUNCTIONS);
                $processor->importStylesheet($this->loadStylesheet($definition['stylesheet']));
                $this->addParameters($processor, $definition['stylesheet'], $variables);

                $result = $processor->transformToXml($document);
                if ($result === false || $result === null) {
                    $this->throwXmlException(__('XSL transformation failed.'));
                }
                $result = $this->changeEncoding((string)$result, $definition['encoding']);
                if ($definition['bom']) {
                    $result = "\xEF\xBB\xBF" . $result;
                }
                $output[$this->buildFilename($definition['filename'], $variables)] = $result;
            }
            return $output;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    /**
     * @return array<int, array{filename: string, encoding: string, search: string, replace: string,
     *     quotes: string, bom: bool, stylesheet: string}>
     * @throws LocalizedException
     */
    private function getFileDefinitions(string $template): array
    {
        if (trim($template) === '') {
            throw new LocalizedException(__('The profile has no XSL template.'));
        }
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $root = new SimpleXMLElement($template, LIBXML_NONET);
        } catch (\Exception $e) {
            $this->throwXmlException(__('The XSL template is not valid XML.'));
        } finally {
            libxml_use_internal_errors($previousErrors);
        }

        $definitions = [];
        foreach ($root->xpath('//files/file') ?: [] as $file) {
            $attributes = $file->attributes();
            $stylesheet = current($file->xpath('*') ?: []);
            if (!$stylesheet) {
                throw new LocalizedException(__('<file> node without xsl:stylesheet found in XSL template.'));
            }
            $filename = trim((string)($attributes['filename'] ?? ''));
            if ($filename === '') {
                throw new LocalizedException(__('Every <file> node needs a filename attribute.'));
            }
            $definitions[] = [
                'filename' => $filename,
                'encoding' => (string)($attributes['encoding'] ?? ''),
                'search' => (string)($attributes['search'] ?? ''),
                'replace' => (string)($attributes['replace'] ?? ''),
                'quotes' => (string)($attributes['quotes'] ?? ''),
                'bom' => (string)($attributes['addUtf8Bom'] ?? '') === '1',
                // Old templates iterate products/product; the intermediate XML uses objects/object.
                'stylesheet' => str_replace(
                    'select="products/product"',
                    'select="objects/object"',
                    (string)$stylesheet->asXML()
                ),
            ];
        }
        if (!$definitions) {
            throw new LocalizedException(__('No <files><file>...</file></files> markup found in XSL template.'));
        }
        return $definitions;
    }

    /**
     * @throws LocalizedException
     */
    private function loadStylesheet(string $stylesheet): DOMDocument
    {
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            if (!$document->loadXML($stylesheet, LIBXML_NONET)) {
                $this->throwXmlException(__('Could not parse xsl:stylesheet.'));
            }
            $processor = new XSLTProcessor();
            $processor->registerPHPFunctions(self::ALLOWED_PHP_FUNCTIONS);
            try {
                $imported = $processor->importStylesheet($document);
            } catch (\Throwable $e) {
                $imported = false;
            }
            if (!$imported) {
                $this->throwXmlException(__('Invalid xsl:stylesheet.'));
            }
            return $document;
        } finally {
            libxml_use_internal_errors($previousErrors);
        }
    }

    /**
     * Template level "search/replace" and "quotes" attributes act on node text of the source XML.
     *
     * @param array{search: string, replace: string, quotes: string} $definition
     */
    private function applyCharacterReplacement(string $xml, array $definition): string
    {
        if ($definition['search'] !== '') {
            $search = str_split(str_replace('quote', '"', $definition['search']));
            $replace = str_split($definition['replace']);
            $this->searchCharacters = [];
            $this->replaceCharacters = [];
            foreach ($search as $index => $character) {
                $replacement = $replace[$index] ?? ($replace ? end($replace) : '');
                $this->searchCharacters[] = $character === '"' ? '&quot;' : $character;
                $this->replaceCharacters[] = $replacement;
                if ($character === '"') {
                    $this->searchCharacters[] = '"';
                    $this->replaceCharacters[] = $replacement;
                }
            }
            $xml = (string)preg_replace_callback(
                '/>([^<>]+)</u',
                fn(array $m): string => '>' . str_replace($this->searchCharacters, $this->replaceCharacters, $m[1]) . '<',
                $xml
            );
        }
        if ($definition['quotes'] !== '') {
            $replacement = match ($definition['quotes']) {
                'double' => '&quot;&quot;',
                'remove' => '',
                default => $this->escaper->escapeHtml($definition['quotes']),
            };
            $xml = str_replace('&quot;', $replacement, $xml);
        }
        return $xml;
    }

    /**
     * @param array<string, string|int> $variables
     */
    private function addParameters(XSLTProcessor $processor, string $stylesheet, array $variables): void
    {
        $parameters = [
            'timestamp' => $this->timezone->scopeTimeStamp(),
            'collectioncount' => $variables['collectioncount'] ?? 0,
            'exportid' => $variables['exportid'] ?? 0,
            'rootCategoryId' => $variables['rootCategoryId'] ?? 0,
            'guid' => $this->identityGenerator->generateId(),
        ];
        foreach ($parameters as $name => $value) {
            if (str_contains($stylesheet, '$' . $name)) {
                $processor->setParameter('', $name, (string)$value);
            }
        }
    }

    /**
     * @param array<string, string|int> $variables
     */
    private function buildFilename(string $pattern, array $variables): string
    {
        $now = $this->timezone->date();
        $filename = strtr(str_replace('|', '-', $pattern), [
            '%d%' => $now->format('d'),
            '%m%' => $now->format('m'),
            '%y%' => $now->format('y'),
            '%Y%' => $now->format('Y'),
            '%h%' => $now->format('H'),
            '%i%' => $now->format('i'),
            '%s%' => $now->format('s'),
            '%timestamp%' => (string)$this->timezone->scopeTimeStamp(),
            '%collectioncount%' => (string)($variables['collectioncount'] ?? 0),
            '%exportid%' => (string)($variables['exportid'] ?? 0),
            '%uuid%' => uniqid(),
        ]);
        // Destination is chosen by the profile; never let a template climb out of it.
        $filename = (string)substr((string)strrchr('/' . str_replace('\\', '/', $filename), '/'), 1);
        foreach (self::BLOCKED_EXTENSIONS as $extension) {
            while (str_ends_with(strtolower($filename), $extension)) {
                $filename = substr($filename, 0, -strlen($extension)) . '.txt';
            }
        }
        return $filename;
    }

    /**
     * @throws LocalizedException
     */
    private function changeEncoding(string $content, string $encoding): string
    {
        if ($encoding === '' || strtoupper($encoding) === 'UTF-8') {
            return $content;
        }
        try {
            $converted = iconv('UTF-8', $encoding, $content);
        } catch (\Throwable $e) {
            $converted = false;
        }
        if ($converted === false) {
            throw new LocalizedException(
                __('Could not convert feed to encoding "%1". Try adding //IGNORE or //TRANSLIT.', $encoding)
            );
        }
        return $converted;
    }


    /**
     * @throws LocalizedException
     */
    private function throwXmlException(\Magento\Framework\Phrase $message): never
    {
        $details = [];
        foreach (libxml_get_errors() as $error) {
            $details[] = sprintf('Line %d: %s', $error->line, trim($error->message));
        }
        libxml_clear_errors();
        throw new LocalizedException(__('%1 %2', $message, implode(' | ', $details)));
    }
}
