<?php
use App\Services\Shipping\NinjaVanGateway as G;

$today = new DateTimeImmutable('2026-10-22');
$ok = ['weight' => '1.5', 'service_level' => 'Standard', 'delivery_date' => '2026-10-23', 'delivery_slot' => '09:00-18:00', 'pickup' => '1', 'pickup_date' => '2026-10-22', 'pickup_slot' => '12:00-15:00', 'volume' => 'Less than 3 Parcels'];
check('valid shipment input passes', G::validateInput($ok, $today)['errors'] === []);
check('weight bounds and format', array_keys(G::validateInput(['weight' => '0'] + $ok, $today)['errors']) === ['weight'] && array_keys(G::validateInput(['weight' => '51'] + $ok, $today)['errors']) === ['weight'] && array_keys(G::validateInput(['weight' => '1e2'] + $ok, $today)['errors']) === ['weight'] && array_keys(G::validateInput(['weight' => '1.234'] + $ok, $today)['errors']) === ['weight']);
check('unknown service level / time window rejected', array_keys(G::validateInput(['service_level' => 'Sameday'] + $ok, $today)['errors']) === ['service_level'] && array_keys(G::validateInput(['delivery_slot' => '01:00-02:00'] + $ok, $today)['errors']) === ['delivery_slot']);
check('delivery date: past, too far, malformed rejected; today and +14 allowed', array_keys(G::validateInput(['delivery_date' => '2026-10-21'] + $ok, $today)['errors']) === ['delivery_date'] && array_keys(G::validateInput(['delivery_date' => '2026-11-06'] + $ok, $today)['errors']) === ['delivery_date'] && array_keys(G::validateInput(['delivery_date' => '2026-13-45'] + $ok, $today)['errors']) === ['delivery_date'] && G::validateInput(['delivery_date' => '2026-10-22'] + $ok, $today)['errors'] === [] && G::validateInput(['delivery_date' => '2026-11-05'] + $ok, $today)['errors'] === []);
check('pickup fields only validated when pickup is requested', G::validateInput(['pickup' => '', 'pickup_date' => 'junk', 'volume' => 'x'] + $ok, $today)['errors'] === [] && array_keys(G::validateInput(['pickup_date' => '2026-10-30', 'volume' => 'nope'] + $ok, $today)['errors']) === ['pickup_date', 'volume']);

check('phone -> E.164 (MY)', G::phone('012-3456789') === '+60123456789' && G::phone('60123456789') === '+60123456789' && G::phone('+60 12 345 6789') === '+60123456789' && G::phone('(012) 345 6789') === '+60123456789' && G::phone('abc') === null);
check('requested tracking: 1-9 letters/digits, unique per order, retries add a digit', G::requestedTracking(1, 1) === 'O000001' && G::requestedTracking(1, 3) === 'O0000013' && G::requestedTracking(1295, 1) === 'O0000ZZ' && preg_match('/^[A-Za-z0-9]{1,9}$/', G::requestedTracking(2176782335, 9)) === 1 && G::requestedTracking(1, 1) !== G::requestedTracking(2, 1));

