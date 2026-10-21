<?php
declare(strict_types=1);
require __DIR__ . '/../functions.php';
$count = 0;
function check(bool $condition, string $label): void { global $count; if (!$condition) { throw new RuntimeException($label); } $count++; }
foreach (['abcdefghij','ABCDEFGHIJ','1234567890','!!!!!!!!!!','Abc123456','Abc123456' . str_repeat('a',42),"Abcdefghi ","Abcdefghi\n",'Ａbcdefghij1'] as $value) {
    check(password_error($value) !== null, 'invalid password boundary');
}
foreach (['AaAaAaAaAa','AAAA111111','AAAA!!!!!!','aaaa111111','aaaa!!!!!!','1111!!!!!!',str_repeat('a',49).'1'] as $value) {
    check(password_error($value) === null, 'valid length/type combination');
}
check(e('<script>"&') === '&lt;script&gt;&quot;&amp;', 'HTML escaping');
check(return_target('https://example.invalid') === 'students.php', 'external return refused');
check(return_target('//example.invalid') === 'students.php', 'scheme-relative return refused');
check(return_target('../login.php') === 'students.php', 'path traversal refused');
check(return_target('student_detail.php?id=42&redirect=https://example.invalid') === 'student_detail.php?id=42', 'return parameters allowlist');
check(text_value(['x'=>['unexpected']], 'x') === '', 'array input refused');
echo "unit: {$count} passed\n";
