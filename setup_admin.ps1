$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
$phpPath = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\php\php.exe'))
if (-not (Test-Path -LiteralPath $phpPath)) { throw 'XAMPPのPHPが見つかりません。xampp\htdocs\βteamに配置してください。' }
$adminLastName = Read-Host '管理者の姓'
$adminFirstName = Read-Host '管理者の名'
$adminEmail = Read-Host 'メールアドレス'
$adminSecret = Read-Host '初期パスワード（半角10〜50文字、2種類以上）' -AsSecureString
$secretPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($adminSecret)
try {
    $adminPassword = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($secretPointer)
    $payload = @{last_name=$adminLastName;first_name=$adminFirstName;email=$adminEmail;password=$adminPassword} | ConvertTo-Json -Compress
    $OutputEncoding = [System.Text.UTF8Encoding]::new($false)
    $payload | & $phpPath (Join-Path $PSScriptRoot 'setup_admin.php')
    if ($LASTEXITCODE -ne 0) { throw '初期管理者を作成できませんでした。' }
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($secretPointer)
    $adminPassword = $null
    $payload = $null
}
