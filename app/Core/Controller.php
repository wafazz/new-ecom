<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function view(string $view, array $data = [], ?string $layout = 'main'): void
    {
        echo View::render($view, $data, $layout);
    }

    protected function redirect(string $path): never
    {
        redirect($path);
    }

    /** Redirect back to a form carrying errors + the submitted values (never passwords). */
    protected function backWithErrors(string $path, array $errors, array $input = []): never
    {
        if (Request::wantsJson()) {
            Response::json(['ok' => false, 'message' => $errors['form'] ?? (string) (reset($errors) ?: 'Please check the form.'), 'errors' => $errors], 422);
        }
        unset($input['password'], $input['password_confirmation'], $input['current_password'], $input['_token']);
        $input = array_filter($input, 'is_scalar'); // nested/array fields are never echoed back
        $_SESSION['_errors'] = $errors;
        $_SESSION['_old'] = $input;
        redirect($path);
    }

    protected function success(string $path, string $message): never
    {
        if (Request::wantsJson()) {
            Response::json(['ok' => true, 'message' => $message, 'redirect' => $path]);
        }
        Session::flash('success', $message);
        redirect($path);
    }
}
