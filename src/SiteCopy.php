<?php
/**
 * @link      https://novu.ch
 * @copyright Copyright (c) Novu
 */

namespace teamnovu\sitecopy;

use craft\base\Element;
use craft\base\Plugin;

use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\commerce\elements\Product;
use craft\events\DefineHtmlEvent;
use craft\events\ElementEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementActionsEvent;
use craft\services\Elements;
use craft\web\twig\variables\CraftVariable;
use Exception;
use teamnovu\sitecopy\assetbundles\sitecopy\BulkCopyAsset;
use teamnovu\sitecopy\elements\actions\BulkCopy;
use teamnovu\sitecopy\elements\actions\BulkCopyClientAction;
use teamnovu\sitecopy\mcp\SiteCopyTools;
use teamnovu\sitecopy\models\SettingsModel;
use yii\base\Event;

/**
 * @author    Novu
 * @package   Site Copy X
 * @since     0.2.0
 *
 */
class SiteCopy extends Plugin
{
    public string $schemaVersion = '1.0.2';
    public bool $hasCpSettings = true;

    public function init()
    {
        parent::init();

        $this->setComponents(
            [
                'sitecopy' => services\SiteCopy::class,
            ]
        );

        // MCP requests are not CP requests (and `craft mcp/serve` is a console request),
        // so this must stay outside the CP-only block below.
        $this->registerMcpCapabilities();

        Craft::$app->onInit(function() {
            if (Craft::$app->getRequest()->getIsCpRequest()) {
                Event::on(
                    CraftVariable::class,
                    CraftVariable::EVENT_INIT,
                    function (Event $event) {
                        $variable = $event->sender;
                        $variable->set('sitecopy', services\SiteCopy::class);
                    }
                );

                Event::on(
                    Element::class,
                    Element::EVENT_DEFINE_SIDEBAR_HTML,
                    function (DefineHtmlEvent $event) {
                        $element = $event->sender;

                        if (self::isOneOf($element, [Entry::class, Asset::class, 'craft\commerce\elements\Product', Category::class])) {
                            $event->html .= $this->addSitecopyWidget($event->sender);
                        }
                    }
                );

                // Site count checked per handler: Craft 6 boots plugins before authenticating the user.
                if (Craft::$app->getIsMultiSite()) {
                    if (self::isCraft6()) {
                        $request = Craft::$app->getRequest();

                        // Inertia visits keep the scripts loaded by the first full page load.
                        if ($request->getIsGet() && !$request->getIsAjax()) {
                            Craft::$app->getView()->registerAssetBundle(BulkCopyAsset::class);
                        }
                    }

                    // Craft 6 loads native assets; the adapter's craft\elements\Asset is only a subclass.
                    $assetClass = class_exists('CraftCms\Cms\Asset\Elements\Asset')
                        ? 'CraftCms\Cms\Asset\Elements\Asset'
                        : Asset::class;

                    Event::on(
                        $assetClass,
                        Element::EVENT_REGISTER_ACTIONS,
                        function (RegisterElementActionsEvent $event) {
                            if (self::hasMultipleEditableSites()) {
                                $event->actions[] = self::bulkCopyAction();
                            }
                        }
                    );

                    Event::on(
                        Product::class,
                        Element::EVENT_REGISTER_ACTIONS,
                        function (RegisterElementActionsEvent $event) {
                            if (
                                self::hasMultipleEditableSites()
                                && (str_contains($event->source, 'productType:') || str_contains($event->source, 'custom:'))
                            ) {
                                $event->actions[] = self::bulkCopyAction();
                            }
                        }
                    );

                    Event::on(
                        Category::class,
                        Element::EVENT_REGISTER_ACTIONS,
                        function (RegisterElementActionsEvent $event) {
                            if (self::hasMultipleEditableSites()) {
                                $event->actions[] = self::bulkCopyAction();
                            }
                        }
                    );

                    Event::on(
                        Entry::class,
                        Element::EVENT_REGISTER_ACTIONS,
                        function (RegisterElementActionsEvent $event) {
                            if (self::hasMultipleEditableSites() && strpos($event->source, 'section:') !== false) {
                                try {
                                    $sectionUid = explode('section:', $event->source)[1];
                                    // Craft 4 has getSectionByUid() on the sections service.
                                    $sections = method_exists(Craft::$app->getEntries(), 'getSectionByUid')
                                        ? Craft::$app->getEntries()
                                        : Craft::$app->getSections();
                                    $section = $sections->getSectionByUid($sectionUid);

                                    if ($section && $section->getHasMultiSiteEntries()) {
                                        $event->actions[] = self::isCraft6() ? BulkCopyClientAction::create() : BulkCopy::class;
                                    }
                                } catch (\Exception $e) {
                                    Craft::error($e->getMessage());
                                }
                            }
                        }
                    );
                }

                Craft::$app->view->hook(
                    'cp.globals.edit.content',
                    function (array &$context) {
                        /** @var $element GlobalSet */
                        $element = $context['globalSet'];

                        return $this->addSitecopyWidget($element);
                    }
                );

                Craft::$app->view->hook(
                    'cp.commerce.product.edit.details',
                    function (array &$context) {
                        return $this->addSitecopyWidget($context['product']);
                    }
                );

                Event::on(
                    Elements::class,
                    Elements::EVENT_AFTER_SAVE_ELEMENT,
                    function (ElementEvent $event) {
                        $this->sitecopy->syncElementContent($event, Craft::$app->request->post('sitecopy', []));
                    }
                );
            }
        });
    }

