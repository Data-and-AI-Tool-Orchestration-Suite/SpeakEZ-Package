<?php
/** @var User $user */
$page = "tenants";
global $rootURL;
include_once __DIR__ . "/../_header.php";
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Tenants</h1>
        <button type="button" class="btn btn-success" onclick="showTenantModal()">
            <i class="fas fa-plus"></i> Add Tenant
        </button>
    </div>
    <div class="row">
        <div class="col">
            <table id="tenants" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Can Self Manage?</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="tenantModal" tabindex="-1" role="dialog" aria-labelledby="tenantModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="tenantModalLabel">Tenant Management</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <input type="hidden" id="tenant-id" value="" />
                        <div class="col-md-12">
                            <div class="input-group mb-3">
                                <span class="input-group-text" id="tenant-name-label">Tenant Name</span>
                                <input id="tenant-name" type="text" class="form-control" aria-label="Name" aria-describedby="tenant-name">
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="can-self-manage-check">
                                <label class="form-check-label" for="can-self-manage-check" style="font-size:16px;">
                                    Allow tenant to add users?
                                </label>
                            </div>
                        </div>
                    </div>
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="submitTenant();">Submit</button>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        // document.addEventListener("DOMContentLoaded", function() {
        //     $("#tenant-roles").selectpicker();
        // });

        var tenantsTable = $("#tenants");
        var tenantsDatatable = null;
        var tenants = {};
        var tenantModal = $("#tenantModal");
        var roles = {};

        function showTenantModal(checkAddUsers=false) {
            $("#tenantModal").modal("show");
            $("#can-self-manage-check").prop("checked", checkAddUsers);
        }

        $(function() {
            tenantsDatatable = tenantsTable.DataTable({
                serverSide: true,
                processing: true,
                ajax: {
                    url: "<?= $rootURL ?>/tenants/list"
                },
                order: [[ 1, "asc" ]],
                responsive: true,
                buttons: [
                    "pageLength","colvis"
                ],
                layout: {
                    topStart: "buttons",
                },
                columnDefs: [
                    {
                        className: "dt-center",
                        targets: "_all"
                    },
                    {
                        orderable: false,
                        targets: [2, 3]
                    },
                    {
                        visible: false,
                        targets: [0]
                    }
                ],
                language: {
                    emptyTable: "No tenants have been added."
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: "id"
                    },
                    {
                        data: null,
                        render: function (data) {
                            let html = "";
                            html +=`<a href="<?= $rootURL ?>/tenants/${data.id}/manage">${data.name}</a>`;
                            return html;
                        }
                    },
                    {
                        data: null,
                        render: function (data) {
                            if (data.can_self_manage){
                                return "Yes";
                            } else {
                                return "No";
                            }
                            
                        }
                    },
                    {
                        data: null,
                        render: function (data) {
                            let html = "";
                            html +=`<button class="btn btn-primary btn-xs me-1" onclick="editTenant('${data.id}');">
                                        <span class="fas fa-edit" data-toggle="tooltip" data-placement="left" title="Edit Tenant"></span>
                                    </button>
                                    <button class="btn btn-danger btn-xs me-1" onclick="deleteTenant('${data.id}');">
                                        <span class="fas fa-minus" data-toggle="tooltip" data-placement="left" title="Delete Tenant"></span>
                                    </button>`;
                            
                            return html;
                        }
                    }
                ]
            });
        });

        function submitTenant() {
            let tenantId = $("#tenant-id").val();
            if (tenantId === ""){
                tenantId = null;
            }

            let tenantName = $("#tenant-name").val();
            if (tenantName === null || tenantName === "") {
                showError("Please enter a name for tenant.");
                return;
            }

            let formData = {
                "name": tenantName,
                "can_self_manage": $("#can-self-manage-check").prop("checked")
            };
            
            if (tenantId){
                formData["id"] = tenantId;
            }

            $.ajax({
                url: "<?= $rootURL ?>/tenants/submit",
                type: "POST",
                data: formData,
                dataType: "json",
                success: function(data) {
                    showSuccess("Successfully added tenant.");
                    tenantsDatatable.ajax.reload(null, false);
                    tenantModal.modal("hide");
                },
                error: function(xhr, status, error) {
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        showError(xhr.responseJSON.error);
                    } else {
                        showError("Error deleting tenant. Try again...");
                    }
                }
            });
        }

        function deleteTenant(tenantId) {
            let isExecuted = confirm("Are you sure to delete this tenant? This action is not reversible.");
            if (isExecuted) {
                $.ajax({
                    url: "<?= $rootURL; ?>/tenants/delete",
                    type: "POST",
                    data: {"id": tenantId},
                    dataType: "json",
                    success: function(data) {
                        showSuccess("Successfully deleted tenant.");
                        tenantsDatatable.ajax.reload(null, false);
                    },
                    error: function(xhr, status, error) {
                        if (xhr.responseJSON && xhr.responseJSON.error) {
                            showError(xhr.responseJSON.error);
                        } else {
                            showError("Error deleting tenant. Try again...");
                        }
                    }
                });
            }
        }

        function fillTenantForm(tenant) {
            if (tenant !== null) {
                $("#tenant-id").val(tenant.id);
                $("#tenant-name").val(tenant.name);
                showTenantModal(tenant.can_self_manage);
            }
        }

        function editTenant(tenantId) {
            let rowData = tenantsDatatable.rows().data().filter(function(data, index){
                return data["id"] === tenantId;  // Assuming "id" is the first column
            }).toArray();
            fillTenantForm(rowData[0]);
        }

        function clearTenantForm() {
            $("#tenant-id").val("");
            $("#tenant-name").val("");
            $("#tenant-roles").val("-1");
        }

        tenantModal.on("hidden.bs.modal", function() {
            clearTenantForm();
        });

    </script>
<?php
include_once __DIR__ . "/../_footer.php";