<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\base\conditions;

use craft\base\conditions\BaseNumberConditionRule;
use vnali\studio\helpers\Time;

abstract class BaseDurationConditionRule extends BaseNumberConditionRule
{
    /**
     * @inheritdoc
     */
    protected function inputType(): string
    {
        return 'text';
    }

    /**
     * @inheritdoc
     */
    protected function paramValue(): ?string
    {
        if (!ctype_digit((string)$this->value)) {
            $this->value = (string)Time::time_to_sec($this->value);
        }

        if (!ctype_digit((string)$this->maxValue)) {
            $this->maxValue = (string)Time::time_to_sec($this->maxValue);
        }

        return parent::paramValue();
    }
}
