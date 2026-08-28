<?php

declare(strict_types=1);

namespace Evalent\Banner\Ui\Dataprovider\Form\Modifier;

use Evalent\Banner\Model\Attribute\ScopeOverriddenValue;
use Evalent\Banner\Model\Banner;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;

class Eav extends AbstractModifier
{

    private $attributeValues;

    private $banner;

    /**
     * @var \Magento\Framework\Registry
     */
    private $registry;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;

    /**
     * @var \Evalent\Banner\Model\Attribute\ScopeOverriddenValue
     */
    private $scopeOverriddenValue;

    public function __construct(
        Registry $registry,
        RequestInterface $request,
        ScopeOverriddenValue $scopeOverriddenValue
    ) {
        $this->registry = $registry;
        $this->request = $request;
        $this->scopeOverriddenValue = $scopeOverriddenValue;
    }


    public function modifyData(array $data)
    {
        return $data;
    }

    /**
     * @inheritdoc
     * @since 101.0.0
     */
    public function modifyMeta(array $meta)
    {
        if (!$this->getCurrentStore()) {
            return $meta;
        }
        $meta = [
            "general" => [
                "children" => [
                    "enabled" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("enabled")
                                ],
                            ],
                        ],
                    ],
                    "date_start" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("date_start")
                                ],
                            ],
                        ],
                    ],
                    "date_end" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("date_end")
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            "content" => [
                "children" => [
                    "heading" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("heading")
                                ],
                            ],
                        ],
                    ],
                    "heading_secondary" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("heading_secondary")
                                ],
                            ],
                        ],
                    ],
                    "description" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("description")
                                ],
                            ],
                        ],
                    ],
                    "button_text" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("button_text")
                                ],
                            ],
                        ],
                    ],
                    "button_style" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("button_style")
                                ],
                            ],
                        ],
                    ],
                    "button_content_theme" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("button_content_theme")
                                ],
                            ],
                        ],
                    ],
                    "heading_tag" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("heading_tag")
                                ],
                            ],
                        ],
                    ],
                    "description_position" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("description_position")
                                ],
                            ],
                        ],
                    ],
                    "desktop" => [
                        "children" => [
                            "desktop_image" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("desktop_image")
                                        ],
                                    ],
                                ],
                            ],
                            "button_enabled_desktop" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("button_enabled_desktop")
                                        ],
                                    ],
                                ],
                            ],
                            "content_align_desktop" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("content_align_desktop")
                                        ],
                                    ],
                                ],
                            ],
                            "text_align_desktop" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("text_align_desktop")
                                        ],
                                    ],
                                ],
                            ],
                            "heading_size_desktop" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("heading_size_desktop")
                                        ],
                                    ],
                                ],
                            ],
                            "heading_secondary_size_desktop" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("heading_secondary_size_desktop")
                                        ],
                                    ],
                                ],
                            ],
                            "description_size_desktop" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("description_size_desktop")
                                        ],
                                    ],
                                ],
                            ],
                            "button_size_desktop" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("button_size_desktop")
                                        ],
                                    ],
                                ],
                            ]
                        ]
                    ],
                    "mobile" => [
                        "children" => [
                            "mobile_image" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("mobile_image")
                                        ],
                                    ],
                                ],
                            ],
                            "button_enabled_mobile" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("button_enabled_mobile")
                                        ],
                                    ],
                                ],
                            ],
                            "content_align_mobile" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("content_align_mobile")
                                        ],
                                    ],
                                ],
                            ],
                            "text_align_mobile" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("text_align_mobile")
                                        ],
                                    ],
                                ],
                            ],
                            "heading_size_mobile" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("heading_size_mobile")
                                        ],
                                    ],
                                ],
                            ],
                            "heading_secondary_size_mobile" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("heading_secondary_size_mobile")
                                        ],
                                    ],
                                ],
                            ],
                            "description_size_mobile" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("description_size_mobile")
                                        ],
                                    ],
                                ],
                            ],
                            "button_size_mobile" => [
                                "arguments" => [
                                    "data" => [
                                        "config" => [
                                            'service' => [
                                                "template" => "ui/form/element/helper/service"
                                            ],
                                            "disabled" => !$this->hasStoreSpecificValue("button_size_mobile")
                                        ],
                                    ],
                                ],
                            ]
                        ]
                    ],
                ],
            ],
            "banner_link" => [
                "children" => [
                    "banner_link_url" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("banner_link_url")
                                ],
                            ],
                        ],
                    ],
                    "banner_link_url_new_window" => [
                        "arguments" => [
                            "data" => [
                                "config" => [
                                    'service' => [
                                        "template" => "ui/form/element/helper/service"
                                    ],
                                    "disabled" => !$this->hasStoreSpecificValue("banner_link_url_new_window")
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        return $meta;
    }

    public function hasStoreSpecificValue($attributeCode)
    {
        return $this->scopeOverriddenValue->containsValue(\Evalent\Banner\Api\Data\BannerInterface::class, $this->getCurrentBanner(), $attributeCode, $this->getCurrentStore());
    }


    public function getCurrentStore()
    {
        return $this->request->getParam('store');
    }


    public function getCurrentBanner()
    {
        if ($this->banner == null) {
            $this->banner = $this->registry->registry('evalent_banner_banner_entity');
        }
        return $this->banner;
    }
}
