<!-- index.php -->
<?php
require_once 'config.php';
/** @var UserSession $userSession */
$page = 'home';
global $rootURL;
include_once __DIR__ . '/../_header.php';
?>

<!DOCTYPE html>
<html lang="en">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4"><?= $project->getName() ?> Dashboard</h1>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-secondary" title="Upload file(s)" data-bs-toggle="modal" data-bs-target="#uploadModal">
                <i class="fa fa-cloud-upload"></i> Upload Media
            </button>
            <button type="button" class="btn btn-secondary" title="Upload transcript(s)" data-bs-toggle="modal" data-bs-target="#uploadTranscriptModal">
                <i class="fa fa-cloud-upload"></i> Upload Transcript(s)
            </button>
        </div>
    </div>
<head>
    <meta charset="UTF-8">
    <title>Project Dashboard</title>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-6">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h3 class="mb-0">Collections</h3>
                <div class="spinner-border" role="status" id="spinner" hidden>
                    <span class="sr-only">Loading...</span>
                </div>

            </div>
                <table id="collectionsTable" class="table table-striped">
                    <thead>
                        <tr>
                            <th>Collection Code</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Dynamically populated by JavaScript -->
                    </tbody>
                </table>
            </div>
            <div class="col-md-6">
                <h3>Running Processes</h3>
                <table id="processesTable" class="table table-striped">
                    <thead>
                        <tr>
                            <th>Collection Code</th>
                            <th>Filename</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Dynamically populated by JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>


    <!-- upload file Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form id="uploadForm">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5" id="uploadModalLabel">Upload Audio/Video File(s)</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="col-lg-12 mb-3 form-floating">
                            <div class="custom-file" id="customFile" lang="en">
                                <input type="file" class="form-control custom-file-input" accept=".mp3,.wav,.aac,.flac,.ogg,.m4a,.wma,.aiff,.au,.mp4,.mov,.avi,.wmv,.flv,.mkv,.webm,.mpeg,.mpg,.m4v,.3gp,.3g2,.ts,.mts,.m2ts" id="uploadReportFile" aria-describedby="fileLabel" multiple required>
                            </div>
                        </div>
                        <div class="col-lg-12 mb-3 form-floating">
                            <div class="input-group mb-3 align-items-stretch">
                                <span class="input-group-text" id="coll-code-desc">Collection Code</span>
                                <input id="coll-code" type="text" class="form-control" aria-label="Collection Code" aria-describedby="coll-code-desc" placeholder="no spaces allowed..." required>
                            </div>
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
                                <span class="input-group-text" id="coll-code-transcript-desc">Collection Code</span>
                                <input id="coll-code-transcript" type="text" class="form-control" aria-label="Collection Code" aria-describedby="coll-code-transcript-desc" placeholder="no spaces allowed..." required>
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




    <script>
        var collectionsTable;
        $(document).ready(function() {
            document.getElementById('coll-code').addEventListener('keypress', function(event) {
                if (event.key === ' ') {
                event.preventDefault(); // Prevent spaces in collection code
                }
            });

            // Initialize DataTables
            collectionsTable = $('#collectionsTable').DataTable({
                columns: [
                    { 
                        data: 'code',
                        render: function(data) {
                            return `<a href="<?= $rootURL ?>/projects/<?=$projectId?>/collections/${data}">${data}</a>`;
                        }
                    }
                ],
                ajax: {
                    url: '<?= $rootURL ?>/projects/list-collections/<?=$projectId?>',
                    method: 'GET',
                    dataSrc: function(response) {
                        if (Array.isArray(response.collection_codes)) {
                            return response.collection_codes.map(function(code) {
                                return { code: code }; // Convert to objects
                            });
                        }
                        return []; // Fallback for unexpected response
                    }
                }
            });

            const processesTable = $('#processesTable').DataTable({
                columns: [
                    { data: 'collectionCode' },
                    { data: 'fileName' },
                    { data: 'status' }
                ],
                ajax: {
                    url: '<?= $rootURL ?>/projects/get-running-jobs/<?=$projectId?>',
                    dataSrc: function(response) {
                        if (response.jobs) {
                            const data = Object.entries(response.jobs).map(([fileName, jobInfo]) => ({
                                collectionCode: jobInfo.collection_code, // Add collection code
                                fileName: fileName,
                                status: jobInfo.jobs && jobInfo.jobs.length > 0 
                                    ? jobInfo.jobs.join(', ') 
                                    : 'No jobs'
                            }));
                            return data;
                        }
                        return []; // Fallback for unexpected response
                    }
                },
                // Auto-refresh processes every 30 seconds
                pageLength: 10,
                autoWidth: false,
                order: [[1, 'desc']] // Default ordering by status (column 1)
            });

            // Optional: Add auto-refresh for both tables
            setInterval(function() {
                collectionsTable.ajax.reload(null, false);
                processesTable.ajax.reload(null, false);
            }, 30000);
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
    });

    /**
     * Handles the upload process for media files.
     * This function is called after the user clicks the "Upload" button
     * in the "Upload Media" modal.
     */
    async function uploadFiles(fileNames) {
        // Get the spinner and button elements
        let $spinner = $('#submitUploadBtnSpinner');
        // FIX: Correctly select the button by its ID
        let $button = $('#submitUploadBtn'); 
        
        // Show the spinner and disable the button
        $spinner.show();
        $button.prop('disabled', true);

        // Get the collection code from the correct input
        var collCode = $("#coll-code").val();
        
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
                        $('#uploadStatus').append('<p class="text-danger"><strong>Error: Could not find file ' + fileName + ' in selection.</strong></p>');
                        failureCount++;
                        if (!reupload) {
                            // Assuming deleteFile expects an array, like in your other function
                            deleteFile([fileId]); 
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
                            data: JSON.stringify({ "file_id": fileId }) // Use fileId here
                        });

                        // After file is uploaded, calculate and store duration
                        await $.ajax({
                            url: "/files/calculate-duration",
                            type: 'POST',
                            contentType: 'application/json',
                            data: JSON.stringify({ "file_id": fileId }) // Use fileId here
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
                            // Assuming deleteFile expects an array
                            deleteFile([fileId]);
                        }
                    }
                    
                    // Update the progress display after each file
                    var totalProcessed = successCount + failureCount;
                    $('#uploadProgress').html('<strong>Uploading ' + totalProcessed + '/' + fileNames.length + 
                        ' files' + (successCount > 0 ? ' (' + successCount + ' successful)' : '') + '...</strong>');
                }
                
                // Step 4: Final status update
                if (successCount === fileNames.length) {
                    $('#uploadProgress').removeClass('text-info').addClass('text-success')
                        .html('<strong>All files uploaded successfully! (' + successCount + '/' + fileNames.length + ')</strong>');
                    $("#uploadModal").modal("hide");
                    
                    // Reload the datatable
                    if (typeof collectionsTable !== 'undefined') {
                        collectionsTable.ajax.reload(null, false);
                    }

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

    function deleteFile(fileId) {
        $.ajax({
            url: rootURL+'/files/delete',
            method: 'POST',
            data: {
                'file_id': fileId,
                'project_id': projectId
            },
            success: function(response) {
                if (!response.success) {
                    console.log("Failed to delete file from database after error");
                }
            },
            failure: function() {
                console.log("Failed to delete file from database after error");
            }
        });
    }

        // Transcript upload handler
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
            
            // Make sure to get the value from the input field
            var collCode = $("#coll-code-transcript").val(); 
            
            $('#uploadTranscriptStatus').prepend('<p id="uploadTranscriptProgress" class="text-info"><strong>Uploading 0/' + fileNames.length + ' files...</strong></p>');
            var successCount = 0;
            var failureCount = 0;

            if (collCode.includes(" ")) {
                $('#uploadTranscriptStatus').prepend('<p class="text-danger"><strong>Collection Code cannot contain spaces</strong></p>');
                $spinner.hide();
                $button.prop('disabled', false);
                return;
            }

            // Step 1: Get presigned URLs for each file
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
                // FIX: 'curl_request' *is* the URL. No parsing needed.
                var uploadUrl = item.curl_request;
                var fileName = item.fileName;
                var fileId = item.file_id;

                // Find the matching file object from the 'files' FileList
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
                    // FIX: Use PUT, not POST. Send the file data directly.
                    await $.ajax({
                        url: uploadUrl,
                        type: 'PUT',
                        processData: false,
                        contentType: fileToUpload.type || 'application/octet-stream',
                        data: fileToUpload
                    });

                    // After transcript is uploaded, create a ClearML dataset
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

                // Update progress
                var totalProcessed = successCount + failureCount;
                $('#uploadTranscriptProgress').html('<strong>Uploading ' + totalProcessed + '/' + fileNames.length + ' files' + (successCount > 0 ? ' (' + successCount + ' successful)' : '') + '...</strong>');
            }

            // Step 3: Final status update
            if (successCount === fileNames.length) {
                $('#uploadTranscriptProgress').removeClass('text-info').addClass('text-success')
                    .html('<strong>All transcripts uploaded successfully! (' + successCount + '/' + fileNames.length + ')</strong>');
                $("#uploadTranscriptModal").modal("hide");
                
                // Reload the datatable (assuming 'collectionsTable' is your table ID)
                if (typeof collectionsTable !== 'undefined') {
                    collectionsTable.ajax.reload(null, false);
                }

            } else {
                $('#uploadTranscriptProgress').removeClass('text-info').addClass(successCount > 0 ? 'text-warning' : 'text-danger')
                    .html('<strong>Upload complete: ' + successCount + '/' + fileNames.length + ' transcripts uploaded successfully.</strong>');
            }

            $spinner.hide();
            $button.prop('disabled', false);
        }
        $('#uploadTranscriptModal').on('hide.bs.modal', function (event) {
            $('#uploadTranscriptFile').val('');
            $('#uploadTranscriptStatus').html('');
            $('#submitUploadTranscriptBtn').show();
            $('#coll-code-transcript').val('');
        });
    </script>
</body>
</html>

<?php include_once __DIR__ . '/../_footer.php'; ?>