$order = ['order_no' => 'ORD-ABC1234567', 'customer_name' => 'Al', 'customer_phone' => '012-3456789', 'customer_email' => 'al@x.test', 'ship_line1' => '1 Jalan Satu', 'ship_line2' => null, 'ship_city' => 'Petaling Jaya', 'ship_state' => 'Selangor', 'ship_postcode' => '47800', 'total' => '123.50', 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'notes' => 'Leave at guard house'];
$items = [['product_name' => 'Widget', 'variant_label' => 'Red / M', 'qty' => 2], ['product_name' => 'Gadget', 'variant_label' => null, 'qty' => 1]];
$sender = ['sender_name' => 'Lure Shop', 'sender_phone' => '012-1112222', 'sender_email' => 'ship@lure.test', 'sender_address1' => '9 Jalan Kilang', 'sender_address2' => '', 'sender_postcode' => '50000', 'sender_city' => 'Kuala Lumpur', 'sender_state' => 'Kuala Lumpur'];
$v = G::validateInput($ok, $today)['values'];
$p = G::buildPayload($order, $items, $sender, $v, 'ORD-ABC1234567');
check('payload: required top-level keys + service type', array_keys($p) === ['service_type', 'service_level', 'requested_tracking_number', 'reference', 'from', 'to', 'parcel_job'] && $p['service_type'] === 'Parcel' && $p['reference']['merchant_order_number'] === 'ORD-ABC1234567');
check('payload: 2-letter customer name padded to NinjaVan minimum of 3', mb_strlen($p['to']['name']) >= 3 && str_starts_with($p['to']['name'], 'Al'));
check('payload: MY address fields, empty address2 omitted, E.164 phones', $p['to']['address'] === ['address1' => '1 Jalan Satu', 'country' => 'MY', 'postcode' => '47800', 'city' => 'Petaling Jaya', 'state' => 'Selangor'] && $p['to']['phone_number'] === '+60123456789' && $p['from']['phone_number'] === '+60121112222');
check('payload: items carry description (with variant) and quantity', $p['parcel_job']['items'] === [['item_description' => 'Widget (Red / M)', 'quantity' => 2], ['item_description' => 'Gadget', 'quantity' => 1]]);
check('payload: COD unpaid -> cash_on_delivery = order total', $p['parcel_job']['cash_on_delivery'] === 123.5);
check('payload: delivery window + weight + weekend delivery', $p['parcel_job']['delivery_start_date'] === '2026-10-23' && $p['parcel_job']['delivery_timeslot'] === ['start_time' => '09:00', 'end_time' => '18:00', 'timezone' => 'Asia/Kuala_Lumpur'] && $p['parcel_job']['dimensions'] === ['weight' => 1.5] && $p['parcel_job']['allow_weekend_delivery'] === true);
check('payload: pickup requested -> scheduled pickup fields + pickup address', $p['parcel_job']['is_pickup_required'] === true && $p['parcel_job']['pickup_service_type'] === 'Scheduled' && $p['parcel_job']['pickup_date'] === '2026-10-22' && $p['parcel_job']['pickup_timeslot']['start_time'] === '12:00' && $p['parcel_job']['pickup_approximate_volume'] === 'Less than 3 Parcels' && $p['parcel_job']['pickup_address']['name'] === 'Lure Shop');
check('payload: delivery instructions from the order note', $p['parcel_job']['delivery_instructions'] === 'Leave at guard house');
$paid = G::buildPayload(['payment_method' => 'billplz', 'payment_status' => 'paid', 'notes' => ''] + $order, $items, $sender, G::validateInput(['pickup' => ''] + $ok, $today)['values'], 'ORD-ABC1234567');
check('payload: paid order -> no COD, no note, no pickup fields', !isset($paid['parcel_job']['cash_on_delivery']) && !isset($paid['parcel_job']['delivery_instructions']) && $paid['parcel_job']['is_pickup_required'] === false && !isset($paid['parcel_job']['pickup_date']));
check('payload encodes to valid JSON', is_array(json_decode(json_encode($p), true)));

// Vector computed independently: printf body | openssl dgst -sha256 -hmac nv-secret -binary | base64
$body = '{"tracking_id":"X1","status":"Completed"}';
$sig = 'lPtDvRHJoaw6GCBqWM29gE63Mhk9npIHWpcT07HQfZE=';
check('webhook signature matches the openssl vector', base64_encode(hash_hmac('sha256', $body, 'nv-secret', true)) === $sig);
check('valid webhook accepted', G::webhookValid($body, $sig, 'nv-secret'));
check('wrong key / altered body / hex instead of base64 / empty / missing rejected', !G::webhookValid($body, $sig, 'other') && !G::webhookValid($body . ' ', $sig, 'nv-secret') && !G::webhookValid($body, bin2hex(base64_decode($sig)), 'nv-secret') && !G::webhookValid($body, '', 'nv-secret') && !G::webhookValid($body, null, 'nv-secret'));
