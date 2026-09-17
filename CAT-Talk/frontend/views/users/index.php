<?php
/** @var User $user */
$page = "users";
global $rootURL;
include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Users</h1>
        <div>
            <?php if (Plugin::isPluginActiveByName("user_agreement")): ?>
            <button type="button" class="btn btn-warning" onclick="resetUserAgreements()">
                <i class="fas fa-window-restore"></i> Reset User Agreements
            </button>
            <?php endif; ?>
            <button type="button" class="btn btn-success" onclick="showUserModal()">
                <i class="fas fa-user-plus"></i> Add User
            </button>
        </div>
    </div>
    <div class="row">
        <div class="col">
            <table id="users" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Vanity Email</th>
                    <th>Organization</th>
                    <th>Affiliation</th>
                    <th>IDP</th>
                    <th>Site Roles</th>
                    <th>Tenants</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
                <tfoot>
                <tr>
                    <th>ID</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Vanity Email</th>
                    <th>Organization</th>
                    <th>Affiliation</th>
                    <th>IDP</th>
                    <th>Site Roles</th>
                    <th>Tenants</th>
                    <th>Actions</th>
                </tr>
                </tfoot>
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
                            <!-- Single email input for editing existing users -->
                            <div class="input-group mb-3" id="single-email-container">
                                <span class="input-group-text" id="user-email-label">Email</span>
                                <input id="user-email" type="text" class="form-control" placeholder="abc123@uky.edu" aria-label="Email" aria-describedby="user-email">
                            </div>
                            <!-- Multiple email textarea for adding new users -->
                            <div class="mb-3" id="bulk-email-container" style="display: none;">
                                <label for="user-emails-bulk" class="form-label">
                                    Email Addresses <small class="text-muted">(one per line)</small>
                                </label>
                                <textarea id="user-emails-bulk" class="form-control" rows="5" 
                                    placeholder="abc123@uky.edu&#10;def456@uky.edu&#10;ghi789@uky.edu"></textarea>
                                <div class="form-text">
                                    Enter multiple email addresses, one per line. All users will be created with the same roles and tenant permissions.
                                </div>
                            </div>
                            <!-- Toggle buttons -->
                            <div class="mb-3" id="email-mode-toggle">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="email-mode" id="single-mode" checked>
                                    <label class="btn btn-outline-primary btn-sm" for="single-mode">Single User</label>
                                    
                                    <input type="radio" class="btn-check" name="email-mode" id="bulk-mode">
                                    <label class="btn btn-outline-primary btn-sm" for="bulk-mode">Multiple Users</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row border-bottom">
                        <div class="col-md-12">
                            <div class="input-group mb-3">
                                <label class="input-group-text" for="user-roles-label">Site Roles</label>
                                <select id="user-roles" aria-label="Select User Role" multiple></select>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div style="display: flex;">
                            <h5>Tenants</h5>
                            <div class="mb-3" style="margin-left: auto;">
                                <div class="d-flex" id="tenant-add-container">
                                    <button class="btn btn-success" onclick="addNewTenant()"><i class="fas fa-plus"></i></button>
                                    <select id="tenant-add" aria-label="Select new tenant"></select>
                                </div>
                            </div>
                        </div>
                        <div id="tenant-roles" style="border-radius:5px; background-color:rgba(100, 100, 100, 0.1); padding:10px;">
                            
                        </div>
                        
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="submitUser();">
                        <span id="submit-btn-text">Submit</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        // document.addEventListener('DOMContentLoaded', function() {
        //     $("#user-roles").selectpicker();
        // });

        var usersTable = $('#users');
        var usersDatatable = null;
        var users = {};
        var userModal = $('#userModal');
        var roles = {};
        var tenants = {};

        // Email mode toggle handlers
        $(document).ready(function() {
            $('input[name="email-mode"]').change(function() {
                if ($(this).attr('id') === 'single-mode') {
                    $('#single-email-container').show();
                    $('#bulk-email-container').hide();
                    $('#submit-btn-text').text('Submit');
                } else {
                    $('#single-email-container').hide();
                    $('#bulk-email-container').show();
                    $('#submit-btn-text').text('Submit All Users');
                }
            });
        });

        function showUserModal(userTenants=null, isEdit=false){
            // Show/hide email mode toggle based on whether this is an edit operation
            if (isEdit) {
                $('#email-mode-toggle').hide();
                $('#single-email-container').show();
                $('#bulk-email-container').hide();
                $('#single-mode').prop('checked', true);
                $('#submit-btn-text').text('Submit');
            } else {
                $('#email-mode-toggle').show();
                // Reset to single mode by default
                $('#single-mode').prop('checked', true);
                $('#single-email-container').show();
                $('#bulk-email-container').hide();
                $('#submit-btn-text').text('Submit');
            }

            $("#user-roles").selectpicker("refresh");
            $("#tenant-roles").empty();
            $("#tenant-add-container").removeClass("d-none");
            if (userTenants !== null && Object.keys(userTenants).length > 0){
                userTenantIds = Object.keys(userTenants);
                userTenantIds.forEach(tenantId => {
                    addTenant(tenantId, userTenants[tenantId].name, tenants[tenantId].roles, userTenants[tenantId].roles)
                });
            } else {
                $("#tenant-roles").append('<h6 id="no-tenants-msg">No tenants have been added</h6>');
            }

            $("#tenant-add").empty();
            Object.keys(tenants).forEach(tenantId => {
                if (userTenants === null || !Object.keys(userTenants).includes(tenantId)) {
                    $("#tenant-add").append('<option value="'+tenantId+'">'+tenants[tenantId].name+'</option>');
                }
            });
            $("#tenant-add").selectpicker("refresh");
            if ($('#tenant-add').find('option').length === 0) {
                $("#tenant-add-container").addClass("d-none");
            } else if (Object.keys(tenants).length === 1 && (!userTenants || userTenants.length === 0)) {
                // Only one tenant to add to, user does not currently have a tenant
                addNewTenant();
            }
            userModal.modal("show");
        }

        $(function() {
            usersDatatable = usersTable.DataTable({
                serverSide: true,
                processing: true,
                ajax: {
                    url: "<?= $rootURL ?>/users/list"
                },
                order: [[ 3, "asc" ]],
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
                        orderable: false,
                        targets: [0, 7, 8, 10]
                    },
                    {
                        visible: false,
                        targets: [0,1,2,8]
                    }
                ],
                language: {
                    emptyTable: "No users have been added."
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'id'
                    },
                    {
                        data: 'firstname'
                    },
                    {
                        data: 'lastname'
                    },
                    {
                        data: null,
                        render: function (data) {
                            if (data.id.startsWith("notloggedin_")){
                                return "Waiting for user to log in..."
                            } else {
                                return data.fullname;
                            }
                        }
                    },
                    {
                        data: 'eppn'
                    },
                    {
                        data: 'email'
                    },
                    {
                        data: 'idpname'
                    },
                    {
                        data: 'affiliation',
                        render: function (data) {
                            let html = "";
                            if (data !== null) {
                                let aff = data.split(';');
                                for (let i = 0; i < aff.length; i++) {
                                    if (i === aff.length - 1) {
                                        html += aff[i];
                                    } else {
                                        html += aff[i] + '<br>';
                                    }
                                }
                            }
                            return html;
                        }
                    },
                    {
                        data: 'idp'
                    },
                    {
                        data: null,
                        render: function (data) {
                            let html = "";
                            const roles = JSON.parse(data.roles);
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
                            const tenants = data.tenants;
                            for (const [tenantId, tenantData] of Object.entries(tenants)) {
                                if (tenantData.name){
                                    html += `${tenantData.name}<br>`;
                                }
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
                                        <button class='btn btn-danger btn-xs me-1' onclick='deleteUser("${data.id}");'>
                                            <span class='fas fa-user-slash' data-toggle='tooltip' data-placement='left' title='Delete User'></span>
                                        </button>`;
                            // }
                            return html;
                        }
                    }
                ]
            });

            $.ajax({
                url: "<?= $rootURL ?>/users/get-roles",
                type: "GET",
                success: function(result){
                    $.each(result.roles, function(index){
                        $('#user-roles').append('<option value="'+result.roles[index][0]+'">'+result.roles[index][1]+'</option>');
                        roles[result.roles[index][0]] = result.roles[index][1];
                    });
                    $("#user-roles").selectpicker();
                },
                error: function(error) {
                    console.log(error);
                }
            });

            $.ajax({
                url: "<?= $rootURL ?>/tenants/get-tenants",
                type: "GET",
                success: function(result){
                    tenants = result.tenants;
                    $("#tenant-add").selectpicker();
                },
                error: function(error) {
                    console.log(error);
                }
            });
        });

        function submitUser() {
            let userId = $('#user-id').val();
            if (userId === "") {
                userId = null;
            }

            let userEmails = [];
            let isBulkMode = $('#bulk-mode').is(':checked');
            
            if (isBulkMode) {
                // Get emails from textarea
                let emailText = $('#user-emails-bulk').val().trim();
                if (emailText === '') {
                    showError('Please enter at least one email address.');
                    return;
                }
                
                // Split by newlines and clean up
                userEmails = emailText.split('\n')
                    .map(email => email.trim())
                    .filter(email => email.length > 0);
                    
                if (userEmails.length === 0) {
                    showError('Please enter at least one valid email address.');
                    return;
                }
                
                // Validate all emails
                let invalidEmails = [];
                userEmails.forEach(email => {
                    if (!validateEmail(email)) {
                        invalidEmails.push(email);
                    }
                });
                
                if (invalidEmails.length > 0) {
                    showError('The following email addresses are invalid: ' + invalidEmails.join(', '));
                    return;
                }
            } else {
                // Single email mode
                let userEmail = $('#user-email').val();
                if (userEmail === null || userEmail === '') {
                    showError('Please enter email for user.');
                    return;
                }

                if (!validateEmail(userEmail)) {
                    showError("Please enter a valid email.");
                    return;
                }
                
                userEmails = [userEmail];
            }

            let userRole = $('#user-roles').val();
            if (userRole === null) {
                showError('Please choose this user\'s role.');
                return;
            }

            let userTenants = getTenantSelections();

            // Show loading state
            let submitBtn = $('#submit-btn-text');
            let originalText = submitBtn.text();
            submitBtn.text(isBulkMode && userEmails.length > 1 ? 'Creating Users...' : 'Submitting...');

            // Prepare form data for array submission
            let formData = {
                'id': userId, // For single user updates, pass the ID
                'email': userEmails.length === 1 ? userEmails[0] : userEmails, // Send as array for multiple emails
                'roles': userRole,
                'tenants': userTenants,
            };

            // If updating a single user, keep the ID; for bulk creation, remove it
            if (isBulkMode || userEmails.length > 1) {
                delete formData.id; // Bulk operations shouldn't have IDs
            }

            $.ajax({
                url: '<?= $rootURL ?>/users/submit',
                type: "POST",
                data: JSON.stringify(formData),
                contentType: "application/json",
                dataType: 'json',
                success: function(data, textStatus, xhr) {
                    submitBtn.text(originalText);

                    // Handle 207 Partial Success
                    if (xhr.status === 207) {
                        let successCount = data.users ? data.users.length : 0;
                        let errorCount = data.errors ? data.errors.length : 0;
                        let errorMessages = data.errors.map(err => `${err.email}: ${err.error}`);

                        showWarning(`Created ${successCount} user(s) successfully. ${errorCount} failed:<br>` + errorMessages.join('<br>'));
                    } else {
                        if (userEmails.length === 1) {
                            showSuccess('Successfully submitted user.');
                        } else {
                            let usersCreated = Array.isArray(data.users) ? data.users.length : 1;
                            showSuccess(`Successfully created ${usersCreated} user(s).`);
                        }
                    }

                    usersDatatable.ajax.reload();
                    userModal.modal('hide');
                },
                error: function(xhr, status, error) {
                    submitBtn.text(originalText);
                    handleSubmissionError(xhr, userEmails.length > 1);
                }
            });
        }

        function handleSubmissionError(xhr, isBulkMode) {
            if (xhr.responseJSON) {
                if (xhr.responseJSON.error) {
                    if (isBulkMode && xhr.responseJSON.details) {
                        // All failed with details
                        let errorMessages = xhr.responseJSON.details.map(err => `${err.email}: ${err.error}`);
                        showError(`Failed to create users:<br>` + errorMessages.join('<br>'));
                    } else {
                        // Single error or general error
                        showError(xhr.responseJSON.error);
                    }
                } else {
                    showError(isBulkMode ? "Error creating users. Try again..." : "Error submitting user. Try again...");
                }
            } else {
                showError(isBulkMode ? "Error creating users. Try again..." : "Error submitting user. Try again...");
            }
        }



        <?php if (Plugin::isPluginActiveByName("user_agreement")): ?>
        function resetUserAgreements() {
            let isExecuted = confirm("Are you sure you want to reset user agreements? All users will have to accept the agreement again.");
            if (isExecuted) {
                $.get({
                    url: '<?= $rootURL; ?>/users/reset-agreements',
                    dataType: 'json',
                    success: function(data) {
                        showSuccess('Successfully reset user agreements.');
                        usersDatatable.ajax.reload();
                    },
                    error: function(xhr, status, error) {
                        if (xhr.responseJSON && xhr.responseJSON.error) {
                            showError(xhr.responseJSON.error);
                        } else {
                            showError("Error resetting agreements. Try again...");
                        }
                    }
                });
            }
        }
        <?php endif; ?>

        function deleteUser(userId) {
            let isExecuted = confirm("Are you sure to delete this user? This action is not reversible.");
            if (isExecuted) {
                $.post({
                    url: '<?= $rootURL; ?>/users/delete',
                    data: {'id': userId},
                    dataType: 'json',
                    success: function(data) {
                        showSuccess('Successfully deleted user.');
                        usersDatatable.ajax.reload();
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

        function fillUserForm(user) {
            if (user !== null) {
                $('#user-id').val(user.id);
                $('#user-email').val(user.eppn);

                let usersRoles = JSON.parse(user.roles);
                let selectedRoles = Object.keys(roles).filter(roleId => usersRoles.hasOwnProperty(roleId));

                $('#user-roles').val(selectedRoles);
                $('#user-roles').selectpicker('refresh');
            }
        }

        function removeTenantFromUser(tenantId) {
            if ($('#tenant-roles').find('.user-tenant-row').length === 1) {
                showError("A user must be a member of at least one tenant.");
                return;
            }
            $(`.user-tenant-row#${tenantId}`).remove();
            $("#tenant-add").append(`<option value=${tenantId}>${tenants[tenantId].name}</option>`);
            $("#tenant-add-container").removeClass("d-none");
            $("#tenant-add").selectpicker("refresh");            
        }

        function addNewTenant() {
            const tenantId = $("#tenant-add").val()
            $(`#tenant-add option[value='${tenantId}']`).remove();
            $("#tenant-add").selectpicker("refresh");
            if ($('#tenant-add').find('option').length === 0) {
                $("#tenant-add-container").addClass("d-none");
            }
            const tenantName = tenants[tenantId].name;
            const tenantRoles = tenants[tenantId].roles;
            addTenant(tenantId, tenantName, tenantRoles);
        }

        function addTenant(tenantId, tenantName, tenantRoles, userRoles=null) {
            // Remove no-tenants message if it exists
            $("#tenant-roles #no-tenants-msg").remove()
            // Create a new row container
            let row = $(`<div class="user-tenant-row row mb-2" id="${tenantId}"></div>`);

            // Left side: Tenant Name
            let nameCol = $('<div class="col-md-6 d-flex"></div>');
            nameCol.append(`<button class="btn btn-sm btn-danger me-2" onclick="removeTenantFromUser('${tenantId}')"><i class="fas fa-minus"></i></button>`)
            nameCol.append(`<h6 style="margin-top:auto;">${tenantName}</h6>`)

            // Right side: Select picker for roles
            let selectCol = $('<div class="col-md-6"></div>');
            let select = $('<select class="selectpicker form-control" multiple></select>')
                .attr("id", "tenant-roles-" + tenantId)
                .attr("data-live-search", "true");

            // Add options to the select picker
            Object.keys(tenantRoles).forEach(roleId => {
                select.append('<option value="'+roleId+'">'+tenantRoles[roleId]+'</option>');
            });

            if (userRoles){
                let selectedRoles = Object.keys(roles).filter(roleId => userRoles.hasOwnProperty(roleId));
                select.val(selectedRoles);
            }

            // Append select to its column and refresh the selectpicker
            selectCol.append(select);
            row.append(nameCol, selectCol);
            
            // Append row to the #tenant-roles container
            $("#tenant-roles").append(row);

            // Initialize or refresh selectpicker
            select.selectpicker("refresh");
        }

        function getTenantSelections() {
            let userTenants = {};

            $("#tenant-roles .user-tenant-row").each(function() {
                let tenantId = $(this).attr("id");
                let selectedRoles = $("#tenant-roles-" + tenantId).val() || null; // Get selected values
                userTenants[tenantId] = selectedRoles; // Store in object
            });

            $(`#tenant-add option`).each(function() {
                userTenants[$(this).attr("value")] = [];
            })
            return sortTenantSelections(userTenants);
        }

        function sortTenantSelections(obj) {
            // Step 1: Replace `null` values with `[]`
            Object.keys(obj).forEach(key => {
                if (obj[key] === null) {
                    obj[key] = [];
                }
            });

            // Step 2: Convert object to an array of entries, sort, and convert back to an object
            return Object.fromEntries(
                Object.entries(obj).sort((a, b) => {
                    if (Array.isArray(a[1]) && a[1].length === 0) return 1;  // Move empty arrays to the end
                    if (Array.isArray(b[1]) && b[1].length === 0) return -1;
                    return 0;  // Preserve order otherwise
                })
            );
        }

        function editUser(userId) {
            let row_data = usersDatatable.rows().data().filter(function(data, index){
                return data['id'] === userId;  // Assuming 'id' is the first column
            }).toArray();
            fillUserForm(row_data[0]);
            
            const userTenants = row_data[0].tenants;

            showUserModal(userTenants, true); // Pass true to indicate this is an edit operation
        }

        function clearUserForm() {
            $('#user-id').val('');
            $('#user-email').val('');
            $('#user-emails-bulk').val('');
            $('#user-roles').val("-1");
            $("#tenant-roles").empty();
            $("#tenant-add").empty();
            $("#tenant-add").selectpicker("refresh");
            // Reset to single mode
            $('#single-mode').prop('checked', true);
            $('#single-email-container').show();
            $('#bulk-email-container').hide();
            $('#submit-btn-text').text('Submit');
        }

        userModal.on('hidden.bs.modal', function() {
            clearUserForm();
        });

        function validateEmail(email) {
            const emailPattern = /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/;
            return emailPattern.test(email);
        }

    </script>
<?php
include_once __DIR__ . '/../_footer.php';