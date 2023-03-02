<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * StudioCssAsset Bundle
 */
class StudioCssAsset extends AssetBundle
{
    /**
    * @inheritdoc
    */
    public function init()
    {
        $this->sourcePath = '@vnali/studio/resources';

        $this->depends = [
            CpAsset::class,
        ];

        /*
        $this->css = [
            'css/tailwind.css',
            'css/custom.css',
        ];
        */

        parent::init();
    }
}
