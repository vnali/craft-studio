<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\fields;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\BaseNativeField;

/**
 * Podcast type field
 */
class PodcastTypeField extends BaseNativeField
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
            ['label' => Craft::t('studio', 'Episodic'), 'value' => 'Episodic'],
            ['label' => Craft::t('studio', 'Serial'), 'value' => 'Serial'],
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
