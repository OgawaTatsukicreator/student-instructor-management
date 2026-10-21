<?php
declare(strict_types=1);

function smtp_code(string $email, string $code): void
{
    require_once __DIR__ . '/third_party/PHPMailer/src/Exception.php';
    require_once __DIR__ . '/third_party/PHPMailer/src/PHPMailer.php';
    require_once __DIR__ . '/third_party/PHPMailer/src/SMTP.php';
    $c = $GLOBALS['config']['smtp'];
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !filter_var($c['from'],FILTER_VALIDATE_EMAIL) || $c['host']==='') { throw new RuntimeException('SMTP is not configured'); }
    if (!in_array($c['security'],['tls','ssl','none'],true)
        || ($c['security']==='none' && !in_array($c['host'],['127.0.0.1','localhost','::1'],true))) { throw new RuntimeException('Invalid SMTP encryption configuration'); }
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP(); $mail->Host=$c['host']; $mail->Port=(int)$c['port'];
    $mail->SMTPAuth=$c['username']!==''; $mail->Username=$c['username']; $mail->Password=$c['password'];
    $mail->SMTPSecure=$c['security']==='none'?'':$c['security']; $mail->SMTPAutoTLS=$c['security']!=='none';
    $mail->Timeout=10; $mail->Timelimit=15; $mail->CharSet='UTF-8';
    $mail->setFrom($c['from'],$c['from_name']); $mail->addAddress($email);
    $mail->Subject='βteam パスワード変更の認証コード';
    $mail->Body="パスワード変更の認証コードは次のとおりです。\n\n{$code}\n\n有効期限は10分です。\nこの操作に心当たりがない場合は、このメールを破棄してください。\nパスワードはメール認証が完了するまで変更されません。";
    $mail->send();
}

