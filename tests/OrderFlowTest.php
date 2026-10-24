<?php
use App\Services\OrderService as O;
use App\Services\Payment\Payments;

check('every status has a FLOW entry', array_keys(O::FLOW) === ['pending', 'processing', 'shipped', 'completed', 'cancelled', 'returned']);
check('completed, cancelled and returned are terminal', O::FLOW['completed'] === [] && O::FLOW['cancelled'] === [] && O::FLOW['returned'] === []);
check('no skipping: pending cannot ship', !in_array('shipped', O::FLOW['pending'], true));
check('cancel only before shipping', in_array('cancelled', O::FLOW['processing'], true) && !in_array('cancelled', O::FLOW['shipped'], true));
check('every target status is a known status', array_reduce(O::FLOW, fn($ok, $to) => $ok && array_diff($to, array_keys(O::FLOW)) === [], true));
check('payment methods: cod + bank_transfer + billplz', array_keys(Payments::all()) === ['cod', 'bank_transfer', 'billplz']);
check('unknown payment method rejected', Payments::find('bitcoin') === null && Payments::find('') === null);
check('bank transfer instructions mention order number', str_contains(Payments::find('bank_transfer')->instructions(), 'order number'));
