param(
    [string]$RepoPath = "C:\xampp\htdocs\fixerupper",
    [string]$Remote = "origin",
    [string]$Branch = "",
    [int]$ActivityWindowMinutes = 90
)

$ErrorActionPreference = "Stop"
$env:GIT_TERMINAL_PROMPT = "0"

function Write-Log {
    param([string]$Message)

    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $line = "[$timestamp] $Message"

    if (-not (Test-Path -LiteralPath $script:LogDir)) {
        New-Item -ItemType Directory -Path $script:LogDir -Force | Out-Null
    }

    for ($attempt = 1; $attempt -le 5; $attempt++) {
        try {
            Add-Content -LiteralPath $script:LogFile -Value $line -Encoding UTF8
            return
        } catch {
            if ($attempt -eq 5) {
                throw
            }

            Start-Sleep -Milliseconds 250
        }
    }
}

function Invoke-Git {
    param([string[]]$GitArgs)

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"

    try {
        $output = & git @GitArgs 2>&1
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    foreach ($line in $output) {
        Write-Log "git $($GitArgs -join ' '): $line"
    }

    if ($exitCode -ne 0) {
        throw "git $($GitArgs -join ' ') failed with exit code $exitCode"
    }

    return $output
}

function Get-StatusPath {
    param([string]$StatusLine)

    if ([string]::IsNullOrWhiteSpace($StatusLine) -or $StatusLine.Length -lt 4) {
        return ""
    }

    $path = $StatusLine.Substring(3).Trim()

    if ($path.Contains(" -> ")) {
        $path = ($path -split " -> ")[-1].Trim()
    }

    return $path.Trim('"')
}

function Test-RecentProjectActivity {
    param(
        [string]$StatusText,
        [int]$WindowMinutes
    )

    if ([string]::IsNullOrWhiteSpace($StatusText)) {
        return $false
    }

    $cutoff = (Get-Date).AddMinutes(-1 * $WindowMinutes)
    $lines = $StatusText -split "(\r\n|\n|\r)" | Where-Object { -not [string]::IsNullOrWhiteSpace($_) }

    foreach ($line in $lines) {
        $state = $line.Substring(0, [Math]::Min(2, $line.Length))

        if ($state.Contains("D")) {
            return $true
        }

        $relativePath = Get-StatusPath $line
        if ([string]::IsNullOrWhiteSpace($relativePath)) {
            continue
        }

        $fullPath = Join-Path $RepoPath $relativePath

        if (Test-Path -LiteralPath $fullPath -PathType Leaf) {
            $item = Get-Item -LiteralPath $fullPath
            if ($item.LastWriteTime -ge $cutoff) {
                return $true
            }
        } elseif (Test-Path -LiteralPath $fullPath -PathType Container) {
            $recentFile = Get-ChildItem -LiteralPath $fullPath -Recurse -File -Force |
                Where-Object { $_.LastWriteTime -ge $cutoff } |
                Select-Object -First 1

            if ($null -ne $recentFile) {
                return $true
            }
        }
    }

    return $false
}

$script:LogDir = Join-Path $RepoPath "logs"
$script:LogFile = Join-Path $script:LogDir "git-hourly-save.log"

try {
    Set-Location -LiteralPath $RepoPath

    Invoke-Git @("rev-parse", "--is-inside-work-tree") | Out-Null

    if ([string]::IsNullOrWhiteSpace($Branch)) {
        $Branch = ((Invoke-Git @("branch", "--show-current")) -join "").Trim()
    }

    if ([string]::IsNullOrWhiteSpace($Branch)) {
        $Branch = "main"
    }

    $status = (& git status --porcelain) -join [Environment]::NewLine
    if ([string]::IsNullOrWhiteSpace($status)) {
        Write-Log "No project changes found. Autosave skipped."
        exit 0
    }

    if (-not (Test-RecentProjectActivity -StatusText $status -WindowMinutes $ActivityWindowMinutes)) {
        Write-Log "Project changes exist, but no recent activity was detected in the last $ActivityWindowMinutes minutes. Autosave skipped."
        exit 0
    }

    Invoke-Git @("add", "-A") | Out-Null

    $status = (& git status --porcelain) -join [Environment]::NewLine
    if (-not [string]::IsNullOrWhiteSpace($status)) {
        $commitTime = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
        Invoke-Git @("commit", "-m", "Hourly save: $commitTime") | Out-Null
        Write-Log "Created an hourly save commit."
    } else {
        Write-Log "No file changes found."
    }

    $remotes = (& git remote) -split [Environment]::NewLine
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = "Continue"

    try {
        $upstream = & git rev-parse --abbrev-ref "$Branch@{upstream}" 2>$null
        $upstreamExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    $hasUpstream = ($upstreamExitCode -eq 0) -and (-not [string]::IsNullOrWhiteSpace(($upstream -join "")))

    if (($remotes -contains $Remote) -and $hasUpstream) {
        try {
            Invoke-Git @("push") | Out-Null
            Write-Log "Pushed branch '$Branch' to its configured upstream."
        } catch {
            Write-Log "Push failed, but local autosave was kept: $($_.Exception.Message)"
        }
    } elseif ($remotes -contains $Remote) {
        Write-Log "Remote '$Remote' exists, but branch '$Branch' has no upstream yet. Run: git push -u $Remote $Branch"
    } else {
        Write-Log "Remote '$Remote' is not configured yet. Changes are saved locally only."
    }

    exit 0
} catch {
    Write-Log "ERROR: $($_.Exception.Message)"
    exit 1
}
