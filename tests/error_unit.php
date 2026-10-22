<?php
declare(strict_types=1);
require __DIR__ . '/../functions.php';
require __DIR__ . '/../services.php';
$count = 0;
function test_error(bool $condition, string $label): void {
    global $count;
    if (!$condition) { throw new RuntimeException($label); }
    $count++;
}
test_error(valid_text('日本語の氏名',50,false), 'Japanese names');
test_error(!valid_text("姓\0",50,false), 'NUL in name rejected');
test_error(!valid_text("姓\n",50,false), 'newline in name rejected');
test_error(!valid_text("\xff",2000,true), 'invalid UTF-8 rejected');
test_error(valid_text("共有メモ\n次の行\t内容\r\n",2000,true), 'multiline notes accepted');
test_error(!valid_text("メモ\x01",2000,true), 'control in notes rejected');
test_error(!valid_text(str_repeat('あ',2001),2000,true), 'notes character boundary');
foreach (['0999-12-31','0001-01-01','0000-00-00'] as $date) {
    test_error(isset(student_errors(['last_name'=>'姓','first_name'=>'名','notes'=>'','birth_date'=>$date])['birth_date']), 'unsupported database year rejected');
}
test_error(failure_status(new RuntimeException('secret')) === 500, 'unexpected failure is 500');
test_error(failure_status(new ConfigurationError('secret')) === 500, 'configuration failure is 500');
test_error(failure_status(new ServiceUnavailable('secret')) === 503, 'service failure is 503');
foreach ([2002=>503,2006=>503,1205=>503,1213=>503,1146=>500,1045=>500,1064=>500] as $code=>$status) {
    $error = new PDOException('private SQL'); $error->errorInfo = ['HY000',$code,'private SQL'];
    test_error(failure_status($error) === $status, 'PDO error classification');
}
$error = new FormError('wait',429,'email',15);
test_error(failure_status($error) === 429 && $error->field === 'email' && $error->retry_after === 15, 'business metadata preserved');
$_SERVER['SCRIPT_NAME'] = '/βteam/student_edit.php'; $_GET['id'] = '12';
test_error(error_destination(true)[0] === 'student_edit.php?id=12', 'safe recovery target');
$_GET['id'] = ['12'];
test_error(error_destination(true)[0] === 'login.php', 'array recovery id rejected');
$_GET['id'] = '12&redirect=https://example.invalid';
test_error(error_destination(true)[0] === 'login.php', 'recovery injection rejected');
test_error(strlen(diagnostic_id()) === 16 && ctype_xdigit(diagnostic_id()), 'reference format');
echo "error-unit: {$count} passed\n";
