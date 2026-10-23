<?php
declare(strict_types=1);
require __DIR__ . '/../functions.php';
require __DIR__ . '/../services.php';
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
check(return_target('students.php?page=2&q=%E5%B1%B1%E7%94%B0') === 'students.php?page=2&q=%E5%B1%B1%E7%94%B0', 'search preserved in list return');
check(return_target('teachers.php?q%5B%5D=value') === 'teachers.php', 'array search refused in return');
check(return_target('students.php?q=%00secret') === 'students.php', 'control characters refused in return');
check(return_target('student_detail.php?id=42&q=secret') === 'student_detail.php?id=42', 'search only allowed for lists');
check(text_value(['x'=>['unexpected']], 'x') === '', 'array input refused');
check(cookie_base_path('/βteam/login.php') === '/%CE%B2team/', 'Unicode folder cookie path');
check(cookie_base_path('/%CE%B2team/login.php') === '/%CE%B2team/', 'encoded folder is not encoded twice');
check(cookie_base_path('/%ce%b2team/login.php') === '/%ce%b2team/', 'lowercase URL escapes match browser cookie paths');
check(cookie_base_path('/%cE%b2team/login.php') === '/%cE%b2team/', 'mixed-case URL escapes are preserved');
check(cookie_base_path('/%ce%b2team/login.php?next=/other/page') === '/%ce%b2team/', 'query does not change cookie directory');
check(cookie_base_path('/space%20folder/login.php') === '/space%20folder/', 'escaped spaces are not encoded twice');
check(cookie_base_path('/percent%25folder/login.php') === '/percent%25folder/', 'literal percent folder keeps its escape');
check(cookie_base_path('/login.php') === '/', 'root cookie path');
$person=['last_name'=>'山田','first_name'=>'学','notes'=>''];
check(!isset(student_errors($person+['birth_date'=>'2012-02-29'])['birth_date']), 'leap day accepted');
foreach (['2011-02-29','2099-01-01',"2010-01-01\0",'', '2010-1-1'] as $date) {
    check(isset(student_errors($person+['birth_date'=>$date])['birth_date']), 'invalid or future birthday refused');
}
echo "unit: {$count} passed\n";
