<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Feeds;

use Illuminate\Database\Eloquent\Builder;

/**
 * An app's own tappable scope, passed to trigger() as it is.
 */
final class PublishAtHasPassed
{
    /**
     * @param  Builder<FeedPost>  $query
     */
    public function __invoke(Builder $query): void
    {
        $query->where('publish_at', '<=', now());
    }
}
