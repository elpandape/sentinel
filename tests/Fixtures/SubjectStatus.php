<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

enum SubjectStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
