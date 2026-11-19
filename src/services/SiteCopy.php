<?php
/**
 * @link      https://novu.ch
 * @copyright Copyright (c) Novu
 */
namespace teamnovu\sitecopy\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use craft\events\ElementEvent;
use craft\helpers\ElementHelper;
use craft\helpers\Queue;
use craft\models\Site;
use Exception;
use teamnovu\sitecopy\helpers\CopyJobTracker;
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
     * Reasons why a target site is left out of a copy.
     */
    public const SKIP_UNKNOWN_SITE = 'unknown_site';
    public const SKIP_SAME_AS_SOURCE = 'same_as_source';
    public const SKIP_NO_SITE_PERMISSION = 'no_site_permission';
    public const SKIP_MISSING_ON_SITE = 'missing_on_site';
    public const SKIP_CANNOT_SAVE = 'cannot_save';

    /**
     * Element types that can be copied. Commerce is optional, so its class is a string.
     */
    public const SUPPORTED_ELEMENT_TYPES = [
        Entry::class,
        GlobalSet::class,
        Asset::class,
        Category::class,
        'craft\commerce\elements\Product',
    ];

    /**
     * Set once the sidebar copy has been handled in this request.
     *
     * The sidebar save fires EVENT_AFTER_SAVE_ELEMENT several times for the source site
     * (nested entries, propagation), so only the first call may queue a copy. Do not use
     * syncElementContent() for anything else: use queueElementCopy(), which has no such flag.
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
     * Handles the sidebar widget: queues a copy after the source element was saved.
     *
     * @param ElementEvent $event
     * @param array        $elementSettings The posted `sitecopy[...]` values
     * @throws Throwable
     */
    public function syncElementContent(ElementEvent $event, array $elementSettings)
    {
        // This is not necessarily our localized element: EVENT_AFTER_SAVE_ELEMENT fires
        // several times per save, for each site, draft, revision and nested entry.
        $element = $event->element;

        if (!$this->isSupportedElement($element) || ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        // we cannot know where to copy the content from
        if (empty($elementSettings['sourceSite'])) {
            return;
        }

        // make sure we are in the correct localized element
        if ($element->siteId != $elementSettings['sourceSite']) {
            return;
        }

        // Nested entries (e.g. Matrix or CKEditor entries) are saved before their owner in
        // the same request. They must not take the one copy this request may queue: they
        // are copied as part of the owner's fields.
        if ($this->isNestedElement($element)) {
            return;
        }

        // The widget posts the ID of the element it belongs to (since 4.1.0).
        if (!empty($elementSettings['elementId']) && (int)$elementSettings['elementId'] !== (int)$element->getCanonicalId()) {
            return;
        }

        if (self::$syncing) {
            return;
        }

        // we only want to add our task to the queue once
        self::$syncing = true;

        if ($event->isNew || empty($elementSettings['enabled'])) {
            return;
        }

        // If the field-selection UI was rendered, respect the user's selection.
        // A hidden marker (`hasFieldSelection`) is always submitted alongside the
        // field checkboxes, so we can tell "all unchecked" apart from "UI not shown".
        $fieldsToSync = null;

        if (($elementSettings['hasFieldSelection'] ?? null) === '1') {
            $fieldsToSync = $this->normalizeFieldsToSync($elementSettings['fieldsToSync'] ?? []);
        }

        $attributesToCopy = $this->filterAttributesToCopy($this->getAttributesToCopyForElement($element), $fieldsToSync);

        if (empty($attributesToCopy)) {
            return;
        }

        $sourceSiteId = (int)$elementSettings['sourceSite'];
        $resolved = $this->resolveTargetSites($element, $sourceSiteId, $this->normalizeIds($elementSettings['targets'] ?? []));
        $this->logSkippedTargets($element, $resolved['skipped']);

        if (empty($resolved['siteIds'])) {
            return;
        }

        $elementId = (int)$element->id;
        $siteIds = $resolved['siteIds'];
        $user = Craft::$app->getUser()->getIdentity();
        $userId = $user ? (int)$user->id : null;

        // Push the job only once the request is over, so the target sites' modification
        // dates are read after the source save has fully settled.
        Craft::$app->onAfterRequest(function() use ($elementId, $sourceSiteId, $siteIds, $attributesToCopy, $fieldsToSync, $userId) {
            $this->pushCopyJob($elementId, $sourceSiteId, $siteIds, $attributesToCopy, $fieldsToSync, $userId);
        });
    }

    /**
     * Resolves the target sites and pushes a copy job right away.
     *
     * Unlike syncElementContent() this has no per-request flag and does not wait for the
     * end of the request, so it can be called once per element in a loop, from the bulk
     * action, the MCP tools or a console command.
     *
     * @param ElementInterface $element The canonical element, loaded in the source site
     * @param int $sourceSiteId
     * @param array $targetSiteIds
     * @param string[] $attributesToCopy
     * @param string[]|null $fieldsToSync Field handles to copy; null means all fields
     * @param bool $dryRun Resolve the targets without queueing anything
     * @param User|null $user Defaults to the logged-in user
     * @return array{elementId: int, jobId: string|null, siteIds: int[], skipped: array<int, array{siteId: int, reason: string}>, attributes: string[], dryRun: bool}
     */
    public function queueElementCopy(ElementInterface $element, int $sourceSiteId, array $targetSiteIds, array $attributesToCopy, ?array $fieldsToSync = null, bool $dryRun = false, ?User $user = null): array
    {
        $user = $user ?? Craft::$app->getUser()->getIdentity();
        $resolved = $this->resolveTargetSites($element, $sourceSiteId, $targetSiteIds, $user);
        $attributesToCopy = $this->filterAttributesToCopy($attributesToCopy, $fieldsToSync);
        $jobId = null;

        if (!$dryRun && !empty($resolved['siteIds']) && !empty($attributesToCopy)) {
            $jobId = $this->pushCopyJob(
                (int)$element->id,
                $sourceSiteId,
                $resolved['siteIds'],
                $attributesToCopy,
                $fieldsToSync,
                $user ? (int)$user->id : null
            );
        }

        return [
            'elementId'  => (int)$element->id,
            'jobId'      => $jobId,
            'siteIds'    => $resolved['siteIds'],
            'skipped'    => $resolved['skipped'],
            'attributes' => $attributesToCopy,
            'dryRun'     => $dryRun,
        ];
    }

    /**
     * Splits the requested target sites into the ones the element can be copied to and
     * the ones it can't, with the reason (see the SKIP_* constants).
     *
     * @param ElementInterface $element The canonical element
     * @param int $sourceSiteId
     * @param array $targetSiteIds
     * @param User|null $user Defaults to the logged-in user
     * @return array{siteIds: int[], skipped: array<int, array{siteId: int, reason: string}>}
     */
    public function resolveTargetSites(ElementInterface $element, int $sourceSiteId, array $targetSiteIds, ?User $user = null): array
    {
        $user = $user ?? Craft::$app->getUser()->getIdentity();
        $sitesService = Craft::$app->getSites();
        $elementsService = Craft::$app->getElements();
        $siteIds = [];
        $skipped = [];

        foreach ($this->normalizeIds($targetSiteIds) as $siteId) {
            $site = $sitesService->getSiteById($siteId);
            $reason = null;

            if (!$site) {
                $reason = self::SKIP_UNKNOWN_SITE;
            } elseif ($siteId === $sourceSiteId) {
                $reason = self::SKIP_SAME_AS_SOURCE;
            } elseif (!$user || !$user->can('editsite:' . $site->uid)) {
                $reason = self::SKIP_NO_SITE_PERMISSION;
            } else {
                $siteElement = $elementsService->getElementById((int)$element->id, get_class($element), $siteId);

                if (!$siteElement) {
                    $reason = self::SKIP_MISSING_ON_SITE;
                } elseif (!$elementsService->canSave($siteElement, $user)) {
                    $reason = self::SKIP_CANNOT_SAVE;
                }
            }

            if ($reason !== null) {
                $skipped[] = ['siteId' => $siteId, 'reason' => $reason];
            } else {
                $siteIds[] = $siteId;
            }
        }

        return ['siteIds' => $siteIds, 'skipped' => $skipped];
    }

    /**
     * Pushes a copy job and records it so its status can be looked up later.
     *
     * @param int $elementId
     * @param int $sourceSiteId
     * @param int[] $siteIds Already resolved target sites
     * @param string[] $attributesToCopy
     * @param string[]|null $fieldsToSync
     * @param int|null $userId The user who asked for the copy
     * @return string|null The queue job ID
     */
    public function pushCopyJob(int $elementId, int $sourceSiteId, array $siteIds, array $attributesToCopy, ?array $fieldsToSync = null, ?int $userId = null): ?string
    {
        $resultKey = Craft::$app->getSecurity()->generateRandomString(32);

        // Snapshot each target site's modification date when the copy is requested, so
        // the job can skip any target site that is edited while the job is queued.
        $job = new SyncElementContent([
            'elementId'         => $elementId,
            'sourceSiteId'      => $sourceSiteId,
            'sites'             => array_values($siteIds),
            'attributesToCopy'  => array_values($attributesToCopy),
            'fieldsToSync'      => $fieldsToSync,
            'targetDateUpdated' => SyncElementContent::readSiteDateUpdated($elementId, $siteIds),
            'userId'            => $userId,
            'resultKey'         => $resultKey,
        ]);

        $priority = $this->settings ? (int)$this->settings->combinedSettingsQueuePriority : null;
        $jobId = Queue::push($job, $priority);

        if ($jobId === null || $jobId === '') {
            return null;
        }

        $jobId = (string)$jobId;
        CopyJobTracker::recordQueued($jobId, $resultKey, $elementId, $sourceSiteId, $siteIds, $userId);

        return $jobId;
    }

    /**
     * Logs why target sites were left out of a copy requested from the control panel.
     *
     * @param ElementInterface $element
     * @param array $skipped As returned by resolveTargetSites()
     */
    public function logSkippedTargets(ElementInterface $element, array $skipped): void
    {
        foreach ($skipped as $skip) {
            Craft::warning(
                "Not copying element {$element->id} to site {$skip['siteId']}: {$skip['reason']}.",
                __METHOD__
            );
        }
    }

    /**
     * @param mixed $element
     * @return bool
     */
    public function isSupportedElement($element): bool
    {
        if (!$element instanceof ElementInterface) {
            return false;
        }

        foreach (self::SUPPORTED_ELEMENT_TYPES as $class) {
            if (is_a($element, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the element is owned by another element (Craft 5 nested entries).
     *
     * @param ElementInterface $element
     * @return bool
     */
    public function isNestedElement(ElementInterface $element): bool
    {
        return interface_exists('craft\base\NestedElementInterface')
            && $element instanceof \craft\base\NestedElementInterface
            && $element->getPrimaryOwnerId() !== null;
    }

    /**
     * The attributes the plugin settings allow to copy for this element.
     * Global sets only ever copy their fields.
     *
     * @param ElementInterface $element
     * @return string[]
     */
    public function getAttributesToCopyForElement(ElementInterface $element): array
    {
        if ($element instanceof GlobalSet) {
            return ['fields'];
        }

        return array_values($this->getAttributesToCopy());
    }

    /**
     * Drops `fields` when an empty field selection was made, since there is nothing to copy.
     *
     * @param string[] $attributesToCopy
     * @param string[]|null $fieldsToSync
     * @return string[]
     */
    public function filterAttributesToCopy(array $attributesToCopy, ?array $fieldsToSync): array
    {
        if ($fieldsToSync === []) {
            $attributesToCopy = array_diff($attributesToCopy, ['fields']);
        }

        return array_values(array_unique($attributesToCopy));
    }

    /**
     * Normalizes a posted field selection. A checkbox group with nothing checked posts
     * an empty string, which means "no fields".
     *
     * @param mixed $fieldsToSync
     * @return string[]
     */
    public function normalizeFieldsToSync($fieldsToSync): array
    {
        if (!is_array($fieldsToSync)) {
            $fieldsToSync = is_string($fieldsToSync) && $fieldsToSync !== '' ? [$fieldsToSync] : [];
        }

        $fieldsToSync = array_filter($fieldsToSync, function($handle) {
            return is_string($handle) && $handle !== '';
        });

        return array_values(array_unique($fieldsToSync));
    }

    /**
     * Normalizes IDs given as an array, a single value or a comma-separated string.
     *
     * @param mixed $ids
     * @return int[]
     */
    public function normalizeIds($ids): array
    {
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        } elseif (!is_array($ids)) {
            $ids = $ids === null ? [] : [$ids];
        }

        $normalized = [];

        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit(trim($id)))) {
                $id = (int)trim((string)$id);

                if ($id > 0) {
                    $normalized[$id] = $id;
                }
            }
        }

        return array_values($normalized);
    }

    /**
     * @param ElementInterface $element
     * @param string[]|null $fieldsToSync Field handles to include; null means all fields
     * @return array
     */
    public function getSerializedFieldValues(ElementInterface $element, ?array $fieldsToSync = null)
    {
        $fieldLayout = $element->getFieldLayout();

        if (!$fieldLayout) {
            return [];
        }

        $fields = $fieldLayout->getCustomFields();
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
