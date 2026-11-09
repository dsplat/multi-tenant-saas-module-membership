<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Membership\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Membership\Services\MembershipSignalService;
use MultiTenantSaas\Modules\Order\Events\OrderPaid;

class OrderPaidMembershipListener
{
    public function __construct(
        protected MembershipSignalService $signalService,
    ) {}

    public function handle(OrderPaid $event): void
    {
        try {
            $this->signalService->handleOrderPaid($event);
        } catch (\Throwable $e) {
            // 支付已成功落库；成长/成就失败只记录，不影响支付回调应答。
            Log::warning('[Membership] OrderPaid 信号处理失败', [
                'tenant_id' => $event->tenantId,
                'order_id' => $event->orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
