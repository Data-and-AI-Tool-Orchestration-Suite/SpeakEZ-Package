<?php
/** @var UserSession $userSession */
$page = "metrics";
include_once __DIR__ . '/../_header.php';
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
    <h1 class="h4">Usage Audit Logs</h1>
</div>

<div class="row mb-3">
    <div class="col-md-3">
        <label for="start-date">Start Date:</label>
        <input type="date" id="start-date" class="form-control" />
    </div>
    <div class="col-md-3">
        <label for="end-date">End Date:</label>
        <input type="date" id="end-date" class="form-control" />
    </div>
    <div class="col-md-3 align-self-end">
        <button id="filter-date-btn" class="btn btn-primary">Filter</button>
        <button id="clear-date-btn" class="btn btn-secondary">Clear</button>
    </div>
</div>


<div class="card mb-4">
    <div class="card-body">
        <table id="usage-breakdown-table" class="table table-striped table-bordered" style="width:100%">
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>User Name</th>
                    <th>Total Minutes Audio</th>
                    <th>Total Words Transcribed</th>
                    <th>Total Whisper Cost</th>
                    <th>Total Tokens In</th>
                    <th>Total Tokens Out</th>
                    <th>Total LLM Cost</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5>File Durations</h5>
        <div class="mb-2">
            <strong>Total Hours of Audio Uploaded:</strong> <span id="total-audio-hours">0.00</span>
            <br>
            <strong>Total Audio/Video Files Uploaded:</strong> <span id="total-audio-video-files">0.00</span>
            <br>
            <strong>Total Transcripts Uploaded:</strong> <span id="total-num-transcripts">0</span>
        </div>
        <table id="file-duration-table" class="table table-striped table-bordered" style="width:100%">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Collection Code</th>
                    <th>Filename</th>
                    <th>Duration (seconds)</th>
                    <th>Created At</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

<script type="text/javascript">

$(document).ready(function() {
    var table = $('#usage-breakdown-table').DataTable({
        "processing": true,
        "serverSide": false,
        "ajax": {
            "url": "/metrics/<?= $tenant->getId() ?>/list-usage-breakdown",
            "type": "GET",
            "dataSrc": "data",
            "data": function(d) {
                d.start_date = $('#start-date').val();
                d.end_date = $('#end-date').val();
            }
        },
        "columns": [
            { "data": "user_id" },
            { "data": "full_name" },
            { "data": "total_minutes_audio", "render": $.fn.dataTable.render.number(',', '.', 2) },
            { "data": "total_words_transcribed", "render": $.fn.dataTable.render.number(',', 0) },
            { "data": "total_whisper_cost", "render": $.fn.dataTable.render.number(',', '.', 6, '$') },
            { "data": "total_tokens_in", "render": $.fn.dataTable.render.number(',', 0) },
            { "data": "total_tokens_out", "render": $.fn.dataTable.render.number(',', 0) },
            { "data": "total_llm_cost", "render": $.fn.dataTable.render.number(',', '.', 6, '$') }
        ],
        "order": [[0, 'asc']]
    });

    var durationTable = $('#file-duration-table').DataTable({
        "processing": true,
        "serverSide": false,
        "ajax": {
            "url": "/metrics/<?= $tenant->getId() ?>/list-duration-rows",
            "type": "GET",
            "dataSrc": function(json) {
                // Calculate total hours and update the UI
                var totalSeconds = 0;
                if (json.data && Array.isArray(json.data)) {
                    for (var i = 0; i < json.data.length; i++) {
                        var sec = parseFloat(json.data[i].duration_seconds);
                        if (!isNaN(sec)) totalSeconds += sec;
                    }
                }
                var totalHours = totalSeconds / 3600;
                $('#total-audio-hours').text(totalHours.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                $('#total-audio-video-files').text(json.data.length);
                // Fetch total transcripts from dedicated endpoint
                $.get('/metrics/<?= $tenant->getId() ?>/total-num-transcripts', {
                    start_date: $('#start-date').val(),
                    end_date: $('#end-date').val()
                }, function(resp) {
                    var val = 0;
                    if (typeof resp === 'object' && resp !== null && 'total_num_transcripts' in resp) {
                        val = resp.total_num_transcripts;
                    } else if (!isNaN(resp)) {
                        val = resp;
                    }
                    $('#total-num-transcripts').text(val);
                });
                return json.data;
            },
            "data": function(d) {
                d.start_date = $('#start-date').val();
                d.end_date = $('#end-date').val();
            }
        },
        "columns": [
            {"data": "project_name"},
            { "data": "collection_code"},
            { "data": "filename" },
            { "data": "duration_seconds", "render": $.fn.dataTable.render.number(',', '.', 2) },
            { "data": "created_at" }
        ],
        "order": [[2, 'desc']]
    });

    $('#filter-date-btn').on('click', function() {
        table.ajax.reload();
        durationTable.ajax.reload();
    });
    $('#clear-date-btn').on('click', function() {
        $('#start-date').val('');
        $('#end-date').val('');
        table.ajax.reload();
        durationTable.ajax.reload();
    });
});
</script>

<?php
include_once __DIR__ . '/../_footer.php';