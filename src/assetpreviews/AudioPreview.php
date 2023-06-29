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

        $variables['speakers'] = [
            ['value' => '', 'label' => Craft::t('studio', 'Select/Create speaker')],
        ];

        $view->startJsBuffer();
        $variables['createCaption'] = $view->renderTemplate('studio/assets/_previews/caption.twig', $variables);
        $variables['createCaptionJs'] = $view->clearJsBuffer(false);

        $view->startJsBuffer();
        $variables['createChapter'] = $view->renderTemplate('studio/assets/_previews/chapter.twig');
        $variables['createChapterJs'] = $view->clearJsBuffer(false);

        $view->startJsBuffer();
        $variables['createSoundbite'] = $view->renderTemplate('studio/assets/_previews/soundbite.twig');
        $variables['createSoundbiteJs'] = $view->clearJsBuffer(false);

        return Craft::$app->getView()->renderTemplate(
            'studio/assets/_previews/audio',
            array_merge([
                'asset' => $this->asset,
                'url' => $url,
            ], $variables)
        );
    }
}
