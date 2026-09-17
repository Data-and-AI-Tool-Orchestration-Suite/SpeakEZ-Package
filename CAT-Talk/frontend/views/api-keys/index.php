<?php
/** @var User $user */
$page = "api-key";
$config = include CONFIG_FILE;
include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">API Key Generator</h1>
        <button type="button" class="btn btn-success" style="margin-right: 10px;" onclick="showAPIKeyModal()">
            <i class="fas fa-plus mr-1"></i> New API Key
        </button>
    </div>
    <div class="row">
        <div class="col">

            <table id="api-keys" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>Name</th>
                    <?php if (Plugin::withName("projects") && Plugin::withName("projects")->isActive()): ?>
                    <th>Project Name</th> 
                    <?php endif; ?>
                    <th>API Key</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
            </table>    
        </div>


        
    </div>

    <div class="modal fade" id="apiKeyModal" tabindex="-1" role="dialog" aria-labelledby="apiKeyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="apiKeyModalTitle">Create a New API Key</h5>
                    <button type="button" class="btn-close" aria-label="Close" onclick="hideAPIKeyModal()"></button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label for="apiKeyNameInput" class="mb-1">Name</label>
                        <input type="text" class="form-control" id="apiKeyNameInput" placeholder="Name your new API Key">
                    </div>
                    <div class="alert alert-info" role="alert">
                        Remember: Best practice is to name your key based on what it will be used for.<br>
                        It is recommended that you make a new key for individual uses.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="hideAPIKeyModal()">Dismiss</button>
                    <button type="button" class="btn btn-primary" onclick="createNewAPIKey()">Create Key</button>
                </div>
            </div>
        </div>
    </div>
    
    <script type="text/javascript">

        confirmModal.on('hidden.bs.modal', function() {
            confirmModalTitle.html('');
            confirmModalInternalId.val('');
            confirmModalTextSpan.html('');
        });

        var apiKeys = {}

        function showAPIKeyModal() {
            $("#apiKeyModal").modal("show");
        }

        function hideAPIKeyModal() {
            $("#apiKeyModal").modal("hide");
        }

        function createNewAPIKey(){
            let newAPIKeyName = $("#apiKeyNameInput").val();
            if (newAPIKeyName){
                $.ajax({
                    url: '<?= $rootURL ?>/api-keys/save',
                    method: 'post',
                    data: {
                        "name": newAPIKeyName
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data){
                            hideAPIKeyModal(); // hide the modal
                            $("#apiKeyNameInput").val(""); // clear current value in the field
                            apiKeyDatatable.ajax.reload( null, false );
                            showSuccess("Created new API key");
                        }
                        
                    },
                    error: function(xhr, textStatus, errorThrown) {
                        if (xhr.responseJSON && xhr.responseJSON.error) {
                            showError(xhr.responseJSON.error);
                        } else {
                            showError("Error submitting user. Try again...");
                        }
                    }
                });
            } else {
                showError("You must enter a name to create an API Key");
            }
        }

        function deleteAPIKey(id) {
            if (id !== null && id !== '') {
                var task = apiKeys[id];
                confirmModalTitle.html('Confirm API Key Deletion');
                confirmModalInternalId.val(id);
                confirmModalTextSpan.html("Are you sure you wish to delete API Key [" + task['name'] + "]");
            }
            confirmModal.modal('show');
            confirmModalButton.off();
            confirmModalButton.on('click', function() {
                confirmDelete();
            });
        }

        function confirmDelete(){
            if (confirmModalInternalId.val() === null || confirmModalInternalId.val() === '') {
                showError('You must supply a task internal id to delete');
                return;
            } else {
                $.ajax({
                    url: '<?= $rootURL ?>/api-keys/delete',
                    method: 'post',
                    data: {
                        "id": confirmModalInternalId.val()
                    },
                    dataType: 'json',
                    success: function(data) {
                        apiKeyDatatable.ajax.reload( null, false );
                        confirmModal.modal('hide');
                        showSuccess("Deleted API key");                        
                    },
                    error: function(xhr, textStatus, errorThrown) {
                        if (xhr.responseJSON && xhr.responseJSON.error) {
                            showError(xhr.responseJSON.error);
                        } else {
                            showError("Error submitting user. Try again...");
                        }
                    }
                });
            }
        }

        var apiKeyTable = $('#api-keys');
        var apiKeyDatatable = null;

        $(function() {
            let unorderableColumns = [2];
            let hiddenColumns = [];
            apiKeyDatatable = apiKeyTable.DataTable({
                preDrawCallback: function (settings) {
                    var api = new $.fn.dataTable.Api(settings);
                    var pagination = $(this)
                        .closest('.dataTables_wrapper')
                        .find('.dataTables_paginate');
                    pagination.toggle(api.page.info().pages > 1);
                },
                serverSide: true,
                processing: true,
                ajax: {
                    url: "<?= $rootURL ?>/api-keys/list",
                    error: function(error){
                        console.error(error)
                    }
                },
                order: [[ 0, "asc" ]],
                responsive: true,
                bInfo : false,
                buttons: [
                    //'pageLength', 'colvis'
                ],
                columnDefs: [
                    {
                        className: "dt-center",
                        targets: '_all'
                    },
                    {
                        orderable: false,
                        targets: unorderableColumns
                    },
                    {
                        visible: false,
                        targets: hiddenColumns
                    }
                ],
                language: {
                    emptyTable: "No API Keys have been added"
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'name'
                    },
                    <?php if (Plugin::withName("projects") && Plugin::withName("projects")->isActive()): ?>
                    {
                        data: null,
                        render: function ( data ) {
                            if (!data.project_name){
                                return "No associated project";
                            }
                            return data.project_name
                        }
                    },
                    <?php endif; ?>
                    {
                        data: 'id',
                        render: function ( data ) {
                            html = `<div class="copy-container" style="margin-right: auto; margin-left: auto; display: inline-flex; ">
                                        <div id="textToCopy" class="copy-text mr-1" style="margin-top: auto; margin-bottom:auto;">${data}</div>
                                        <button id="copyButton" class="btn " onclick="copyToClipboard('${data}')">
                                            <span class='fas fa-copy' data-toggle='tooltip' data-placement='left' title='Copy API Key'></span>
                                        </button>
                                    </div>`;
                            return html;
                        }
                    },
                    {
                        data: null,
                        render: function ( data ) {
                            html = "";
                            html += "<button class='btn btn-danger btn-xs mr-2' onclick='deleteAPIKey(\"" + data.id + "\");'>" +
                                    "<span class='fas fa-trash' data-toggle='tooltip' data-placement='left' title='Delete API Key'></span>" +
                                    "</button>";
                            return html;
                        }
                    }
                ]
            });
            $('.dt-buttons').addClass('btn-group-sm');
            $('.dt-buttons div').addClass('btn-group-sm');
            apiKeyDatatable.on('xhr.dt', function (e, settings, data) {
                apiKeys = {};
                if (data){
                    $.each(data.data, function(i, v) {
                        apiKeys[v.id] = v;
                    });
                }
            });
        });





    </script>
<?php
include_once __DIR__ . '/../_footer.php';