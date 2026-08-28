<?php
/**
 * Generic Documentation Viewer Block.
 * Always reads {module_root}/README.md and splits it into Manual + Developer sections.
 *
 * URL: carpetavenue/doc/view?module=CarpetAvenue_Core[&tab=developer]
 *
 * @package   CarpetAvenue_Core
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Core\Block\Adminhtml\Doc;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Module\Dir as ModuleDir;

class View extends Template
{
    protected $_template = 'CarpetAvenue_Core::doc/viewer.phtml';

    private ?array $sections = null;   // ['manual' => string, 'developer' => string]

    public function __construct(
        Context                    $context,
        private readonly ModuleDir $moduleDir,
        array                      $data = [],
    ) {
        parent::__construct($context, $data);
    }

    // ── Public API ────────────────────────────────────────────────

    /** Raw markdown for the Manual tab (everything under ## Manual). */
    public function getManualContent(): ?string
    {
        return $this->getSections()['manual'] ?? null;
    }

    /** Raw markdown for the Developer tab (everything under ## Developer). */
    public function getDeveloperContent(): ?string
    {
        return $this->getSections()['developer'] ?? null;
    }

    /** Native file path shown in the viewer footer, e.g. app/code/CarpetAvenue/Core/README.md */
    public function getNativePath(): string
    {
        $module = $this->getModuleParam();
        return 'app/code/' . str_replace('_', '/', $module) . '/README.md';
    }

    /** Which tab should be active on load: 'manual' or 'developer'. */
    public function getActiveTab(): string
    {
        $tab = $this->getRequest()->getParam('tab', 'manual');
        return in_array($tab, ['manual', 'developer'], true) ? $tab : 'manual';
    }

    /** URL back to the documentation hub. */
    public function getHubUrl(): string
    {
        return $this->getUrl('carpetavenue/doc/index');
    }

    // ── Private helpers ───────────────────────────────────────────

    private function getModuleParam(): string
    {
        return (string)$this->getRequest()->getParam('module', '');
    }

    /** Read and split README.md into sections. Result is memoised. */
    private function getSections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $this->sections = [];

        $module = $this->getModuleParam();
        if (!preg_match('/^[A-Za-z0-9_]+$/', $module)) {
            return $this->sections;
        }

        try {
            $root = $this->moduleDir->getDir($module);
        } catch (\InvalidArgumentException) {
            return $this->sections;
        }

        $file = $root . '/README.md';
        if (!is_file($file)) {
            return $this->sections;
        }

        $content = file_get_contents($file) ?: '';

        // Split at top-level ## Manual and ## Developer headings (case-insensitive)
        preg_match('/^##\s+Manual\b(.+?)(?=^##\s+Developer\b|\z)/msi', $content, $m);
        preg_match('/^##\s+Developer\b(.+)/msi', $content, $d);

        $this->sections = [
            'manual'    => isset($m[1]) ? trim($m[1]) : null,
            'developer' => isset($d[1]) ? trim($d[1]) : null,
        ];

        return $this->sections;
    }
}
