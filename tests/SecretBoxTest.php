<?php
use App\Core\SecretBox;
use App\Services\BillplzSettings as B;

putenv('APP_KEY=unit-test-key-0123456789abcdef0123456789');
$enc = SecretBox::encrypt('sk-live-1234567890');
check('encrypt/decrypt round trip', SecretBox::decrypt($enc) === 'sk-live-1234567890');
check('ciphertext does not contain the plaintext', !str_contains($enc, 'sk-live') && !str_contains(base64_decode(substr($enc, 7)), 'sk-live'));
check('same secret encrypts differently each time (random nonce)', SecretBox::encrypt('x1234567') !== SecretBox::encrypt('x1234567'));
check('tampered ciphertext rejected', SecretBox::decrypt(substr($enc, 0, -3) . 'AAA') === null);
check('plain / foreign values are not decrypted', SecretBox::decrypt('sk-live-1234567890') === null && SecretBox::decrypt('') === null && SecretBox::decrypt('enc:v1:!!!') === null);
putenv('APP_KEY=a-different-key-0123456789abcdef0123456789');
check('changing APP_KEY makes old values unreadable (null, no crash)', SecretBox::decrypt($enc) === null);
putenv('APP_KEY');

check('hint shows only the last 4 of a long secret', B::hint('73eb57f0-7d4e-42b9-a544-aeac6e4b0f81') === '••••0f81');
check('hint hides short secrets entirely', B::hint('short123') === '••••••••');
check('base URL follows the mode, never user input', B::baseUrl('sandbox') === 'https://www.billplz-sandbox.com/api' && B::baseUrl('production') === 'https://www.billplz.com/api');
