<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Evalent\MegaMenu\Block\Html;


use CarpetAvenue\Core\Helper\SysConfig;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Tree\Node;
use Magento\Framework\Data\Tree\Node\Collection;
use Magento\Framework\Data\Tree\NodeFactory;
use Magento\Framework\Data\TreeFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Element\Template;
use Evalent\MegaMenu\Rewrite\Magento\Catalog\Plugin\Block\Topmenu as Plugin;
use Evalent\MegaMenu\Model\Cache\TopMenuCache;

/**
 * Html page top menu block
 *
 * @api
 * @since 100.0.2
 */
class Topmenu extends \Magento\Theme\Block\Html\Topmenu
{
    /**
     * @var Plugin
     */
    protected $plugin;

    /**
     * @var \Magento\Cms\Api\BlockRepositoryInterface
     */
    private $blockRepository;

    public function __construct(
        BlockRepositoryInterface $blockRepository,
        Template\Context $context,
        NodeFactory $nodeFactory,
        TreeFactory $treeFactory,
        Plugin $plugin,
        array $data = []
    ) {
        parent::__construct($context, $nodeFactory, $treeFactory, $data);
        $this->blockRepository = $blockRepository;
        $this->plugin = $plugin;
    }

    /**
     * Recursively generates top menu html from data that is specified in $menuTree
     *
     * @param Node $menuTree
     * @param string $childrenWrapClass
     * @param int $limit
     * @param array $colBrakes
     * @return string
     */
    protected function _getHtml(
        Node $menuTree,
             $childrenWrapClass,
             $limit,
        array $colBrakes = []
    ) {

        // Use object manager way to load CarpetAvenue\Core\Helper\SysConfig;
        $objectManager = ObjectManager::getInstance();
        $sysConfig = $objectManager->get(SysConfig::class);
        $additionalDataShow = (int) $sysConfig->getConfig("catalog/navigation/show_hide_additional_data");

        $html = '';

        $children = $menuTree->getChildren();
        $childLevel = $this->getChildLevel($menuTree->getLevel());
        $this->removeChildrenWithoutActiveParent($children, $childLevel);

        $counter = 1;
        $childrenCount = $children->count();

        $parentPositionClass = $menuTree->getPositionClass();
        $itemPositionClassPrefix = $parentPositionClass ? $parentPositionClass . '-' : 'nav-';
        //$thumbnailLazy = $this->getViewFileUrl('Magento_Theme::images/lazyload_thumb.webp');

        /** @var Node $child */
        foreach ($children as $child) {
            $child->setLevel($childLevel);
            $child->setIsFirst($counter === 1);
            $child->setIsLast($counter === $childrenCount);
            $child->setPositionClass($itemPositionClassPrefix . $counter);

            $outermostClassCode = '';
            $outermostClass = $menuTree->getOutermostClass();

            // Adds class(es) to outermost a-tag
            if ($childLevel === 0 && $outermostClass) {
                $outermostClassCode = ' class="' . $outermostClass . '" ';
                $this->setCurrentClass($child, $outermostClass);
            }

            if ($this->shouldAddNewColumn($colBrakes, $counter)) {
                $html .= '</ul></li><li class="column"><ul>';
            }

            // Initialize inline CSS variables
            $inlineCssLi = '';
            $inlineCssATag = '';

            $bgColor = $child->getBackgroundColor();
            $fgColor = $child->getForegroundColor();


            if (!empty($fgColor)) {
                $inlineCssATag = ' style="color: ' . $fgColor . ';"';
            } elseif (!empty($bgColor)) {
                $inlineCssATag = ' style=""';
            }

            if (!empty($bgColor)) {
                $inlineCssLi = ' style="background-color: ' . $bgColor . ';"';
            }

            $html .= '<li ' . $this->_getRenderedMenuItemAttributes($child) . $inlineCssLi . '>';

            // Get custom category thumbnail img
            $imgHtml = '';
            if ($childLevel !== 0 && !empty($child->getThumbnailImageUrl())) {
                $childImageUrl = $this->plugin->getImageUrl($child->getData('thumbnail_image_path'), 200, 200);
                // No srcset: both candidates resolved to the SAME file, tagged
                // `768w` and `1920w`, with no `sizes`. The browser then derives a
                // density of 1920/viewport and reports a shrunken intrinsic size
                // for it (a 262px image measured 150px at 1100px, 123px at 900px),
                // so the menu thumbnails changed size with the viewport for no
                // layout reason. One honest src, real intrinsic size.
                $imgHtml = '<div class="megamenu__child-image picture">'
                    .'<picture>'
                    .'<img src="'.$childImageUrl.'" alt="" loading="lazy">'
                    .'</picture>'
                    .'</div>';
            }

            $url = $child->getCustomUrl() ?? $child->getUrl();

            /*if (!$additionalDataShow && is_string($childrenWrapClass) && !empty($childrenWrapClass)) {
                $childrenWrapClass .= ' additional-data-non-visible';
            }*/

            $badgeHtml = '';
            if ($childLevel == 0 && $child->getData('megamenu_badge')) {
                $badgeName = $child->getData('megamenu_badge');
                $badgeColor = $child->getData('megamenu_badge_color');
                $badgeHtml = "<div class='megamenu_badge' style='background: $badgeColor !important;color: #fff;'>$badgeName</div>";
            }


            $html .= '<a href="' . $url . '" ' . $outermostClassCode . $inlineCssATag. '>' . $imgHtml . '<div class="megamenu__child-title">' . $this->escapeHtml(
                    $child->getName()
                ) . '</div></a>' . $this->_addSubMenu(
                    $child,
                    $childLevel,
                    $childrenWrapClass,
                    $limit
                ) .$badgeHtml. '</li>';
            $counter++;
        }

        $additionalDataShow = true;

        if ($additionalDataShow) {
            if ($childLevel == 1 && $menuTree->getCustomBlock() && $menuTree->getCustomBlock() != "" && $menuTree->getCustomBlock() != 0) {
                try {
                    $block = $this->blockRepository->getById($menuTree->getCustomBlock());
                    $html .= '<li class="megamenu--block '.$menuTree->getName().'">' . $block->getContent() . '</li>';
                } catch (LocalizedException $e) {
                }
            }
        }

        /*
        if ($additionalDataShow) {
            // Custom "Show all" button label, if any
            $customShowAllLabel = $menuTree->getShowallCustomLabel();
            $showAllLabel = $customShowAllLabel ?? __('Show all %1' ,$this->escapeHtml($menuTree->getName()));
            $liLevel1CategoryLink =  '<li class="category-all"><a href="' . $menuTree->getUrl() . '">' . $showAllLabel .'</a></li>';
            if ($childLevel == 1) {
                $html .= $liLevel1CategoryLink;
            }
        }
        */

        if (is_array($colBrakes) && !empty($colBrakes) && $limit) {
            $html = '<li class="column"><ul>' . $html . '</ul></li>';
        }


        return $html;
    }

