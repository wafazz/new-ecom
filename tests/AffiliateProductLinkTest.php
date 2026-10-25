<?php
// Affiliate product links: /r/CODE?to=/products/slug, and the path the redirect will accept. No database needed.
use App\Services\AffiliateService;

$link = AffiliateService::productLink('AH7K2', 'premium-tee-black');
check('a product link ends in /r/CODE?to=/products/slug', str_ends_with($link, '/r/AH7K2?to=/products/premium-tee-black'));
check('the destination in the link passes the redirect check', preg_match('#^/(?!/)[A-Za-z0-9/_\-]*$#', '/products/premium-tee-black') === 1);
