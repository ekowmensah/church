<?php

/**
 * Extract church contribution and gateway fee without treating a Hubtel fee
 * item as member income.
 *
 * @return array{contribution_amount:?float,customer_paid_amount:?float,gateway_charge_amount:float,item_total:?float}
 */
function hubtel_ussd_separate_amounts(array $orderInfo): array
{
    $payment = is_array($orderInfo['Payment'] ?? null) ? $orderInfo['Payment'] : [];
    $items = is_array($orderInfo['Items'] ?? null) ? $orderInfo['Items'] : [];
    $customerPaid = isset($payment['AmountPaid']) && is_numeric($payment['AmountPaid'])
        ? (float) $payment['AmountPaid']
        : null;
    $afterCharges = isset($payment['AmountAfterCharges']) && is_numeric($payment['AmountAfterCharges'])
        ? (float) $payment['AmountAfterCharges']
        : null;
    $subtotal = isset($orderInfo['Subtotal']) && is_numeric($orderInfo['Subtotal'])
        ? (float) $orderInfo['Subtotal']
        : null;

    $itemTotal = 0.0;
    $pricedItems = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = strtolower(trim((string) ($item['Name'] ?? $item['name'] ?? '')));
        if ($name !== '' && preg_match('/\b(charge|fee|levy|processing)\b/i', $name)) {
            continue;
        }

        $lineAmount = null;
        foreach (['TotalAmount', 'totalAmount', 'TotalPrice', 'totalPrice', 'Amount', 'amount', 'Total', 'total'] as $key) {
            if (isset($item[$key]) && is_numeric($item[$key])) {
                $lineAmount = (float) $item[$key];
                break;
            }
        }
        if ($lineAmount === null) {
            $unitPrice = null;
            foreach (['UnitPrice', 'unitPrice', 'Price', 'price'] as $key) {
                if (isset($item[$key]) && is_numeric($item[$key])) {
                    $unitPrice = (float) $item[$key];
                    break;
                }
            }
            if ($unitPrice !== null) {
                $quantity = isset($item['Quantity']) && is_numeric($item['Quantity'])
                    ? (float) $item['Quantity']
                    : (isset($item['quantity']) && is_numeric($item['quantity']) ? (float) $item['quantity'] : 1.0);
                $lineAmount = $unitPrice * max(1.0, $quantity);
            }
        }
        if ($lineAmount !== null && $lineAmount > 0) {
            $itemTotal += $lineAmount;
            $pricedItems++;
        }
    }
    $itemTotal = $pricedItems > 0 ? round($itemTotal, 2) : null;

    // Item pricing is the best evidence of what the member selected. On the
    // live shortcode payload AmountAfterCharges represents that contribution
    // while Subtotal/AmountPaid may include the customer-borne Hubtel fee.
    $contribution = $itemTotal ?? $afterCharges ?? $subtotal ?? $customerPaid;
    $contribution = $contribution !== null ? round(max(0, $contribution), 2) : null;

    $charge = 0.0;
    if ($customerPaid !== null && $contribution !== null) {
        $charge = max($charge, $customerPaid - $contribution);
    }
    if ($customerPaid !== null && $afterCharges !== null) {
        $charge = max($charge, $customerPaid - $afterCharges);
    }

    return [
        'contribution_amount' => $contribution,
        'customer_paid_amount' => $customerPaid !== null ? round(max(0, $customerPaid), 2) : null,
        'gateway_charge_amount' => round(max(0, $charge), 2),
        'item_total' => $itemTotal,
    ];
}
