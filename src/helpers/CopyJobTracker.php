<?php
/**
 * @link      https://novu.ch
 * @copyright Copyright (c) Novu
 */

namespace teamnovu\sitecopy\helpers;

use Craft;

/**
 * Keeps track of queued copy jobs and of the result each job records when it runs.
 *
 * Craft deletes a queue job once it has run successfully, and the queue reports the
 * same status for a finished job, a job removed by hand and an ID it never knew. So
 * the job writes its own result to the cache, under a random key that is stored on
 * the job itself (queue job IDs are reused, e.g. after a database restore).
 *
 * Everything is best effort: a missing cache entry means "unknown", never "finished".
 */
class CopyJobTracker
{
    /**
     * How long queued-job records and results are kept, in seconds (7 days).
     */
    public const CACHE_DURATION = 604800;

    /**
     * How many recent job IDs are remembered per user.
     */
    public const MAX_RECENT_JOBS = 20;

    private const KEY_PREFIX = 'site-copy-x:';

    /**
     * Remembers a job that was just pushed to the queue.
     *
     * @param string $jobId
     * @param string $resultKey
     * @param int $elementId
     * @param int $sourceSiteId
     * @param int[] $siteIds
     * @param int|null $userId
     */
    public static function recordQueued(string $jobId, string $resultKey, int $elementId, int $sourceSiteId, array $siteIds, ?int $userId): void
    {
        $cache = Craft::$app->getCache();

        $cache->set(self::KEY_PREFIX . 'job:' . $jobId, [
            'jobId' => $jobId,
            'resultKey' => $resultKey,
            'elementId' => $elementId,
            'sourceSiteId' => $sourceSiteId,
            'siteIds' => array_values($siteIds),
            'userId' => $userId,
            'queuedAt' => time(),
        ], self::CACHE_DURATION);

        if ($userId === null) {
            return;
        }

        $key = self::KEY_PREFIX . 'user-jobs:' . $userId;
        $jobIds = $cache->get($key);
        $jobIds = is_array($jobIds) ? array_values(array_diff($jobIds, [$jobId])) : [];
        array_unshift($jobIds, $jobId);

        $cache->set($key, array_slice($jobIds, 0, self::MAX_RECENT_JOBS), self::CACHE_DURATION);
    }

    /**
     * Returns what was recorded when the job was queued, or null if nothing is known about it.
     *
     * @param string $jobId
     * @return array|null
     */
    public static function getQueued(string $jobId): ?array
    {
        $record = Craft::$app->getCache()->get(self::KEY_PREFIX . 'job:' . $jobId);

        return is_array($record) ? $record : null;
    }

    /**
     * Returns the IDs of the user's most recent copy jobs, newest first.
     *
     * @param int $userId
     * @return string[]
     */
    public static function getRecentJobIds(int $userId): array
    {
        $jobIds = Craft::$app->getCache()->get(self::KEY_PREFIX . 'user-jobs:' . $userId);

        return is_array($jobIds) ? array_values(array_map('strval', $jobIds)) : [];
    }

    /**
     * Stores the result of a job run.
     *
     * @param string $resultKey
     * @param array $result
     */
    public static function recordResult(string $resultKey, array $result): void
    {
        Craft::$app->getCache()->set(self::KEY_PREFIX . 'result:' . $resultKey, $result, self::CACHE_DURATION);
    }

    /**
     * Returns the result a job recorded, or null if it recorded none.
     *
     * @param string $resultKey
     * @return array|null
     */
    public static function getResult(string $resultKey): ?array
    {
        $result = Craft::$app->getCache()->get(self::KEY_PREFIX . 'result:' . $resultKey);

        return is_array($result) ? $result : null;
    }
}
