<?php

namespace App\Observers;

use App\Actions\Tenants\InheritParentTenant;
use App\Models\OrderPayment;
use App\Models\Payment;

class OrderPaymentObserver
{
    /**
     * Take the payment's tenant, and refuse an allocation naming another tenant's payment.
     */
    public function saving(OrderPayment $orderPayment): void
    {
        app(InheritParentTenant::class)($orderPayment, Payment::class, 'payment_id');
    }
}
