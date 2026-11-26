<?php

namespace teamnovu\sitecopy\assetbundles\sitecopy;

use craft\web\AssetBundle;

/**
 * Craft 6 only: listens for the bulk copy client action on element index pages.
 */
class BulkCopyAsset extends AssetBundle
{
    public function init()
    {
        $this->sourcePath = '@teamnovu/sitecopy/assetbundles/sitecopy/dist';
        $this->depends = [SitecopyAsset::class];
        $this->js = ['js/bulk-copy.js'];

        parent::init();
    }
}
