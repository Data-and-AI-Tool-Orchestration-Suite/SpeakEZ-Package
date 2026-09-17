<?php
/** @var User $user */
$page = 'documents';
include_once VIEWS_DIR . '/_header.php';
global $rootURL;
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Documents - <span class="text-muted">A Resources Template View</span></h1>
        <button type="button" class="btn btn-success" style="margin-right: 10px;" data-bs-toggle="modal" data-bs-target="#documentModal">
            <i class="fas fa-plus mr-1"></i> New Document
        </button>
    </div>
    <div class="row">
        <div class="col">
            <table id="documents" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Content</th>
                    <th>Permissions</th>
                    <th>Edit</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal to Create/Edit Documents -->
    <div class="modal fade" id="documentModal" tabindex="-1" role="dialog" aria-labelledby="documentModalLabel" aria-hidden="true">
        <input type="hidden" id="documentIdInput" value="">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="documentModalTitle">Submit a Document</h5>
                    <button type="button" class="btn-close" aria-label="Close" onclick="hideDocumentModal()" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="form-group mb-3">
                        <label for="documentNameInput" class="mb-1">Name</label>
                        <input type="text" class="form-control" id="documentNameInput" placeholder="Name your new document">
                    </div>
                    <div class="form-group mb-3">
                        <label for="documentContentInput" class="mb-1">Content</label>
                        <input type="text" class="form-control" id="documentContentInput" placeholder="Add content to the document">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="hideDocumentModal()" data-bs-dismiss="modal">Dismiss</button>
                    <button type="button" class="btn btn-primary" onclick="submitDocument()">Submit Document</button>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        var document = {};
        var documentsTable = $('#documents');
        var documentsDataTable = null;

        function submitDocument() {
            let id = $("#documentIdInput").val() || null;
            let newDocumentName = $("#documentNameInput").val();
            let newDocumentContent = $("#documentContentInput").val();

            if (!newDocumentName){
                showError("You must enter a name to create a document");
                return;
            }
            if (!newDocumentContent){
                showError("You must enter content to create a document");
                return;
            }

            let postData = {
                "name": newDocumentName,
                "content": newDocumentContent
            };
            if (id) {
                postData["id"] = id;
            }

            $.ajax({
                url: '<?= $rootURL ?>/documents/submit',
                method: 'POST',
                data: postData,
                dataType: 'json',
                success: function(data) {
                    if (data){
                        $("#documentModal").modal("hide");
                        $("#documentIdInput").val("");        // clear ID
                        $("#documentNameInput").val("");      // clear name
                        $("#documentContentInput").val("");   // clear content
                        documentsDataTable.ajax.reload(null, false);
                        showSuccess(id ? "Updated document" : "Created new document");
                    }
                },
                error: function(xhr, textStatus, errorThrown) {
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        showError(xhr.responseJSON.error);
                    } else {
                        showError("Error submitting document. Try again...");
                    }
                }
            });
        }

        function hideDocumentModal() {
            $("#documentNameInput").val("");
            $("#documentContentInput").val("");
            $("#documentIdInput").val("");
        }


        function editDocument(id, name, content) {
            $("#documentIdInput").val(id);
            $("#documentNameInput").val(name);
            $("#documentContentInput").val(content);
            $("#documentModal").modal("show");
        }


        async function deleteDocument(id) {
            let isConfirmed = await customConfirm("Are you sure to delete this document? This action is not reversible.");
            if (isConfirmed) {
                $.post({
                    url: `<?= $rootURL; ?>/documents/${id}/delete`,
                    method: 'DELETE',
                    success: function(data) {
                        showSuccess('Successfully deleted document.');
                        documentsDataTable.ajax.reload();
                    },
                    error: function(xhr, status, error) {
                        if (xhr.responseJSON && xhr.responseJSON.error) {
                            showError(xhr.responseJSON.error);
                        } else {
                            showError("Error deleting document. Try again...");
                        }
                    }
                });
            }
        }

        $(function() {
            documentsDataTable = documentsTable.DataTable({
                serverSide: true,
                processing: true,
                ajax: {
                    url: "<?= $rootURL ?>/documents/list"
                },
                order: [[ 1, "asc" ]],
                responsive: true,
                buttons: [
                    'pageLength','colvis', 'csv', 'excel', 'pdf', 'print', 'copy'
                ],
                layout: {
                    topStart: 'buttons',
                },
                columnDefs: [
                    {
                        className: "dt-center",
                        targets: '_all'
                    },
                    {
                        orderable: true,
                        targets: [1, 2, 3]
                    },
                    {
                        visible: false,
                        targets: [0]
                    }
                ],
                language: {
                    emptyTable: "Nothing has been added."
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'id'
                    },
                    {
                        data: null,
                        render: function ( data, type ) {
                            return `<a href='<?= $rootURL ?>/documents/${data.id}/details'>${data.name}</a>`;
                        }
                    },
                    {
                        data: 'content',
                    },
                    {
                        data: null,
                        render: function (data) {
                            if (data.permission === "read") {
                                return "Read";
                            } else if (data.permission === "write") {
                                return "Read/Write";
                            } else if (data.permission === "manage") {
                                return "Read/Write/Manage";
                            }
                            // This should never be reached
                            return "None"
                        }
                    },
                    {
                        data: null,
                        render: function (data) {
                            let html =`<button class='btn btn-primary btn-xs me-1' onclick='editDocument("${data.id}", "${data.name}", "${data.content}");'>
                                        <span class='fas fa-file-pen' data-toggle='tooltip' data-placement='left' title='Edit Document'></span>
                                    </button>`
                            if (data.permission === "manage"){
                                html +=`<button class='btn btn-danger btn-xs me-1' onclick='deleteDocument("${data.id}");'>
                                            <span class='fas fa-file-circle-xmark' data-toggle='tooltip' data-placement='left' title='Delete Document'></span>
                                        </button>`;
                            }
                            return html;
                        }
                    }
                ]
            });
        });

        


    </script>
<?php
include_once VIEWS_DIR . '/_footer.php';