<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\fields;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;

/**
 * Podcast medium field
 */
class PodcastMediumField extends BaseNativeField
{
    /**
     * @var string The input type
     */
    public string $type = 'dropdown';

    public $options;

    /**
     * @inheritdoc
     */
    public function __construct($config = [])
    {
        $this->options = [
            ['label' => Craft::t('studio', 'Podcast'), 'value' => 'podcast'],
            ['label' => Craft::t('studio', 'Music'), 'value' => 'music'],
            ['label' => Craft::t('studio', 'Video'), 'value' => 'video'],
            ['label' => Craft::t('studio', 'Film'), 'value' => 'film'],
            ['label' => Craft::t('studio', 'Audiobook'), 'value' => 'audiobook'],
            ['label' => Craft::t('studio', 'Newsletter'), 'value' => 'newsletter'],
            ['label' => Craft::t('studio', 'Blog'), 'value' => 'blog'],
        ];

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml(?ElementInterface $element = null, bool $static = false): string
    {
        if ($this->type == 'radio') {
            return Craft::$app->getView()->renderTemplate('_includes/forms/radioGroup', [
                'name' => $this->attribute(),
                'value' => $this->value($element),
                'options' => $this->options,
            ]);
        } else {
            return Craft::$app->getView()->renderTemplate('_includes/forms/select', [
                'id' => $this->id(),
                'name' => $this->attribute(),
                'value' => $this->value($element),
                'options' => $this->options,
            ]);
        }
    }
}
