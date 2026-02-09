$ErrorActionPreference = "Stop"
$pluginDir = "superman-links"
$zipFile = "superman-links.zip"

# Clean up
Remove-Item $zipFile -ErrorAction SilentlyContinue
Remove-Item $pluginDir -Recurse -ErrorAction SilentlyContinue

# Create temp folder structure
New-Item -ItemType Directory -Path $pluginDir | Out-Null
New-Item -ItemType Directory -Path "$pluginDir\includes" | Out-Null

# Copy plugin files
Copy-Item "superman-links.php" "$pluginDir\"
Copy-Item "readme.txt" "$pluginDir\"
Copy-Item "includes\class-api.php" "$pluginDir\includes\"
Copy-Item "includes\class-settings.php" "$pluginDir\includes\"
Copy-Item "includes\class-updater.php" "$pluginDir\includes\"
Copy-Item "includes\class-webhook.php" "$pluginDir\includes\"

# Create zip
Compress-Archive -Path $pluginDir -DestinationPath $zipFile -Force

# Clean up temp folder
Remove-Item $pluginDir -Recurse -Force

# Report
$size = [math]::Round((Get-Item $zipFile).Length / 1KB, 1)
Write-Host "Created $zipFile ($size KB)"
