<?php

namespace vnali\studio\gql\types;

use craft\gql\types\elements\Element;

use GraphQL\Type\Definition\ResolveInfo;

use vnali\studio\gql\interfaces\elements\PodcastInterface;

class PodcastType extends Element
{
    // Public Methods
    // =========================================================================
    public function __construct(array $config)
    {
        $config['interfaces'] = [
            PodcastInterface::getType(),
        ];

        parent::__construct($config);
    }

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        $fieldName = $resolveInfo->fieldName;

        return $source->$fieldName;
    }
}
