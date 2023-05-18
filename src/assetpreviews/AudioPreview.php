<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\assetpreviews;

use Craft;
use craft\base\AssetPreviewHandler;
use yii\base\NotSupportedException;

/**
 * Audio preview class
 */
class AudioPreview extends AssetPreviewHandler
{
    /**
     * @inheritdoc
     */
    public function getPreviewHtml(array $variables = []): string
    {
        $url = $this->asset->getUrl();
        if ($url === null) {
            throw new NotSupportedException('Preview not supported.');
        }

        $view = Craft::$app->view;
        $view->startJsBuffer();
        $variables['createField'] = $view->renderTemplate('studio/assets/_previews/chapter.twig');
        $variables['createFieldJs'] = $view->clearJsBuffer(false);

        return Craft::$app->getView()->renderTemplate(
            'studio/assets/_previews/audio',
            array_merge([
                'asset' => $this->asset,
                'url' => $url,
            ], $variables)
        );
    }
}
