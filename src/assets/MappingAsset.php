<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset Bundle used on mapping pages.
 */
class MappingAsset extends AssetBundle
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
        
        $this->css = [
            'css/mapping.css',
        ];
        
        $this->js = [
            'js/mapping.js',
        ];

        parent::init();
    }
}
