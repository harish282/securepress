<?php

declare(strict_types=1);

namespace SecurePress\Core\Audit\Listeners;

use SecurePress\Core\Audit\AuditEvent;
use SecurePress\Core\Audit\AuditEventCategory;
use SecurePress\Core\Audit\AuditEventLevel;
use SecurePress\Core\Audit\AuditLoggerInterface;
use SecurePress\Core\Support\WpHelper;

/**
 * Records security-relevant WooCommerce events.
 *
 * Only registers its hooks when WooCommerce is active (`class_exists('WooCommerce')`), so
 * it's safe to instantiate unconditionally — the hook attachment is the gate. We
 * deliberately track:
 *
 *  - **New orders** (notice) — useful baseline for fraud investigations.
 *  - **Status transitions** (notice → warning if cancelled / failed → completed by hand).
 *  - **Payment completion** (notice).
 *  - **Refunds** (warning) — direct money movement, always audit-worthy.
 *
 * Things we deliberately don't track yet: cart events, product views, customer profile
 * changes — high-volume, low signal-to-noise. Add them in your own listener if needed.
 */
final class WooCommerceListener implements ListenerInterface
{
    public function __construct(private readonly AuditLoggerInterface $logger)
    {
    }

    public function register(): void
    {
        if (!class_exists('WooCommerce', false)) {
            return;
        }

        WpHelper::addAction('woocommerce_new_order', [$this, 'onNewOrder'], 10, 1);
        WpHelper::addAction('woocommerce_order_status_changed', [$this, 'onOrderStatusChanged'], 10, 4);
        WpHelper::addAction('woocommerce_payment_complete', [$this, 'onPaymentComplete'], 10, 1);
        WpHelper::addAction('woocommerce_order_refunded', [$this, 'onOrderRefunded'], 10, 2);
    }

    public function onNewOrder(int $orderId): void
    {
        $event = AuditEvent::make('order.created', AuditEventCategory::WOOCOMMERCE, AuditEventLevel::NOTICE)
            ->withTarget('order', (string) $orderId)
            ->withMessage(sprintf('New WooCommerce order #%d created.', $orderId));

        $this->logger->record($event);
    }

    public function onOrderStatusChanged(int $orderId, string $oldStatus, string $newStatus, mixed $order = null): void
    {
        $level = $this->levelForStatusChange($oldStatus, $newStatus);

        $event = AuditEvent::make('order.status.changed', AuditEventCategory::WOOCOMMERCE, $level)
            ->withTarget('order', (string) $orderId)
            ->withMessage(sprintf('Order #%d: %s → %s', $orderId, $oldStatus, $newStatus))
            ->withContext([
                'order_id' => $orderId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

        $this->logger->record($event);
    }

    public function onPaymentComplete(int $orderId): void
    {
        $event = AuditEvent::make('order.payment_complete', AuditEventCategory::WOOCOMMERCE, AuditEventLevel::NOTICE)
            ->withTarget('order', (string) $orderId)
            ->withMessage(sprintf('Payment captured for order #%d.', $orderId));

        $this->logger->record($event);
    }

    public function onOrderRefunded(int $orderId, int $refundId): void
    {
        $event = AuditEvent::make('order.refunded', AuditEventCategory::WOOCOMMERCE, AuditEventLevel::WARNING)
            ->withTarget('order', (string) $orderId)
            ->withMessage(sprintf('Order #%d was refunded (refund #%d).', $orderId, $refundId))
            ->withContext([
                'order_id' => $orderId,
                'refund_id' => $refundId,
            ]);

        $this->logger->record($event);
    }

    private function levelForStatusChange(string $oldStatus, string $newStatus): string
    {
        $reopened = in_array($oldStatus, ['cancelled', 'failed'], true)
            && in_array($newStatus, ['processing', 'completed'], true);

        if ($reopened || $newStatus === 'cancelled') {
            return AuditEventLevel::WARNING;
        }

        return AuditEventLevel::NOTICE;
    }
}
