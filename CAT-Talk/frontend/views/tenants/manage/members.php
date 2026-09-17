<?php
/** 
 * @var User $user
 * @var string $tenantId
 * @var Tenant $tenant 
 * */



$canManage = (($tenant->hasRole($user->getId(), "Admin") && $tenant->canSelfManage())
                || $user->isAdmin() // Comment this line to remove admin access, usually for testing
            );


?>

    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Users - <span class="text-muted">Manage users of this tenant</span></h1>
        <?php if ($canManage): ?>
        <div class="btn-toolbar mb-2 mb-md-0">
            <button type="button" class="btn btn-success" style="margin-right: 10px;" onclick="showSubmitUserModal();">
                <i class="fas fa-plus mr-1"></i> Add User
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

    <div class="modal fade" id="userModal" tabindex="-1" role="dialog" aria-labelledby="userModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalLabel">User Management</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <input type="hidden" id="user-id" value="" />
                        <div class="col-md-12">
                            <div class="input-group mb-3">
                                <span class="input-group-text" id="user-email-label">Email</span>
                                <input id="user-email" type="text" class="form-control" placeholder="abc123@uky.edu" aria-label="Email" aria-describedby="user-email">
                            </div>
                        </div>
                    </div>
                    <div class="row border-bottom">
                        <div class="col-md-12">
                            <div class="input-group mb-3">
                                <label class="input-group-text" for="tenant-user-roles-label">Roles</label>
                                <select id="tenant-user-roles" aria-label="Select User Role" multiple></select>
                            </div>
                        </div>
                    </div>
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="submitUser();">Submit</button>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">

        //================================================
        //                   MEMBERS
        //================================================

        var usersTable = $('#users');
        var usersDatatable = null;
        var users = null;
        var submitUserModal = $("#userModal");
        var roles = {}

        function showSubmitUserModal(){
            submitUserModal.modal("show");
        }



        function submitUser() {
            let userId = $('#user-id').val();
            if (userId === ""){
                userId = null;
            }

            let userEmail = $('#user-email').val();
            if (userEmail === null || userEmail === '') {
                showError('Please enter email for user.');
                return;
            }

            if (!validateEmail(userEmail)) {
                showError("Please enter a valid email.");
                return;
            }

            let userRoles = $('#tenant-user-roles').val();
            if (userRoles === null){
                showError('Please choose this user\'s role.');
                return;
            }

            
            let formData = {
                'id': userId,
                'email': userEmail,
                'roles': userRoles,
            };

            $.ajax({
                url: '<?= $rootURL ?>/tenants/<?= $tenantId ?>/submit-user',
                type: "POST",
                data: JSON.stringify(formData),
                contentType: "application/json", // Ensure JSON is sent
                dataType: 'json',
                success: function(data) {
                    showSuccess('Successfully submitted user.');
                    usersDatatable.ajax.reload();
                    submitUserModal.modal('hide');
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

        function removeUser(id) {
            if (id !== null && id !== '') {
                let user = users[id];
                confirmModalInternalId.val(id);
                if (id == "<?= $user->getId() ?>"){
                    confirmModalTitle.html('Confirm Leave Tenant');
                    let leaveTenantMessage = "Are you sure you wish to leave this tenant?";
                    if (Object.keys(users).length == 1){
                        leaveTenantMessage += "<br><span class='text-danger'>This will result in the permanent deletion of this tenant and all its data.</span>"
                    }
                    confirmModalTextSpan.html(leaveTenantMessage);
                } else {
                    confirmModalTitle.html('Confirm remove User');
                    let displayName = user['full_name'] || user['eppn'] || user['email']
                    confirmModalTextSpan.html("Are you sure you wish to remove user [" + displayName + "]");
                }
                
            }
            confirmModal.modal('show');
            confirmModalButton.off();
            confirmModalButton.on('click', function() {
                confirmRemoveUser();
            });
        }


        function confirmRemoveUser() {
            if (confirmModalInternalId.val() === null || confirmModalInternalId.val() === '') {
                showError('You must supply a user internal id to remove');
                return;
            }
            $.ajax({
                method: "POST",
                url: '<?= $rootURL ?>/tenants/<?= $tenantId ?>/remove-user',
                data: {
                    'user_id': confirmModalInternalId.val(),
                },
                dataType: 'json',
                success: function(data) {
                    showSuccess('Successfully removed user');
                    usersDatatable.ajax.reload( null, false );
                    if (confirmModalInternalId.val() == "<?= $user->getId() ?>"){
                        window.location = "<?= $rootURL ?>/tenants"
                    }
                    confirmModal.modal('hide');
                },
                error: function(xhr, status, error) {
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        showError(xhr.responseJSON.error);
                    } else {
                        showError("Error removing user. Try again...");
                    }
                }
            });
        }

        function fillUserForm(user) {
            if (user !== null) {
                $('#user-id').val(user.id);
                $('#user-email').val(user.eppn);

                let usersRoles = user.roles;
                let selectedRoles = Object.keys(roles).filter(roleId => usersRoles.hasOwnProperty(roleId));

                $('#tenant-user-roles').val(selectedRoles);
                $('#tenant-user-roles').selectpicker('refresh');
            }
        }


        function editUser(userId) {
            let row_data = usersDatatable.rows().data().filter(function(data, index){
                return data['id'] === userId;  // Assuming 'id' is the first column
            }).toArray();
            fillUserForm(row_data[0]);
            
            const userTenants = row_data[0].tenants;

            showSubmitUserModal(userTenants);
        }

        function clearUserForm() {
            $('#user-id').val('');
            $('#user-email').val('');
            $('#tenant-user-roles').val("-1");
        }

        submitUserModal.on('hidden.bs.modal', function() {
            clearUserForm();
        });

        function validateEmail(email) {
            const emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
            return emailPattern.test(email);
        }


        $(function() {
            usersHiddenColumns = [1, 2, 4, 5];
            <?php if (!$canManage): ?>
            usersHiddenColumns.append(7)
            <?php endif; ?>
            
            usersDatatable = usersTable.DataTable({
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
                    url: "<?= $rootURL ?>/tenants/<?= $tenantId ?>/list-users",
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
                        data: null,
                        render: function (data) {
                            let html = "";
                            const roles = data.roles;
                            for (const [roleId, roleName] of Object.entries(roles)) {
                                html += `${roleName}<br>`;
                            }
                            return html;
                        }
                    },
                    {
                        data: null,
                        render: function (data) {
                            let html = "";
                            // if (data.id !== "<?php echo $user->getId(); ?>") {
                                html +=`<button class='btn btn-primary btn-xs me-1' onclick='editUser("${data.id}");'>
                                            <span class='fas fa-user-edit' data-toggle='tooltip' data-placement='left' title='Edit User'></span>
                                        </button>
                                        <button class='btn btn-danger btn-xs me-1' onclick='removeUser("${data.id}");'>
                                            <span class='fas fa-user-slash' data-toggle='tooltip' data-placement='left' title='Remove User'></span>
                                        </button>`;
                            // }
                            return html;
                        }
                    }
                ]
            });
            usersDatatable.buttons().container().prependTo('#users_filter');
            usersDatatable.buttons().container().addClass('float-left');
            $('.dt-buttons').addClass('btn-group-sm');
            $('.dt-buttons div').addClass('btn-group-sm');
            usersTable.on('xhr.dt', function (e, settings, data) {
                users = {};
                if (data && data.data){
                    $.each(data.data, function(i, v) {
                        users[v.id] = v;
                    });
                }
            });

            $.ajax({
                url: "<?= $rootURL ?>/tenants/<?= $tenantId ?>/get-roles",
                type: "GET",
                success: function(result){
                    $.each(Object.keys(result.roles), function(key){
                        $('#tenant-user-roles').append('<option value="'+key+'">'+result.roles[key]+'</option>');
                        roles[key] = result.roles[key];
                    });
                    $("#tenant-user-roles").selectpicker();
                },
                error: function(xhr, status, error) {
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        showError(xhr.responseJSON.error);
                    } else {
                        showError("Error retrieving user roles. Try reloading...");
                    }
                }
            });
            


        });
        
    </script>