param(
    [Parameter(Mandatory = $true)]
    [string]$DocumentPath,

    [Parameter(Mandatory = $true)]
    [string]$OutputDirectory,

    [int]$PagesPerFile = 5,

    [int]$PageCount = 61,

    [switch]$MeasureOnly
)

$ErrorActionPreference = "Stop"
$document = $null
$word = $null
$wordPid = $null
$initialPids = @(Get-Process WINWORD -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id)

New-Item -ItemType Directory -Path $OutputDirectory -Force | Out-Null
$resolvedDocument = (Resolve-Path -LiteralPath $DocumentPath).Path
$resolvedOutput = (Resolve-Path -LiteralPath $OutputDirectory).Path

try {
    $word = New-Object -ComObject Word.Application
    Write-Output "WORD_CREATED"
    $word.Visible = $false
    $word.DisplayAlerts = 0
    $word.AutomationSecurity = 3
    $word.Options.UpdateLinksAtOpen = $false

    Start-Sleep -Milliseconds 500
    $newProcesses = @(Get-Process WINWORD -ErrorAction SilentlyContinue |
        Where-Object { $initialPids -notcontains $_.Id } |
        Sort-Object StartTime -Descending)
    if ($newProcesses.Count -eq 0) {
        throw "Word COM did not create an isolated WINWORD process; refusing to use an existing user process."
    }
    $wordPid = $newProcesses[0].Id
    Write-Output ("WORD_PID={0}" -f $wordPid)

    Write-Output "OPENING_DOCUMENT"
    $document = $word.Documents.Open(
        $resolvedDocument,
        $false,
        $true,
        $false,
        "",
        "",
        $false,
        "",
        "",
        0,
        "",
        $false,
        $true,
        0,
        $true,
        ""
    )
    Write-Output "DOCUMENT_OPENED"

    if ($MeasureOnly) {
        $measuredPageCount = $document.ComputeStatistics(2)
        Write-Output ("MEASURED_PAGE_COUNT={0}" -f $measuredPageCount)
        return
    }

    for ($from = 1; $from -le $pageCount; $from += $PagesPerFile) {
        $to = [Math]::Min($from + $PagesPerFile - 1, $pageCount)
        $name = "pages-{0:D3}-{1:D3}.pdf" -f $from, $to
        $outputPdf = Join-Path $resolvedOutput $name
        Write-Output ("EXPORTING={0}-{1}" -f $from, $to)
        $document.ExportAsFixedFormat(
            $outputPdf,
            17,
            $false,
            0,
            3,
            $from,
            $to,
            0,
            $true,
            $false,
            0,
            $false,
            $true,
            $false
        )
        Write-Output ("EXPORTED={0}-{1}" -f $from, $to)
        Write-Output $outputPdf
    }

    Write-Output ("PAGE_COUNT={0}" -f $pageCount)
}
finally {
    if ($null -ne $document) {
        $document.Close($false)
        [void][Runtime.InteropServices.Marshal]::ReleaseComObject($document)
    }
    if ($null -ne $word -and $null -ne $wordPid) {
        $word.Quit()
        [void][Runtime.InteropServices.Marshal]::ReleaseComObject($word)
    }
    [GC]::Collect()
    [GC]::WaitForPendingFinalizers()
    if ($null -ne $wordPid -and (Get-Process -Id $wordPid -ErrorAction SilentlyContinue)) {
        Stop-Process -Id $wordPid -Force
    }
}
