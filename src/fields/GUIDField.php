<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\fields;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\TextField;

class GUIDField extends TextField
{
    /**
     * @var string The input type
     */
    public string $type = 'text';

    /**
     * @inheritdoc
     */
    protected function inputHtml(?ElementInterface $element = null, bool $static = false): string
    {
        return Craft::$app->getView()->renderTemplate('_includes/forms/text', [
            'name' => $this->attribute(),
            'value' => $this->value($element),
        ]);
    }
}
