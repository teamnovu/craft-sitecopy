<?php
/**
 * @link      https://novu.ch
 * @copyright Copyright (c) Novu
 */
namespace teamnovu\sitecopy\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\Model;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\events\ElementEvent;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\models\Site;
use Exception;
use teamnovu\sitecopy\jobs\SyncElementContent;
use teamnovu\sitecopy\models\SettingsModel;
use Throwable;

/**
 * Class SiteCopy
 *
 * @package teamnovu\sitecopy\services
 */
class SiteCopy extends Component
{
    /**
     * @var SettingsModel|null
     */
    private $settings = null;

    public function init(): void
    {
        parent::init();

        $this->settings = \teamnovu\sitecopy\SiteCopy::getInstance()->getSettings();
    }

    public static function getCriteriaFieldsEntries()
    {
        return [
            [
                'value' => 'id',
                'label' => Craft::t('site-copy-x', 'Entry id'),
            ],
            [
                'value' => 'type',
                'label' => Craft::t('site-copy-x', 'Entry type (handle)'),
            ],
            [
                'value' => 'section',
                'label' => Craft::t('site-copy-x', 'Section (handle)'),
            ],
            [
                'value' => 'site',
                'label' => Craft::t('site-copy-x', 'Site (handle)'),
            ],
        ];
    }

    public static function getCriteriaFieldsGlobals()
    {
        return [
            [
                'value' => 'id',
                'label' => Craft::t('site-copy-x', 'Global set id'),
            ],
            [
                'value' => 'handle',
                'label' => Craft::t('site-copy-x', 'Global set handle'),
            ],
            [
                'value' => 'site',
                'label' => Craft::t('site-copy-x', 'Site (handle)'),
            ],
        ];
    }

    public static function getCriteriaFieldsAssets()
    {
        return [
            [
                'value' => 'id',
                'label' => Craft::t('site-copy-x', 'Asset id'),
            ],
            [
                'value' => 'volume',
                'label' => Craft::t('site-copy-x', 'Volume (handle)'),
            ],
            [
                'value' => 'site',
                'label' => Craft::t('site-copy-x', 'Site (handle)'),
            ],
        ];
    }

    public static function getOperators()
    {
        return [
            [
                'value' => 'eq',
                'label' => Craft::t('site-copy-x', 'Equals'),
            ],
            [
                'value' => 'neq',
                'label' => Craft::t('site-copy-x', 'Does not equal'),
            ],
        ];
    }

    /**
     * Indicates if we are already syncing
     *
     * @var bool
     */
    private static $syncing = false;

    /**
     * Get list of sites to sync to.
     *
     * @param array $sites
     * @param array $exclude
     * @return array
     */
    public function getSiteInputOptions(array $sites = [], $exclude = [])
    {
        $sites = $sites ?: Craft::$app->getSites()->getAllSites();

        $sites = array_map(
            function ($site) use ($exclude) {
                if (!$site instanceof Site) {
                    $siteId = $site['siteId'] ?? $site ?? null;
                    if ($siteId !== null) {
                        $site = Craft::$app->sites->getSiteById($siteId);
                    }
                }

                if ($site instanceof Site && !in_array($site->id, $exclude)) {
                    $user = Craft::$app->getUser()->getIdentity();

                    if ($user->can('editsite:' . $site->uid)) {
                        $site = [
                            'label' => $site->name,
                            'value' => $site->id,
                            'groupId' => $site->groupId,
                            'inputAttributes' => ['onclick' => 'updateSitecopyToggleAll(this)'],
                        ];
                    } else {
                        $site = null;
                    }

                } else {
                    $site = null;
                }

                return $site;
            },
            $sites
        );

        $sites = array_filter($sites);

        usort($sites, function ($a, $b) {
            return $a['groupId'] - $b['groupId'];
        });

        if (count($sites) > 1) {
            array_unshift($sites, [
                'id' => 'sitecopy-toggle-all',
                'label' => Craft::t('site-copy-x', 'Select all'),
                'value' => '',
                'groupId' => null,
                'inputAttributes' => ['onclick' => 'toggleSitecopyTargets(this)'],
            ]);
        }

        return $sites;
    }

    /**
     * Get list of attributes to sync.
     *
     * @return array
     */
    public function getAttributesToCopyOptions()
    {
        return [
            [
                'value' => 'fields',
                'label' => Craft::t('site-copy-x', 'Fields (Content)'),
            ],
            [
                'value' => 'title',
                'label' => Craft::t('site-copy-x', 'Title'),
            ],
            [
                'value' => 'slug',
                'label' => Craft::t('site-copy-x', 'Slug'),
            ],
            [
                'value' => 'variants',
                'label' => Craft::t('site-copy-x', 'Commerce Variants'),
            ],
        ];
    }

