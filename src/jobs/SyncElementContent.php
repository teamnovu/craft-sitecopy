<?php
/**
 * @link      https://novu.ch
 * @copyright Copyright (c) Novu
 */

namespace teamnovu\sitecopy\jobs;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\queue\BaseJob;
use teamnovu\sitecopy\SiteCopy;
use Exception;

/**
 * Class SyncElementContent
 *
 * @package teamnovu\sitecopy\jobs
 */
class SyncElementContent extends BaseJob
{
    // Properties
    // =========================================================================

    /**
     * @var int The element ID where we want to perform the syncing on
     */
    public $elementId;

    /**
     * @var int the site ID where we want to copy the content from
     */
    public $sourceSiteId;

    /**
     * @var int[] The sites IDs where we want to overwrite the content
     */
    public $sites;

    /**
     * @var array
     */
    public $attributesToCopy;

    /**
     * @var string[]|null Field handles to copy; null means all fields
     */
    public $fieldsToSync = null;

    /**
     * @var array<string,string>|null Snapshot of each target site's
     * `elements_sites.dateUpdated` taken at the moment the copy was requested,
     * keyed by site ID (as a string). When the job runs, any target site whose
     * current `dateUpdated` no longer matches this snapshot was edited after the
     * copy was queued and is skipped, so a queued copy can never overwrite newer
     * content on the target site.
     *
     * `null` disables the check. This keeps jobs that were queued by an older
     * plugin version — and deserialized after an upgrade — working unchanged.
     */
    public $targetDateUpdated = null;

    // Public Methods
    // =========================================================================

