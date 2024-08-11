<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingMessengerBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Zim\SymfonyTracingMessengerBundle\Instrumentation\Messenger\MessengerEventSubscriber;

class SymfonyTracingMessengerBundle extends AbstractBundle
{
    protected string $extensionAlias = 'messenger_tracing';

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $definition = (new Definition(MessengerEventSubscriber::class))
            ->addTag('kernel.event_subscriber')
            ->addTag('kernel.reset', ['method' => 'reset'])
            ->setArguments([
                '$rootContextProvider' => new Reference('tracing.root_context_provider'),
                '$tracer' => new Reference('tracing.scoped_tracer.default')
            ])
        ;

        $builder->setDefinition(MessengerEventSubscriber::class, $definition);
    }
}
