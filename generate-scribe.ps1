# PowerShell script to generate Scribe documentation with automatic fix for Windows permission issues
# Usage: .\generate-scribe.ps1

$backPath = "C:\ospanel\domains\Hasapchy\back"
Set-Location $backPath

Write-Host "Generating Scribe documentation..." -ForegroundColor Cyan
Write-Host ""

# Clean up directories before generation
$docsPath = Join-Path $backPath "public\docs"
$scribePath = Join-Path $backPath "public\vendor\scribe"

if (Test-Path $docsPath) {
    Write-Host "Cleaning up public/docs..." -ForegroundColor Yellow
    Remove-Item -Path $docsPath -Recurse -Force -ErrorAction SilentlyContinue
}

if (Test-Path $scribePath) {
    Write-Host "Cleaning up public/vendor/scribe..." -ForegroundColor Yellow
    Remove-Item -Path $scribePath -Recurse -Force -ErrorAction SilentlyContinue
}

# Run scribe:generate
Write-Host ""
Write-Host "Running: php artisan scribe:generate" -ForegroundColor Cyan
php artisan scribe:generate

$exitCode = $LASTEXITCODE

# If generation failed or if docs directory exists, run the fix script
if ($exitCode -ne 0 -or (Test-Path $docsPath)) {
    Write-Host ""
    Write-Host "Generation completed with issues. Running fix script..." -ForegroundColor Yellow
    Write-Host ""
    
    if (Test-Path $docsPath) {
        # Remove target directory if it exists
        if (Test-Path $scribePath) {
            Remove-Item -Path $scribePath -Recurse -Force -ErrorAction SilentlyContinue
        }
        
        # Create target directory
        New-Item -ItemType Directory -Path $scribePath -Force | Out-Null
        
        # Copy all files and subdirectories
        Copy-Item -Path "$docsPath\*" -Destination $scribePath -Recurse -Force
        
        # Wait a moment for file handles to close
        Start-Sleep -Seconds 1
        
        # Remove source directory
        Remove-Item -Path $docsPath -Recurse -Force -ErrorAction SilentlyContinue
        
        Write-Host "Assets have been moved to public/vendor/scribe" -ForegroundColor Green
    }
}

# Add auto-auth JavaScript to template if not present
$scribeTemplate = Join-Path $backPath "resources\views\scribe\index.blade.php"
if (Test-Path $scribeTemplate) {
    $templateContent = Get-Content $scribeTemplate -Raw -Encoding UTF8
    
    if (-not ($templateContent -match "Access token сохранен автоматически")) {
        Write-Host ""
        Write-Host "Adding auto-auth JavaScript to template..." -ForegroundColor Yellow
        
        $autoAuthScript = @"

<script>
// Автоматическое сохранение access token из ответа логина
(function() {
    // Перехватываем все ответы от API
    const originalFetch = window.fetch;
    window.fetch = function(...args) {
        return originalFetch.apply(this, args).then(response => {
            // Клонируем ответ для чтения без нарушения оригинального потока
            const clonedResponse = response.clone();
            
            // Проверяем, является ли это запросом к эндпоинту логина
            const url = args[0];
            if (typeof url === 'string' && url.includes('/api/user/login')) {
                clonedResponse.json().then(data => {
                    if (data && data.access_token) {
                        // Сохраняем токен в localStorage
                        localStorage.setItem('scribe_access_token', data.access_token);
                        
                        // Обновляем все поля Authorization в форме
                        updateAuthFields(data.access_token);
                        
                        console.log('✅ Access token автоматически сохранен из ответа логина');
                    }
                }).catch(() => {
                    // Игнорируем ошибки парсинга
                });
            }
            
            return response;
        });
    };
    
    // Функция для обновления всех полей Authorization
    function updateAuthFields(token) {
        // Ищем все скрытые поля Authorization
        const authInputs = document.querySelectorAll('input[name="Authorization"]');
        authInputs.forEach(input => {
            input.value = 'Bearer ' + token;
            // Триггерим событие change для обновления UI
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        
        // Также обновляем видимые поля, если они есть
        const visibleAuthInputs = document.querySelectorAll('input[data-component="header"][name="Authorization"]');
        visibleAuthInputs.forEach(input => {
            input.value = 'Bearer ' + token;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }
    
    // При загрузке страницы проверяем, есть ли сохраненный токен
    document.addEventListener('DOMContentLoaded', function() {
        const savedToken = localStorage.getItem('scribe_access_token');
        if (savedToken) {
            updateAuthFields(savedToken);
            console.log('✅ Сохраненный access token восстановлен');
        }
    });
    
    // Также перехватываем XMLHttpRequest для совместимости
    const originalXHROpen = XMLHttpRequest.prototype.open;
    const originalXHRSend = XMLHttpRequest.prototype.send;
    
    XMLHttpRequest.prototype.open = function(method, url, ...args) {
        this._url = url;
        return originalXHROpen.apply(this, [method, url, ...args]);
    };
    
    XMLHttpRequest.prototype.send = function(...args) {
        this.addEventListener('load', function() {
            if (this._url && this._url.includes('/api/user/login') && this.status === 200) {
                try {
                    const data = JSON.parse(this.responseText);
                    if (data && data.access_token) {
                        localStorage.setItem('scribe_access_token', data.access_token);
                        updateAuthFields(data.access_token);
                        console.log('✅ Access token автоматически сохранен из ответа логина (XHR)');
                    }
                } catch (e) {
                    // Игнорируем ошибки парсинга
                }
            }
        });
        return originalXHRSend.apply(this, args);
    };
})();
</script>

"@
        
        # Заменяем </body> на скрипт + </body>
        if ($templateContent -match "</body>") {
            $templateContent = $templateContent -replace "</body>", ($autoAuthScript + "`n</body>")
            [System.IO.File]::WriteAllText($scribeTemplate, $templateContent, [System.Text.Encoding]::UTF8)
            Write-Host "✅ Auto-auth JavaScript добавлен в шаблон" -ForegroundColor Green
        }
    }
}

Write-Host ""
if ($exitCode -eq 0) {
    Write-Host "Documentation generated successfully!" -ForegroundColor Green
} else {
    Write-Host "Documentation generation completed (with manual fix applied)" -ForegroundColor Yellow
}
