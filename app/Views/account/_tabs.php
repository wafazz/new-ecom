<?php /** Profile / Password switch shown at the top of both account pages. Expects $base and $active ('profile'|'password'). */ ?>
<nav class="acct-tabs" aria-label="Account settings">
    <a href="<?= e(url($base . '/profile')) ?>"<?= $active === 'profile' ? ' aria-current="page"' : '' ?>>Profile</a>
    <a href="<?= e(url($base . '/password')) ?>"<?= $active === 'password' ? ' aria-current="page"' : '' ?>>Password</a>
</nav>
