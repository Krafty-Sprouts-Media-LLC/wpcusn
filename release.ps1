# WPCUSN Release Script
# Usage: .\release.ps1 [version]
# Example: .\release.ps1 1.0.1

param(
    [Parameter(Mandatory=$true)]
    [string]$Version
)

# Validate version format (e.g., 1.0.0, 1.0.1, 1.1.0)
if ($Version -notmatch '^\d+\.\d+\.\d+$') {
    Write-Host "Error: Version must be in format X.Y.Z (e.g., 1.0.0)" -ForegroundColor Red
    exit 1
}

Write-Host "Creating release for version $Version..." -ForegroundColor Green

# Update version in wpcusn.php
$pluginFile = "wpcusn.php"
if (Test-Path $pluginFile) {
    $content = Get-Content $pluginFile -Raw
    $content = $content -replace "Version: \d+\.\d+\.\d+", "Version: $Version"
    $content = $content -replace "define\( 'WPCUSN_VERSION', '[^']+' \);", "define( 'WPCUSN_VERSION', '$Version' );"
    Set-Content $pluginFile $content
    Write-Host "✓ Updated version in $pluginFile" -ForegroundColor Green
} else {
    Write-Host "Error: $pluginFile not found!" -ForegroundColor Red
    exit 1
}

# Get current date for changelog
$date = Get-Date -Format 'yyyy-MM-dd'

# Check if changelog entry exists
$changelogFile = "CHANGELOG.md"
if (Test-Path $changelogFile) {
    $changelogContent = Get-Content $changelogFile -Raw
    if ($changelogContent -notmatch "## \[$Version\]") {
        Write-Host "Warning: Changelog entry for $Version not found. Please add it manually." -ForegroundColor Yellow
    }
} else {
    Write-Host "Warning: CHANGELOG.md not found!" -ForegroundColor Yellow
}

# Git operations
Write-Host "`nGit operations:" -ForegroundColor Cyan
Write-Host "1. Staging changes..." -ForegroundColor Yellow
git add wpcusn.php CHANGELOG.md

Write-Host "2. Committing changes..." -ForegroundColor Yellow
git commit -m "Bump version to $Version"

Write-Host "3. Creating tag v$Version..." -ForegroundColor Yellow
git tag -a "v$Version" -m "Release version $Version"

Write-Host "`n✓ Ready to push!" -ForegroundColor Green
Write-Host "`nNext steps:" -ForegroundColor Cyan
Write-Host "  git push origin main" -ForegroundColor White
Write-Host "  git push origin v$Version" -ForegroundColor White
Write-Host "`nOr push both at once:" -ForegroundColor Cyan
Write-Host "  git push origin main --tags" -ForegroundColor White

