$statePath = "storage\rag\patient_typos_ingest_state.json"
$chromaPath = "storage\rag\chroma"
$sourcePath = "rasa\data\patient_typos"

if (Test-Path $statePath) {
    $state = Get-Content -Path $statePath -Raw | ConvertFrom-Json
    $done = @($state.done_files).Count
} else {
    $done = 0
}

$total = if (Test-Path $sourcePath) {
    @(Get-ChildItem -Path $sourcePath -Filter "patient_typos_*.yml" -File).Count
} else {
    0
}
$size = 0
if (Test-Path $chromaPath) {
    $measure = Get-ChildItem -Path $chromaPath -Recurse -File | Measure-Object -Property Length -Sum
    if ($null -ne $measure.Sum) {
        $size = [int64]$measure.Sum
    }
}

[pscustomobject]@{
    done_shards = $done
    total_shards = $total
    percent = if ($total -gt 0) { [math]::Round(($done / $total) * 100, 2) } else { $null }
    rag_index_bytes = $size
    rag_index_gb = [math]::Round($size / 1GB, 3)
    source_corpus_present = (Test-Path $sourcePath)
    state_file = $statePath
} | Format-List
