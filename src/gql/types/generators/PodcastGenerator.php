<?php

namespace vnali\studio\gql\types\generators;

use Craft;
use craft\gql\base\Generator;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Gql as GqlHelper;

use vnali\studio\elements\Podcast;
use vnali\studio\gql\interfaces\elements\PodcastInterface;
use vnali\studio\gql\types\PodcastType;
use vnali\studio\Studio;

class PodcastGenerator extends Generator implements GeneratorInterface, SingleGeneratorInterface
{
    // Public Methods
    // =========================================================================
    public static function generateTypes(mixed $context = null): array
    {
        $podcastFormats = Studio::$plugin->podcastFormats->getAllPodcastFormats();
        $gqlTypes = [];

        foreach ($podcastFormats as $podcastFormat) {
            $requiredContexts = Podcast::gqlScopesByContext($podcastFormat);

            if (!GqlHelper::isSchemaAwareOf($requiredContexts)) {
                if (!GqlHelper::canSchema('podcastFormats.all')) {
                    continue;
                }
            }

            $type = static::generateType($podcastFormat);
            $gqlTypes[$type->name] = $type;
        }

        return $gqlTypes;
    }

    public static function generateType(mixed $context): mixed
    {
        $typeName = Podcast::gqlTypeNameByContext($context);

        if ($createdType = GqlEntityRegistry::getEntity($typeName)) {
            return $createdType;
        }

        $contentFieldGqlTypes = self::getContentFields($context);
        $podcastFormatFields = Craft::$app->getGql()->prepareFieldDefinitions(array_merge(PodcastInterface::getFieldDefinitions(), $contentFieldGqlTypes), $typeName);

        return GqlEntityRegistry::createEntity($typeName, new PodcastType([
            'name' => $typeName,
            'fields' => function() use ($podcastFormatFields) {
                return $podcastFormatFields;
            },
        ]));
    }
}
