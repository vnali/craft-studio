<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\fields;

use Craft;
use craft\base\ElementInterface;

class NativeLightswitchField extends \craft\fieldlayoutelements\BaseNativeField
{
    public $class;

    public $name;

    public string $attribute;

    public $value;

    public function fields(): array
    {
        $fields = parent::fields();

        // Don't include the value
        //unset($fields['value']);

        return $fields;
    }

    protected function value(?ElementInterface $element = null): mixed
    {
        return $element->{$this->attribute()} ?? "0";
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        return Craft::$app->getView()->renderTemplate('_includes/forms/lightswitch', [
            'class' => $this->class,
            'on' => $this->value($element),
            'value' => 1,
            'name' => $this->name ?? $this->attribute(),
        ]);
    }
}
