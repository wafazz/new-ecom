<?php
use App\Core\Validator;

$v = new Validator(['name' => ['x'], 'email' => ['a@b.c']], ['name' => 'required', 'email' => 'required|email']);
check('array input treated as empty, no crash', isset($v->errors()['name']) && isset($v->errors()['email']));
$v = new Validator(['name' => ' Bo ', 'n' => '12'], ['name' => 'required|min:2', 'n' => 'int']);
check('valid input passes', !$v->fails());
check('min length', (new Validator(['p' => 'abc'], ['p' => 'min:8']))->fails());
check('optional blank skipped', !(new Validator(['phone' => ''], ['phone' => 'max:5']))->fails());