    private static function hasMultipleEditableSites(): bool
    {
        return Craft::$app->getSites()->getTotalEditableSites() > 1;
    }

    public static function isCraft6(): bool
    {
        return class_exists('CraftCms\Cms\Cms');
    }

    /**
     * @return BulkCopy|\CraftCms\Cms\Element\Actions\ClientAction
     */
    private static function bulkCopyAction()
    {
        return self::isCraft6() ? BulkCopyClientAction::create() : new BulkCopy();
    }

    // Craft 6 loads native element classes, which craft\elements\* only alias or subclass.
    public static function isOneOf(object $element, array $classes): bool
    {
        $nativeClasses = [
            Entry::class => 'CraftCms\Cms\Entry\Elements\Entry',
            Asset::class => 'CraftCms\Cms\Asset\Elements\Asset',
        ];

        foreach ($classes as $class) {
            if ($element instanceof $class) {
                return true;
            }

            if (isset($nativeClasses[$class]) && $element instanceof $nativeClasses[$class]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registers the Site Copy X tools with Craft's MCP server (Craft 5.12+ and Craft 6).
     *
     * On older Craft versions the MCP classes don't exist and nothing happens. The SDK
     * check covers future mcp/sdk releases that move the attribute classes.
     */
    private function registerMcpCapabilities(): void
    {
        if (!class_exists('Mcp\Capability\Attribute\McpTool')) {
            return;
        }

        if (class_exists('CraftCms\Cms\Mcp\CapabilityRegistry')) {
            app(\CraftCms\Cms\Mcp\CapabilityRegistry::class)->register(SiteCopyTools::class);

            return;
        }

        if (!class_exists('craft\services\Mcp')) {
            return;
        }

        Event::on(
            \craft\services\Mcp::class,
            \craft\services\Mcp::EVENT_REGISTER_CAPABILITY_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = SiteCopyTools::class;
            }
        );
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new SettingsModel();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('site-copy-x/_cp/settings', [
            'settings'                    => $this->getSettings(),
            'criteriaFieldOptionsEntries' => services\SiteCopy::getCriteriaFieldsEntries(),
            'criteriaFieldOptionsGlobals' => services\SiteCopy::getCriteriaFieldsGlobals(),
            'criteriaFieldOptionsAssets'  => services\SiteCopy::getCriteriaFieldsAssets(),
            'criteriaOperatorOptions'     => services\SiteCopy::getOperators(),
            'isCraft6'                    => self::isCraft6(),
        ]);
    }

    /**
     * @return string|void
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     */
    private function addSitecopyWidget(\craft\base\ElementInterface $element)
    {
        $isNew = $element->id === null;
        $sites = Craft::$app->getSites()->getAllSites();

        if ($isNew || count($sites) < 2) {
            return;
        }

        $scas = $this->sitecopy->handleSiteCopyActiveState($element);

        $siteCopyEnabled = $scas['siteCopyEnabled'];
        $selectedSites = $scas['selectedSites'];

        $currentSite = $element->siteId ?? null;

        // Build the per-field selection list when "fields" is in the configured attributes.
        $siteCopyFields = [];
        $siteCopyFieldHandles = [];
        if (in_array('fields', $this->sitecopy->getAttributesToCopy())) {
            $fieldLayout = $element->getFieldLayout();
            if ($fieldLayout) {
                foreach ($fieldLayout->getCustomFields() as $field) {
                    $siteCopyFields[] = ['value' => $field->handle, 'label' => Craft::t('site', $field->name)];
                    $siteCopyFieldHandles[] = $field->handle;
                }
            }
        }

        return Craft::$app->view->renderTemplate(
            'site-copy-x/_cp/elementsEdit',
            [
                'siteId'               => $element->siteId,
                'elementId'            => $element->id,
                'canonicalId'          => $element->getCanonicalId(),
                'supportedSites'       => $sites,
                'siteCopyEnabled'      => $siteCopyEnabled,
                'selectedSites'        => $selectedSites,
                'currentSite'          => $currentSite,
                'siteCopyFields'       => $siteCopyFields,
                'siteCopyFieldHandles' => $siteCopyFieldHandles,
            ]
        );
    }
}
