# PowerShell script to fix Scribe assets directory issue on Windows
# This script manually moves files from public/docs to public/vendor/scribe

$backPath = "C:\ospanel\domains\Hasapchy\back"
$docsPath = Join-Path $backPath "public\docs"
$scribePath = Join-Path $backPath "public\vendor\scribe"

Write-Host "Fixing Scribe assets directory..." -ForegroundColor Yellow

# Check if public/docs exists
if (Test-Path $docsPath) {
    Write-Host "Found public/docs directory" -ForegroundColor Green
    
    # Remove target directory if it exists
    if (Test-Path $scribePath) {
        Write-Host "Removing existing public/vendor/scribe..." -ForegroundColor Yellow
        Remove-Item -Path $scribePath -Recurse -Force -ErrorAction SilentlyContinue
    }
    
    # Create target directory
    New-Item -ItemType Directory -Path $scribePath -Force | Out-Null
    
    # Copy all files and subdirectories
    Write-Host "Copying files from public/docs to public/vendor/scribe..." -ForegroundColor Yellow
    Copy-Item -Path "$docsPath\*" -Destination $scribePath -Recurse -Force
    
    # Remove source directory
    Write-Host "Removing public/docs directory..." -ForegroundColor Yellow
    Start-Sleep -Seconds 1  # Wait a moment for file handles to close
    Remove-Item -Path $docsPath -Recurse -Force -ErrorAction SilentlyContinue
    
    Write-Host "Done! Assets have been moved to public/vendor/scribe" -ForegroundColor Green
} else {
    Write-Host "public/docs directory not found. Nothing to fix." -ForegroundColor Yellow
}
