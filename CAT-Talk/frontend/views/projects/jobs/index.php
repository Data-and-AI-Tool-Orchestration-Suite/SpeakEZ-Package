<?php
/** @var UserSession $userSession */
$page = 'home';
global $rootURL;
include_once __DIR__ . '/../../_header.php';
?>
<section class="home">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <div class="d-flex justify-content-left flex-wrap flex-md-nowrap align-items-center pb-2 mb-3">
            <button onclick="window.location.href='<?= $rootURL ?>/projects/dashboard/<?= $projectId ?>'" class="btn btn-secondary me-2" ><i class="fa-solid fa-arrow-left"></i> Back</button>
            <h1 class="h4"><?= $collectionCode ?> Dashboard</h1>
        </div>
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center">
            <div class="d-flex gap-2">
            <button type="button" class="btn btn-secondary" title="Upload file(s)" data-bs-toggle="modal" data-bs-target="#uploadModal">
                <i class="fa fa-cloud-upload"></i> Upload Media
            </button>
            <button type="button" class="btn btn-secondary" title="Upload transcript(s)" data-bs-toggle="modal" data-bs-target="#uploadTranscriptModal">
                <i class="fa fa-cloud-upload"></i> Upload Transcript(s)
            </button>
        </div>
            <div class="spinner-border" role="status" id="spinner" hidden>
                <span class="sr-only">Loading...</span>
            </div>
        </div>
    </div>
    <div class="row">
        &nbsp;<div class="col-md-12">
            <table id="file-table" class="display table table-striped table-bordered" style="width:100%">
                <thead>
                <tr>
                    <th><input type="checkbox" id="check-all" /></th>
                    <th>Collection Code</th>
                    <th>Files</th>
                    <th data-bs-toggle="tooltip" title="Convert audio files to text">Transcribe</th>
                    <th data-bs-toggle="tooltip" title="Add timestamps to transcripts">Synchronify</th>
                    <th data-bs-toggle="tooltip" title="Generate summary documents and an OhmsXML for a transcript">Describalize</th>
                    <th data-bs-toggle="tooltip" title="Perform risk analysis on a transcript">Riskalyze</th>
                    <th data-bs-toggle="tooltip" title="Edit a generated transcript">Touch-Up</th>
                    <th data-bs-toggle="tooltip" title="Perform custom analysis jobs on a transcript">Analyze</th>
                    <th>Upload Date</th>
                    <th>Actions</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tfoot>
                <tr>
                    <th></th>
                    <th>Collection Code</th>
                    <th>Files</th>
                    <th>Transcribe</th>
                    <th>Synchronify</th>
                    <th>Describalize</th>
                    <th>Riskalyze</th>
                    <th>Touch-Up</th>
                    <th>Analyze</th>
                    <th>Upload Date</th>
                    <th>Actions</th>
                    <th>Status</th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- upload file Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="uploadForm">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5" id="uploadModalLabel">Upload Audio or Video File(s)</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="col-lg-12 mb-3 form-floating">
                            <div class="custom-file" id="customFile" lang="en">
                                <input type="file" class="form-control custom-file-input" accept=".mp3,.wav,.aac,.flac,.ogg,.m4a,.wma,.aiff,.au,.mp4,.mov,.avi,.wmv,.flv,.mkv,.webm,.mpeg,.mpg,.m4v,.3gp,.3g2,.ts,.mts,.m2ts" id="uploadReportFile" aria-describedby="fileLabel" multiple required>
                            </div>
                        </div>
                        <div class="col-lg-12 mb-3 form-floating">
                            <!-- <div class="input-group mb-3">
                                <span class="input-group-text" id="coll-code-desc">Collection Code</span>
                                <input id="coll-code" type="text" class="form-control" aria-label="Collection Code" aria-describedby="coll-code-desc" required>
                            </div> -->
                        </div>
                        <div id="uploadStatus"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button id="submitUploadBtn" type="button" class="btn btn-primary">Upload
                            <span id="submitUploadBtnSpinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display: none;"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
     <!-- /Upload File modal -->
    <form id="uploadForm" style="display: none;" enctype="multipart/form-data">
        <input type="file" id="fileInput" name="file" multiple>
    </form>
</section>


    <!-- upload synchronifier file Modal -->
    <div class="modal fade" id="uploadSynchronifierModal" tabindex="-1" aria-labelledby="uploadSynchronifierModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="uploadSynchronifierForm">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5" id="uploadSynchronifierModalLabel">Upload Transcript File(s)</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="col-lg-12 mb-3 form-floating">
                            <div class="custom-file" id="customSynchronifierFile" lang="en">
                                <input type="file" class="form-control custom-file-input" accept="*" id="uploadSynchronifierReportFile" aria-describedby="fileLabel" multiple required>
                            </div>
                        </div>
                        <div class="col-lg-12 mb-3 form-floating">
                        </div>
                        <div id="uploadSynchronifierStatus"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button id="submitSynchronifierUploadBtn" type="button" class="btn btn-primary">Upload
                            <span id="submitSynchronifierUploadBtnSpinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display: none;"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
     <!-- /Upload synchronifier File modal -->
    <form id="uploadSynchronifierForm" style="display: none;" enctype="multipart/form-data">
        <input type="file" id="fileSynchronifierInput" name="file" multiple>
    </form>
</section>

<!-- upload verbatimizer file Modal -->
<div class="modal fade" id="uploadVerbatimizerModal" tabindex="-1" aria-labelledby="uploadVerbatimizerModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="uploadVerbatimizerForm">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5" id="uploadVerbatimizerModalLabel">Upload Audio Or Video File(s)</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="col-lg-12 mb-3 form-floating">
                            <div class="custom-file" id="customVerbatimizerFile" lang="en">
                                <input type="file" class="form-control custom-file-input" accept="*" id="uploadVerbatimizerReportFile" aria-describedby="fileLabel" multiple required>
                            </div>
                        </div>
                        <div class="col-lg-12 mb-3 form-floating">
                        </div>
                        <div id="uploadVerbatimizerStatus"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button id="submitVerbatimizerUploadBtn" type="button" class="btn btn-primary">Upload
                            <span id="submitVerbatimizerUploadBtnSpinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display: none;"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
     <!-- /Upload verbatimizer File modal -->
    <form id="uploadVerbatimizerForm" style="display: none;" enctype="multipart/form-data">
        <input type="file" id="fileVerbatimizerInput" name="file" multiple>
    </form>

    <!-- upload transcript Modal -->
    <div class="modal fade" id="uploadTranscriptModal" tabindex="-1" aria-labelledby="uploadTranscriptModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="uploadTranscriptForm">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5" id="uploadTranscriptModalLabel">Upload Transcript(s)</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="col-lg-12 mb-3 form-floating">
                            <div class="custom-file" id="customTranscriptFile" lang="en">
                                <input type="file" class="form-control custom-file-input" accept=".txt,.doc,.docx,.pdf,.rtf,.csv,.json,.md,.odt,.vtt,.srt,.sub,.transcript,.tex,.html,.xml,.tsv,.xhtml,.pages,.wps,.wpd,.enex,.epub,.mobi,.log,.lst,.tex,.texi,.texinfo,.nfo,.info,.rst,.asciidoc,.adoc,.asc,.rtfd,.fdx,.fdxt,.fountain,.scriv,.scrivx,.xps,.oxps,.ps,.psw,.abw,.zabw,.sxw,.sdw,.gdoc,.gslides,.gdraw,.gtable,.gform,.gmap,.gsite,.gscript,.gdoc,.gslides,.gdraw,.gtable,.gform,.gmap,.gsite,.gscript" id="uploadTranscriptFile" aria-describedby="fileLabel" multiple required>
                            </div>
                        </div>
                        <div class="col-lg-12 mb-3 form-floating">
                            <div class="input-group mb-3 align-items-stretch">
                            </div>
                        </div>
                        <div id="uploadTranscriptStatus"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button id="submitUploadTranscriptBtn" type="button" class="btn btn-primary">Upload
                            <span id="submitUploadTranscriptBtnSpinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display: none;"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- /Upload Transcript modal -->
    <form id="uploadForm" style="display: none;" enctype="multipart/form-data">
        <input type="file" id="fileInput" name="file" multiple>
    </form>
</section>




