param(
    [string]$RepoPath = "C:\xampp\htdocs\fixerupper",
    [string]$Remote = "origin",
    [string]$Branch = ""
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

    Add-Content -LiteralPath $script:LogFile -Value $line -Encoding UTF8
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
        Invoke-Git @("push") | Out-Null
        Write-Log "Pushed branch '$Branch' to its configured upstream."
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
