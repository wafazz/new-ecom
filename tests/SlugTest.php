<?php
use App\Core\Str;

check('slug basic', Str::slug('Classic Tee!') === 'classic-tee');
check('slug empty fallback', Str::slug('???') === 'item');
check('slug collapses symbols', Str::slug('  A -- B / C ') === 'a-b-c');