    /**
     * Reads the current `elements_sites.dateUpdated` for the given element on
     * each of the given sites, keyed by site ID (as a string).
     *
     * This is the per-site modification date. It is intentionally not read from
     * `Element::$dateUpdated`, which reflects the shared `elements` table and is
     * therefore identical across all of an element's sites.
     *
     * @param int $elementId
     * @param int[] $siteIds
     * @return array<string,string>
     */
    public static function readSiteDateUpdated(int $elementId, array $siteIds): array
    {
        if (empty($siteIds)) {
            return [];
        }

        $pairs = (new Query())
            ->select(['siteId', 'dateUpdated'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $elementId, 'siteId' => $siteIds])
            ->pairs();

        $normalized = [];

        foreach ($pairs as $siteId => $dateUpdated) {
            $normalized[(string)$siteId] = $dateUpdated;
        }

        return $normalized;
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $elementsService = Craft::$app->getElements();

        if (empty($this->sites)) {
            return;
        }

        $sourceElement = $elementsService->getElementById($this->elementId, null, $this->sourceSiteId);

        if (!$sourceElement) {
            return;
        }

        $data = [];

        foreach ($this->attributesToCopy as $attribute) {
            if ($attribute == 'fields') {
                $tmp = SiteCopy::getInstance()->sitecopy->getSerializedFieldValues($sourceElement, $this->fieldsToSync);

                if (empty($tmp)) {
                    continue;
                }
            } elseif ($attribute == 'variants') {
                if (!$sourceElement instanceof craft\commerce\elements\Product || !$sourceElement->getType()->hasVariants) {
                    continue;
                }

                $variantFields = [];

                foreach ($sourceElement->getVariants() as $variant) {
                    $variantFields[$variant->id]['custom_fields'] = $variant->getFieldValues();

                    if (in_array('title', $this->attributesToCopy)) {
                        $variantFields[$variant->id]['title'] = $variant->title;
                    }
                }

                $tmp = $variantFields;
            } else {
                $tmp = $sourceElement->{$attribute};
            }

            $data[$attribute] = $tmp;
        }

        $totalSites = count($this->sites);
        $currentSite = 0;
        $mutex = Craft::$app->getMutex();

        foreach ($this->sites as $siteId) {
            $this->setProgress($queue, $currentSite / $totalSites, Craft::t('app', '{step} of {total}', [
                'step'  => $currentSite + 1,
                'total' => $totalSites,
            ]));

            // Skip any target site that was edited after this copy was queued, so a
            // delayed job can't overwrite newer content (e.g. an editor pasting the
            // translation on the target site while the copy still sits in the queue).
            if ($this->targetChangedSinceQueued($siteId)) {
                Craft::warning(
                    "Skipped copying element {$this->elementId} to site {$siteId}: it was modified after the copy was queued.",
                    __METHOD__
                );

                $currentSite++;
                continue;
            }

            /** @var Element $siteElement */
            $siteElement = $elementsService->getElementById($sourceElement->id, get_class($sourceElement), $siteId);

            foreach ($data as $key => $item) {
                if ($key == 'fields') {
                    // Remap linked elements to target site
                    $item = $this->remapLinkedElements($item, $this->sourceSiteId, $siteId, $sourceElement);
                    $siteElement->setFieldValues($item);
                } elseif ($key == 'variants') {
                    foreach ($item as $variantId => $value) {
                        $variant = craft\commerce\elements\Variant::find()->id($variantId)->siteId($siteId)->one();

                        if ($variant) {
                            // Remap linked elements in variant fields
                            $remappedFields = $this->remapLinkedElements($value['custom_fields'], $this->sourceSiteId, $siteId, $variant);
                            $variant->setFieldValues($remappedFields);

                            if (isset($value['title'])) {
                                $variant->title = $value['title'];
                            }

                            $variant->setScenario(Element::SCENARIO_ESSENTIALS);
                            Craft::$app->getElements()->saveElement($variant);
                        }
                    }
                } else {
                    // this is not possible for custom fields as of craft 3.4.0, make sure they dont reach this
                    $siteElement->{$key} = $item;
                }
            }

            $lockKey = "element:$siteElement->canonicalId";
            if (!$mutex->acquire($lockKey, 15)) {
                throw new Exception('Could not acquire a lock to save the element.');
            }

            $siteElement->setScenario(Element::SCENARIO_ESSENTIALS);

            try {
                // Disable propagation to prevent Craft from cascading nested entries
                // (e.g. Matrix blocks) back to the source site, which would cause duplicates.
                $elementsService->saveElement($siteElement, true, false);
            } finally {
                $mutex->release($lockKey);
            }

            $currentSite++;
        }
    }

