[CmdletBinding()]
param(
    [string]$PluginPath = (Join-Path $PSScriptRoot '..\openbooking-wp')
)

$ErrorActionPreference = 'Stop'
$plugin = (Resolve-Path -LiteralPath $PluginPath).Path
$failures = [System.Collections.Generic.List[string]]::new()

$allowedRootFiles = @('LICENSE', 'openbooking-wp.php', 'readme.txt', 'uninstall.php')
$allowedDirectories = @('assets', 'blocks', 'languages', 'resources', 'src', 'templates')
$forbiddenTerms = @(
    'OpenBookingPro', 'OPENBOOKING_PRO', 'openbooking-pro',
    'openbooking-premium', 'premium', 'saas', 'Lemon_Squeezy', 'license_server'
)
$secretPatterns = @(
    'sk_live_[A-Za-z0-9]{16,}',
    'AKIA[0-9A-Z]{16}',
    'gh[pousr]_[A-Za-z0-9]{30,}',
    '-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----'
)
$blockedExtensions = @('.exe', '.dll', '.com', '.bat', '.cmd', '.ps1', '.sh', '.phar')

Get-ChildItem -LiteralPath $plugin -Force | ForEach-Object {
    if ($_.PSIsContainer) {
        if ($allowedDirectories -notcontains $_.Name) {
            $failures.Add("Directorio no permitido: $($_.Name)")
        }
    } elseif ($allowedRootFiles -notcontains $_.Name) {
        $failures.Add("Archivo raíz no permitido: $($_.Name)")
    }
}

$files = Get-ChildItem -LiteralPath $plugin -Recurse -Force -File
foreach ($file in $files) {
    if (($file.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        $failures.Add("Enlace o punto de reanálisis no permitido: $($file.FullName)")
        continue
    }
    if ($blockedExtensions -contains $file.Extension.ToLowerInvariant()) {
        $failures.Add("Extensión ejecutable no permitida: $($file.FullName)")
    }
    if ($file.Length -gt 8MB) {
        $failures.Add("Archivo inesperadamente grande: $($file.FullName)")
    }

    if ($file.Extension -in @('.php', '.js', '.json', '.txt', '.md', '.css', '.html', '.xml')) {
        $content = [IO.File]::ReadAllText($file.FullName)
        foreach ($term in $forbiddenTerms) {
            if ($content.IndexOf($term, [StringComparison]::OrdinalIgnoreCase) -ge 0) {
                $failures.Add("Identificador comercial en $($file.FullName): $term")
            }
        }
        foreach ($pattern in $secretPatterns) {
            if ($content -match $pattern) {
                $failures.Add("Posible secreto en $($file.FullName): $pattern")
            }
        }
    }
}

$header = [IO.File]::ReadAllText((Join-Path $plugin 'openbooking-wp.php'))
$readme = [IO.File]::ReadAllText((Join-Path $plugin 'readme.txt'))
$headerVersion = [regex]::Match($header, '(?m)^\s*\* Version:\s*([0-9.]+)\s*$').Groups[1].Value
$constantVersion = [regex]::Match($header, "define\(\s*'OBWP_VERSION',\s*'([0-9.]+)'\s*\)").Groups[1].Value
$stableVersion = [regex]::Match($readme, '(?m)^Stable tag:\s*([0-9.]+)\s*$').Groups[1].Value
if ([string]::IsNullOrWhiteSpace($headerVersion) -or $headerVersion -ne $constantVersion -or $headerVersion -ne $stableVersion) {
    $failures.Add("Versiones inconsistentes: header=$headerVersion constant=$constantVersion stable=$stableVersion")
}

$php = Get-Command php -ErrorAction SilentlyContinue
if ($null -ne $php) {
    foreach ($file in ($files | Where-Object Extension -eq '.php')) {
        & $php.Source -l $file.FullName | Out-Null
        if ($LASTEXITCODE -ne 0) {
            $failures.Add("Sintaxis PHP inválida: $($file.FullName)")
        }
    }
} else {
    Write-Warning 'PHP no está en PATH; se omite php -l.'
}

if ($failures.Count -gt 0) {
    $failures | Sort-Object -Unique | ForEach-Object { Write-Error $_ }
    exit 1
}

Write-Output "OK: distribución comunitaria verificada ($($files.Count) archivos, versión $headerVersion)."
