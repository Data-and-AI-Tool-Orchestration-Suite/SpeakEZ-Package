<?php
/** @var UserSession $userSession */
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">LLM Usage - <span class="text-muted">LLM usage by user and project</span></h1>
    </div>
    <div class="row mb-5">
        <div class="col">
            <table id="llm-usage" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>Username</th>
                    <th>API Key</th>
                    <th>Usage</th>
                    <th>OpenAI Equivalent Cost</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
            </table>
        </div>
    </div>

    <script type="text/javascript">

        //================================================
        //                   OVERVIEW
        //================================================

        var embedding_usage_table = $('#llm-usage');
        var embedding_usage_datatable = null;
        var usage = {};

        $(function() {
            usageHiddenColumns = [];
            embedding_usage_datatable = embedding_usage_table.DataTable({
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
                    url: "<?= $rootURL ?>/metrics/list-usage",
                    type: 'GET',
                    data: function (d) {
                        // Add custom parameters to the AJAX request
                        d.request_type = "llm";
                    }
                },
                order: [[ 3, "desc" ]], // Order by action_time
                responsive: true,
                dom: 'Bfrtip',
                bInfo: false,
                buttons: [
                    'pageLength', 'colvis'
                ],
                columnDefs: [
                    {
                        className: "dt-center",
                        targets: '_all'
                    },
                    {
                        visible: false,
                        targets: usageHiddenColumns
                    }
                ],
                language: {
                    emptyTable: "No usage data available"
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: null,
                        render: function(data) {
                            return data.action_details.full_name || data.action_details.eppn || "Unknown";
                        }
                    },
                    {
                        data: null,
                        render: function(data) {
                            return data.action_details.api_key_name || "N/A";
                        }
                    },
                    {
                        data: null,
                        render: function(data) {
                            return `Tokens embedded: ${data.action_details.total_prompt_tokens || 0}`;
                        }
                    },
                    {
                        data: null,
                        render: function(data) {
                            let cost = parseFloat(data.action_details.openai_cost || 0);
                            return "$" + cost.toFixed(2);
                        }
                    }
                ]
            });
            embedding_usage_datatable.buttons().container().prependTo('#usage_filter');
            embedding_usage_datatable.buttons().container().addClass('float-left');
            $('.dt-buttons').addClass('btn-group-sm');
            $('.dt-buttons div').addClass('btn-group-sm');
            embedding_usage_table.on('xhr.dt', function (e, settings, data) {
                embedding_usage = {};
                if (data && data.data) {
                    $.each(data.data, function(i, v) {
                        embedding_usage[v.id] = v;
                    });
                }
            });
        });

    </script>