function mail_slot(int $teacher_id): void
{
    transaction(function () use ($teacher_id): void {
        $now=time();
        foreach ([['hour',3600,5],['cooldown',60,1]] as [$kind,$seconds,$maximum]) {
            $key=hash('sha256','password-mail:'.$teacher_id.':'.$kind);
            query('INSERT IGNORE INTO rate_limits(bucket_key,hits,window_started) VALUES (?,0,?)',[$key,$now]);
            $row=query('SELECT hits,window_started FROM rate_limits WHERE bucket_key=? FOR UPDATE',[$key])->fetch();
            if ($now-(int)$row['window_started'] >= $seconds) { query('UPDATE rate_limits SET hits=1,window_started=? WHERE bucket_key=?',[$now,$key]); }
            elseif ((int)$row['hits'] >= $maximum) { throw new FormError($kind==='hour'?'メール送信は1時間に5回までです。時間をおいて再度お試しください。':'再送には60秒お待ちください。'); }
            else { query('UPDATE rate_limits SET hits=hits+1 WHERE bucket_key=?',[$key]); }
        }
    });
}
function code_hash(string $token, string $code): string
{
    $key=$GLOBALS['config']['app_key'];
    if (!is_string($key) || strlen($key)<32) { throw new FormError('メール認証の設定が完了していません。管理者へ連絡してください。'); }
    return hash_hmac('sha256',$token.':'.$code,$key);
}
function deliver_change(array $user, ?string $new_hash, ?string $current_password, ?string $previous_token=null): void
{
    $token=bin2hex(random_bytes(32)); $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
    $code_hash=code_hash($token,$code);
    mail_slot((int)$user['id']);
    $email=transaction(function () use ($user,$new_hash,$current_password,$previous_token,$token,$code_hash): string {
        management_lock(); $fresh=user_row((int)$user['id']);
        if (!$fresh || (int)$fresh['credential_version']!==(int)$_SESSION['credential_version']) { throw new FormError('ログイン情報が変わりました。画面を開き直してください。'); }
        if ($previous_token!==null) {
            $old=query('SELECT * FROM password_changes WHERE teacher_id=? AND token=? AND session_binding=? FOR UPDATE',[$user['id'],$previous_token,session_binding()])->fetch();
            if (!$old || !(int)$old['sent'] || (int)$old['expires_at']<=time() || (int)$old['attempts']>=5
                || $old['email']!==$fresh['email'] || (int)$old['credential_version']!==(int)$fresh['credential_version']) { throw new FormError('認証手続きの有効期限が切れました。入力からやり直してください。'); }
            $new_hash=$old['pending_hash'];
        } elseif ($current_password===null || !password_verify($current_password,$fresh['password'])) { throw new FormError('現在のパスワードが変わりました。画面を開き直してください。'); }
        query('DELETE FROM password_changes WHERE teacher_id=?',[$user['id']]);
        query('INSERT INTO password_changes(teacher_id,token,session_binding,email,code_hash,pending_hash,credential_version,expires_at) VALUES (?,?,?,?,?,?,?,?)',
            [$user['id'],$token,session_binding(),$fresh['email'],$code_hash,$new_hash,$fresh['credential_version'],time()+600]);
        return $fresh['email'];
    });
    try { smtp_code($email,$code); }
    catch (Throwable $e) {
        query('DELETE FROM password_changes WHERE teacher_id=? AND token=?',[$user['id'],$token]);
        unset($_SESSION['pending_password_token']);
        error_log('βteam SMTP delivery failed ['.get_class($e).']');
        throw new FormError('メールを送信できませんでした。パスワードは変更していません。時間をおくか、管理者へ送信設定の確認を依頼してください。');
    }
    $marked=query('UPDATE password_changes SET sent=1 WHERE teacher_id=? AND token=?',[$user['id'],$token]);
    if ($marked->rowCount()!==1) { throw new FormError('別の変更手続きが開始されました。入力からやり直してください。'); }
    $_SESSION['pending_password_token']=$token;
}
function pending_change(array $user): array|false
{
    $token=$_SESSION['pending_password_token']??'';
    return query('SELECT * FROM password_changes WHERE teacher_id=? AND token=? AND session_binding=?',[$user['id'],$token,session_binding()])->fetch();
}
function cancel_change(array $user): void
{
    query('DELETE FROM password_changes WHERE teacher_id=? AND token=? AND session_binding=?',[$user['id'],$_SESSION['pending_password_token']??'',session_binding()]);
    unset($_SESSION['pending_password_token']);
}
function confirm_change(array $user, string $code): array
{
    return transaction(function () use ($user,$code): array {
        management_lock(); $fresh=user_row((int)$user['id']);
        $row=query('SELECT * FROM password_changes WHERE teacher_id=? AND token=? AND session_binding=? FOR UPDATE',[$user['id'],$_SESSION['pending_password_token']??'',session_binding()])->fetch();
        if (!$row) { return ['state'=>'expired']; }
        if (!$fresh || !(int)$row['sent'] || (int)$row['expires_at']<=time() || (int)$row['attempts']>=5
            || $fresh['email']!==$row['email'] || (int)$fresh['credential_version']!==(int)$row['credential_version']
            || (int)$fresh['credential_version']!==(int)$_SESSION['credential_version']) {
            query('DELETE FROM password_changes WHERE teacher_id=?',[$user['id']]); return ['state'=>'expired'];
        }
        if (!preg_match('/^[0-9]{6}$/D',$code) || !hash_equals($row['code_hash'],code_hash($row['token'],$code))) {
            $attempts=(int)$row['attempts']+1;
            if ($attempts>=5) { query('DELETE FROM password_changes WHERE teacher_id=?',[$user['id']]); return ['state'=>'locked']; }
            query('UPDATE password_changes SET attempts=? WHERE teacher_id=?',[$attempts,$user['id']]);
            return ['state'=>'wrong','remaining'=>5-$attempts];
        }
        query('UPDATE teachers SET password=?,must_change_password=0,credential_version=credential_version+1,version=version+1 WHERE id=?',[$row['pending_hash'],$user['id']]);
        query('DELETE FROM password_changes WHERE teacher_id=?',[$user['id']]);
        return ['state'=>'ok','credential_version'=>(int)$fresh['credential_version']+1];
    });
}
function password_return(array $user): string
{
    $candidate=return_target($_SESSION['password_return']??'students.php');
    $parts=parse_url($candidate); $path=$parts['path']; parse_str($parts['query']??'',$params);
    if (str_starts_with($path,'teacher') && $user['role']==='general') { return 'students.php'; }
    if (in_array($path,['teacher_create.php','teacher_edit.php'],true) && $user['role']!=='admin') { return 'students.php'; }
    if (in_array($path,['student_create.php','student_edit.php'],true) && $user['role']==='general') { return 'students.php'; }
    if (in_array($path,['student_detail.php','student_edit.php','student_notes.php','teacher_detail.php','teacher_edit.php'],true)) {
        if (empty($params['id'])) { return 'students.php'; }
        $id=(int)$params['id']; $table=str_starts_with($path,'student')?'students':'teachers';
        if (!query("SELECT 1 FROM $table WHERE id=?",[$id])->fetchColumn()) { return 'students.php'; }
        if ($path==='student_notes.php' && !may_edit_notes($user,$id)) { return 'students.php'; }
        if ($path==='teacher_detail.php' && $user['role']==='operator' && !query("SELECT 1 FROM teachers t JOIN roles r ON r.id=t.role_id WHERE t.id=? AND r.name IN ('operator','general')",[$id])->fetchColumn()) { return 'students.php'; }
    }
    return $candidate;
}
function finish_change(int $credential_version, string $return): never
{
    $_SESSION['credential_version']=$credential_version;
    session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32)); $_SESSION['auth_nonce']=bin2hex(random_bytes(32));
    unset($_SESSION['pending_password_token'],$_SESSION['password_return']);
    flash('パスワードを変更しました。'); redirect($return);
}