    /**
     * Add sub menu HTML code for current menu item
     *
     * @param Node $child
     * @param string $childLevel
     * @param string $childrenWrapClass
     * @param int $limit
     * @return string HTML code
     */
    protected function _addSubMenu($child, $childLevel, $childrenWrapClass, $limit)
    {
        $html = '';
        if (!$child->hasChildren() && !$child->getCustomBlock()) {
            return $html;
        }

        $colStops = [];
        if ($childLevel == 0 && $limit) {
            $colStops = $this->_columnBrake($child->getChildren(), $limit);
        }

        $html .= '<ul class="level' . $childLevel . ' ' . $childrenWrapClass . '" style="display:none;">';
        $html .= $this->_getHtml($child, $childrenWrapClass, $limit, $colStops);
        $html .= '</ul>';

        return $html;
    }


    /**
     * Remove children from collection when the parent is not active
     *
     * @param Collection $children
     * @param int $childLevel
     * @return void
     */
    private function removeChildrenWithoutActiveParent(Collection $children, int $childLevel): void
    {
        /** @var Node $child */
        foreach ($children as $child) {
            if ($childLevel === 0 && $child->getData('is_parent_active') === false) {
                $children->delete($child);
            }
        }
    }

    /**
     * Retrieve child level based on parent level
     *
     * @param int $parentLevel
     *
     * @return int
     */
    private function getChildLevel($parentLevel): int
    {
        return $parentLevel === null ? 0 : $parentLevel + 1;
    }

    /**
     * Check if new column should be added.
     *
     * @param array $colBrakes
     * @param int $counter
     * @return bool
     */
    private function shouldAddNewColumn(array $colBrakes, int $counter): bool
    {
        return count($colBrakes) && $colBrakes[$counter]['colbrake'];
    }

    /**
     * Returns array of menu item's attributes
     *
     * @param Node $item
     * @return array
     */
    protected function _getMenuItemAttributes(Node $item)
    {
        if ($item->getDataByKey('additional_megamenu_data')){
            $additionalClasses = str_replace(',', ' ', $item->getDataByKey('additional_megamenu_data'));
        }
        else{
            $additionalClasses = '';
        }
        return ['class' => implode(' ', $this->_getMenuItemClasses($item)) . ' ' . $additionalClasses];
    }

    /**
     * Returns array of menu item's classes
     * NOTE: Removed active class not jiving with the cache
     *
     * @param Node $item
     * @return array
     */
    protected function _getMenuItemClasses(Node $item)
    {
        $classes = [
            'level' . $item->getLevel(),
            $item->getPositionClass(),
        ];

        if ($item->getIsCategory()) {
            $classes[] = 'category-item';
        }

        if ($item->getIsFirst()) {
            $classes[] = 'first';
        }

        if ($item->getIsActive()) {
            // $classes[] = 'active';
        } elseif ($item->getHasActive()) {
            // $classes[] = 'has-active';
        }

        if ($item->getIsLast()) {
            $classes[] = 'last';
        }

        if ($item->getClass()) {
            $classes[] = $item->getClass();
        }

        if ($item->hasChildren()) {
            $classes[] = 'parent';
        }

        return $classes;
    }

    public function getToMenuHtml()
    {
        $topMenuCache = ObjectManager::getInstance()->get(TopMenuCache::class);

        //-- check menu is in cache
        $cacheMenuHtml = $topMenuCache->get();
        if ($cacheMenuHtml) {
             return $cacheMenuHtml;
        }

        $columnsLimit = $this->getColumnsLimit() ?: 0;
        $html = $this->getHtml('level-top', 'submenu', $columnsLimit);

        //-- menu save into cache
        $topMenuCache->save($html);
        return $html;
    }
}
