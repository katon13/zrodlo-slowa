param(
    [string]$OutputPath = "",
    [switch]$PolicySelfTest
)

$ErrorActionPreference = 'Stop'

$repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$exportsDirectory = Join-Path $repoRoot 'exports'
if (-not (Test-Path -LiteralPath $exportsDirectory)) {
    New-Item -ItemType Directory -Path $exportsDirectory | Out-Null
}

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $exportsDirectory 'zrodlo-slowa_AUDYT_PELNY_2026-08-11.zip'
}
$resolvedOutput = [System.IO.Path]::GetFullPath($OutputPath)
if (-not $resolvedOutput.StartsWith($exportsDirectory + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'The audit archive must be written inside the repository exports directory.'
}

$blockedDirectoryNames = @(
    '.git', '.idea', '.vscode', '.vs', '.codex', '.gradle', '.kotlin',
    '.cache', '.fleet', '.metadata', '.pytest_cache', '__pycache__',
    'build', 'dist', 'out', 'vendor', 'node_modules', 'storage', 'exports', 'coverage',
    'screenshots', 'backups'
)
$allowedEnvironmentExamples = @(
    '.env.example', '.env.install.example', '.env.local.example',
    '.env.test.example', '.env.production.example'
)
$blockedFileNames = @(
    'local.properties', '.DS_Store', 'Thumbs.db', 'desktop.ini',
    '_audit_filelist.txt', '.phpunit.result.cache', 'gradle-daemon-jvm.properties'
)
$blockedExtensions = @(
    '.apk', '.aab', '.apks', '.jks', '.keystore', '.p12', '.pfx', '.pem', '.key',
    '.zip', '.7z', '.rar', '.tar', '.gz', '.tgz', '.log', '.tmp', '.bak', '.dump',
    '.sqlite', '.sqlite3', '.db'
)

function Test-IsBlockedEnvironmentFileName([string]$FileName) {
    $normalized = $FileName.Trim().ToLowerInvariant()
    if ($normalized -ne '.env' -and -not $normalized.StartsWith('.env.')) {
        return $false
    }
    return $allowedEnvironmentExamples -notcontains $normalized
}

if ($PolicySelfTest) {
    foreach ($name in @('.env', '.env.prod', '.env.staging', '.env.secret', '.ENV.PROD')) {
        if (-not (Test-IsBlockedEnvironmentFileName $name)) {
            throw "Environment-file policy allowed a real configuration: $name"
        }
    }
    foreach ($name in $allowedEnvironmentExamples) {
        if (Test-IsBlockedEnvironmentFileName $name) {
            throw "Environment-file policy blocked an explicit example: $name"
        }
    }
    Write-Output 'Environment-file package policy: PASS'
    return
}

function Test-IsExcluded([System.IO.FileInfo]$File) {
    $relative = $File.FullName.Substring($repoRoot.Length).TrimStart([char[]]'\/')
    $segments = $relative -split '[\\/]'
    foreach ($segment in $segments[0..([Math]::Max(0, $segments.Count - 2))]) {
        if ($blockedDirectoryNames -contains $segment) {
            return $true
        }
    }
    if (Test-IsBlockedEnvironmentFileName $File.Name) {
        return $true
    }
    if ($blockedFileNames -contains $File.Name) {
        return $true
    }
    if ($blockedExtensions -contains $File.Extension.ToLowerInvariant()) {
        return $true
    }
    if ($File.Name -match '^dump\d*\.xml$') {
        return $true
    }
    if (
        $File.Directory.FullName -eq (Join-Path $repoRoot 'docs') -and
        $File.Name -like 'ChatGPT Image*.png'
    ) {
        # Identical copies are retained under docs/assets.
        return $true
    }
    return $false
}

$collectedFiles = [System.Collections.Generic.List[System.IO.FileInfo]]::new()
$pendingDirectories = [System.Collections.Generic.Queue[System.IO.DirectoryInfo]]::new()
$pendingDirectories.Enqueue([System.IO.DirectoryInfo]::new($repoRoot))
while ($pendingDirectories.Count -gt 0) {
    $directory = $pendingDirectories.Dequeue()
    foreach ($childDirectory in $directory.EnumerateDirectories()) {
        if ($blockedDirectoryNames -contains $childDirectory.Name) {
            continue
        }
        if (($childDirectory.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0) {
            continue
        }
        $pendingDirectories.Enqueue($childDirectory)
    }
    foreach ($file in $directory.EnumerateFiles()) {
        if (-not (Test-IsExcluded $file)) {
            $collectedFiles.Add($file)
        }
    }
}
$files = @($collectedFiles | Sort-Object FullName)

$evidenceNames = @(
    'final_admin_pl_portrait.png',
    'final_author_pl.png',
    'final_admin_small_en.png',
    'final_approval_small_pl.png',
    'final_camera_prompt_pl.png',
    'final_camera_denied_pl.png',
    'final_source_small_pl.png',
    'final_source_small_en.png',
    'final_source_intro_contrast3.png',
    'final_deeplink.xml'
)
$evidenceFiles = @(
    $evidenceNames |
        ForEach-Object { Get-Item -LiteralPath (Join-Path $exportsDirectory $_) -ErrorAction SilentlyContinue } |
        Where-Object { $_ -is [System.IO.FileInfo] }
)
$safetyFundEvidenceFiles = @(
    'safety-fund-pl-small.png',
    'safety-fund-en-normal.png',
    '3dors-wartownik-pl.png',
    '3dors-wartownik-en.png',
    '3dors-wartownik-archiwum-pl.png',
    '3dors-wartownik-osobne-okno-pl.png',
    'odpowiedz-publikacja-pod-artykulem.png',
    'odpowiedz-publikacja-jak-zarabiac.png',
    'kampanie-admin-powiadomienia-www.png',
    'kampanie-publiczne-www.png',
    'powiadomienia-badge-mobile.png'
) | ForEach-Object {
    Get-Item -LiteralPath (Join-Path $repoRoot "docs/screenshots/$_") -ErrorAction SilentlyContinue
} | Where-Object { $_ -is [System.IO.FileInfo] }
$evidenceFiles += $safetyFundEvidenceFiles
$testEvidenceFiles = @()
$sourceConnectedResult = Get-ChildItem -LiteralPath (Join-Path $repoRoot 'mobile/zrodlo-slowa-android/app/build/outputs/androidTest-results/connected/debug') -Filter '*.xml' -File -ErrorAction SilentlyContinue | Select-Object -First 1
if ($sourceConnectedResult) {
    $testEvidenceFiles += [pscustomobject]@{
        File = $sourceConnectedResult
        Entry = 'zrodlo-slowa/audit-evidence/test-results/source-package-visibility-e2e.xml'
    }
}
$authorConnectedResult = Get-ChildItem -LiteralPath (Join-Path $repoRoot 'mobile/3dors-android/app/build/outputs/androidTest-results/connected/debug/flavors/author') -Filter '*.xml' -File -ErrorAction SilentlyContinue | Select-Object -First 1
if ($authorConnectedResult) {
    $testEvidenceFiles += [pscustomobject]@{
        File = $authorConnectedResult
        Entry = 'zrodlo-slowa/audit-evidence/test-results/author-deep-link-receipt.xml'
    }
}

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
if (Test-Path -LiteralPath $resolvedOutput) {
    Remove-Item -LiteralPath $resolvedOutput -Force
}

$archiveStream = [System.IO.File]::Open($resolvedOutput, [System.IO.FileMode]::CreateNew)
try {
    $archive = [System.IO.Compression.ZipArchive]::new(
        $archiveStream,
        [System.IO.Compression.ZipArchiveMode]::Create,
        $false
    )
    try {
        foreach ($file in $files) {
            $relative = $file.FullName.Substring($repoRoot.Length).TrimStart([char[]]'\/').Replace('\', '/')
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $file.FullName,
                "zrodlo-slowa/$relative",
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }

        foreach ($file in $evidenceFiles) {
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $file.FullName,
                "zrodlo-slowa/audit-evidence/visuals/$($file.Name)",
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }

        foreach ($testEvidence in $testEvidenceFiles) {
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $archive,
                $testEvidence.File.FullName,
                $testEvidence.Entry,
                [System.IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }

        $evidenceReadme = $archive.CreateEntry('zrodlo-slowa/audit-evidence/README.txt')
        $evidenceWriter = [System.IO.StreamWriter]::new($evidenceReadme.Open(), [System.Text.UTF8Encoding]::new($false))
        try {
            $evidenceWriter.WriteLine('Selected visual evidence from the Android emulator and the local two-instance administration environment.')
            $evidenceWriter.WriteLine('Real 3DORS operation screens use FLAG_SECURE. final_deeplink.xml is the safe UI hierarchy captured after the onNewIntent test.')
            $evidenceWriter.WriteLine('Test conditions: docs/FINAL_AUDIT_BEFORE_PHYSICAL_E2E.md')
            $evidenceWriter.WriteLine('Referral implementation: docs/RAPORT_WDROZENIA_REFERRAL_2026-08-10.md')
            $evidenceWriter.WriteLine('Talent and response-publication implementation: docs/RAPORT_WDROZENIA_ODPOWIEDZI_PUBLIKACJA_I_TALENT_2026-08-10.md')
            $evidenceWriter.WriteLine('Campaign and notification implementation: docs/RAPORT_WDROZENIA_KAMPANIE_POWIADOMIENIA_2026-08-10.md')
            $evidenceWriter.WriteLine('Sentinel scaling and monitor view: docs/RAPORT_WDROZENIA_WARTOWNIK_SKALOWANIE_2026-08-11.md')
            $evidenceWriter.WriteLine('Connected-test XML results: audit-evidence/test-results/')
        } finally {
            $evidenceWriter.Dispose()
        }

        $manifest = $archive.CreateEntry('zrodlo-slowa/AUDIT_ARCHIVE_MANIFEST.txt')
        $writer = [System.IO.StreamWriter]::new($manifest.Open(), [System.Text.UTF8Encoding]::new($false))
        try {
            $writer.WriteLine('ZRODLO SLOWA - snapshot for independent audit')
            $writer.WriteLine('Date: 2026-08-11')
            $writer.WriteLine("Source files: $($files.Count)")
            $writer.WriteLine("Selected runtime evidence files: $($evidenceFiles.Count)")
            $writer.WriteLine("Connected-test result files: $($testEvidenceFiles.Count)")
            $writer.WriteLine('Excluded: every real .env/.env.* file (only explicitly named examples are allowed), .git, IDE/system data, vendor/node_modules, cache, runtime storage, database backups, builds, APKs, keystores, logs and older archives.')
            $writer.WriteLine('Start here: CONSULTATION_README.md')
            $writer.WriteLine('Reports: docs/FINAL_AUDIT_BEFORE_PHYSICAL_E2E.md, docs/RAPORT_WDROZENIA_REFERRAL_2026-08-10.md, docs/RAPORT_WDROZENIA_ODPOWIEDZI_PUBLIKACJA_I_TALENT_2026-08-10.md, docs/RAPORT_WDROZENIA_KAMPANIE_POWIADOMIENIA_2026-08-10.md and docs/RAPORT_WDROZENIA_WARTOWNIK_SKALOWANIE_2026-08-11.md')
        } finally {
            $writer.Dispose()
        }
    } finally {
        $archive.Dispose()
    }
} finally {
    $archiveStream.Dispose()
}

$hash = Get-FileHash -LiteralPath $resolvedOutput -Algorithm SHA256
$hashPath = "$resolvedOutput.sha256"
Set-Content -LiteralPath $hashPath -Value ("{0}  {1}" -f $hash.Hash, [System.IO.Path]::GetFileName($resolvedOutput)) -Encoding ascii

[pscustomobject]@{
    Archive = $resolvedOutput
    Bytes = (Get-Item -LiteralPath $resolvedOutput).Length
    Files = $files.Count + $evidenceFiles.Count + $testEvidenceFiles.Count + 2
    SHA256 = $hash.Hash
    HashFile = $hashPath
}
