<?php
/**
 * @link      https://novu.ch
 * @copyright Copyright (c) Novu
 */

namespace teamnovu\sitecopy\mcp;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\elements\GlobalSet;
use craft\elements\User;
use craft\helpers\ElementHelper;
use craft\mcp\attributes\RequiresPermission;
use craft\mcp\McpActor;
use craft\models\Site;
use craft\queue\Queue as CraftQueue;
use craft\queue\QueueInterface;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use teamnovu\sitecopy\helpers\CopyJobTracker;
use teamnovu\sitecopy\jobs\SyncElementContent;
use teamnovu\sitecopy\services\SiteCopy as SiteCopyService;
use teamnovu\sitecopy\SiteCopy;
use Throwable;
use yii\queue\Queue as YiiQueue;

/**
 * Site Copy X tools for Craft's MCP server (Craft 5.12+).
 *
 * Craft builds a new instance for every tool call, so only stateless services are
 * injected. This class is only referenced when Craft's MCP classes exist, see
 * SiteCopy::registerMcpCapabilities(), so it may use PHP 8.2 syntax.
 */
class SiteCopyTools
{
    /**
     * Most elements one copy call may queue.
     */
    public const MAX_ELEMENTS = 50;

    /**
     * Most jobs one status call reports on.
     */
    public const MAX_JOBS = 50;

    /**
     * Attributes the MCP copy may copy. Commerce variants are left out: copy them from
     * the control panel.
     */
    private const MCP_ATTRIBUTES = ['fields', 'title', 'slug'];

    private const SITE_SCHEMA = [
        'type' => ['string', 'integer'],
        'description' => 'Site handle (e.g. "de"), language (e.g. "en") or numeric site ID.',
    ];

    public function __construct(
        private McpActor $actor,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'site_copy_x_targets',
        title: 'Site Copy X: copy targets',
        description: 'Shows where an element can be copied with site_copy_x_copy. For each other site it returns a status: "ok", or why the site can\'t be a target: "missing_on_site" (the element does not exist there), "no_site_permission", "cannot_save". Also lists the element\'s fields (handle, name, type, translatable), so you can tell which fields to include or exclude, e.g. SEO fields. Find the element ID first with Craft\'s own tools, such as elements_list.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function targets(
        #[Schema(description: 'ID of the element to copy (entry, asset, category, global set or Commerce product).', minimum: 1)]
        int $elementId,
        #[Schema(definition: self::SITE_SCHEMA)]
        string|int $sourceSite,
    ): array {
        $user = $this->actor->user();
        $service = $this->service();
        $source = $this->resolveSite($sourceSite, 'sourceSite');
        [$element, , $message] = $this->findSourceElement($elementId, $source, $user);

        if (!$element) {
            throw new ToolCallException($message);
        }

        $otherSiteIds = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if ((int)$site->id !== (int)$source->id) {
                $otherSiteIds[] = (int)$site->id;
            }
        }

        $resolved = $service->resolveTargetSites($element, (int)$source->id, $otherSiteIds, $user);
        $statusBySite = array_fill_keys($resolved['siteIds'], 'ok');

        foreach ($resolved['skipped'] as $skip) {
            $statusBySite[$skip['siteId']] = $skip['reason'];
        }

        $targets = [];

        foreach ($otherSiteIds as $siteId) {
            $targets[] = $this->describeSiteById($siteId) + ['status' => $statusBySite[$siteId] ?? 'unknown'];
        }

        $attributes = $this->allowedAttributes($element);
        $result = [
            'element' => $this->describeElement($element),
            'sourceSite' => $this->describeSite($source),
            'targets' => $targets,
            'attributesCopied' => $attributes,
            'fields' => in_array('fields', $attributes, true) ? $this->describeFields($element) : [],
        ];

        if ($this->settingsCopyVariants() && is_a($element, 'craft\commerce\elements\Product')) {
            $result['note'] = 'Commerce variants are not copied by the MCP tools. Copy them from the control panel.';
        }

