<?php

namespace teamnovu\sitecopy\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\web\Controller;
use teamnovu\sitecopy\services\SiteCopy as SiteCopyService;
use teamnovu\sitecopy\SiteCopy;
use Throwable;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Control panel endpoints for the bulk copy action and the "missing on site" warning.
 */
class ApiController extends Controller
{
    /**
     * Element types the bulk copy action is available for. Commerce is optional.
     */
    private const BULK_ELEMENT_TYPES = [
        Entry::class,
        Asset::class,
        Category::class,
        'craft\commerce\elements\Product',
    ];

    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Front-end users can be logged in too (e.g. public registration), so make sure
        // these endpoints are only reachable from the control panel.
        $this->requireCpRequest();
        $this->requirePermission('accessCp');
        $this->requirePostRequest();

        return true;
    }

    public function actionBulkCopyOverlay(): Response
    {
        $request = Craft::$app->getRequest();
        $service = SiteCopy::getInstance()->sitecopy;
        $elementType = $this->requireElementType($request->getBodyParam('elementType'), self::BULK_ELEMENT_TYPES);
        $siteId = $this->requireSiteId($request->getBodyParam('siteId'));
        $elements = $this->findViewableElements($elementType, $service->normalizeIds($request->getBodyParam('ids', [])), $siteId);
        $supportedSites = [];

        foreach ($elements as $element) {
            if ($element instanceof Entry && $element->section && !$element->section->getHasMultiSiteEntries()) {
                return $this->asJson([
                    'html'    => '',
                    'success' => false,
                ]);
            }

            $supportedSites = Craft::$app->getSites()->getAllSites();
            break;
        }

        $elementTypeClass = substr($elementType, strrpos($elementType, '\\') + 1);

        $html = Craft::$app->view->renderTemplate(
            'site-copy-x/_cp/bulkCopyOverlay',
            [
                'siteId'          => $siteId,
                'ids'             => array_map(function(ElementInterface $element) {
                    return (int)$element->id;
                }, $elements),
                'supportedSites'  => $supportedSites,
                'elementCount'    => count($elements),
                'elementType'     => $elementTypeClass,
                'siteCopyEnabled' => 1,
                'selectedSites'   => [],
                'currentSite'     => Craft::$app->getSites()->getCurrentSite(),
            ]
        );

        return $this->asJson([
            'html'    => $html,
            'success' => true,
        ]);
    }

    /**
     * Tells which of the sites the user can edit are missing one or more of the given elements.
     */
    public function actionCheckSiteAvailability(): Response
    {
        $request = Craft::$app->getRequest();
        $service = SiteCopy::getInstance()->sitecopy;
        $ids = $service->normalizeIds($request->getBodyParam('elementIds', $request->getBodyParam('elementId')));
        $sourceSiteId = (int)$request->getBodyParam('sourceSiteId');
        $elementType = $ids ? Craft::$app->getElements()->getElementTypeById($ids[0]) : null;

        if (!$sourceSiteId || !$elementType || !$this->isAllowedElementType($elementType, SiteCopyService::SUPPORTED_ELEMENT_TYPES)) {
            return $this->asJson([
                'success' => false,
                'error'   => 'Element not found',
            ]);
        }

        $viewableIds = array_map(function(ElementInterface $element) {
            return (int)$element->id;
        }, $this->findViewableElements($elementType, $ids, $sourceSiteId, true));

        if (empty($viewableIds)) {
            return $this->asJson([
                'success' => false,
                'error'   => 'Element not found',
            ]);
        }

        $user = Craft::$app->getUser()->getIdentity();
        $availableSites = [];
        $unavailableSites = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            if (!$user->can('editsite:' . $site->uid)) {
                continue;
            }

            $existingIds = $this->elementQuery($elementType, $viewableIds, (int)$site->id, true)->ids();
            $missing = count($viewableIds) - count($existingIds);

            if ($missing === 0) {
                $availableSites[] = [
                    'id'   => (int)$site->id,
                    'name' => $site->name,
                ];
            } else {
                $unavailableSites[] = [
                    'id'      => (int)$site->id,
                    'name'    => $site->name,
                    'missing' => $missing,
                ];
            }
        }

        return $this->asJson([
            'success'          => true,
            'total'            => count($viewableIds),
            'availableSites'   => $availableSites,
            'unavailableSites' => $unavailableSites,
        ]);
    }

    public function actionBulkCopy(): Response
    {
        $request = Craft::$app->getRequest();
        $service = SiteCopy::getInstance()->sitecopy;
        $elementType = $this->requireElementType($request->getBodyParam('elementType'), self::BULK_ELEMENT_TYPES);
        $sitecopySettings = $request->getBodyParam('sitecopy', []);
        $sitecopySettings = is_array($sitecopySettings) ? $sitecopySettings : [];
        $sourceSiteId = $this->requireSiteId($sitecopySettings['sourceSite'] ?? $request->getBodyParam('siteId'));
        $ids = $service->normalizeIds($request->getBodyParam('ids', []));
        $targetSiteIds = $service->normalizeIds($sitecopySettings['targets'] ?? []);

        try {
            $jobIds = [];

            // One job per element. Do not go through syncElementContent() here: it only
            // queues one copy per request (see SiteCopy::$syncing).
            foreach ($this->findViewableElements($elementType, $ids, $sourceSiteId) as $element) {
                $result = $service->queueElementCopy(
                    $element,
                    $sourceSiteId,
                    $targetSiteIds,
                    $service->getAttributesToCopyForElement($element)
                );

                $service->logSkippedTargets($element, $result['skipped']);

                if ($result['jobId'] !== null) {
                    $jobIds[] = $result['jobId'];
                }
            }

            $session = Craft::$app->getSession();

            if (empty($jobIds)) {
                $session->setError(Craft::t('site-copy-x', 'Nothing was copied. Check the selected sites and your permissions.'));
            } else {
                $session->setNotice(Craft::t('site-copy-x', 'Copy queued for {queued} of {total} elements.', [
                    'queued' => count($jobIds),
                    'total'  => count($ids),
                ]));
            }

            return $this->asJson([
                'success' => true,
                'queued'  => count($jobIds),
                'total'   => count($ids),
                'jobIds'  => $jobIds,
            ]);
        } catch (Throwable $e) {
            Craft::error('Error in bulk copy: ' . $e->getMessage(), __METHOD__);

            return $this->asJson(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param mixed $elementType
     * @param string[] $allowed
     * @return string
     * @throws BadRequestHttpException
     */
    private function requireElementType($elementType, array $allowed): string
    {
        if (!is_string($elementType) || !$this->isAllowedElementType($elementType, $allowed)) {
            throw new BadRequestHttpException('Unsupported element type.');
        }

        return $elementType;
    }

    /**
     * @param string $elementType
     * @param string[] $allowed
     * @return bool
     */
    private function isAllowedElementType(string $elementType, array $allowed): bool
    {
        return in_array($elementType, $allowed, true) && class_exists($elementType);
    }

    /**
     * @param mixed $siteId
     * @return int
     * @throws BadRequestHttpException
     */
    private function requireSiteId($siteId): int
    {
        $siteId = is_numeric($siteId) ? (int)$siteId : 0;

        if (!$siteId || !Craft::$app->getSites()->getSiteById($siteId)) {
            throw new BadRequestHttpException('Invalid site.');
        }

        return $siteId;
    }

    /**
     * Loads the given elements in a site, whatever their status, and keeps the ones the
     * user may view.
     *
     * @param string $elementType
     * @param int[] $ids
     * @param int $siteId
     * @param bool $includeDrafts Also match drafts and revisions (the sidebar may send a draft ID)
     * @return ElementInterface[]
     */
    private function findViewableElements(string $elementType, array $ids, int $siteId, bool $includeDrafts = false): array
    {
        // An empty ID list must not turn into "no ID filter".
        if (empty($ids)) {
            return [];
        }

        $user = Craft::$app->getUser()->getIdentity();
        $elementsService = Craft::$app->getElements();

        return array_values(array_filter(
            $this->elementQuery($elementType, $ids, $siteId, $includeDrafts)->all(),
            function(ElementInterface $element) use ($elementsService, $user) {
                return $elementsService->canView($element, $user);
            }
        ));
    }

    /**
     * @param string $elementType
     * @param int[] $ids
     * @param int $siteId
     * @param bool $includeDrafts
     * @return \craft\elements\db\ElementQueryInterface
     */
    private function elementQuery(string $elementType, array $ids, int $siteId, bool $includeDrafts)
    {
        /** @var ElementInterface|string $elementType */
        $query = $elementType::find()
            ->id($ids)
            ->siteId($siteId)
            ->status(null);

        if ($includeDrafts) {
            $query->drafts(null)->provisionalDrafts(null)->revisions(null);
        }

        return $query;
    }
}
