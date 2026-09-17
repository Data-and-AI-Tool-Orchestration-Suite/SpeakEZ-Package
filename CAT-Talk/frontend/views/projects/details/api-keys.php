<?php
/** @var UserSession $userSession */
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Project API Keys</h1>
        <button type="button" class="btn btn-success" style="margin-right: 10px;" onclick="showAPIKeyModal()">
            <i class="fas fa-plus mr-1"></i> New API Key
        </button>
    </div>
    <div class="row mb-5">
        <div class="col">

            <table id="api-keys" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>Name</th>
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
                    <div class="form-group">
                        <label for="apiKeyNameInput">Name</label>
                        <input type="text" class="form-control mb-2" id="apiKeyNameInput">
                    </div>
                    <div class="alert alert-info" role="alert">
                        Name your key for based on what it will be used for.<br>
                        This key will be associated with the current project. It cannot be used for adapters/adapter configurations outside of this project<br>
                        
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
                        "name": newAPIKeyName,
                        "project_id": "<?= $projectId ?>"
                    },
                    dataType: 'json',
                    success: function(data) {
                        hideAPIKeyModal(); // hide the modal
                        $("#apiKeyNameInput").val(""); // clear current value in the field
                        apiKeyDatatable.ajax.reload( null, false );
                        showSuccess("Created new API key");
                    },
                    error: function(xhr, status, error) {
                        if (xhr.responseJSON && xhr.responseJSON.error) {
                            showError(xhr.responseJSON.error);
                        } else {
                            showError("Error creating API Key. Try again...");
                        }
                    }
                });
            } else {
                showError("You must enter a name to create an API Key");
            }
        }

        function deleteAPIKey(id) {
            if (id !== null && id !== '') {
                var apiKey = apiKeys[id];
                confirmModalTitle.html('Confirm API Key Deletion');
                confirmModalInternalId.val(id);
                confirmModalTextSpan.html("Are you sure you wish to delete API Key [" + apiKey['name'] + "]");
            }
            confirmModal.modal('show');
            confirmModalButton.off();
            confirmModalButton.on('click', function() {
                confirmDeleteAPIKey();
            });
        }

        function confirmDeleteAPIKey(){
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
                    error: function(xhr, status, error) {
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
                    url: "<?= $rootURL ?>/projects/<?= $projectId ?>/list-api-keys",
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
                        targets: [1, 2]
                    },
                    // {
                    //     visible: false,
                    //     targets: [1]
                    // }
                ],
                language: {
                    emptyTable: "No API Keys have been added"
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'name'
                    },
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