    /**
     * @param ElementEvent $event
     * @param array        $elementSettings
     * @throws Throwable
     */
    public function syncElementContent(ElementEvent $event, array $elementSettings)
    {
        /** @var Entry|GlobalSet $entry */
        // This is not necessarily our localized entry
        // the EVENT_AFTER_SAVE_ELEMENT gets called multiple times during the save, for each localized entry and draft / revision
        $entry = $event->element;
        $isDraftOrRevision = ElementHelper::isDraftOrRevision($entry);

        if ((!$entry instanceof Entry && !$entry instanceof craft\commerce\elements\Product && !$entry instanceof GlobalSet && !$entry instanceof Asset && !$entry instanceof Category) || $isDraftOrRevision) {
            return;
        }

        // we cannot know where to copy the content from
        if (empty($elementSettings['sourceSite'])) {
            return;
        }

        // make sure we are in the correct localized entry
        if ($entry->siteId != $elementSettings['sourceSite']) {
            return;
        }

        if (self::$syncing) {
            return;
        }

        // we only want to add our task to the queue once
        self::$syncing = true;

        // elementSettings will be null in HUD, where we want to continue with defaults
        if ($elementSettings !== null && ($event->isNew || empty($elementSettings['enabled']))) {
            return;
        }

        $selectedAttributes = $this->getAttributesToCopy();

        if ($entry instanceof GlobalSet) {
            $attributesToCopy = ['fields'];
        } elseif ($entry instanceof Asset) {
            $attributesToCopy = $selectedAttributes;
        } else {
            $attributesToCopy = $selectedAttributes;
        }

        if (empty($attributesToCopy)) {
            return;
        }

        $allSites = Craft::$app->getSites()->getAllSites();

        $targets = $elementSettings['targets'] ?? [];

        if (!is_array($targets)) {
            $targets = [$targets];
        }

        $matchingSites = [];
        $user = Craft::$app->getUser()->getIdentity();

        foreach ($allSites as $site) {
            $siteId = $site->id;

            // permissions are already handled in getSiteInputOptions(), but this is the BE validation
            if (!$user->can('editsite:' . $site->uid)) {
                continue;
            }

            $matchingTarget = in_array($siteId, $targets);

            if (!$matchingTarget) {
                continue;
            }

            $siteElement = Craft::$app->elements->getElementById(
                $entry->id,
                null,
                $siteId
            );

            if ($siteElement) {
                $matchingSites[] = (int)$siteId;
            } else {
                Craft::warning(
                    "Cannot copy to site '{$site->name}' (ID: {$siteId}): element does not exist on this site.",
                    __METHOD__
                );
            }
        }

        if (!empty($matchingSites)) {
            // If the field-selection UI was rendered, respect the user's selection.
            // A hidden marker (`hasFieldSelection`) is always submitted alongside the
            // field checkboxes, so we can tell "all unchecked" apart from "UI not shown".
            $hasFieldSelection = ($elementSettings['hasFieldSelection'] ?? null) === '1';
            $fieldsToSync = $hasFieldSelection ? ($elementSettings['fieldsToSync'] ?? []) : null;

            $elementId = (int)$entry->id;
            $sourceSiteId = $elementSettings['sourceSite'];

            Craft::$app->onAfterRequest(function() use ($elementId, $sourceSiteId, $matchingSites, $attributesToCopy, $fieldsToSync) {
                // Snapshot each target site's modification date *after* the source save
                // has fully settled, so it is the baseline the queued job compares
                // against. Any target site edited after this point is left untouched.
                $job = new SyncElementContent([
                    'elementId'         => $elementId,
                    'sourceSiteId'      => $sourceSiteId,
                    'sites'             => $matchingSites,
                    'attributesToCopy'  => $attributesToCopy,
                    'fieldsToSync'      => $fieldsToSync,
                    'targetDateUpdated' => SyncElementContent::readSiteDateUpdated($elementId, $matchingSites),
                ]);

                $priority = (int)$this->settings->combinedSettingsQueuePriority;
                Queue::push($job, $priority);
            });
        }
    }

