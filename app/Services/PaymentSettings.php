<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Services\Payment\Billplz;

/**
 * Which payment methods the shop offers, and the bank account shown for Bank transfer.
 * Edited on the admin Payments page. Bank details written in `.env` (BANK_*) still work as the starting
 * values until they are saved there; the example placeholders from `.env.example` count as "not set".
 */
final class PaymentSettings
{
    /** Methods that can be switched on or off here (code => setting key). */
    public const TOGGLES = ['cod' => 'pay_cod_enabled', 'bank_transfer' => 'pay_bank_enabled', 'billplz' => 'pay_billplz_enabled'];
    private const PLACEHOLDERS = ['your bank', '0000000000', 'your company sdn bhd'];
    public const COD_DEFAULT_NOTE = 'Pay in cash when your order arrives.';

    public static function enabled(string $code): bool
    {
        return isset(self::TOGGLES[$code]) && Setting::get(self::TOGGLES[$code], '1') === '1';
    }

    private static function clean(string $v): string
    {
        $v = trim($v);
        return in_array(mb_strtolower($v), self::PLACEHOLDERS, true) ? '' : $v;
    }

    /** @return array{name:string, holder:string, number:string, note:string} */
    public static function bank(): array
    {
        return [
            'name' => self::clean(Setting::get('bank_name', (string) env('BANK_NAME', ''))),
            'holder' => self::clean(Setting::get('bank_account_holder', (string) env('BANK_ACCOUNT_HOLDER', ''))),
            'number' => self::clean(Setting::get('bank_account_no', (string) env('BANK_ACCOUNT_NO', ''))),
            'note' => trim(Setting::get('bank_note', '')),
        ];
    }

    public static function bankComplete(?array $bank = null): bool
    {
        $b = $bank ?? self::bank();
        return $b['name'] !== '' && $b['holder'] !== '' && $b['number'] !== '';
    }

    public static function codNote(): string
    {
        $n = trim(Setting::get('pay_cod_note', ''));
        return $n !== '' ? $n : self::COD_DEFAULT_NOTE;
    }

    /** Would the shop still have a way to take payment with these switches / details? */
    private static function canTakePayment(bool $cod, bool $bank, bool $billplz, array $bankDetails): bool
    {
        return $cod || ($bank && self::bankComplete($bankDetails)) || ($billplz && Billplz::ready());
    }

    /**
     * Validate and save the Payments form.
     * @param array $in cod_enabled, bank_enabled, billplz_enabled ('1' or absent), cod_note, bank_name, bank_account_holder, bank_account_no, bank_note
     * @return array<string,string> errors keyed by field ('form' for the whole page); nothing is saved unless empty
     */
    public static function save(array $in): array
    {
        $str = fn(string $k) => is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';
        $on = fn(string $k) => ($in[$k] ?? '') === '1';
        $errors = [];
        $bank = ['name' => $str('bank_name'), 'holder' => $str('bank_account_holder'), 'number' => $str('bank_account_no'), 'note' => $str('bank_note')];
        $cod = ['on' => $on('cod_enabled'), 'note' => $str('cod_note')];
        $bankOn = $on('bank_enabled');
        $billplzOn = $on('billplz_enabled');

        if (mb_strlen($cod['note']) > 255) {
            $errors['cod_note'] = 'Keep this under 255 characters.';
        }
        if (mb_strlen($bank['name']) > 80) {
            $errors['bank_name'] = 'The bank name is too long (80 characters max).';
        }
        if (mb_strlen($bank['holder']) > 120) {
            $errors['bank_account_holder'] = 'The account name is too long (120 characters max).';
        }
        if ($bank['number'] !== '' && !preg_match('/^[0-9][0-9 \-]{3,28}[0-9]$/', $bank['number'])) {
            $errors['bank_account_no'] = 'Use digits only (spaces and dashes are fine), 5 to 30 characters.';
        }
        if (mb_strlen($bank['note']) > 300) {
            $errors['bank_note'] = 'Keep this under 300 characters.';
        }
        if ($bankOn) { // the account shown to customers must be real
            foreach (['name' => ['bank_name', 'Enter the bank name.'], 'holder' => ['bank_account_holder', 'Enter the name on the account.'], 'number' => ['bank_account_no', 'Enter the account number.']] as $k => [$field, $msg]) {
                if (!isset($errors[$field]) && self::clean($bank[$k]) === '') {
                    $errors[$field] = $msg;
                }
            }
        }
        if ($errors === [] && !self::canTakePayment($cod['on'], $bankOn, $billplzOn, array_map([self::class, 'clean'], $bank))) {
            $errors['form'] = 'Keep at least one payment method available, or customers will not be able to check out. '
                . 'Cash on delivery is the simplest; Bank transfer needs complete bank details; Online banking needs a tested Billplz connection.';
        }
        if ($errors !== []) {
            return $errors;
        }
        Setting::set(self::TOGGLES['cod'], $cod['on'] ? '1' : '0');
        Setting::set(self::TOGGLES['bank_transfer'], $bankOn ? '1' : '0');
        Setting::set(self::TOGGLES['billplz'], $billplzOn ? '1' : '0');
        Setting::set('pay_cod_note', $cod['note']);
        Setting::set('bank_name', self::clean($bank['name']));
        Setting::set('bank_account_holder', self::clean($bank['holder']));
        Setting::set('bank_account_no', self::clean($bank['number']));
        Setting::set('bank_note', $bank['note']);
        return [];
    }
}
