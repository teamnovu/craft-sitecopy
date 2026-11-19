<?php

namespace teamnovu\sitecopy\assetbundles\sitecopy;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;

class SitecopyAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = "@teamnovu/sitecopy/assetbundles/sitecopy/dist";

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/sitecopy.js',
        ];

        $this->css = [
            'css/sitecopy.css',
        ];

        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function registerAssetFiles($view): void
    {
        parent::registerAssetFiles($view);

        if ($view instanceof View) {
            $view->registerTranslations('site-copy-x', [
                'Warning: the element does not exist on the following sites and will not be copied: {sites}',
                'Warning: some of the selected elements do not exist on the following sites and will not be copied there: {sites}',
            ]);
        }
    }
}