    public function getSerializedFieldValues(Entry|craft\commerce\elements\Product|Asset|GlobalSet|Category $element, ?array $fieldsToSync = null)
    {
        $fields = $element->getFieldLayout()->getCustomFields();
        $serializedValues = [];

        foreach ($fields as $field) {
            if ($fieldsToSync !== null && !in_array($field->handle, $fieldsToSync)) {
                continue;
            }

            $value = $element->getFieldValue($field->handle);

            if ($value instanceof ElementQuery) {
                $serializedValues[$field->handle] = $field->serializeValue($value->status([Element::STATUS_ENABLED, Element::STATUS_DISABLED]), $element);
            } else {
                $serializedValues[$field->handle] = $field->serializeValue($value, $element);
            }
        }

        return $serializedValues;
    }

    /**
     * @return array
     * @throws Exception
     */
    public function handleSiteCopyActiveState(Entry|craft\commerce\elements\Product|Asset|GlobalSet|Category $element)
    {
        if (!is_object($element)) {
            throw new Exception('Given value must be an object!');
        }

        $siteCopyEnabled = false;
        $selectedSites = [];

        $settings = $this->getCombinedSettings($element);
        $targetSites = [];
        $user = Craft::$app->getUser()->getIdentity();

        foreach ($settings['settings'] as $setting) {
            $criteriaField = $setting[0] ?? null;
            $operator = $setting[1] ?? null;
            $value = $setting[2] ?? null;
            $sourceId = $setting[3] ?? null;
            $targetId = $setting[4] ?? null;

            if (!empty($criteriaField) && !empty($operator) && !empty($value) && !empty($sourceId) && !empty($targetId)) {
                if (($sourceId != '*' && (int)$sourceId != $element->siteId) || ($criteriaField !== 'typeHandle' && !$element->hasProperty($criteriaField))) {
                    continue;
                }

                $checkFrom = false;

                if ($criteriaField === 'id') {
                    $checkFrom = $element->canonicalId;
                } elseif ($criteriaField === 'handle') {
                    $checkFrom = $element->{$criteriaField};
                   } elseif (isset($element->{$criteriaField}->handle)) {
                    $checkFrom = $element->{$criteriaField}->handle;
                }

                $check = false;

                if ($operator === 'eq') {
                    $check = $checkFrom == $value;
                } elseif ($operator === 'neq') {
                    $check = $checkFrom != $value;
                }

                if ($check && (int)$targetId !== $element->siteId) {
                    if (isset($targetSites[$targetId])) {
                        $targetSite = $targetSites[$targetId];
                    } else {
                        $targetSite = Craft::$app->getSites()->getSiteById($targetId);

                        if ($targetSite) {
                            $targetSites[$targetId] = $targetSite;
                        }
                    }

                    if ($targetSite && $user->can('editsite:' . $targetSite->uid)) {
                        $siteCopyEnabled = true;
                        $selectedSites[] = (int)$targetId;

                        if ($settings['method'] == 'xor') {
                            break;
                        }
                    }
                } elseif ($settings['method'] == 'and' && (int)$targetId !== $element->siteId) {
                    // check failed, revert values to default
                    $siteCopyEnabled = false;
                    $selectedSites = [];

                    break;
                }
            }
        }

        return [
            'siteCopyEnabled' => $siteCopyEnabled,
            'selectedSites'   => $selectedSites,
        ];
    }

    /**
     * @return array
     */
    public function getAttributesToCopy()
    {
        if ($this->settings && isset($this->settings->attributesToCopy) && is_array($this->settings->attributesToCopy)) {
            return $this->settings->attributesToCopy;
        }

        return [];
    }

    /**
     * @return array
     */
    public function getCombinedSettings(Entry|craft\commerce\elements\Product|Asset|GlobalSet|Category $element)
    {
        $combinedSettings = [];

        // default set to xor for backwards compatibility
        $combinedSettingsCheckMethod = 'xor';

        $attribute = 'combinedSettingsEntries';

        if ($element instanceof GlobalSet) {
            $attribute = 'combinedSettingsGlobals';
        } elseif ($element instanceof Asset) {
            $attribute = 'combinedSettingsAssets';
        }

        if ($this->settings && isset($this->settings->{$attribute}) && is_array($this->settings->{$attribute})) {
            $combinedSettings = $this->settings->{$attribute};
        }

        if ($this->settings && isset($this->settings->combinedSettingsCheckMethod) && is_string($this->settings->combinedSettingsCheckMethod)) {
            $combinedSettingsCheckMethod = $this->settings->combinedSettingsCheckMethod;
        }

        return ['settings' => $combinedSettings, 'method' => $combinedSettingsCheckMethod];
    }
}
