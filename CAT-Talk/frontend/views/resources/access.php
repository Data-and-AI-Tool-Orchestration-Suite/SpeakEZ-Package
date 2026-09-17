<?php
/** 
 * @var User $user
 * @var string $userId
 * @var Resource $resource
 * @var string $resourceId
 */
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Members - <span class="text-muted">Manage access to this <?= $resource->getType() ?></span></h1>
        <?php if ($resource->canManage($userId)): ?>
        <div class="btn-toolbar mb-2 mb-md-0">
            <button type="button" class="btn btn-success" style="margin-right: 10px;" onclick="showGrantAccessModal();">
                <i class="fas fa-plus mr-1"></i> Add Member
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="row mb-5">
        <div class="col">
            <table id="users-with-access" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Permissions</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="grantAccessModal" tabindex="-1" role="dialog" aria-labelledby="grantAccessModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="grantAccessModalTitle">Grant Access</h5>
                    <button type="button" class="btn-close" aria-label="Close" onclick="hideGrantAccessModal();"></button>
                </div>
                <div class="modal-body">
                <table id="grant-access-table" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="hideGrantAccessModal();">Dismiss</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="manageAccessModal" tabindex="-1" role="dialog" aria-labelledby="manageAccessModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Manage Access</h5>
                <button type="button" class="btn-close" aria-label="Close" onclick="hideManageAccessModal();"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="manageAccessUserId">
                <div class="form-group">
                <label for="permissionSelect">Permission Level</label>
                <select class="form-select" id="permissionSelect">
                    <option value="read">Read</option>
                    <option value="write">Write</option>
                    <option value="manage">Manage</option>
                </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" id="revokeAccessBtn" class="btn btn-danger">Revoke Access</button>
                <button type="button" class="btn btn-secondary" onclick="hideManageAccessModal();">Close</button>
            </div>
            </div>
        </div>
    </div>


    <script type="text/javascript">

        //===============================================
        //                   ACCESS
        //===============================================

        var usersWithAccessTable = $('#users-with-access');
        var usersWithAccessDataTable = null;
        var usersWithAccess = null;

        var grantAccessDatatable = null;

        function showGrantAccessModal() {
            grantAccessDatatable.ajax.reload();
            $("#grantAccessModal").modal("show");
        }

        function hideGrantAccessModal() {
            $("#grantAccessModal").modal("hide");
        }

        function showManageAccessModal(userId, userName, currentPermission) {
            $("#manageAccessUserId").val(userId);
            $("#permissionSelect").val(currentPermission);
            $("#revokeAccessBtn").off("click").on("click", function() {
                revokeAccess(userId, userName);
                hideManageAccessModal();
            });

            $("#permissionSelect").off("change").on("change", function() {
                let newPermission = $(this).val();
                grantAccess(userId, newPermission);
            });

            $("#manageAccessModal").modal("show");
        }

        function hideManageAccessModal() {
            $("#manageAccessModal").modal("hide");
        }

        function grantAccess(userId, permission){
            $.ajax({
                url: '<?= $rootURL ?>/<?= strtolower($resource->getType()) ?>s/<?= $resourceId ?>/grant-access',
                method: 'POST',
                data: {
                    "grantee_id": userId,
                    "permission": permission
                },
                dataType: "json",
                success: function(data) {
                    usersWithAccessDataTable.ajax.reload( null, false );
                    grantAccessDatatable.ajax.reload( null, false );
                    showSuccess("Successfully granted access");
                },
                error: function(xhr, status, error) {
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        showError(xhr.responseJSON.error);
                    } else {
                        showError("Error granting access. Try again...");
                    }
                }
            });
        }

        async function revokeAccess(id, userName=null) {
            if (!userName) {
                userName = "this user"
            }
            let isConfirmed = await customConfirm(`Are you sure to wish to revoke ${userName}'s access?`);
            $.ajax({
                method: "POST",
                url: '<?= $rootURL ?>/<?= strtolower($resource->getType()) ?>s/<?= $resourceId ?>/revoke-access',
                data: {
                    'revokee_id': id,
                },
                dataType: 'json',
                success: function(data) {
                    showSuccess('Successfully removed member');
                    usersWithAccessDataTable.ajax.reload( null, false );
                    grantAccessDatatable.ajax.reload( null, false );
                    if (confirmModalInternalId.val() == "<?= $user->getId() ?>"){
                        window.location = "<?= $rootURL ?>/<?= strtolower($resource->getType()) ?>s"
                    }
                    confirmModal.modal('hide');
                },
                error: function(xhr, status, error) {
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        showError(xhr.responseJSON.error);
                    } else {
                        showError("Error removing member. Try again...");
                    }
                }
            });
        }



        $(function() {
            usersWithAccessHiddenColumns = [0, 1, 2, 3, ];
            
            usersWithAccessDataTable = usersWithAccessTable.DataTable({
                serverSide: true,
                processing: true,
                ajax: {
                    url: "<?= $rootURL ?>/<?= strtolower($resource->getType()) ?>s/<?= $resourceId ?>/list-users-with-access",
                },
                order: [[ 1, "asc" ]],
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
                        targets: []
                    },
                    {
                        visible: false,
                        targets: usersWithAccessHiddenColumns
                    }
                ],
                language: {
                    emptyTable: "No users have access to this <?= strtolower($resource->getType()) ?>"
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: "id"
                    },
                    {
                        data: 'eppn'
                    },
                    {
                        data: 'first_name'
                    },
                    {
                        data: 'last_name'
                    },
                    {
                        data: 'full_name'
                    },
                    {
                        data: 'email'
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
                            let userName = data.first_name + " " + data.last_name;
                            return `
                                <button class='btn btn-primary btn-xs' 
                                    onclick='showManageAccessModal("${data.id}", "${userName}", "${data.permission}");'>
                                    <span class='fas fa-user-cog' data-toggle='tooltip' data-placement='left' title='Manage Access'></span>
                                </button>`;
                        }
                    }
                ]
            });
            usersWithAccessDataTable.buttons().container().prependTo('#users_filter');
            usersWithAccessDataTable.buttons().container().addClass('float-left');
            $('.dt-buttons').addClass('btn-group-sm');
            $('.dt-buttons div').addClass('btn-group-sm');
            usersWithAccessTable.on('xhr.dt', function (e, settings, data) {
                usersWithAccess = {};
                if (data && data.data){
                    $.each(data.data, function(i, v) {
                        usersWithAccess[v.id] = v;
                    });
                }
                
            });
            


            grantAccessDatatable = $("#grant-access-table").DataTable({
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
                    url: "<?= $rootURL ?>/<?= strtolower($resource->getType()) ?>s/<?= $resourceId ?>/list-users-without-access",
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
                        targets: []
                    },
                    {
                        visible: false,
                        targets: []
                    }
                ],
                language: {
                    emptyTable: "No other users can be added"
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'full_name'
                    },
                    {
                        data: 'email'
                    },
                    {
                        data: null,
                        render: function ( data ) {
                            html = "";
                            html += `<button class='btn btn-primary btn-xs me-2' onclick='grantAccess("${data.id}", "read");'>` +
                                    "<span class='fas fa-book-open-reader' data-toggle='tooltip' data-placement='left' title='Grant Read Access'></span>" +
                                    "</button>";
                            html += `<button class='btn btn-info btn-xs me-2' onclick='grantAccess("${data.id}", "write");'>` +
                                    "<span class='fas fa-pen' data-toggle='tooltip' data-placement='left' title='Grant Write Access'></span>" +
                                    "</button>";
                            html += `<button class='btn btn-warning btn-xs me-2' onclick='grantAccess("${data.id}", "manage");'>` +
                                    "<span class='fas fa-people-roof' data-toggle='tooltip' data-placement='left' title='Grant Manage Access'></span>" +
                                    "</button>";
                            return html;
                        }
                    }
                ]
            });
            grantAccessDatatable.buttons().container().addClass('float-left');
            $('.dt-buttons').addClass('btn-group-sm');
            $('.dt-buttons div').addClass('btn-group-sm');


        });
        
    </script>