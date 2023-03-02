<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\fields;

use Craft;
use craft\base\ElementInterface;
use craft\fieldlayoutelements\TextField;
use vnali\studio\helpers\Time;

class DurationField extends TextField
{
    /**
     * @var string The input type
     */
    public string $type = 'text';

    protected function value(?ElementInterface $element = null): mixed
    {
        if (ctype_digit((string) $element->{$this->attribute()}) && isset($element->{$this->attribute()})) {
            return Time::sec_to_time($element->{$this->attribute()});
        } else {
            return $element->{$this->attribute()};
        }
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml(?ElementInterface $element = null, bool $static = false): string
    {
        return Craft::$app->getView()->renderTemplate('_includes/forms/text', [
            'name' => $this->attribute(),
            'value' => $this->value($element),
            'required' => $this->required,
        ]);
    }
}
