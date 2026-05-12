<?php

declare(strict_types=1);

namespace SecurePress\WooCommerce\Pipelines;

use SecurePress\WooCommerce\Detection\DetectionContext;
use SecurePress\WooCommerce\Detection\Decision;
use SecurePress\WooCommerce\Middleware\WcMiddlewareInterface;

/**
 * Reusable feature-pipeline base.
 *
 * Subclasses (CheckoutPipeline, RegistrationPipeline, …) supply their middleware
 * collection and a default "no middleware blocked us" outcome. The base handles:
 *
 *  - timing the pipeline run for telemetry;
 *  - composing the middleware in declared order into a single callable chain;
 *  - giving each middleware a `$next` it can call OR short-circuit by returning a
 *    `Decision::deny(...)` directly.
 *
 * Why not reuse the existing {@see \SecurePress\Core\Middleware\MiddlewarePipeline}:
 * the existing one threads a generic `array<string, mixed>` payload, which is fine
 * for HTTP-shaped middleware but forces every WC middleware to re-parse signals out
 * of and back into an untyped bag. With a domain-specific payload + result we get
 * static analysis and IDE completion across every WC middleware for free.
 *
 * `defaultDecision()` is the outcome when every middleware called `$next(...)` and
 * nobody emitted a final {@see Decision} — i.e., the pipeline implicitly accepted the
 * request because none of the rules tripped.
 */
abstract class AbstractFeaturePipeline
{
    /** @var list<WcMiddlewareInterface> */
    private array $middleware = [];

    /**
     * @param list<WcMiddlewareInterface> $middleware
     */
    public function __construct(array $middleware = [])
    {
        foreach ($middleware as $m) {
            $this->push($m);
        }
    }

    public function push(WcMiddlewareInterface $middleware): static
    {
        $this->middleware[] = $middleware;

        return $this;
    }

    /**
     * @return list<WcMiddlewareInterface>
     */
    public function middleware(): array
    {
        return $this->middleware;
    }

    public function run(DetectionContext $context): PipelineResult
    {
        $started = microtime(true);

        // `$latest` accumulates the most enriched context that reached the tail of
        // the pipeline. We thread it through `$next` so the PipelineResult exposes
        // every signal middleware appended — important for the audit log, which
        // reads `$result->context->signals`.
        $latest = $context;
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            static function (callable $next, WcMiddlewareInterface $middleware): callable {
                return static fn (DetectionContext $context): Decision => $middleware->handle($context, $next);
            },
            function (DetectionContext $context) use (&$latest): Decision {
                $latest = $context;

                return $this->defaultDecision($context);
            },
        );

        $decision = $pipeline($context);
        // When a middleware short-circuited with deny/challenge, the Decision still
        // carries the running signal set — merge it back into the result's context
        // so consumers don't have to inspect both fields.
        if ($decision->signals !== [] && count($decision->signals) > count($latest->signals)) {
            $latest = new DetectionContext(
                kind: $latest->kind,
                ip: $latest->ip,
                userAgent: $latest->userAgent,
                email: $latest->email,
                userId: $latest->userId,
                data: $latest->data,
                signals: $decision->signals,
                score: $decision->score,
                occurredAt: $latest->occurredAt,
                route: $latest->route,
                referer: $latest->referer,
                sessionId: $latest->sessionId,
            );
        }
        $elapsedMs = (microtime(true) - $started) * 1000.0;

        return new PipelineResult($latest, $decision, $elapsedMs);
    }

    protected function defaultDecision(DetectionContext $context): Decision
    {
        return Decision::accept($context->score, $context->signals);
    }
}
