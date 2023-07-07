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

    public ?string $chapterBlockType = null;

    public ?string $soundbiteField = null;
    
    public ?string $soundbiteBlockType = null;

    public ?string $fundingField = null;

    public ?string $fundingBlockType = null;
    
    public ?string $podcastLicenseField = null;

    public ?string $podcastLicenseBlockType = null;

    public ?string $episodeLicenseField = null;

    public ?string $episodeLicenseBlockType = null;

    public ?string $podcastPersonField = null;

    public ?string $podcastPersonBlockType = null;

    public ?string $episodePersonField = null;

    public ?string $episodePersonBlockType = null;

    public ?string $transcriptTextField = null;

    public ?string $transcriptField = null;

    public ?string $transcriptBlockType = null;

    public ?string $trailerField = null;

    public ?string $trailerBlockType = null;

    public ?string $enclosureField = null;

    public ?string $enclosureBlockType = null;

    public function rules(): array
    {
        $rules = parent::rules();
        $rules[] = [['checkAccessToVolumes'], 'in', 'range' => [0, 1]];
        return $rules;
    }
}
