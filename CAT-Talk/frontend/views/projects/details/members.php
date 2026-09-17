<?php
/** 
 * @var User $user
 * @var Project $project 
 * */


?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Members - <span class="text-muted">Manage members of this project</span></h1>
        <?php if ($project->isRole($user->getId(), "Admin")): ?>
        <div class="btn-toolbar mb-2 mb-md-0">
            <button type="button" class="btn btn-success" style="margin-right: 10px;" onclick="showMemberModal();">
                <i class="fas fa-plus mr-1"></i> Add Member
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="row mb-5">
        <div class="col">
            <table id="users" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>Username</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Identity Provider</th>
                    <th>Roles</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="memberModal" tabindex="-1" role="dialog" aria-labelledby="memberModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="memberModalTitle">Add Members</h5>
                    <button type="button" class="btn-close" aria-label="Close" onclick="hideMemberModal();"></button>
                </div>
                <div class="modal-body">
                <table id="add-members-table" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Identity Provider</th>
                            <th>Roles</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="hideMemberModal();">Dismiss</button>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">

        //================================================
        //                   MEMBERS
        //================================================

        var users_table = $('#users');
        var users_datatable = null;
        var users = null;

        var addMembersDatatable = null;

        function showMemberModal() {
            addMembersDatatable.ajax.reload();
            $("#memberModal").modal("show");
        }

        function hideMemberModal() {
            $("#memberModal").modal("hide");
        }

        function addMember(userId){
            $.ajax({
                url: '<?= $rootURL ?>/projects/<?= $projectId ?>/add-member',
                method: 'post',
                data: {
                    "user_id": userId,
                },
                success: function(data) {
                    users_datatable.ajax.reload( null, false );
                    addMembersDatatable.ajax.reload( null, false );
                    showSuccess("Successfully added member");
                },
                error: function(xhr, status, error) {
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        showError(xhr.responseJSON.error);
                    } else {
                        showError("Error adding member. Try again...");
                    }
                }
            });
        }

        function removeMember(id) {
            if (id !== null && id !== '') {
                let member = users[id];
                confirmModalInternalId.val(id);
                if (id == "<?= $user->getId() ?>"){
                    confirmModalTitle.html('Confirm Leave Project');
                    let leaveProjectMessage = "Are you sure you wish to leave this project?";
                    if (Object.keys(users).length == 1){
                        leaveProjectMessage += "<br><span class='text-danger'>This will result in the permanent deletion of this project and all its data.</span>"
                    }
                    confirmModalTextSpan.html(leaveProjectMessage);
                } else {
                    confirmModalTitle.html('Confirm remove Member');
                    let displayName = member['full_name'] || member['eppn'] || member['email']
                    confirmModalTextSpan.html("Are you sure you wish to remove member [" + displayName + "]");
                }
                
            }
            confirmModal.modal('show');
            confirmModalButton.off();
            confirmModalButton.on('click', function() {
                confirmRemoveMember();
            });
        }


        function confirmRemoveMember() {
            if (confirmModalInternalId.val() === null || confirmModalInternalId.val() === '') {
                showError('You must supply a user internal id to remove');
                return;
            }
            $.ajax({
                method: "POST",
                url: '<?= $rootURL ?>/projects/<?= $projectId ?>/remove-member',
                data: {
                    'user_id': confirmModalInternalId.val(),
                },
                dataType: 'json',
                success: function(data) {
                    showSuccess('Successfully removed member');
                    users_datatable.ajax.reload( null, false );
                    addMembersDatatable.ajax.reload( null, false );
                    if (confirmModalInternalId.val() == "<?= $user->getId() ?>"){
                        window.location = "<?= $rootURL ?>/projects"
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
            usersHiddenColumns = [1, 2, 4, 5, 6];
            memberPermissions = "<?= $project->getMemberRole($user->getId()); ?>";
            if (memberPermissions != "Admin"){
                usersHiddenColumns.push(7);
            }
            users_datatable = users_table.DataTable({
                preDrawCallback: function (settings) {
                    var api = new $.fn.dataTable.Api(settings);
                    var pagination = $(this)
                        .closest('.dataTables_wrapper')
                        .find('.dataTables_paginate');
                    pagination.toggle(api.page.info().pages > 1);
                },
                serverSide: false,
                ajax: {
                    url: "<?= $rootURL ?>/projects/<?= $projectId ?>/list-members",
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
                        targets: [6, 7]
                    },
                    {
                        visible: false,
                        targets: usersHiddenColumns
                    }
                ],
                language: {
                    emptyTable: "No users have been added"
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'eppn'
                    },
                    {
                        data: 'firstname'
                    },
                    {
                        data: 'lastname'
                    },
                    {
                        data: 'fullname'
                    },
                    {
                        data: 'email'
                    },
                    {
                        data: 'idp'
                    },
                    {
                        data: 'roles',
                    },
                    {
                        data: null,
                        render: function ( data ) {
                            html = "";
                            html += "<button class='btn btn-danger btn-xs mr-2' onclick='removeMember(\"" + data.id + "\");'>" +
                                    "<span class='fas fa-minus' data-toggle='tooltip' data-placement='left' title='Remove Member'></span>" +
                                    "</button>";
                            return html;
                        }
                    }
                ]
            });
            users_datatable.buttons().container().prependTo('#users_filter');
            users_datatable.buttons().container().addClass('float-left');
            $('.dt-buttons').addClass('btn-group-sm');
            $('.dt-buttons div').addClass('btn-group-sm');
            users_table.on('xhr.dt', function (e, settings, data) {
                users = {};
                if (data && data.data){
                    $.each(data.data, function(i, v) {
                        users[v.id] = v;
                    });
                }
                
            });
            


            addMembersDatatable = $("#add-members-table").DataTable({
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
                    url: "<?= $rootURL ?>/projects/<?= $projectId ?>/list-unaffiliated-users",
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
                        targets: [6, 7]
                    },
                    {
                        visible: false,
                        targets: [1, 2, 4, 5, 6]
                    }
                ],
                language: {
                    emptyTable: "No other users can be added"
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'eppn'
                    },
                    {
                        data: 'firstname'
                    },
                    {
                        data: 'lastname'
                    },
                    {
                        data: 'fullname'
                    },
                    {
                        data: 'email'
                    },
                    {
                        data: 'idp'
                    },
                    {
                        data: 'roles',
                        render: function ( data ) {
                            return data;
                        }
                    },
                    {
                        data: null,
                        render: function ( data ) {
                            html = "";
                            html += "<button class='btn btn-success btn-xs mr-2' onclick='addMember(\"" + data.id + "\");'>" +
                                    "<span class='fas fa-plus' data-toggle='tooltip' data-placement='left' title='Add Member'></span>" +
                                    "</button>";
                            return html;
                        }
                    }
                ]
            });
            addMembersDatatable.buttons().container().addClass('float-left');
            $('.dt-buttons').addClass('btn-group-sm');
            $('.dt-buttons div').addClass('btn-group-sm');


        });
        
    </script>