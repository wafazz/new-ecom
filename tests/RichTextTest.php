<?php
use App\Services\RichText as R;

check('empty / whitespace -> empty', R::clean(null) === '' && R::clean("  \n ") === '');
check('legacy plain text becomes paragraphs with line breaks', R::clean("One\ntwo\n\nThree") === '<p>One<br>two</p><p>Three</p>');
check('legacy plain text is escaped', R::clean('5 > 3 & "quotes"') === '<p>5 &gt; 3 &amp; &quot;quotes&quot;</p>');
check('basic formatting kept; b/i mapped to strong/em', R::clean('<p>a <b>b</b> <i>i</i> <u>u</u> <s>s</s></p>') === '<p>a <strong>b</strong> <em>i</em> <u>u</u> <s>s</s></p>');
check('headings limited to h2/h3', R::clean('<h1>a</h1><h2>b</h2><h3>c</h3><h6>d</h6>') === '<h2>a</h2><h2>b</h2><h3>c</h3><h3>d</h3>');
check('lists and blockquote kept', R::clean('<ul><li>a</li></ul><ol><li>b</li></ol><blockquote>q</blockquote>') === '<ul><li>a</li></ul><ol><li>b</li></ol><blockquote>q</blockquote>');
check('Quill bullet lists (<ol><li data-list=bullet>) become <ul>', R::clean('<ol><li data-list="bullet"><span class="ql-ui" contenteditable="false"></span>x</li></ol>') === '<ul><li>x</li></ul>');
check('Quill ordered lists stay <ol>', R::clean('<ol><li data-list="ordered">x</li></ol>') === '<ol><li>x</li></ol>');

check('script tag and its content are dropped', R::clean('<p>ok</p><script>alert(1)</script>') === '<p>ok</p>');
check('event handlers and inline style are removed', R::clean('<p onclick="x()" style="color:red" class="c">t</p>') === '<p>t</p>');
check('img/iframe/svg/math/object are dropped', R::clean('<p>a</p><img src=x onerror=alert(1)><iframe src="//e"></iframe><svg><script>alert(1)</script></svg><math><mi>q</mi></math><object data=x></object>') === '<p>a</p>');
check('unknown tags are unwrapped, text kept', R::clean('<div><span>hi</span></div><table><tr><td>cell</td></tr></table>') === 'hicell');
check('comments are dropped', R::clean('<p>a<!-- <script>x</script> -->b</p>') === '<p>ab</p>');
check('escaped markup stays text', R::clean('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>') === '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
check('broken markup is repaired', R::clean('<p>one<p>two <b>bold') === '<p>one</p><p>two <strong>bold</strong></p>');

$a = fn(string $href) => R::clean('<a href="' . $href . '">x</a>');
check('https link kept with safe rel/target', $a('https://shop.example/a?b=1&c=2') === '<a href="https://shop.example/a?b=1&amp;c=2" target="_blank" rel="noopener noreferrer nofollow">x</a>');
check('mailto and tel links kept', str_contains($a('mailto:a@b.co'), 'href="mailto:a@b.co"') && str_contains($a('tel:+60123456789'), 'href="tel:+60123456789"'));
check('javascript: links lose the link, keep the text', $a('javascript:alert(1)') === 'x' && $a('JaVaScRiPt:alert(1)') === 'x');
check('javascript: hidden with tabs/newlines/entities is caught', $a("java\tscript:alert(1)") === 'x' && $a(' jav&#x09;ascript:alert(1)') === 'x' && $a("\x01javascript:alert(1)") === 'x');
check('data:, vbscript:, file:, protocol-relative and relative links are dropped', $a('data:text/html,<script>alert(1)</script>') === 'x' && $a('vbscript:x') === 'x' && $a('file:///etc/passwd') === 'x' && $a('//evil.com') === 'x' && $a('/local') === 'x');
check('link attributes other than href are ignored', R::clean('<a href="https://a.co" onclick="x" style="y" target="_self" rel="opener">l</a>') === '<a href="https://a.co" target="_blank" rel="noopener noreferrer nofollow">l</a>');
check('quote in href cannot break out of the attribute', !str_contains(R::clean('<a href="https://a.co/&quot; onmouseover=&quot;x">l</a>'), '" onmouseover'));

check('empty paragraphs from the editor give an empty result', R::clean('<p><br></p>') === '' && R::clean('<p></p><p> </p>') === '');
check('trailing and leading empty paragraphs are trimmed', R::clean('<p><br></p><p>keep</p><p><br></p><p><br></p>') === '<p>keep</p>');
check('cleaning twice changes nothing (idempotent)', (function () { $x = R::clean('<h1>T</h1><ol><li data-list="bullet">a</li></ol><a href="https://a.co">l</a><p>x<br>y</p>'); return R::clean($x) === $x; })());
check('textLength counts visible characters only', R::textLength('<p>ab <strong>cd</strong></p>') === 5);
