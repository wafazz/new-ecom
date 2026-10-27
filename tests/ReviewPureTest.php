<?php
// Product review rules that need no database: star range, text length, and the public name.
use App\Services\ReviewService as R;

$ok = fn(array $in) => R::validate($in)['errors'] === [];
$good = ['rating' => '4', 'title' => 'Nice', 'body' => 'Works well and looks great.'];
check('valid review passes', $ok($good));
check('rating 0 refused', isset(R::validate([...$good, 'rating' => '0'])['errors']['rating']));
check('rating 6 refused', isset(R::validate([...$good, 'rating' => '6'])['errors']['rating']));
check('rating missing refused', isset(R::validate([...$good, 'rating' => null])['errors']['rating']));
check('rating text refused', isset(R::validate([...$good, 'rating' => 'five'])['errors']['rating']));
check('body too short refused', isset(R::validate([...$good, 'body' => 'ok'])['errors']['body']));
check('body too long refused', isset(R::validate([...$good, 'body' => str_repeat('a', 1001)])['errors']['body']));
check('title optional', $ok([...$good, 'title' => '']) && R::validate([...$good, 'title' => ''])['data']['title'] === null);
check('title too long refused', isset(R::validate([...$good, 'title' => str_repeat('t', 121)])['errors']['title']));
check('data is cast to int rating', R::validate($good)['data']['rating'] === 4);
check('public name: first + last initial', R::publicName('Nur Aisyah Binti Ali') === 'Nur A.');
check('public name: single name kept', R::publicName('Aisyah') === 'Aisyah');
check('public name: blank falls back', R::publicName('   ') === 'Shopper');
