<?php
// Payment methods on/off, the bank account for Bank transfer, and the "never leave customers with no way to pay" guard. Runs only on `*_test` databases.
use App\Core\Database as DB;
use App\Models\Setting;
use App\Services\DashboardService;
use App\Services\Payment\Payments;
use App\Services\PaymentSettings as P;

if (!str_ends_with((string) env('DB_NAME', ''), '_test')) {
    echo "  skip  (set DB_NAME=<name>_test to run DB tests)\n";
    return;
}
DB::query("DELETE FROM settings WHERE `key` LIKE 'pay\\_%' OR `key` LIKE 'bank\\_%'");
foreach (['bank_name', 'bank_account_holder', 'bank_account_no'] as $k) { Setting::set($k, ''); } // explicit blanks: independent of whatever BANK_* is in .env
$offered = fn() => array_keys(Payments::checkoutMethods());
$form = fn(array $o = []) => $o + ['cod_enabled' => '1', 'bank_enabled' => '', 'billplz_enabled' => '1', 'cod_note' => '', 'bank_name' => '', 'bank_account_holder' => '', 'bank_account_no' => '', 'bank_note' => ''];
$bankForm = ['bank_enabled' => '1', 'bank_name' => 'Maybank', 'bank_account_holder' => 'Lure Sdn Bhd', 'bank_account_no' => '5123 4567 8901'];

check('fresh shop: Cash on delivery is on; Bank transfer is NOT offered until the account details exist', in_array('cod', $offered(), true) && !in_array('bank_transfer', $offered(), true));
check('every method is "on" by default (switches only turn things off)', P::enabled('cod') && P::enabled('bank_transfer') && P::enabled('billplz') && !P::enabled('nonsense'));

// COD toggle
check('turning COD off (with another way to pay) hides it at checkout', P::save($form($bankForm + ['cod_enabled' => ''])) === [] && !in_array('cod', $offered(), true) && in_array('bank_transfer', $offered(), true));
check('...but orders that already chose COD still resolve their method and label', Payments::find('cod') !== null && Payments::label('cod') === 'Cash on delivery');
check('turning COD back on shows it again', P::save($form($bankForm)) === [] && in_array('cod', $offered(), true));

// guard
$before = [Setting::get('pay_cod_enabled'), Setting::get('pay_bank_enabled')];
$e = P::save($form(['cod_enabled' => '', 'bank_enabled' => '', 'billplz_enabled' => '']));
check('switching everything off is refused with a clear message, and nothing is saved', isset($e['form']) && str_contains($e['form'], 'at least one') && [Setting::get('pay_cod_enabled'), Setting::get('pay_bank_enabled')] === $before);
check('COD off + Bank on but no details is refused (Bank would not be offered)', isset(P::save($form(['cod_enabled' => '', 'bank_enabled' => '1']))['bank_name']));
check('COD off + only Billplz on, with Billplz not connected, is refused', isset(P::save($form(['cod_enabled' => '', 'bank_enabled' => '', 'billplz_enabled' => '1']))['form']));
check('COD off + Bank complete is fine', P::save($form($bankForm + ['cod_enabled' => ''])) === [] && $offered() === ['bank_transfer']);
P::save($form($bankForm));

// bank details
check('bank details are saved and read back', P::bank() === ['name' => 'Maybank', 'holder' => 'Lure Sdn Bhd', 'number' => '5123 4567 8901', 'note' => ''] && P::bankComplete());
$ins = Payments::find('bank_transfer')->instructions();
check('customers are told the bank, account number and holder, and to use the order number', str_contains($ins, 'Maybank') && str_contains($ins, '5123 4567 8901') && str_contains($ins, 'Lure Sdn Bhd') && str_contains($ins, 'order number'));
check('an extra note is appended to the instructions', P::save($form($bankForm + ['bank_note' => 'Email the slip to pay@lure.test'])) === [] && str_contains(Payments::find('bank_transfer')->instructions(), 'Email the slip to pay@lure.test'));
P::save($form($bankForm));
check('turning Bank transfer on needs bank name, holder and number (each reported)', array_keys(P::save($form(['bank_enabled' => '1']))) === ['bank_name', 'bank_account_holder', 'bank_account_no']);
check('the example values from .env.example do not count as real details', isset(P::save($form(['bank_enabled' => '1', 'bank_name' => 'Your Bank', 'bank_account_holder' => 'Your Company Sdn Bhd', 'bank_account_no' => '0000000000']))['bank_name']));
$bad = fn(string $no) => isset(P::save($form(array_merge($bankForm, ['bank_account_no' => $no])))['bank_account_no']);
check('account numbers must be digits (spaces and dashes allowed), 5-30 long', $bad('abc12345') && $bad('1234') && $bad(str_repeat('1', 31)) && $bad('12345; DROP TABLE') && $bad('1234-5678-') && P::save($form(array_merge($bankForm, ['bank_account_no' => '1234-5678-9012']))) === []);
check('over-long fields are refused', isset(P::save($form(array_merge($bankForm, ['bank_name' => str_repeat('x', 81)])))['bank_name']) && isset(P::save($form(array_merge($bankForm, ['bank_account_holder' => str_repeat('x', 121)])))['bank_account_holder']) && isset(P::save($form($bankForm + ['bank_note' => str_repeat('x', 301)]))['bank_note']) && isset(P::save($form($bankForm + ['cod_note' => str_repeat('x', 256)]))['cod_note']));
P::save($form($bankForm));
check('Bank transfer switched OFF keeps (even partial) details but is not offered', P::save($form(['bank_enabled' => '', 'bank_name' => 'Half Bank'])) === [] && !in_array('bank_transfer', $offered(), true) && P::bank()['name'] === 'Half Bank');
P::save($form($bankForm));
check('details with markup are stored as text (escaped when shown)', P::save($form(array_merge($bankForm, ['bank_name' => '<b>Evil</b> Bank']))) === [] && P::bank()['name'] === '<b>Evil</b> Bank');
P::save($form($bankForm));

// COD message
check('COD message: default when blank, custom when set', Payments::find('cod')->instructions() === P::COD_DEFAULT_NOTE && P::save($form($bankForm + ['cod_note' => 'Have exact change ready.'])) === [] && Payments::find('cod')->instructions() === 'Have exact change ready.');
P::save($form($bankForm));

// Billplz switch
check('Billplz has its own switch (stored; it is only offered when also connected and tested)', P::save($form($bankForm + ['billplz_enabled' => ''])) === [] && !P::enabled('billplz') && !isset(Payments::checkoutMethods()['billplz']));
P::save($form($bankForm));

// dashboard checklist
$item = fn() => array_values(array_filter(DashboardService::setup(), fn($i) => str_contains($i['label'], 'bank account')))[0];
check('setup checklist: bank details item is done when they are complete', $item()['done'] === true && $item()['href'] === '/admin/payments');
P::save($form(['bank_enabled' => '', 'bank_name' => '', 'bank_account_holder' => '', 'bank_account_no' => '']));
check('...and done when Bank transfer is switched off (nothing to set up)', $item()['done'] === true);
Setting::set('pay_bank_enabled', '1');
check('...and NOT done when Bank transfer is on but the details are missing', $item()['done'] === false);
P::save($form($bankForm));
