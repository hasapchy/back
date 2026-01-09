# PowerShell script to add auto-auth JavaScript to Scribe documentation
# Run this after generating Scribe documentation

$backPath = "C:\ospanel\domains\Hasapchy\back"
$scribeTemplate = Join-Path $backPath "resources\views\scribe\index.blade.php"
$jsFile = Join-Path $backPath "public\vendor\scribe\js\auto-auth.js"

Write-Host "Adding auto-auth script to Scribe documentation..." -ForegroundColor Cyan

if (-not (Test-Path $scribeTemplate)) {
    Write-Host "ERROR: Scribe template not found at: $scribeTemplate" -ForegroundColor Red
    Write-Host "Please run 'php artisan scribe:generate' first." -ForegroundColor Yellow
    exit 1
}

if (-not (Test-Path $jsFile)) {
    Write-Host "ERROR: JavaScript file not found at: $jsFile" -ForegroundColor Red
    exit 1
}

# Читаем содержимое шаблона
$content = Get-Content $scribeTemplate -Raw -Encoding UTF8

# Проверяем, не добавлен ли уже скрипт
if ($content -match "auto-auth\.js") {
    Write-Host "Auto-auth script already exists in template." -ForegroundColor Yellow
    exit 0
}

# Ищем место для вставки (перед </body>)
if ($content -match "</body>") {
    # Добавляем подключение скрипта перед </body>
    $scriptTag = "    <script src=`"{{ asset('vendor/scribe/js/auto-auth.js') }}`"></script>`n</body>"
    $content = $content -replace "</body>", $scriptTag
    
    # Сохраняем файл
    [System.IO.File]::WriteAllText($scribeTemplate, $content, [System.Text.Encoding]::UTF8)
    
    Write-Host "✅ Auto-auth script successfully added to Scribe template!" -ForegroundColor Green
} else {
    Write-Host "ERROR: Could not find </body> tag in template." -ForegroundColor Red
    exit 1
}
