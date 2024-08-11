<?php
declare(strict_types=1);

namespace Zim\SymfonyTracingMessengerBundle\Instrumentation\Messenger;

use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Contracts\Service\ResetInterface;
use Zim\SymfonyTracingCoreBundle\RootContextProvider;
use Zim\SymfonyTracingCoreBundle\ScopedSpan;
use Zim\SymfonyTracingCoreBundle\ScopedTracerInterface;

class MessengerEventSubscriber implements EventSubscriberInterface, ResetInterface
{
    private ?ScopedSpan $currentSpan;

    public function __construct(
        private readonly RootContextProvider $rootContextProvider,
        private readonly ScopedTracerInterface $tracer,
    )
    {
        $this->currentSpan = null;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SendMessageToTransportsEvent::class => [
                ['onSendToTransport', 0],
            ],
            WorkerMessageReceivedEvent::class => [
                ['onMessageReceived', 0],
            ],
            WorkerMessageHandledEvent::class => [
                ['onMessageHandled', 0],
            ],
            WorkerMessageFailedEvent::class => [
                ['onMessageFailed', 0],
            ],
        ];
    }

    public function onSendToTransport(SendMessageToTransportsEvent $event): void
    {
        $envelope = $this->propagateContext(
            $event->getEnvelope()
        );

        $event->setEnvelope($envelope);
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        $stamp = $event->getEnvelope()->last(ContextPropagationStamp::class);

        if ($stamp === null) {
            return;
        }

        $rootContext = TraceContextPropagator::getInstance()->extract($stamp->context);

        $this->currentSpan = $this->tracer->startSpan(
            name: $this->generateSpanName($event->getEnvelope()),
            spanKing:  SpanKind::KIND_CONSUMER,
            parentContext: $rootContext
        );

        $this->currentSpan->getSpan()->setAttribute('receiver', $event->getReceiverName());
        $this->currentSpan->getSpan()->setAttribute('message', var_export($event->getEnvelope()->getMessage(), true));
        $this->rootContextProvider->set(Context::getCurrent());
    }

    public function onMessageHandled(): void
    {
        $this->reset();
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $currentContext = $this->rootContextProvider->get();

        if ($currentContext) {
            $span = Span::fromContext($currentContext);
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->recordException($event->getThrowable());
        }

        $this->reset();
    }

    public function reset(): void
    {
        if ($this->currentSpan !== null) {
            $this->currentSpan->end();
            $this->currentSpan = null;
        }

        $this->rootContextProvider->reset();
    }

    private function propagateContext(Envelope $envelope): Envelope
    {
        $carrier = [];
        $context = [];
        TraceContextPropagator::getInstance()->inject($carrier);

        foreach ($carrier as $key => $value) {
            $context[$key] = $value;
        }

        $stamp = new ContextPropagationStamp($context);

        return $envelope->with($stamp);
    }

    private function generateSpanName(Envelope $envelope): string
    {
        $message = $envelope->getMessage();
        return sprintf('Consumer message: %s', get_class($message));
    }
}
