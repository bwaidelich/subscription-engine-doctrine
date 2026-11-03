<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine\Tests\Integration;

use Wwwision\SubscriptionEngine\Subscription\Position;

final readonly class TestEvent
{
    public function __construct(
        public Position $position,
    ) {}
}