<script type="text/javascript">
    var rootURL = '<?= $rootURL ?>';
    var collectionCode = '<?= $collectionCode ?>';
    var projectId = '<?= $projectId ?>';
    var selected_ids = [];
    var dt;
    var currentRowIndex;
    var verbInterval, descInterval, ohmsInterval, riskInterval;

    $(document).ready(function () {
        $('.custom-file-input').on('change', function() {
            var fileName = $(this).val().split('\\').pop();
            $(this).next('.custom-file-label').addClass("selected").html(fileName);
        });

        // Initialize tooltips
        $('[data-bs-toggle="tooltip"]').tooltip();

        dt = new DataTable('#file-table', {
            ajax: {
                url: "<?= $rootURL ?>/files/list",
                data: {
                    "projectId": projectId,
                    "collCodes": collectionCode
                },
                error: function(xhr, error, thrown) {
                    if (xhr.status === 401) { // Unauthorized error
                        alert("Your session has expired. Please log in again.");
                        window.location.href = "<?= $rootURL ?>/login"; // Redirect to login page
                    } else {
                        alert("An error occurred while fetching data. Your session has potentially expired. Please reload the page.");
                    }
                }
            },
            processing: true,
            serverSide: true,
            responsive: true,
            dataId: 'DT_RowId',
            order: [[2, 'asc']],
            paging: false,
            dom: '<"top"f>rt<"bottom"ip><"clear">',
            columnDefs: [
                {
                    className: "dt-center",
                    targets: '_all'
                },
                {
                    orderable: false,
                    targets: [0,3,4,5,6,7,8,10,11]
                },
                {
                    type: "date",
                    targets: [7]
                },
                {
                    visible: <?php echo $_headerCurrentTenantId === '07ab7356-70ea-4f8e-ba3b-84f37c90ffad' ? 'true' : 'false' ?>,
                    targets: [4,5,6]
                },
                {
                    visible: false,
                    targets: [1, 10]
                }
            ],
            language: {
                emptyTable: "No files added."
            },
            columns: [
                {
                    data: null,
                    render: function(data) {
                        return `<input type="checkbox" class="check" data-id="${data.id}" />`;
                    }
                },
                {
                    data: 'collection_code'
                },
                {
                    data: null,
                    render: function (data, type, row) {
                        const files = [];
                        if (data.filename) files.push(data.filename);
                        if (data.transcriptionFilename) files.push(data.transcriptionFilename);

                        const filenames = files.map(path => {
                            const parts = path.split('/');
                            return parts[parts.length - 1];
                        });

                        const html = filenames.join('<br>');
                        const sortKey = filenames[0] || ''; // Use first filename for sorting

                        if (type === 'sort' || type === 'type') {
                            return sortKey.toLowerCase(); // ensure consistent sorting
                        }
                        return html;
                    }
                },
                {
                    data: null,
                    render: function(data) {
                        // Disable Transcribe button for file_type 'transcript'
                        if (data.file_type === 'transcript') {
                            return `<button class="btn btn-sm btn-outline-primary" disabled><i class="fa-solid fa-play"></i></button>`;
                        }
                        // ...existing logic...
                        let statusObj = JSON.parse(data.status);
                        let status = statusObj['status'];
                        if (status['verbatimizer'] === "Started"){
                            if (!verbInterval) {
                                verbInterval = setInterval(function () {
                                    checkStatus(data.id);
                                }, 60000);
                            }
                            return `<button class="btn processing-record-btn" onclick="cancelJob(['${data.id}'], 'verbatimizer')" ><img class="processing-record-img" src="/img/record.png"/></button>`;
                        } else if (status['verbatimizer'] === "Error") {
                            return `<i class="fa fa-x"></i>`;
                        } else if (status['verbatimizer'] === "complete") {
                            return `<div class="izer-action-wrapper">
                                    <button onclick="prepareVerbatimizer(this, ['${data.id}'], ${data.DT_RowId})"  class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-repeat"></i></button>
                                </div>`;
                        } else {
                            return `<div class="izer-action-wrapper">
                                    <button onclick="startVerbatimizer(this, ['${data.id}'], ${data.DT_RowId})"  class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-play"></i></button>
                                </div>`;
                        }
                    }
                },
                {
                    data: null,
                    render: function(data) {
                        // Disable Synchronify button for file_type 'transcript'
                        if (data.file_type === 'transcript') {
                            return `<button class="btn btn-sm btn-outline-primary" disabled><i class="fa-solid fa-play"></i></button>`;
                        }
                        // ...existing logic...
                        let statusObj = JSON.parse(data.status);
                        let status = statusObj['status'];
                        if (status['synchronifier'] === "Started"){
                            if (!riskInterval) {
                                riskInterval = setInterval(function () {
                                    checkStatus(data.id);
                                }, 60000);
                            }
                            return `<button class="btn processing-record-btn" onclick="cancelJob(['${data.id}'], 'synchronifier')" ><img class="processing-record-img" src="/img/record.png"/></button>`;
                        } else if (status['synchronifier'] === "Error") {
                            return `<i class="fa fa-x"></i>`;
                        } else if (status['synchronifier'] === "complete") {
                            return `<div class="izer-action-wrapper">
                                    <button onclick="prepareSynchronifier(this, '${data.id}', ${data.DT_RowId})"  class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-repeat"></i></button>
                                </div>`;
                        } else {
                            return `<div class="izer-action-wrapper">
                                        <button onclick="prepareSynchronifier(this, '${data.id}', ${data.DT_RowId})"  class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-play"></i></button>
                                    </div>`;
                        }
                    }
                },
                {
                    data: null,
                    render: function(data) {
                        // Always enabled for transcript
                        let disabled = (data.file_type === 'transcript') ? '' : (isJobAllowed(data) ? "" : "disabled");
                        let statusObj = JSON.parse(data.status);
                        let status = statusObj['status'];
                        if (status['describalizer'] === "Started"){
                            if (!riskInterval) {
                                riskInterval = setInterval(function () {
                                    checkStatus(data.id);
                                }, 60000);
                            }
                            return `<button class="btn processing-record-btn" onclick="cancelJob(['${data.id}'], 'describalizer')" ><img class="processing-record-img" src="/img/record.png"/></button>`;
                        } else if (status['describalizer'] === "complete") {
                                    return `<div class="izer-action-wrapper">
                                            <button onclick="startDescribalizer(this, ['${data.id}'], ${data.DT_RowId})"  class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-repeat"></i></button>
                                        </div>`;
                        }
                        return `<div class="izer-action-wrapper">
                                    <button onclick="startDescribalizer(this, ['${data.id}'])" class="btn btn-sm btn-outline-primary" ${disabled}>
                                        <i class="fa-solid fa-play"></i>
                                    </button>
                                </div>`;
                    }
                },
                {
                    data: null,
                    render: function(data) {
                        // Always enabled for transcript
                        let disabled = (data.file_type === 'transcript') ? '' : (isJobAllowed(data) ? "" : "disabled");
                        let statusObj = JSON.parse(data.status);
                        let status = statusObj['status'];
                        if (status['riskalyzer'] === "Started"){
                            if (!riskInterval) {
                                riskInterval = setInterval(function () {
                                    checkStatus(data.id);
                                }, 60000);
                            }
                            return `<button class="btn processing-record-btn" onclick="cancelJob(['${data.id}'], 'riskalyzer')" ><img class="processing-record-img" src="/img/record.png"/></button>`;
                        } else if (status['riskalyzer'] === "complete") {
                                    return `<div class="izer-action-wrapper">
                                            <button onclick="startRiskalyzer(this, ['${data.id}'], ${data.DT_RowId})"  class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-repeat"></i></button>
                                        </div>`;
                        }
                        return `<div class="izer-action-wrapper">
                                    <button onclick="startRiskalyzer(this, ['${data.id}'], ${data.DT_RowId})" class="btn btn-sm btn-outline-primary" ${disabled}>
                                        <i class="fa-solid fa-play"></i>
                                    </button>
                                </div>`;
                    }
                },
                {
                    data: null,
                    render: function(data) {
                        // Always enabled for transcript
                        let disabled = (data.file_type === 'transcript') ? '' : (isJobAllowed(data) ? "" : "disabled");
                        return `<div class="izer-action-wrapper">
                                    <button onclick="window.location.href='${rootURL}/files/touchup/${projectId}/${collectionCode}/${data.id}'" 
                                        class="btn btn-sm btn-outline-primary" ${disabled}>
                                        <i class="fa fa-edit"></i>
                                    </button>
                                </div>`;
                    }
                },
                {
                    data: null,
                    render: function(data) {
                        // Always enabled for transcript
                        let disabled = (data.file_type === 'transcript') ? '' : (isJobAllowed(data) ? "" : "disabled");
                        return `<div class="izer-action-wrapper">
                                    <button onclick="window.location.href='${rootURL}/files/analyze/${projectId}/${collectionCode}/${data.id}'" 
                                        class="btn btn-sm btn-outline-primary" ${disabled}>
                                        <i class="fa fa-chart-bar"></i>
                                    </button>
                                </div>`;
                    }
                },
                {
                    data: 'date_uploaded',
                    render: function (dateString) {
                        // Parse the date string into a JavaScript Date object
                        const date = new Date(dateString.replace(' ', 'T') + 'Z');

                        const options = {
                            timeZone: 'America/New_York',
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric',
                            hour: 'numeric', // Remove leading zero
                            minute: '2-digit',
                            hour12: true // 12-hour format with AM/PM
                        };
                        return date.toLocaleString('en-US', options);
                    }
                },
                {
                    data: null,
                    render: function(data) {
                        let archive = JSON.parse(data.archived);
                        let data_id = data.id;
                        if (archive['archived'] === true) {
                            return `<i class="fa-regular fa-square-check" style="color: green; font-size: 1.5em;"></i>`;
                        } else {
                            return `
                                <button data-id="${data_id}" class="btn btn-sm btn-warning archive-btn"><i class="fa fa-archive"></i></button><br>
                                <span class="small text-muted">${archive['days_left']} days until auto-archive</span>`;
                        }
                    }
                },
                {
                    data: 'status',
                    render: function(data){
                        let statusObj = JSON.parse(data);
                        let status = statusObj['status'];
                        let html= "";
                        for (const key in status) {
                            let capKey = capitalizeFirstLetter(key);
                            html += capKey + ": " + status[key] + "<br>";
                        }
                        return html;
                    }
                }
            ]

        });

        
        function isJobAllowed(data) {
            let statusObj = JSON.parse(data.status);
            let status = statusObj['status'];
            return (status['verbatimizer']?.toLowerCase() === "complete" || status['synchronifier']?.toLowerCase() === "complete");
        }


        //  fix for buttons being wonky
        // $('.dt-search').append(`<select id="collection-filter" style="width:unset; display:inline-block;" class="form-select form-select-sm ms-3" aria-label="select">
        //                                     <option disabled selected hidden>Collection</option>
        //                                     <option value="all">All</option>
        //                                 </select>`);
        $('.dt-search').append(`<button id="archive-selected-btn" class="btn btn-sm btn-secondary ms-2 dt-my-buttons">Archive
                                            <span id="archive-spinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display: none;"></span>
                                        </button>`);
        $('.dt-search').append(`<button id="download-selected-btn" class="btn btn-sm btn-secondary ms-3 dt-my-buttons">Download
                                             <span id="download-spinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display: none;"></span>
                                        </button>`);
        $('.dt-search').append(`<button id="delete-selected-btn" class="btn btn-sm btn-secondary ms-3 dt-my-buttons">Delete
                                             <span id="delete-spinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display: none;"></span>
                                        </button>`);
        
        <?php if (isset($_headerCurrentTenantId) && $_headerCurrentTenantId === '07ab7356-70ea-4f8e-ba3b-84f37c90ffad') : ?>
        $('.dt-search').append(`<button id="run-all-selected-btn" class="btn btn-sm btn-secondary ms-3 dt-my-buttons">Run-All
                                             <span id="run-all-spinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display: none;"></span>
                                        </button>`);
        <?php endif; ?>

        $('.dt-search').append(`<button id="run-verbatimizer-selected-btn" class="btn btn-sm btn-secondary ms-3 dt-my-buttons">Run Transcribe
                                             <span id="run-all-spinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display: none;"></span>
                                        </button>`);

        

        $("#collection-filter").change(function() {
            let code = $(this).val();
            if (code === 'all'){
                dt.ajax.url(`<?= $rootURL ?>/files/list`).load();
            } else {
                dt.ajax.url(`<?= $rootURL ?>/files/list?collCodes=${code}`).load();
            }

        });
    });

    // selects all the checkboxes in the table
    $("#check-all").on("click", function() {
        if ($(this).is(":checked")) {
            $('.check').prop("checked", true);
            selected_ids = [];
            $('.check').each(function() {
                selected_ids.push($(this).data("id"));
            });
        } else {
            $('.check').prop("checked", false);
            selected_ids = [];
        }
    });


    $(document).on('click', '#download-selected-btn', function() {
        if (selected_ids.length === 0) {
            showError("No rows selected for downloading");
        } else {
            let $spinner = $('#download-spinner');
            let $button = $(this);

            // Show the spinner and disable the button
            $spinner.show();
            $button.prop('disabled', true);

            downloadFilesById(null, $spinner, $button);
        }
    });

    $(document).on('click', '#delete-selected-btn', function() {
        if (selected_ids.length === 0) {
            showError("No rows selected for deleting");
        } else {
            let $spinner = $('#delete-spinner');
            let $button = $(this);

            // Show the spinner and disable the button
            $spinner.show();
            $button.prop('disabled', true);

            // Send the array of fileIds to delete
            deleteFile(selected_ids)
                .then(() => {
                    $spinner.hide();
                    $button.prop('disabled', false);
                    checkStatus(null, null)
                    $('#check-all').prop('checked', false);
                })
                .catch((error) => {
                    showError(error.message);
                    $spinner.hide();
                    $button.prop('disabled', false);
                });
        }
    });

    $(document).on('click', '#archive-selected-btn', function() {
        if (selected_ids.length === 0) {
            showError("No rows selected for archiving...");
        } else {
            let $spinner = $('#archive-spinner');
            let $button = $(this);

            // Show the spinner and disable the button
            $spinner.show();
            $button.prop('disabled', true);

            archiveFiles(null, $spinner, $button);

            $spinner.hide();
            $button.prop('disabled', false);
            $('#check-all').prop('checked', false);
            selected_ids = [];
        }
    });

    $(document).on('click', '.archive-btn', function() {
        let id = $(this).data('id');
        archiveFiles([id]);
        checkStatus(null, null)
    });

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    $(document).on('click', '#run-all-selected-btn', function() {
        if (selected_ids.length === 0) {
            showError("No rows selected...");
        } else {
            startAllJobs('verbatimizer', selected_ids, null, null)
            checkStatus(null, null)
        }
        selected_ids = []; // clear selected_ids
        $('#check-all').prop('checked', false);
    });

    $(document).on('click', '#run-verbatimizer-selected-btn', function() {
        if (selected_ids.length === 0) {
            showError("No rows selected...");
        } else {
            startJob('verbatimizer', selected_ids, null, null)
            checkStatus(null, null)
        }
        selected_ids = []; // clear selected_ids
        $('#check-all').prop('checked', false);
    });


    // grabs dynamically loaded checkboxes
    $(document).on('click', '.check', function() {
        let id = $(this).data("id");
        if ($(this).is(":checked")) {
            selected_ids.push(id);
        } else {
            selected_ids = selected_ids.filter(item => item !== id);
            if (selected_ids.length === 0 && $("#check-all").is(":checked")){
                $("#check-all").prop("checked", false);
            }
        }
        if (dt.page.info().recordsTotal !== selected_ids.length) {
            $("#check-all").prop("checked", false);
        } else {
            $("#check-all").prop("checked", true);
        }
    });

    $('#submitUploadTranscriptBtn').on('click', function() {
        var form = $('#uploadTranscriptForm')[0];
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }
        var fileInput = $('#uploadTranscriptFile')[0];
        if (!fileInput) {
            console.error('Transcript file input element not found.');
            return;
        }
        var files = fileInput.files;
        var maxFileSize = 10 * 1024 * 1024 * 1024; // 10GB
        var fileNames = [];
        for (var i = 0; i < files.length; i++) {
            if (files[i].size > maxFileSize) {
                alert('File ' + files[i].name + ' exceeds the maximum upload size of 10GB.');
                return;
            }
            fileNames.push(files[i].name);
        }
        if (fileNames.length > 0) {
            uploadTranscriptFiles(files, fileNames);
        }
    });

    async function uploadTranscriptFiles(files, fileNames) {
        let $spinner = $('#submitUploadTranscriptBtnSpinner');
        let $button = $('#submitUploadTranscriptBtn');
        $spinner.show();
        $button.prop('disabled', true);
        var collCode = collectionCode;
        $('#uploadTranscriptStatus').prepend('<p id="uploadTranscriptProgress" class="text-info"><strong>Uploading 0/' + fileNames.length + ' files...</strong></p>');
        var successCount = 0;
        var failureCount = 0;
        if (collCode.includes(" ")) {
            $('#uploadTranscriptStatus').prepend('<p class="text-danger"><strong>Collection Code cannot contain spaces</strong></p>');
            $spinner.hide();
            $button.prop('disabled', false);
            return;
        }
        // Step 1: Get presigned curl requests for each file
        var data = {
            "project_id": "<?= $project->getId() ?>",
            "collCode": collCode,  // Single collCode for the upload
            "filenames": fileNames  // Array of filenames
        };
        let presignedResponse;
        try {
            presignedResponse = await $.ajax({
                url: '/files/upload-transcript',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(data)
            });
        } catch (error) {
            $('#uploadTranscriptStatus').prepend('<p class="text-danger"><strong>Error getting presigned requests: ' + (error.statusText || 'Unknown error') + '</strong></p>');
            $spinner.hide();
            $button.prop('disabled', false);
            return;
        }
        if (!Array.isArray(presignedResponse) || presignedResponse.length === 0) {
            $('#uploadTranscriptStatus').prepend('<p class="text-danger"><strong>No presigned requests returned.</strong></p>');
            $spinner.hide();
            $button.prop('disabled', false);
            return;
        }
        
        // Step 2: Upload each file using the returned URL
        for (const item of presignedResponse) {
            // 1. The 'curl_request' *is* the URL. No parsing needed.
            var uploadUrl = item.curl_request;
            var fileName = item.fileName;
            var fileId = item.file_id;

            // 2. Find the specific file object from the input list
            var fileToUpload = null;
            Array.from(files).forEach(function(file) {
                if (file.name === fileName) {
                    fileToUpload = file;
                }
            });

            if (!fileToUpload) {
                $('#uploadTranscriptStatus').append('<p class="text-danger"><strong>Error: Could not find file ' + fileName + ' in selection.</strong></p>');
                failureCount++;
                continue;
            }

            try {
                // 3. Make the upload request using PUT
                await $.ajax({
                    url: uploadUrl,    // Use the URL directly
                    type: 'PUT',       // Use PUT for S3 presigned URLs
                    processData: false,  // Required for sending file data
                    contentType: fileToUpload.type || 'application/octet-stream', // Send the file's actual content type
                    data: fileToUpload   // Send the file object directly as the body
                });

                // After transcript is uploaded, create a ClearML dataset (this part was correct)
                await $.ajax({
                    url: "/files/create-clearml-dataset-transcript",
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({ "file_id": fileId })
                });

                successCount++;
            } catch (uploadError) {
                $('#uploadTranscriptStatus').append('<p class="text-danger"><strong>Error uploading transcript ' + fileName + ': ' + (uploadError.statusText || 'Unknown error') + '</strong></p>');
                failureCount++;
            }
            
            // This progress update was correct
            var totalProcessed = successCount + failureCount;
            $('#uploadTranscriptProgress').html('<strong>Uploading ' + totalProcessed + '/' + fileNames.length + ' files' + (successCount > 0 ? ' (' + successCount + ' successful)' : '') + '...</strong>');
        }

        if (successCount === fileNames.length) {
            $('#uploadTranscriptProgress').removeClass('text-info').addClass('text-success')
                .html('<strong>All transcripts uploaded successfully! (' + successCount + '/' + fileNames.length + ')</strong>');
            // Close modal after successful upload
            $('#uploadTranscriptModal').modal('hide');
        } else {
            $('#uploadTranscriptProgress').removeClass('text-info').addClass(successCount > 0 ? 'text-warning' : 'text-danger')
                .html('<strong>Upload complete: ' + successCount + '/' + fileNames.length + ' transcripts uploaded successfully.</strong>');
        }

        $spinner.hide();
        $button.prop('disabled', false);
        $("#uploadModal").modal("hide");
    }
    $('#uploadTranscriptModal').on('hide.bs.modal', function (event) {
        $('#uploadTranscriptFile').val('');
        $('#uploadTranscriptStatus').html('');
        $('#submitUploadTranscriptBtn').show();
        $('#coll-code-transcript').val('');
    });

    $('#submitUploadBtn').on('click', function() {
        var form = $('#uploadForm')[0];

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        var fileInput = $('#uploadReportFile')[0]; // Get the file input element
        if (!fileInput) {
            console.error('File input element not found.');
            return;
        }

        var files = fileInput.files;
        var maxFileSize = 10 * 1024 * 1024 * 1024; // 10GB
        var fileNames = [];

        // Loop through files and collect their names, check size
        for (var i = 0; i < files.length; i++) {
            if (files[i].size > maxFileSize) {
                alert('File ' + files[i].name + ' exceeds the maximum upload size of 10GB (yeesh).');
                return;
            }

            // Push file name to the fileNames array
            fileNames.push(files[i].name);
        }

        // If there are any filenames, proceed with upload
        if (fileNames.length > 0) {
            uploadFiles(fileNames); // Pass the filenames instead of fileChunks
        }
    });


    


    $('#uploadModal').on('hide.bs.modal', function (event) {
        $('#uploadReportFile').val('');
        $('#uploadReportFileLabel').html('Select file...');
        $('#uploadStatus').html('');
        $('#submitUploadBtn').show();
        $('#coll-code').val('');
        checkStatus(null, null)
    });

    $('#uploadSynchronifierModal').on('hide.bs.modal', function (event) {
        $('#uploadSynchronifierReportFile').val('');
        $('#uploadSynchronifierReportFileLabel').html('Select file...');
        $('#uploadSynchronifierStatus').html('');
        $('#submitSynchronifierUploadBtn').show();
        checkStatus(null, null)
    });

    $('#uploadVerbatimizerModal').on('hide.bs.modal', function (event) {
        $('#uploadVerbatimizerReportFile').val('');
        $('#uploadVerbatimizerReportFileLabel').html('Select file...');
        $('#uploadVerbatimizerStatus').html('');
        $('#submitVerbatimizerUploadBtn').show();
        checkStatus(null, null)
    });

    $('#uploadTranscriptModal').on('hide.bs.modal', function (event) {
        $('#uploadTranscriptReportFile').val('');
        $('#uploadTranscriptReportFileLabel').html('Select file...');
        $('#uploadTranscriptStatus').html('');
        $('#submitTranscriptUploadBtn').show();
        checkStatus(null, null)
    });



    $(document).on('click', '#file-table tbody td', function() {
        // Get the row and column indexes of the clicked cell
        let cell = dt.cell(this);
        currentRowIndex = cell.index().row;
    });

    // More comprehensive solution for all button types
    function getFileExtension(filename) {
        const parts = filename.split('.');
        return parts.length > 1 ? '.' + parts.pop() : ''; // Return empty string if no extension
    }

    function prepareSynchronifier(elem, synchronifierId, currentRowIndex) {
        let rowData = dt.row(currentRowIndex).data(); // Get the data for the current row
        let statusObj = JSON.parse(rowData.status); // Parse the status field
        console.log(statusObj.status.synchronifier)
        if (statusObj.status.synchronifier == "complete") {
            prepareVerbatimizer(elem, synchronifierId, currentRowIndex, run=false); // Call prepareVerbatimizer if verbatimizer is complete
        }
        $('#submitSynchronifierUploadBtn').off('click').on('click', function() {
            var form = $('#uploadSynchronifierForm')[0];

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            var fileInput = $('#uploadSynchronifierReportFile')[0]; // Get the file input element
            if (!fileInput) {
                console.error('File input element not found.');
                return;
            }

            var files = fileInput.files;
            var maxFileSize = 10 * 1024 * 1024 * 1024; // 10GB
            var fileNames = [];

            // Loop through files and collect their names, check size
            for (var i = 0; i < files.length; i++) {
                if (files[i].size > maxFileSize) {
                    alert('File ' + files[i].name + ' exceeds the maximum upload size of 10GB (yeesh).');
                    return;
                }

                // Push file name to the fileNames array
                fileNames.push(files[i].name);
            }

            // If there are any filenames, proceed with upload
            if (fileNames.length > 0) {
                uploadSynchronifierFiles(synchronifierId, fileNames, synchronifier=true) // Pass the filenames instead of fileChunks
                    .then(() => {
                        let fileName = fileNames[0];
                        let fileId = dt.row(0).data().id;
                        let extension = getFileExtension(fileName);
                        startSynchronifier(elem, synchronifierId, fileNames[0], currentRowIndex);
                        $('#uploadSynchronifierModal').modal('hide');
                    })
            }

        });

        $('#uploadSynchronifierModal').modal('show');
    }

    function prepareVerbatimizer(elem, verbatimizerId, currentRowIndex, run=true) {
        $('#submitVerbatimizerUploadBtn').off('click').on('click', function() {
            var form = $('#uploadVerbatimizerForm')[0];
            let rowData = dt.row(currentRowIndex).data();
            let originalFileName = rowData.filename;

            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            var fileInput = $('#uploadVerbatimizerReportFile')[0]; // Get the file input element
            if (!fileInput) {
                console.error('File input element not found.');
                return;
            }
            var files = fileInput.files;
            var maxFileSize = 10 * 1024 * 1024 * 1024; // 10GB
            var fileNames = [];

            // Loop through files and collect their names, check size
            // for (var i = 0; i < files.length; i++) {
            //     if (files[i].size > maxFileSize) {
            //         alert('File ' + files[i].name + ' exceeds the maximum upload size of 10GB (yeesh).');
            //         return;
            //     }

            //     // Push file name to the fileNames array
            //     fileNames.push(files[i].name);
            // }

            if (files.length > 1) {
                alert('Please upload only one file at a time for verbatimizer.');
                return;
            }

            if (files[0].name !== originalFileName) {
                alert('Please upload the same audio file for transcription.');
                return;
            }

            if (files[0].size > maxFileSize) {
                alert('File ' + files[i].name + ' exceeds the maximum upload size of 10GB (yeesh).');
                return;
            }
            fileNames.push(files[0].name);

            // If there are any filenames, proceed with upload
            if (fileNames.length > 0) {
                uploadVerbatimizerFiles(verbatimizerId, fileNames, synchronifier=false) // Pass the filenames instead of fileChunks
                    .then(() => {
                        let fileName = fileNames[0];
                        let fileId = dt.row(0).data().id;
                        let extension = getFileExtension(fileName);
                        if (run) {
                            startVerbatimizer(elem, verbatimizerId, currentRowIndex);
                            $('#uploadVerbatimizerModal').modal('hide');
                        } else {
                            $('#uploadVerbatimizerModal').modal('hide');
                        }
                    })
            }
        });

        $('#uploadVerbatimizerModal').modal('show');
    }

    function downloadFilesById(files = null, spinner = null, button = null) {
        $.ajax({
            url: '/files/download-id',
            type: 'GET',
            data: {'ids': files == null ? selected_ids : files},
            dataType: 'json',
            success: function(response) {
                if (Array.isArray(response) && response.length > 0) {
                    let delay = 0;
                    response.forEach((item, index) => {
                        setTimeout(() => {
                            var url = item.zip_url;  // Direct ZIP URL for download

                            // Create a link element to initiate the download
                            var downloadLink = document.createElement("a");
                            downloadLink.href = url;
                            downloadLink.download = ''; // Let the browser handle the filename

                            // Append the link to the body
                            document.body.appendChild(downloadLink);

                            // Trigger the download
                            downloadLink.click();

                            // Clean up
                            document.body.removeChild(downloadLink);

                            // Hide spinner and enable button after the last file download
                            if (index === response.length - 1) {
                                if (spinner != null) {
                                    spinner.hide();
                                }
                                if (button != null) {
                                    button.prop('disabled', false);
                                }
                            }
                        }, delay);

                        delay += 500; // Delay between each download
                    });
                } else if (response.error) {
                    // Display error message if present
                    $('#uploadStatus').prepend('<p class="text-danger"><strong>' + response.error + '</strong></p>');
                    if (spinner != null) {
                        spinner.hide();
                    }
                    if (button != null) {
                        button.prop('disabled', false);
                    }
                }
            },
            error: function(request, error) {
                console.error(error);
                if (spinner != null) {
                    spinner.hide();
                }
                if (button != null) {
                    button.prop('disabled', false);
                }
            }
        });
    }

    function createZipForCollection(collectionCode, urls) {
        return new Promise((resolve, reject) => {
            const zip = new JSZip();
            let filesProcessed = 0;

            urls.forEach(url => {
                fetch(url)
                    .then(response => response.blob())
                    .then(blob => {
                        zip.file(url.substring(url.lastIndexOf('/') + 1), blob);  // Add file to ZIP
                        filesProcessed++;
                        if (filesProcessed === urls.length) {
                            // Generate the ZIP file
                            zip.generateAsync({type: 'blob'}).then(content => {
                                // Create a link element to initiate the download
                                const downloadLink = document.createElement("a");
                                downloadLink.href = URL.createObjectURL(content);
                                downloadLink.download = `${collectionCode}.zip`;  // File name for ZIP

                                // Append the link to the body
                                document.body.appendChild(downloadLink);

                                // Trigger the download
                                downloadLink.click();

                                // Clean up
                                document.body.removeChild(downloadLink);
                                URL.revokeObjectURL(downloadLink.href);  // Free memory

                                resolve();  // Resolve the promise
                            }).catch(error => reject(error));
                        }
                    })
                    .catch(error => reject(error));
            });
        });
    }

    function deleteFile(fileIds) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: rootURL + '/files/delete',
                method: 'POST',
                data: {
                    'file_ids': fileIds, // Changed from 'file_id' to 'file_ids' to handle an array
                    'project_id': projectId
                },
                success: function(response) {
                    if (response.success) {
                        resolve(response);
                    } else {
                        console.log("Failed to delete files from database after error");
                        reject(new Error('File deletion failed for IDs: ' + fileIds.join(', ')));
                    }
                },
                error: function() {
                    console.log("Failed to delete files from database after error");
                    reject(new Error('File deletion failed for IDs: ' + fileIds.join(', ')));
                }
            });
        });
    }

    function cancelJob(id, job) {
        data = {
            "project_id": projectId,
            "collection_code": collectionCode,
            "file_ids": id,
            "job_name": job
        }

        $.ajax({
            url: rootURL + '/jobs/cancel',
            method: 'POST',
            data: data,
            success: function(response) {
                if (Array.isArray(response)) {
                    response.forEach(item => {
                        if (!item.success) {
                            $.ajax({
                                url: rootURL + '/jobs/cancel-celery',
                                method: 'POST',
                                data: data,
                                success: function(response) {
                                    if (Array.isArray(response)) {
                                        response.forEach(item => {
                                            if (!item.success) {
                                                showError(item.error_message);
                                            }
                                        });
                                        checkStatus(null, null)
                                    } else if (!response.success) {
                                        showError("Could not cancel jobs.");
                                    }
                                },
                                error: function() {
                                    showError("Server error!");
                                    checkStatus(null, null)
                                }
                            });
                        }
                    });
                } else if (!response.success) {
                    showError(response.error_message);
                }
                checkStatus(null, null)
            },
            error: function() {
                showError("Server error!");
                checkStatus(null, null)
            }
        });
    }


    function getCurrentDateForDownload() {
        // Get the current date in JavaScript
        let currentDate = new Date();

        // Format the date in the same way as PHP's "date('m-d-Y_H-i-s')"
        return ("0" + (currentDate.getMonth() + 1)).slice(-2) + "-" +
            ("0" + currentDate.getDate()).slice(-2) + "-" +
            currentDate.getFullYear() + "_" +
            ("0" + currentDate.getHours()).slice(-2) + "." +
            ("0" + currentDate.getMinutes()).slice(-2) + "." +
            ("0" + currentDate.getSeconds()).slice(-2);
    }

    /**
     * Handles the upload process for media files.
     * This function is called after the user clicks the "Upload" button
     * in the "Upload Media" modal.
     */
    async function uploadFiles(fileNames) {
        // Get the spinner and button elements
        let $spinner = $('#submitUploadBtnSpinner');
        let $button = $('#submitUploadBtn'); // Correctly reference the button
        
        // Show the spinner and disable the button
        $spinner.show();
        $button.prop('disabled', true);

        var collCode = collectionCode;
        
        // Create a progress display element
        $('#uploadStatus').prepend('<p id="uploadProgress" class="text-info"><strong>Uploading 0/' + fileNames.length + ' files...</strong></p>');
        var successCount = 0;
        var failureCount = 0;

        // Validate Collection Code
        if (collCode.includes(" ")) {
            showError("Collection Code cannot contain spaces");
            $spinner.hide();
            $button.prop('disabled', false);
            return;
        }

        // Step 1: Get presigned URLs for all files
        var data = {
            "project_id": "<?= $project->getId() ?>",
            "collCode": collCode,
            "filenames": fileNames
        };

        try {
            const response = await $.ajax({
                url: '/files/upload',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(data)
            });

            // Step 2: Loop through each response and upload the file
            if (Array.isArray(response) && response.length > 0) {
                for (const item of response) {
                    // FIX: 'curl_request' *is* the URL. No parsing needed.
                    var uploadUrl = item.curl_request;
                    var fileName = item.fileName;
                    var fileId = item.file_id;
                    var reupload = item.reupload;

                    // Find the matching file object from the input
                    var fileInput = document.getElementById('uploadReportFile');
                    var fileToUpload = null;
                    Array.from(fileInput.files).forEach(function(file) {
                        if (file.name === fileName) {
                            fileToUpload = file;
                        }
                    });

                    if (!fileToUpload) {
                        // This case should be rare, but good to handle
                        $('#uploadStatus').append('<p class="text-danger"><strong>Error: Could not find file ' + fileName + ' in selection.</strong></p>');
                        failureCount++;
                        if (!reupload) {
                            deleteFile([fileId]); // Clean up DB entry
                        }
                        continue;
                    }

                    // Step 3: Upload the individual file
                    try {
                        // FIX: Use PUT, not POST. Send the file data directly.
                        await $.ajax({
                            url: uploadUrl,
                            type: 'PUT',
                            processData: false,
                            contentType: fileToUpload.type || 'application/octet-stream',
                            data: fileToUpload
                        });
                        
                        // After file is uploaded, create a ClearML dataset
                        await $.ajax({
                            url: "/files/create-clearml-dataset",
                            type: 'POST',
                            contentType: 'application/json',
                            data: JSON.stringify({ "file_id": fileId })
                        });

                        // After file is uploaded, calculate and store duration
                        await $.ajax({
                            url: "/files/calculate-duration",
                            type: 'POST',
                            contentType: 'application/json',
                            data: JSON.stringify({ "file_id": fileId })
                        });
                        
                        successCount++;

                    } catch (uploadError) {
                        // Handle individual file upload errors
                        $('#uploadStatus').append('<p class="text-danger"><strong>Error uploading file ' + fileName + ': ' + 
                            (uploadError.statusText || 'Unknown error') + 
                            (uploadError.status ? ' (' + uploadError.status + ' ' + uploadError.statusText + ')' : '') + 
                            '</strong></p>');
                        
                        failureCount++;
                        if (!reupload) {
                            deleteFile([fileId]);
                        }
                    }
                    
                    // Update the progress display after each file
                    var totalProcessed = successCount + failureCount;
                    $('#uploadProgress').html('<strong>Uploading ' + totalProcessed + '/' + fileNames.length + 
                        ' files' + (successCount > 0 ? ' (' + successCount + ' successful)' : '') + '...</strong>');
                }
                
                // Update final progress message
                if (successCount === fileNames.length) {
                    $('#uploadProgress').removeClass('text-info').addClass('text-success')
                        .html('<strong>All files uploaded successfully! (' + successCount + '/' + fileNames.length + ')</strong>');
                    $('#uploadModal').modal('hide'); // Close modal
                } else {
                    $('#uploadProgress').removeClass('text-info').addClass(successCount > 0 ? 'text-warning' : 'text-danger')
                        .html('<strong>Upload complete: ' + successCount + '/' + fileNames.length + ' files uploaded successfully.</strong>');
                }

            } else if (response.error) {
                // Handle errors from the /files/upload endpoint
                $('#uploadStatus').prepend('<p class="text-danger"><strong>' + response.error + '</strong></p>');
            }

        } catch (error) {
            // Handle failure to get the presigned URLs
            $('#uploadStatus').prepend('<p class="text-danger"><strong>Error getting upload requests: ' + 
                (error.statusText || 'Unknown error') + '</strong></p>');
        } finally {
            // Always re-enable the button and hide the spinner
            $spinner.hide();
            $button.prop('disabled', false);
        }
    }

    

    async function uploadSynchronifierFiles(synchronifierId, fileNames) {
    let $spinner = $('#submitSynchronifierUploadBtnSpinner');
    let $button = $('#submitSynchronifierUploadBtn');
    $spinner.show();
    $button.prop('disabled', true);

    let collCode = collectionCode;

    let data = {
        "project_id": "<?= $project->getId() ?>",
        "collCode": collCode,
        "filenames": fileNames,
        "audio_id": synchronifierId,
        "synchronifier": true
    };

    return new Promise(async (resolve, reject) => {
        try {
            // 1. Get the pre-signed S3 URLs from your backend
            const response = await $.ajax({
                url: '/files/upload-synchronifier',
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(data)
            });

            if (Array.isArray(response) && response.length > 0) {
                let allUploadsSuccessful = true;

                // 2. Loop through each file configuration returned
                for (const item of response) {
                    // Even though it's called curl_request, it's now just the URL
                    let preSignedUrl = item.curl_request;
                    let fileName = item.fileName;
                    let fileId = item.file_id;
                    let reupload = item.reupload;

                    if (!preSignedUrl) {
                        $('#uploadSynchronifierStatus').prepend(`<p class="text-danger"><strong>Invalid URL for file ${fileName}.</strong></p>`);
                        allUploadsSuccessful = false;
                        continue;
                    }

                    // 3. Find the actual file object from your HTML file input
                    let fileInput = document.getElementById('uploadSynchronifierReportFile');
                    let actualFile = Array.from(fileInput.files).find(f => f.name === fileName);

                    if (!actualFile) {
                        $('#uploadSynchronifierStatus').prepend(`<p class="text-danger"><strong>Could not find file ${fileName} to upload.</strong></p>`);
                        allUploadsSuccessful = false;
                        continue;
                    }

                    try {
                        // 4. Upload the raw file directly to S3 using PUT
                        await $.ajax({
                            url: preSignedUrl,
                            type: 'PUT', // S3 pre-signed URLs almost always require PUT
                            processData: false, // Prevents jQuery from converting the file to a string
                            contentType: actualFile.type || 'application/octet-stream', // Sends the raw file type
                            data: actualFile // Send the raw file object itself
                        });

                        $('#uploadSynchronifierStatus').append(`<p class="text-success"><strong>File ${fileName} uploaded successfully!</strong></p>`);

                    } catch (uploadError) {
                        $('#uploadSynchronifierStatus').prepend(`<p class="text-danger"><strong>Error uploading file ${fileName}: ${uploadError.statusText}</strong></p>`);
                        allUploadsSuccessful = false;

                        // Original cleanup logic
                        if (!reupload && typeof deleteFile === 'function') {
                            deleteFile([fileId]);
                        }
                    }
                }

                // 5. Create ClearML Dataset (Only if we had successful uploads)
                // I moved this outside the loop so it fires once for the batch, rather than once per file
                if (allUploadsSuccessful) {
                    await $.ajax({
                        url: "/files/create-clearml-dataset-synchronifier",
                        type: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({ "file_id": synchronifierId })
                    });
                }

                resolve(); // Resolve when all files are processed
            } else if (response.error) {
                $('#uploadSynchronifierStatus').prepend(`<p class="text-danger"><strong>${response.error}</strong></p>`);
                reject(new Error(response.error));
            } else {
                resolve(); // Resolve if there were no files to process
            }
        } catch (error) {
            $('#uploadSynchronifierStatus').prepend(`<p class="text-danger"><strong>Error getting upload URLs: ${error.statusText}</strong></p>`);
            reject(error);
        } finally {
            $spinner.hide();
            $button.prop('disabled', false);
        }
    });
}
    

    /**
     * Handles the upload process for Verbatimizer files.
     * This function is called after the user clicks the "Upload" button
     * in the "Upload Verbatimizer Report" modal.
     */
    async function uploadVerbatimizerFiles(verbatimizerId, fileNames) {
        let spinner = $('#submitVerbatimizerUploadBtnSpinner');
        let button = $('#submitVerbatimizerUploadBtn');
        spinner.show();
        button.prop('disabled', true);

        let collCode = collectionCode;

        // Step 1: Get presigned URLs for all files
        let data = {
            "project_id": "<?= $project->getId() ?>",
            "collCode": collCode,
            "filenames": fileNames,
            "audio_id": verbatimizerId,
            "synchronifier": false // This seems specific to this endpoint
        };

        // Note: This function returns a Promise, which is good.
        return new Promise(async (resolve, reject) => {
            try {
                const response = await $.ajax({
                    url: '/files/upload', // Uses the same endpoint as uploadFiles
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(data)
                });

                // Step 2: Loop through each response and upload the file
                if (Array.isArray(response) && response.length > 0) {
                    for (const item of response) {
                        // FIX: 'curl_request' *is* the URL. No parsing needed.
                        let uploadUrl = item.curl_request;
                        let fileName = item.fileName;
                        let fileId = item.file_id;
                        let reupload = item.reupload;

                        // Find the matching file object from the input
                        var fileInput = document.getElementById('uploadVerbatimizerReportFile');
                        var fileToUpload = null;
                        Array.from(fileInput.files).forEach(function(file) {
                            if (file.name === fileName) {
                                fileToUpload = file;
                            }
                        });

                        if (!fileToUpload) {
                            $('#uploadVerbatimizerStatus').prepend(`<p class="text-danger"><strong>Error: Could not find file ${fileName} in selection.</strong></p>`);
                            if (!reupload) {
                                deleteFile([fileId]); // Clean up DB entry
                            }
                            continue;
                        }

                        // Step 3: Upload the individual file
                        try {
                            // FIX: Use PUT, not POST. Send the file data directly.
                            await $.ajax({
                                url: uploadUrl,
                                type: 'PUT',
                                processData: false,
                                contentType: fileToUpload.type || 'application/octet-stream',
                                data: fileToUpload
                            });

                            // After file is uploaded, create a ClearML dataset
                            // Note: You are passing verbatimizerId here, not fileId.
                            // This might be correct for your workflow, or it might be a bug.
                            // I'm keeping it as verbatimizerId as in your original code.
                            await $.ajax({
                                url: "/files/create-clearml-dataset",
                                type: 'POST',
                                contentType: 'application/json',
                                data: JSON.stringify({ "file_id": verbatimizerId }) 
                            });

                            $('#uploadVerbatimizerStatus').append(`<p class="text-success"><strong>File ${fileName} uploaded successfully!</strong></p>`);
                        
                        } catch (uploadError) {
                            $('#uploadVerbatimizerStatus').prepend(`<p class="text-danger"><strong>Error uploading file ${fileName}: ${uploadError.statusText}</strong></p>`);
                            if (!reupload) {
                                deleteFile([fileId]);
                            }
                        }
                    }
                    resolve(); // Resolve when all files are processed

                } else if (response.error) {
                    $('#uploadVerbatimizerStatus').prepend(`<p class="text-danger"><strong>${response.error}</strong></p>`);
                    reject(new Error(response.error));
                } else {
                    resolve(); // Resolve if there were no files
                }
            } catch (error) {
                $('#uploadVerbatimizerStatus').prepend(`<p class="text-danger"><strong>Error getting upload requests: ${error.statusText}</strong></p>`);
                reject(error);
            } finally {
                spinner.hide();
                button.prop('disabled', false);
            }
        });
    }



    function archiveFiles(files = null) {
        return new Promise((resolve, reject) => {
            $.ajax({
                url: '/files/archive',
                type: 'POST',
                data: {
                    'ids': files === null ? selected_ids : files
                },
                success: function(response) {
                    if (Array.isArray(response)) {
                        response.forEach(item => {
                            if (!item.success) {
                                showError(item.error_message);
                            }
                        });
                    } else if (response.success) {
                        showSuccess("Archived selected files.");
                    } else {
                        showError("Could not archive files.");
                    }
                    checkStatus(null, null)
                    resolve();
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    showError("Server error!");
                    checkStatus(null, null)
                    reject("Server error!");
                }
            });
        });
    }


    function startAllJobs(startJob, fileIds, filepath, parent, synchronifier_transcript_file=null) {
        return new Promise((resolve, reject) => {
            let data = {
                'file_ids': fileIds,
                'filepath': filepath,
                'job': startJob,
                'project-id': projectId,
            };
            
            if (typeof synchronifier_transcript_file !== 'undefined') {
                data.synchronifier_transcript_file = synchronifier_transcript_file;
            }

            $.ajax({
                url: rootURL + '/job/start-all',
                method: 'POST',
                data: data,
                success: function(response) {
                    if (Array.isArray(response)) {
                        response.forEach(item => {
                            if (!item.success) {
                                showError(item.error_message);
                            }
                        });
                    } else if (!response.success) {
                        showError("Could not start jobs.");
                    }
                },
                error: function() {
                    showError("Server error!");
                    checkStatus(fileId, parent);
                    reject("Server error!");
                }
            });
            checkStatus(null, null)
        });
    }


    function startJob(job, fileIds, filepath, parent, synchronifier_transcript_file=null) {
        let success = false;  
        let data = {
            'file_ids': fileIds, // Changed from 'file_id' to 'file_ids' to handle an array
            'filepath': filepath,
            'job': job,
        };

        if (typeof synchronifier_transcript_file !== 'undefined') {
            data.synchronifier_transcript_file = synchronifier_transcript_file;
        }
        console.log("Starting job: ", job, " with data: ", data);

        if (job == "riskalyzer") {
            let data = {
                'file_ids': fileIds, // Changed from 'file_id' to 'file_ids'
                'filepath': filepath,
                'job_name': job,
                'system_prompt': "",
                'user_prompt': "",
                'output_format': "",
                'parameters': {
                    'combine': false
                }
            };

            $.ajax({
                url: rootURL + '/job/start-custom',
                method: 'POST',
                data: data,
                success: function(response) {
                    response.forEach(item => {
                        if (!item.success) {
                            showError(item.error_message);
                        } 
                    });
                    checkStatus(fileIds, parent)
                },
                error: function() {
                    showError("Server error!");
                    checkStatus(fileIds, parent)
                    return false;
                }
            });
        } else {
            $.ajax({
                url: rootURL + '/job/start',
                method: 'POST',
                data: data,
                success: function(response) {
                    response.forEach(item => {
                        if (!item.success) {
                            showError(item.error_message);
                        } 
                    });
                    checkStatus(fileIds, parent)
                },
                error: function() {
                    showError("Server error!");
                    checkStatus(fileIds, parent)
                    return false;
                }
            });
        }
    }

    function startAllTasks(elem, uuid, currentRowIndex) {
        if (selected_ids.length === 0) {
            showError("No rows selected...");
        } else {
            for (let id of selected_ids) {
                let rowIndex = findRowIndex(id)
                let cellToUpdate = dt.cell(rowIndex, 11);
                let cellData = dt.cell(rowIndex, 0).data()['status'];
                let fileName = dt.cell(rowIndex, 0).data()['filename']; 
                cellData = JSON.parse(cellData);

                let newCellData;
                if (cellData['status']['verbatimizer'] === "Started"){
                    newCellData = JSON.stringify(cellData);
                } else {
                    cellData['status']['verbatimizer'] = "Starting...";
                    newCellData = JSON.stringify(cellData);
                    // updateStatus(uuid, "Starting...", "verbatimizer");
                }
                cellData['status']['verbatimizer'] = "Starting...";
                cellData['status']['describalizer'] = "Starting...";
                cellData['status']['riskalyzer'] = "Starting...";
                newCellData = JSON.stringify(cellData);
                cellToUpdate.data(newCellData)['status'];

                startAllJobs('verbatimizer', id, fileName, parent)
            }
        }
    }

    function startVerbatimizer(elem, uuid, currentRowIndex) {
        let parent = $(elem).parent();
        let cellToUpdate = dt.cell(currentRowIndex, 11);
        let cellData = dt.cell(currentRowIndex, 0).data()['status'];
        let fileName = dt.cell(currentRowIndex, 0).data()['filename']; 
        cellData = JSON.parse(cellData);
        // if (cellData['status']['status'] === "Uploaded" || cellData['status']['status'] === "Re-uploaded"){
        //     cellData['status'] = {};
        // }
        let newCellData;
        if (cellData['status']['verbatimizer'] === "Started"){
            newCellData = JSON.stringify(cellData);
        } else {
            cellData['status']['verbatimizer'] = "Starting...";
            newCellData = JSON.stringify(cellData);
            // updateStatus(uuid, "Starting...", "verbatimizer");
        }
        cellToUpdate.data(newCellData)['status'];
        startJob ('verbatimizer', uuid, fileName, parent);
        $(parent).html(`
            <button class="btn processing-record-btn" onclick="cancelJob(['${uuid}'], 'verbatimizer')">
                <img class="processing-record-img" src="/img/record.png"/>
            </button>
        `);

        if (!verbInterval) {
            // do a check every 5 seconds
            verbInterval = setInterval(function() {
                checkStatus(null, null);
            }, 60000);
        }
    }

    function startMerge(elem, uuid, currentRowIndex) {
        let parent = $(elem).parent();
        let cellToUpdate = dt.cell(currentRowIndex, 9);
        let cellData = dt.cell(currentRowIndex, 0).data()['status'];
        let fileName = dt.cell(currentRowIndex, 0).data()['filename'];
        cellData = JSON.parse(cellData);
        // if (cellData['status']['status'] === "Uploaded" || cellData['status']['status'] === "Re-uploaded"){
        //     cellData['status'] = {};
        // }
        let newCellData;
        if (cellData['status']['verbatimizer'] === "Merging"){
            newCellData = JSON.stringify(cellData);
        } else {
            cellData['status']['verbatimizer'] = "Merging";
            newCellData = JSON.stringify(cellData);
            // updateStatus(uuid, "Merging", "verbatimizer");
        }
        cellToUpdate.data(newCellData);
        startJob ('merge', uuid, fileName, parent);
        $(parent).html(`
            <button class="btn processing-record-btn" onclick="cancelJob('${uuid}', 'merge')">
                <img class="processing-record-img" src="/img/record.png"/>
            </button>
        `);

        if (!verbInterval) {
            // do a check every 5 seconds
            verbInterval = setInterval(function() {
                checkStatus(uuids, parent);
            }, 60000);
        }
    }

    function startSynchronifier(elem, uuid, transcript_filename, currentRowIndex) {
        let parent = $(elem).parent();
        let cellToUpdate = dt.cell(currentRowIndex, 11);
        let cellData = dt.cell(currentRowIndex, 0).data()['status'];
        let fileName = dt.cell(currentRowIndex, 0).data()['filename'];
        cellData = JSON.parse(cellData);
        // if (cellData['status']['status'] === "Uploaded" || cellData['status']['status'] === "Re-uploaded"){
        //     cellData['status'] = {};
        // }
        let newCellData;
        if (cellData['status']['synchronifier'] === "Started"){
            newCellData = JSON.stringify(cellData);
        } else {
            cellData['status']['synchronifier'] = "Starting...";
            newCellData = JSON.stringify(cellData);
            // updateStatus(uuid, "Starting...", "synchronifier");
        }
        cellToUpdate.data(newCellData);
        startJob ('synchronifier', [uuid], fileName, parent, transcript_filename);
        $(parent).html(`
            <button class="btn processing-record-btn" onclick="cancelJob(['${uuid}'], 'synchronifier')">
                <img class="processing-record-img" src="/img/record.png"/>
            </button>
        `);

        if (!verbInterval) {
            // do a check every 5 seconds
            verbInterval = setInterval(function() {
                checkStatus(null, null);
            }, 60000);
        }
    }

    function startDescribalizer(elem, uuid, currentRowIndex) {
        // window.location.href = "https://www.youtube.com/watch?v=BBJa32lCaaY";
        let parent = $(elem).parent();
        let cellToUpdate = dt.cell(currentRowIndex, 11);
        let cellData = dt.cell(currentRowIndex, 0).data()['status'];
        let fileName = dt.cell(currentRowIndex, 0).data()['filename'];
        cellData = JSON.parse(cellData);
        // if (cellData['status']['status'] === "Uploaded" || cellData['status']['status'] === "Re-uploaded"){
        //     cellData['status'] = {};
        // }
        let newCellData;
        if (cellData['status']['describalizer'] === "Started"){
            newCellData = JSON.stringify(cellData);
        } else {
            cellData['status']["describalizer"] = "Starting...";
            newCellData = JSON.stringify(cellData);
            // updateStatus(uuid, "Starting...", "describalizer");
        }
        cellToUpdate.data(newCellData);
        startJob ('describalizer', uuid, fileName, parent);
        $(parent).html(`
            <button class="btn processing-record-btn" onclick="cancelJob(['${uuid}'], 'describalizer')">
                <img class="processing-record-img" src="/img/record.png"/>
            </button>
        `);

        if (!descInterval) {
            // do a check every 5 seconds
            descInterval = setInterval(function() {
                checkStatus(null, null);
            }, 60000);
        }
    }

    function startOhmsifier(elem, uuid, currentRowIndex) {
        let parent = $(elem).parent();
        let cellToUpdate = dt.cell(currentRowIndex, 11);
        let cellData = dt.cell(currentRowIndex, 0).data()['status'];
        let fileName = dt.cell(currentRowIndex, 0).data()['filename'];
        cellData = JSON.parse(cellData);
        // if (cellData['status']['status'] === "Uploaded" || cellData['status']['status'] === "Re-uploaded"){
        //     cellData['status'] = {};
        // }
        let newCellData;
        if (cellData['status']['ohmsifier'] === "Started"){
            newCellData = JSON.stringify(cellData);
        } else {
            cellData['status']["ohmsifier"] = "Starting...";
            newCellData = JSON.stringify(cellData);
            // updateStatus(uuid, "Starting...", "ohmsifier");
        }
        cellToUpdate.data(newCellData);
        startJob ('ohmsifier', uuid, fileName, parent);
        $(parent).html(`
            <button class="btn processing-record-btn">
                <img class="processing-record-img" src="/img/record.png"/>
            </button>
        `);

        if (!ohmsInterval) {
            // do a check every 5 seconds
            ohmsInterval = setInterval(function() {
                checkStatus(uuids, parent);
            }, 60000);
        }
    }

    function startRiskalyzer(elem, uuid, currentRowIndex) {
        let parent = $(elem).parent();
        let cellToUpdate = dt.cell(currentRowIndex, 11);
        let cellData = dt.cell(currentRowIndex, 0).data()['status'];
        let fileName = dt.cell(currentRowIndex, 0).data()['filename'];
        cellData = JSON.parse(cellData);
        let newCellData;
        if (cellData['status']['riskalyzer'] === "Started"){
            newCellData = JSON.stringify(cellData);
        } else {
            cellData['status']["riskalyzer"] = "Starting...";
            newCellData = JSON.stringify(cellData);
            // updateStatus(uuid, "Starting...", "riskalyzer");
        }
        cellToUpdate.data(newCellData);
        startJob ('riskalyzer', uuid, fileName, parent);
        $(parent).html(`
            <button class="btn processing-record-btn" onclick="cancelJob(['${uuid}'], 'riskalyzer')">
                <img class="processing-record-img" src="/img/record.png"/>
            </button>
        `);

        if (!riskInterval) {
            // do a check every 5 seconds
            riskInterval = setInterval(function() {
                checkStatus(null, null);
            }, 60000);
        }
    }

    function updateStatus(uuid, status, action) {
        $.ajax({
            url: rootURL+'/files/update',
            method: 'POST',
            data: {
                'uuid': uuid,
                'status': status,
                'action': action
            },
            success: function(response) {
                if (!response.success) {
                    showError(response.error_message);
                }
            }
        });
    }

    function findCell(uuid, index) {
        var row = dt.rows().nodes().to$().filter(function() {
            return $(this).find('input[type="checkbox"]').data('id') === uuid;
        });

        return dt.row(row);
    }

    function findRowIndex(uuid) {
        var row = dt.rows().nodes().to$().filter(function() {
            return $(this).find('input[type="checkbox"]').data('id') === uuid;
        });
        return dt.row(row).index();
    }

    function checkStatus(uuids=null, parent=null) {
        // Get the current order
        const currentOrder = dt.order();

        // Reload the table without resetting the paging
        dt.ajax.reload(function() {
            // Reapply the previous order after reload
            dt.order(currentOrder).draw(false);
        }, false);
    }

    function capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }

    // Add this CSS to the <head> or inject via JS for better contrast
    const style = document.createElement('style');
    style.innerHTML = `
        .btn[disabled], .btn.disabled {
            background-color: #e0e0e0 !important;
            color: #b0b0b0 !important;
            border-color: #cccccc !important;
            opacity: 1 !important;
            cursor: not-allowed !important;
            filter: grayscale(60%) contrast(0.7);
        }
        .btn[disabled] i, .btn.disabled i {
            color: #b0b0b0 !important;
        }
    `;
    document.head.appendChild(style);
</script>
<?php
    include_once __DIR__ . '/../../_footer.php';


