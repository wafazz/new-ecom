<?php
use App\Services\Payment\BillplzGateway as G;
use App\Services\Payment\Billplz;
use App\Services\Payment\Payments;

// Vectors computed independently with `openssl dgst -sha256 -hmac` (not with this code).
check('callback signature matches the openssl vector', G::sign(['id' => 'zq0tm2wc', 'paid' => 'true'], 'S-test') === '7f3951e7d595b1c297f51220c7567017bbf079506d1615fd06f6d1caed05add4');
check('sorting is case-insensitive and empty values keep just the key', G::sign(['paid' => 'true', 'mobile' => '', 'id' => '1', 'Amount' => '100'], 'k') === 'df7190d4b6564797a22f93316a611051a0ea0a2fc39231c276cf6c338e9b3b8a');

$post = ['id' => 'zq0tm2wc', 'paid' => 'true', 'x_signature' => '7f3951e7d595b1c297f51220c7567017bbf079506d1615fd06f6d1caed05add4'];
check('valid callback accepted', G::callbackValid($post, 'S-test'));
check('callback with a different key rejected', !G::callbackValid($post, 'S-other'));
check('changed value rejected', !G::callbackValid(['id' => 'zq0tm2wc', 'paid' => 'false', 'x_signature' => $post['x_signature']], 'S-test'));
check('extra field rejected (signature covers every field)', !G::callbackValid($post + ['amount' => '1'], 'S-test'));
check('missing / non-string signature rejected', !G::callbackValid(['id' => 'x', 'paid' => 'true'], 'S-test') && !G::callbackValid(['id' => 'x', 'x_signature' => ['a']], 'S-test'));
check('array-valued field rejected, no crash', !G::callbackValid(['id' => ['x'], 'x_signature' => 'abc'], 'S-test'));
check('empty signature rejected', !G::callbackValid(['id' => 'zq0tm2wc', 'paid' => 'true', 'x_signature' => ''], 'S-test'));

$red = ['id' => 'zq0tm2wc', 'paid' => 'true', 'paid_at' => '2026-10-22 10:00:00 +0800', 'x_signature' => 'a3c7dd9a21ab4b57b4d7ac2877ee27df9be90247fa08d67fb66c031ede76f413'];
check('valid redirect accepted (billplz prefix, paid_at sorts before paid)', G::redirectValid($red, 'S-test'));
check('redirect signature is NOT valid as a callback signature', !G::callbackValid($red, 'S-test'));
check('redirect: changed paid flag rejected', !G::redirectValid(['id' => 'zq0tm2wc', 'paid' => 'false', 'paid_at' => $red['paid_at'], 'x_signature' => $red['x_signature']], 'S-test'));
check('redirect: non-array / missing signature rejected', !G::redirectValid('x', 'k') && !G::redirectValid(null, 'k') && !G::redirectValid(['id' => 'a'], 'k'));

check('mobile: 012-3456789 -> +60123456789', G::mobile('012-3456789') === '+60123456789');
check('mobile: 60123456789 and +60 12 345 6789 normalised', G::mobile('60123456789') === '+60123456789' && G::mobile('+60 12 345 6789') === '+60123456789');
check('mobile: junk -> null (email alone is enough)', G::mobile('abc') === null && G::mobile('+1') === null);

check('only Billplz hosts are safe redirect targets', G::safeUrl('https://www.billplz.com/bills/abc') && G::safeUrl('https://www.billplz-sandbox.com/bills/abc'));
check('lookalike / http / other hosts refused', !G::safeUrl('https://www.billplz.com.evil.test/x') && !G::safeUrl('http://www.billplz.com/x') && !G::safeUrl('https://evil.test/www.billplz.com') && !G::safeUrl('javascript:alert(1)') && !G::safeUrl('//www.billplz.com/x'));
check('CSP form-action hosts include both Billplz origins', array_slice(G::formActionHosts(), 0, 2) === ['https://www.billplz.com', 'https://www.billplz-sandbox.com']);
check('method code is billplz and old orders can still resolve its label', (new Billplz())->code() === 'billplz' && isset(Payments::all()['billplz']) && Payments::label('billplz') !== 'billplz');