        return $result;
    }

    /**
     * @param list<int> $elementIds
     * @param list<string|int> $targetSites
     * @param list<string>|null $fields
     * @param list<string>|null $excludeFields
     * @param list<string>|null $attributes
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'site_copy_x_copy',
        title: 'Site Copy X: copy content to other sites',
        description: 'Copies elements from a source site to target sites and OVERWRITES their content on the target sites. Queues one background job per element (at most 50 elements per call) and returns the job IDs: check them with site_copy_x_job_status. Before copying, call site_copy_x_targets, run this tool with dryRun=true, show the preview to the user and wait for their confirmation. Without fields or excludeFields every field is copied. Title and slug are only copied when the plugin settings allow it.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
        meta: ['anthropic/requiresUserInteraction' => true],
    )]
    #[RequiresPermission('accessCp')]
    public function copy(
        #[Schema(definition: [
            'type' => 'array',
            'items' => ['type' => 'integer', 'minimum' => 1],
            'minItems' => 1,
            'maxItems' => self::MAX_ELEMENTS,
            'description' => 'IDs of the elements to copy, at most 50.',
        ])]
        array $elementIds,
        #[Schema(definition: self::SITE_SCHEMA)]
        string|int $sourceSite,
        #[Schema(definition: [
            'type' => 'array',
            'items' => self::SITE_SCHEMA,
            'minItems' => 1,
            'description' => 'Sites to copy to: handles, languages or numeric site IDs.',
        ])]
        array $targetSites,
        #[Schema(definition: [
            'type' => ['array', 'null'],
            'items' => ['type' => 'string'],
            'description' => 'Copy only these field handles. Leave out to copy every field. Do not combine with excludeFields.',
        ])]
        ?array $fields = null,
        #[Schema(definition: [
            'type' => ['array', 'null'],
            'items' => ['type' => 'string'],
            'description' => 'Copy every field except these field handles, e.g. the SEO fields.',
        ])]
        ?array $excludeFields = null,
        #[Schema(definition: [
            'type' => ['array', 'null'],
            'items' => ['type' => 'string', 'enum' => self::MCP_ATTRIBUTES],
            'description' => 'What to copy: "fields", "title", "slug". Defaults to what the plugin settings copy; anything the settings do not allow is ignored.',
        ])]
        ?array $attributes = null,
        #[Schema(description: 'Only report what would be copied, without queueing anything.')]
        bool $dryRun = false,
    ): array {
        $user = $this->actor->user();
        $service = $this->service();
        $ids = $service->normalizeIds($elementIds);

        if (empty($ids)) {
            throw new ToolCallException('Give at least one element ID.');
        }

        if (count($ids) > self::MAX_ELEMENTS) {
            throw new ToolCallException(sprintf('Copy at most %d elements per call. Split the list into several calls.', self::MAX_ELEMENTS));
        }

        if ($fields !== null && $excludeFields !== null) {
            throw new ToolCallException('Use either fields or excludeFields, not both.');
        }

        $source = $this->resolveSite($sourceSite, 'sourceSite');
        $targetSiteIds = [];

        foreach ($targetSites as $targetSite) {
            $site = $this->resolveSite($targetSite, 'targetSites');
            $targetSiteIds[(int)$site->id] = (int)$site->id;
        }

        if (empty($targetSiteIds)) {
            throw new ToolCallException('Give at least one target site.');
        }

        $requestedAttributes = $this->normalizeAttributes($attributes);
        $jobIds = [];
        $results = [];

        foreach ($ids as $elementId) {
            [$element, $code, $message] = $this->findSourceElement($elementId, $source, $user);

            if (!$element) {
                $results[] = ['elementId' => $elementId, 'status' => $code, 'message' => $message];
                continue;
            }

            $allowedAttributes = $this->allowedAttributes($element);
            $copyAttributes = $requestedAttributes === null
                ? $allowedAttributes
                : array_values(array_intersect($requestedAttributes, $allowedAttributes));
            [$fieldsToSync, $unknownFields] = $this->resolveFieldSelection($element, $fields, $excludeFields);

            $result = $service->queueElementCopy(
                $element,
                (int)$source->id,
                array_values($targetSiteIds),
                $copyAttributes,
                $fieldsToSync,
                $dryRun,
                $user
            );

            if (empty($result['attributes'])) {
                $status = 'nothing_to_copy';
            } elseif (empty($result['siteIds'])) {
                $status = 'no_target_site';
            } elseif ($dryRun) {
                $status = 'would_queue';
            } elseif ($result['jobId'] !== null) {
                $status = 'queued';
                $jobIds[] = $result['jobId'];
            } else {
                $status = 'not_queued';
            }

            $entry = [
                'element' => $this->describeElement($element),
                'status' => $status,
                'jobId' => $result['jobId'],
                'targets' => array_map(fn(int $siteId) => $this->describeSiteById($siteId), $result['siteIds']),
                'skippedTargets' => array_map(
                    fn(array $skip) => $this->describeSiteById($skip['siteId']) + ['reason' => $skip['reason']],
                    $result['skipped']
                ),
                'attributes' => $result['attributes'],
                'fields' => $fieldsToSync ?? 'all',
            ];

            if ($requestedAttributes !== null && ($ignored = array_values(array_diff($requestedAttributes, $allowedAttributes)))) {
                $entry['ignoredAttributes'] = $ignored;
            }

            if ($unknownFields) {
                $entry['unknownFields'] = $unknownFields;
            }

            $results[] = $entry;
        }

        if ($dryRun) {
            $next = 'Nothing was queued. Show this preview to the user and run the copy with dryRun=false once they confirm.';
        } elseif ($jobIds) {
            $next = 'The copies run in the background through the Craft queue. Check them with site_copy_x_job_status.';
        } else {
            $next = 'Nothing was queued. See the status of each element.';
        }

        return [
            'dryRun' => $dryRun,
            'sourceSite' => $this->describeSite($source),
            'queuedJobs' => count($jobIds),
            'jobIds' => $jobIds,
            'elements' => $results,
            'next' => $next,
        ];
    }

    /**
     * @param list<string|int>|null $jobIds
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'site_copy_x_job_status',
        title: 'Site Copy X: copy job status',
        description: 'Shows the status of Site Copy X copy jobs: waiting, running (with progress), failed (with the error), finished or finished_with_errors, with the outcome for each target site. Covers copies queued with site_copy_x_copy and from the control panel. Without jobIds it lists your most recent copy jobs. Jobs are tracked for 7 days; "unknown" means no result was recorded.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function jobStatus(
        #[Schema(definition: [
            'type' => ['array', 'null'],
            'items' => ['type' => ['string', 'integer']],
            'maxItems' => self::MAX_JOBS,
            'description' => 'Job IDs returned by site_copy_x_copy. Leave out to list your most recent copy jobs.',
        ])]
        ?array $jobIds = null,
    ): array {
        $user = $this->actor->user();
        $ids = empty($jobIds)
            ? CopyJobTracker::getRecentJobIds((int)$user->id)
            : array_values(array_unique(array_map('strval', $jobIds)));
        $ids = array_slice($ids, 0, self::MAX_JOBS);

        $jobs = array_map(fn(string $jobId) => $this->jobStatusFor($jobId, $user), $ids);
        $result = ['count' => count($jobs), 'jobs' => $jobs];

        if (empty($ids)) {
            $result['message'] = 'You have not queued any Site Copy X copy in the last 7 days.';
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'site_copy_x_rules_list',
        title: 'Site Copy X: automatic copy rules',
        description: 'Lists the automatic copy rules from the Site Copy X settings. When an editor opens an element that matches a rule, the "Copy to site" toggle is switched on with the rule\'s target site selected, so the copy runs when they save unless they switch it off. Also returns which attributes the plugin copies.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function rulesList(): array
    {
        $this->actor->user();
        $settings = SiteCopy::getInstance()->getSettings();
        $method = in_array($settings->combinedSettingsCheckMethod, ['and', 'xor'], true)
            ? $settings->combinedSettingsCheckMethod
            : 'or';

        $groups = [
            'entries' => [$settings->combinedSettingsEntries, SiteCopyService::getCriteriaFieldsEntries()],
            'globalSets' => [$settings->combinedSettingsGlobals, SiteCopyService::getCriteriaFieldsGlobals()],
            'assets' => [$settings->combinedSettingsAssets, SiteCopyService::getCriteriaFieldsAssets()],
        ];

        $rules = [];
        $count = 0;

        foreach ($groups as $group => [$rows, $criteria]) {
            $labels = array_column($criteria, 'label', 'value');
            $rules[$group] = [];

            foreach (is_array($rows) ? $rows : [] as $row) {
                $criterion = $row[0] ?? null;
                $operator = $row[1] ?? null;
                $value = $row[2] ?? null;
                $sourceSiteId = $row[3] ?? null;
                $targetSiteId = $row[4] ?? null;

                // Incomplete rows are ignored by the plugin.
                if (empty($criterion) || empty($operator) || empty($value) || empty($sourceSiteId) || empty($targetSiteId)) {
                    continue;
                }

                $rules[$group][] = [
                    'criterion' => $criterion,
                    'criterionLabel' => $labels[$criterion] ?? $criterion,
                    'operator' => $operator === 'neq' ? 'does not equal' : 'equals',
                    'value' => $value,
                    'sourceSite' => $sourceSiteId === '*' ? 'any' : $this->describeSiteById((int)$sourceSiteId),
                    'targetSite' => $this->describeSiteById((int)$targetSiteId),
                ];
                $count++;
            }
        }

        return [
            'count' => $count,
            'checkMethod' => $method,
            'checkMethodDescription' => match ($method) {
                'and' => 'AND: every rule that applies to the element must match, otherwise nothing is pre-selected.',
                'xor' => 'XOR: only the first matching rule is used.',
                default => 'OR: every matching rule adds its target site.',
            },
            'attributesCopied' => array_values((array)$settings->attributesToCopy),
            'rules' => $rules,
            'note' => 'The "entries" rules also apply to categories and Commerce products.',
        ];
    }

    // Private Methods
    // =========================================================================

    private function service(): SiteCopyService
    {
        return SiteCopy::getInstance()->sitecopy;
    }

    /**
     * @return array{0: ElementInterface|null, 1: string|null, 2: string|null} The element, or an error code and message
     */
    private function findSourceElement(int $elementId, Site $source, User $user): array
    {
        if (!$user->can('editsite:' . $source->uid)) {
            return [null, 'no_site_permission', sprintf('You are not allowed to edit the site "%s".', $source->handle)];
        }

        $elementsService = Craft::$app->getElements();
        $element = $elementsService->getElementById($elementId, null, (int)$source->id);

        if (!$element) {
            return [null, 'not_found', sprintf('Element %d does not exist on the site "%s".', $elementId, $source->handle)];
        }

        if (ElementHelper::isDraftOrRevision($element)) {
            return [null, 'not_canonical', sprintf('Element %d is a draft or revision. Use the ID of the element itself: %d.', $elementId, $element->getCanonicalId())];
        }

        if (!$this->service()->isSupportedElement($element)) {
            return [null, 'unsupported_type', sprintf('Site Copy X cannot copy %s.', $element::pluralLowerDisplayName())];
        }

        if (!$elementsService->canView($element, $user)) {
            return [null, 'not_allowed', sprintf('You are not allowed to view element %d.', $elementId)];
        }

        return [$element, null, null];
    }

    private function resolveSite(string|int $value, string $argument): Site
    {
        $sites = Craft::$app->getSites();
        $needle = trim((string)$value);
        $site = null;

        if ($needle !== '' && ctype_digit($needle)) {
            $site = $sites->getSiteById((int)$needle);
        }

        if (!$site && $needle !== '') {
            $allSites = $sites->getAllSites();
            $lower = strtolower($needle);

            foreach ($allSites as $candidate) {
                if (strtolower($candidate->handle) === $lower) {
                    $site = $candidate;
                    break;
                }
            }

            if (!$site) {
                // Fall back to the language, e.g. "en" for "en-US", if exactly one site matches.
                $matches = array_values(array_filter($allSites, function(Site $candidate) use ($lower) {
                    $language = strtolower($candidate->language);

                    return $language === $lower || explode('-', $language)[0] === $lower;
                }));

                if (count($matches) === 1) {
                    $site = $matches[0];
                }
            }
        }

        if (!$site) {
            throw new ToolCallException(sprintf(
                'Unknown site "%s" in %s. Sites: %s.',
                $needle,
                $argument,
                implode(', ', array_map(
                    fn(Site $site) => sprintf('%s (%s, %s, ID %d)', $site->handle, $site->getName(), $site->language, $site->id),
                    $sites->getAllSites()
                ))
            ));
        }

        return $site;
    }

    /**
     * @return string[]|null
     */
    private function normalizeAttributes(?array $attributes): ?array
    {
        if ($attributes === null) {
            return null;
        }

        $attributes = array_values(array_unique(array_map('strval', $attributes)));
        $unknown = array_diff($attributes, self::MCP_ATTRIBUTES);

        if ($unknown) {
            throw new ToolCallException(sprintf(
                'Unknown attribute "%s". Use %s.',
                implode('", "', $unknown),
                implode(', ', self::MCP_ATTRIBUTES)
            ));
        }

        if (empty($attributes)) {
            throw new ToolCallException('attributes cannot be empty. Leave it out to copy what the plugin settings allow.');
        }

        return $attributes;
    }

    /**
     * What the plugin settings allow to copy for this element, without Commerce variants.
     *
     * @return string[]
     */
    private function allowedAttributes(ElementInterface $element): array
    {
        return array_values(array_intersect($this->service()->getAttributesToCopyForElement($element), self::MCP_ATTRIBUTES));
    }

    private function settingsCopyVariants(): bool
    {
        return in_array('variants', $this->service()->getAttributesToCopy(), true);
    }

    /**
     * @return array{0: string[]|null, 1: string[]} Field handles to copy (null for all) and requested handles the element doesn't have
     */
    private function resolveFieldSelection(ElementInterface $element, ?array $fields, ?array $excludeFields): array
    {
        if ($fields === null && $excludeFields === null) {
            return [null, []];
        }

        $handles = array_map(
            fn(FieldInterface $field) => $field->handle,
            array_values($element->getFieldLayout()?->getCustomFields() ?? [])
        );
        $requested = array_values(array_unique(array_filter(
            array_map('strval', $fields ?? $excludeFields),
            fn(string $handle) => $handle !== ''
        )));
        $unknown = array_values(array_diff($requested, $handles));

        if ($fields !== null) {
            return [array_values(array_intersect($handles, $requested)), $unknown];
        }

        return [array_values(array_diff($handles, $requested)), $unknown];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobStatusFor(string $jobId, User $user): array
    {
        $record = CopyJobTracker::getQueued($jobId);

        if (!$record || (!$user->admin && (int)($record['userId'] ?? 0) !== (int)$user->id)) {
            return [
                'jobId' => $jobId,
                'status' => 'unknown',
                'message' => 'No Site Copy X job with this ID was queued by you in the last 7 days.',
            ];
        }

        $resultKey = (string)($record['resultKey'] ?? '');
        $status = [
            'jobId' => $jobId,
            'element' => $this->describeElementById((int)$record['elementId'], (int)$record['sourceSiteId'], $user),
            'sourceSite' => $this->describeSiteById((int)$record['sourceSiteId']),
            'targets' => array_map(fn($siteId) => $this->describeSiteById((int)$siteId), $record['siteIds'] ?? []),
            'queuedAt' => date(DATE_ATOM, (int)$record['queuedAt']),
        ];

        $details = $this->queueDetails($jobId, $resultKey);
        $result = $resultKey !== '' ? CopyJobTracker::getResult($resultKey) : null;
        $queueStatus = $details !== null ? (int)($details['status'] ?? 0) : null;

        if ($queueStatus === YiiQueue::STATUS_WAITING) {
            $status['status'] = 'waiting';
        } elseif ($queueStatus === YiiQueue::STATUS_RESERVED) {
            $status['status'] = 'running';
            $status['progress'] = (int)($details['progress'] ?? 0);
        } elseif ($queueStatus === CraftQueue::STATUS_FAILED) {
            $status['status'] = 'failed';
            $status['error'] = (string)($details['error'] ?? '');
        } elseif ($result !== null) {
            $status['status'] = $this->resultHasErrors($result) ? 'finished_with_errors' : 'finished';
        } else {
            $status['status'] = 'unknown';
            $status['message'] = 'The job is no longer in the queue and recorded no result. It may have been removed from the queue by hand.';
        }

        if ($result !== null) {
            $status['result'] = $this->describeResult($result);
        }

        return $status;
    }

    /**
     * The queue's details for a job, or null if the job is not in the queue (any more).
     *
     * @return array<string, mixed>|null
     */
    private function queueDetails(string $jobId, string $resultKey): ?array
    {
        $queue = Craft::$app->getQueue();

        if (!$queue instanceof QueueInterface) {
            return null;
        }

        try {
            $details = $queue->getJobDetails($jobId);
        } catch (Throwable) {
            return null;
        }

        // Job IDs are reused (e.g. after a database restore): make sure it is our job.
        $job = $details['job'] ?? null;

        if (!$job instanceof SyncElementContent || (string)$job->resultKey !== $resultKey) {
            return null;
        }

        return $details;
    }

    private function resultHasErrors(array $result): bool
    {
        if (!empty($result['error'])) {
            return true;
        }

        foreach ($result['sites'] ?? [] as $outcome) {
            if (($outcome['status'] ?? null) === SyncElementContent::OUTCOME_FAILED) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeResult(array $result): array
    {
        $sites = [];

        foreach ($result['sites'] ?? [] as $siteId => $outcome) {
            $site = $this->describeSiteById((int)$siteId) + ['outcome' => $outcome['status'] ?? 'unknown'];

            if (!empty($outcome['errors'])) {
                $site['errors'] = array_values($outcome['errors']);
            }

            if (!empty($outcome['warnings'])) {
                $site['warnings'] = array_values($outcome['warnings']);
            }

            $sites[] = $site;
        }

        $described = [
            'finishedAt' => isset($result['finishedAt']) ? date(DATE_ATOM, (int)$result['finishedAt']) : null,
            'sites' => $sites,
        ];

        if (!empty($result['error'])) {
            $described['error'] = $result['error'];
        }

        return $described;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeElement(ElementInterface $element): array
    {
        return [
            'id' => (int)$element->id,
            'type' => $element::displayName(),
            'title' => (string)$element,
            'status' => $element->getStatus(),
            'cpEditUrl' => $element->getCpEditUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeElementById(int $elementId, int $siteId, User $user): array
    {
        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);

        if (!$element || !Craft::$app->getElements()->canView($element, $user)) {
            return ['id' => $elementId];
        }

        return $this->describeElement($element);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function describeFields(ElementInterface $element): array
    {
        $fields = $element->getFieldLayout()?->getCustomFields() ?? [];

        return array_map(fn(FieldInterface $field) => [
            'handle' => $field->handle,
            'name' => $field->name,
            'type' => $field::displayName(),
            'translatable' => $field->getIsTranslatable($element),
        ], array_values($fields));
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSite(Site $site): array
    {
        return [
            'id' => (int)$site->id,
            'handle' => $site->handle,
            'name' => $site->getName(),
            'language' => $site->language,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSiteById(int $siteId): array
    {
        $site = Craft::$app->getSites()->getSiteById($siteId, true);

        return $site ? $this->describeSite($site) : ['id' => $siteId];
    }
}
