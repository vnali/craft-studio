<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\studio\models;

use craft\base\Model;

class Settings extends Model
{
    public ?bool $checkAccessToVolumes = null;

    public ?string $chapterField = null;

    public ?string $chapterEntryType = null;

    public ?string $soundbiteField = null;
    
    public ?string $soundbiteEntryType = null;

    public ?string $fundingField = null;

    public ?string $fundingEntryType = null;
    
    public ?string $podcastLicenseField = null;

    public ?string $podcastLicenseEntryType = null;

    public ?string $episodeLicenseField = null;

    public ?string $episodeLicenseEntryType = null;

    public ?string $podcastPersonField = null;

    public ?string $podcastPersonEntryType = null;

    public ?string $episodePersonField = null;

    public ?string $episodePersonEntryType = null;

    public ?string $transcriptTextField = null;

    public ?string $transcriptField = null;

    public ?string $transcriptEntryType = null;

    public ?string $trailerField = null;

    public ?string $trailerEntryType = null;

    public ?string $enclosureField = null;

    public ?string $enclosureEntryType = null;

    public ?string $podcastLocationField = null;

    public ?string $podcastLocationEntryType = null;

    public ?string $episodeLocationField = null;

    public ?string $episodeLocationEntryType = null;

    public ?string $liveItemField = null;

    public ?string $liveItemEntryType = null;

    public ?string $socialInteractField = null;

    public ?string $podcastTxtField = null;

    public ?string $episodeTxtField = null;

    public ?string $podcastValueField = null;

    public ?string $podcastValueEntryType = null;

    public ?string $episodeValueField = null;

    public ?string $episodeValueEntryType = null;

    public ?string $podrollField = null;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['checkAccessToVolumes'], 'in', 'range' => [0, 1]];
        return $rules;
    }
}
