<?php
declare(strict_types=1);

namespace CarpetAvenue\Widget\Model;

class Widget
{
    protected $backendData;

    public function __construct(
        \Magento\Backend\Helper\Data $backendData
    ) {
        $this->backendData = $backendData;
    }

    /**
     * Fixes the unescaped "}}" bug in widgets' media url shorthand
     */
    public function beforeGetWidgetDeclaration(
        \Magento\Widget\Model\Widget $subject,
        $type,
        $params = [],
        $asIs = true
    ) {
        foreach ($params as $name => $value) {
            if (is_array($value)) {
                continue;
            }
            if (preg_match('/(___directive\/)([a-zA-Z0-9,_-]+)/', $value, $matches)) {
                $directive = base64_decode(strtr($matches[2], '-_,', '+/='));
                $params[$name] = str_replace(['{{media url="', '"}}'], ['', ''], $directive);
            }
        }
        return [$type, $params, $asIs];
    }
}
