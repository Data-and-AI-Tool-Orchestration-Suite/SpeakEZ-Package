<?php
/** @var User $user */
$page = "plugins";
$config = include CONFIG_FILE;
include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Plugins</h1>
    </div>
    <div class="row">
        <div class="col">

            <table id="plugins" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th>Active</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
            </table>  
        </div>  
    </div>

    <div class="modal fade" id="deactivatePluginModal" tabindex="-1" role="dialog" aria-labelledby="deactivatePluginModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deactivatePluginModalLabel">Deactivate Plugin</h5>
                <button type="button" class="btn-close" aria-label="Close" onclick="hideDeactivatePluginModal();"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="deactivatePluginModalInternalId" value="" />
                <div class="row">
                    <div class="col-sm-12 mb-3" id="deactivatePluginModalTextSpan"></div>
                </div>

                <!-- Add the checkbox and message here -->
                <div class="row">
                    <div class="col-sm-12 mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="removeDatabaseTablesCheck">
                            <label class="form-check-label" for="removeDatabaseTablesCheck">
                                Remove the database tables associated with this plugin. <br>
                                <small class="text-danger">Note: If you choose to drop these tables, all data associated with this plugin will be lost.</small>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="hideDeactivatePluginModal();">Dismiss</button>
                <button type="button" class="btn btn-danger" id="deactivatePluginModalConfirmButton">Confirm</button>
            </div>
        </div>
    </div>
</div>


    
    
    <script type="text/javascript">
        var plugins = {}

        var pluginTable = $('#plugins');
        var pluginDatatable = null;

        function showDeactivatePluginModal() {
            $("#deactivatePluginModal").modal("show");
        }

        function hideDeactivatePluginModal() {
            $("#deactivatePluginModal").modal("hide");
        }

        function activatePlugin(pluginId){
            $.ajax({
                url: `<?= $rootURL ?>/plugins/activate/${pluginId}`,
                type: "GET",
                contentType: 'application/json',
                success: function(response){
                    if (response.updated){
                        showSuccess("Activated plugin");
                    } else {
                        showError("Failed to activate plugin");
                    }
                    pluginDatatable.ajax.reload();
                },
                error: function(xhr, textStatus, errorThrown) {
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        showError(xhr.responseJSON.error);
                    } else {
                        showError("Error activating plugin. Try again...");
                    }
                }
            });
        }

        function confirmDeactivatePlugin(pluginId, pluginName){
            if (pluginId !== null && pluginId !== '') {
                $("#removeDatabaseTablesCheck").prop("checked", false);
                $("#deactivatePluginModalTextSpan").html(`Are you sure you wish to deactivate the ${pluginName} plugin?`);
                showDeactivatePluginModal();
                $("#deactivatePluginModalConfirmButton").off("click");
                $("#deactivatePluginModalConfirmButton").on("click", function() {
                    let dropTables = $("#removeDatabaseTablesCheck").prop("checked"); 
                    deactivatePlugin(pluginId, dropTables);
                });
            }
            
        }

        function deactivatePlugin(pluginId, dropTables=false){
            $.ajax({
                url: `<?= $rootURL ?>/plugins/deactivate/${pluginId}?drop_tables=${dropTables}`,
                type: "GET",
                contentType: 'application/json',
                success: function(response){
                        if (response.updated){
                            showSuccess("Deactivated plugin");
                        } else {
                            showError("Failed to deactivate plugin");
                        }
                        pluginDatatable.ajax.reload();
                        hideDeactivatePluginModal();
                    },
                error: function(xhr, textStatus, errorThrown) {
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        showError(xhr.responseJSON.error);
                    } else {
                        showError("Error deactivating plugin. Try again...");
                    }
                }
            });
        }

        $(function() {
            pluginDatatable = pluginTable.DataTable({
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
                    url: "<?= $rootURL ?>/plugins/list",
                    contentType: 'application/json',
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
                        targets: [1]
                    },
                    // {
                    //     visible: false,
                    //     targets: [2]
                    // }
                ],
                language: {
                    emptyTable: "No Plugins have been added"
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'display_name'
                    },
                    {
                        data: 'description',
                        render: function ( data ) {
                            
                            return data
                        }
                    },
                    {
                        data: null,
                        render: function ( data ) {
                            let html;
                            if (data.active) {
                                html = `<button id="" class="btn btn-sm btn-danger" onclick="confirmDeactivatePlugin('${data.id}', '${escapeHTML(data.display_name)}')">
                                            <span class='fas fa-arrow-down' data-toggle='tooltip' data-placement='left' title='Deactivate'></span>
                                        </button>
                                        `;
                            } else {
                                html = `<button id="" class="btn btn-sm btn-success" onclick="activatePlugin('${data.id}')">
                                            <span class='fas fa-arrow-up' data-toggle='tooltip' data-placement='left' title='Activate'></span>
                                        </button>`;
                            }
                            return html;
                        }
                    },
                ]
            });
            $('.dt-buttons').addClass('btn-group-sm');
            $('.dt-buttons div').addClass('btn-group-sm');
            pluginDatatable.on('xhr.dt', function (e, settings, data) {
                plugins = {};
                if (data){
                    $.each(data.data, function(i, v) {
                        plugins[v.id] = v;
                    });
                }
            }); 
        });





    </script>
<?php
include_once __DIR__ . '/../_footer.php';