    /**
     * Returns whether the given target site was modified after this copy was
     * queued, i.e. its current `elements_sites.dateUpdated` no longer matches the
     * snapshot taken when the copy was requested.
     *
     * Returns false when there is no snapshot (check disabled) or the site was not
     * part of the snapshot, so those cases fall through to the normal copy.
     *
     * @param int $siteId
     * @return bool
     */
    public function targetChangedSinceQueued(int $siteId): bool
    {
        if ($this->targetDateUpdated === null) {
            return false;
        }

        $expected = $this->targetDateUpdated[(string)$siteId] ?? null;

        if ($expected === null) {
            return false;
        }

        $current = (new Query())
            ->select(['dateUpdated'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $this->elementId, 'siteId' => $siteId])
            ->scalar();

        return $current !== false && $current !== $expected;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Remap linked elements from source site to target site
     *
     * @param array $fieldValues The field values to remap
     * @param int $sourceSiteId The source site ID
     * @param int $targetSiteId The target site ID
     * @param Element $element The element being synced
     * @return array The remapped field values
     */
    protected function remapLinkedElements($fieldValues, $sourceSiteId, $targetSiteId, $element)
    {
        $fieldsService = Craft::$app->getFields();
        $fieldLayout = $element->getFieldLayout();

        foreach ($fieldValues as $fieldHandle => &$value) {
            $field = $fieldLayout->getFieldByHandle($fieldHandle);

            if ($field === null) {
                continue;
            }

            // Check if this is a linking field (Relations or Link field)
            $fieldClass = get_class($field);

            // Handle Relations fields and other relation-based linking fields
            if (
                strpos($fieldClass, 'Relations') !== false ||
                method_exists($field, 'getTargetSiteId')
            ) {
                // For array values (multiple linked elements)
                if (is_array($value)) {
                    $value = $this->remapElementIds($value, $sourceSiteId, $targetSiteId);
                }
            }
        }
        unset($value);

        // Craft's native Link field stores element links as reference tags that embed
        // the source site id, e.g. {entry:123@5:url}. These tags can live anywhere in
        // the serialized structure (including nested inside Matrix / nested-entry
        // fields), so walk the whole tree and re-point them at the target site.
        $this->remapLinkRefTags($fieldValues, $sourceSiteId, $targetSiteId);

        return $fieldValues;
    }

    /**
     * Recursively rewrite Craft Link field element reference tags so they point at
     * the target site, e.g. `{entry:123@5:url}` => `{entry:123@9:url}`.
     *
     * The element id in a reference tag is canonical (shared across sites), so only
     * the embedded site id needs to change. The rewrite is only applied when the
     * linked element actually exists in the target site.
     *
     * @param mixed $value The serialized value to walk (passed by reference)
     * @param int $sourceSiteId The source site ID
     * @param int $targetSiteId The target site ID
     */
    protected function remapLinkRefTags(&$value, $sourceSiteId, $targetSiteId): void
    {
        if (!is_array($value)) {
            return;
        }

        // A serialized Link field value looks like:
        // ['type' => 'entry', 'value' => '{entry:123@5:url}', ...]
        if (
            isset($value['type'], $value['value']) &&
            is_string($value['value']) &&
            preg_match('/^\{(\w+):(\d+)(?:@(\d+))?:url\}$/', $value['value'], $matches)
        ) {
            $refHandle = $matches[1];
            $elementId = (int)$matches[2];

            // Only rewrite if the linked element actually exists in the target site.
            $targetElement = Craft::$app->getElements()->getElementById($elementId, null, $targetSiteId);

            if ($targetElement) {
                $value['value'] = sprintf('{%s:%s@%s:url}', $refHandle, $elementId, $targetSiteId);
            }

            return;
        }

        foreach ($value as &$child) {
            $this->remapLinkRefTags($child, $sourceSiteId, $targetSiteId);
        }
        unset($child);
    }

    /**
     * Remap element IDs from source site to target site
     *
     * @param array $elementIds Array of element IDs or element data
     * @param int $sourceSiteId The source site ID
     * @param int $targetSiteId The target site ID
     * @return array The remapped element IDs
     */
    protected function remapElementIds($elementIds, $sourceSiteId, $targetSiteId)
    {
        if (empty($elementIds)) {
            return $elementIds;
        }

        $remappedIds = [];

        foreach ($elementIds as $item) {
            // Handle both single IDs and objects/arrays with id property
            $sourceId = is_array($item) || is_object($item) ? ($item['id'] ?? $item->id ?? $item) : $item;

            if (!$sourceId) {
                $remappedIds[] = $item;
                continue;
            }

            // Try to find the element in the source site and then in the target site
            $sourceElement = Craft::$app->getElements()->getElementById($sourceId, null, $sourceSiteId);

            if ($sourceElement) {
                // Get the canonical ID and look for it in the target site
                $targetElement = Craft::$app->getElements()->getElementById(
                    $sourceElement->canonicalId,
                    get_class($sourceElement),
                    $targetSiteId
                );

                if ($targetElement) {
                    // Replace the ID with the target site's element ID
                    if (is_array($item)) {
                        $item['id'] = $targetElement->id;
                    } elseif (is_object($item)) {
                        $item->id = $targetElement->id;
                    } else {
                        $item = $targetElement->id;
                    }
                }
            }

            $remappedIds[] = $item;
        }

        return $remappedIds;
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('app', 'Syncing element contents');
    }
}
