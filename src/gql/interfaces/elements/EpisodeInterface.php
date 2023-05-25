<?php

namespace vnali\studio\gql\interfaces\elements;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use vnali\studio\elements\Episode;
use vnali\studio\gql\types\generators\EpisodeGenerator;

class EpisodeInterface extends Element
{
    /**
     * @inheritdoc
     */
    public static function getTypeGenerator(): string
    {
        return EpisodeGenerator::class;
    }

    /**
     * @inheritdoc
     */
    public static function getType($fields = null): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all episodes.',
            'resolveType' => function(Episode $value) {
                return $value->getGqlTypeName();
            },
        ]));

        EpisodeGenerator::generateTypes();

        return $type;
    }

    /**
     * @inheritdoc
     */
    public static function getName(): string
    {
        return 'EpisodeInterface';
    }

    /**
     * @inheritdoc
     */
    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
            'duration' => [
                'name' => 'duration',
                'type' => Type::STRING(),
                'description' => 'Episode Duration',
            ],
            'episodeSeason' => [
                'name' => 'episodeSeason',
                'type' => Type::INT(),
                'description' => 'Episode Season',
            ],
            'episodeSeasonName' => [
                'name' => 'episodeSeasonName',
                'type' => Type::STRING(),
                'description' => 'Episode Season Name',
            ],
            'episodeNumber' => [
                'name' => 'episodeNumber',
                'type' => Type::INT(),
                'description' => 'Episode Number',
            ],
            'episodeType' => [
                'name' => 'episodeType',
                'type' => Type::STRING(),
                'description' => 'Episode type',
            ],
            'episodeBlock' => [
                'name' => 'episodeBlock',
                'type' => Type::boolean(),
                'description' => 'Episode block',
            ],
            'episodeExplicit' => [
                'name' => 'episodeExplicit',
                'type' => Type::boolean(),
                'description' => 'Episode Explicit',
            ],
            'publishOnRSS' => [
                'name' => 'publishOnRSS',
                'type' => Type::boolean(),
                'description' => 'Publish on RSS',
            ],
            'episodeGUID' => [
                'name' => 'episodeGUID',
                'type' => Type::STRING(),
                'description' => 'GUID',
            ],
        ]), self::getName());
    }
}
