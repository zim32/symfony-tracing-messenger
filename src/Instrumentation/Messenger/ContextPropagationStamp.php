<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingMessengerBundle\Instrumentation\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

class ContextPropagationStamp implements StampInterface
{
    public function __construct(
        public readonly array $context,
    )
    {
    }
}
