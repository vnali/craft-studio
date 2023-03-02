<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset Bundle used on edit site settings pages.
 */
class EditSiteSettingsAsset extends AssetBundle
{
    /**
     * @inheritDoc
     */
    public function init()
    {
        $this->sourcePath = "@vnali/studio/resources";

        $this->depends = [
            CpAsset::class,
        ];
        
        $this->js = [
            'js/site-settings.js',
        ];

        parent::init();
    }
}
