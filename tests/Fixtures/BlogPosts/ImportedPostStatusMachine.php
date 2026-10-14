<?php

namespace RobertBoes\Interlock\Tests\Fixtures\BlogPosts;

use RobertBoes\Interlock\Attributes\FinalStates;
use RobertBoes\Interlock\Attributes\InitialStates;

/**
 * The post lifecycle for posts imported from elsewhere, which may arrive as a draft or ready to publish.
 */
#[InitialStates(PostStatus::Draft, PostStatus::Ready)]
#[FinalStates(PostStatus::Published)]
class ImportedPostStatusMachine extends PostStatusMachine {}
