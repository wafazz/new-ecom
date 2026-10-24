<?php
declare(strict_types=1);

namespace App\Services;

/** A problem the customer can fix (stock changed, email taken...). The message is safe to show. */
final class CheckoutException extends \RuntimeException
{
}
