<?php

namespace teamnovu\sitecopy\elements\actions;

use CraftCms\Cms\Element\Actions\ClientAction;

/**
 * Craft 6 only. Its Vue element index never calls getTriggerHtml(), so bulk copy is a client
 * action handled by bulk-copy.js.
 */
class BulkCopyClientAction
{
    public const EVENT = 'site-copy-x:bulk-copy';

    public static function create(): ClientAction
    {
        return new ClientAction(
            key: self::EVENT,
            label: BulkCopy::displayName(),
            event: self::EVENT,
        );
    }
}
