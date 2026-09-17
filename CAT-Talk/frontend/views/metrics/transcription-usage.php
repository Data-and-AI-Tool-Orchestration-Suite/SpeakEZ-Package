<?php
/** @var UserSession $userSession */
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Transcription Usage - <span class="text-muted">Transcription usage by user and project</span></h1>
    </div>
    <div class="row mb-5">
        <div class="col">
            <table id="transcription-usage" class="table table-striped table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>Username</th>
                    <th>Project</th>
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

        var chat_usage_table = $('#transcription-usage');
        var chat_usage_datatable = null;
        // var usage = JSON.parse(``);
        var usage = {};


        $(function() {
            usageHiddenColumns = [];
            chat_usage_datatable = chat_usage_table.DataTable({
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
                        d.request_type = "transcription";
                    }
                },
                order: [[ 0, "asc" ]],
                responsive: true,
                dom: 'Bfrtip',
                bInfo : false,
                buttons: [
                    'pageLength', 'colvis'
                ],
                columnDefs: [
                    {
                        className: "dt-center",
                        targets: '_all'
                    },
                    // {
                    //     orderable: false,
                    //     targets: [6, 7]
                    // },
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
                            if (data.full_name){
                                return data.full_name;
                            } else {
                                return data.eppn;
                            }
                        }
                    },
                    {
                        data: 'key_name'
                    },
                    {
                        data: null,
                        render: function ( data ) {
                            let html = `Input tokens: ${data.total_prompt_tokens}<br>
                                        Output tokens: ${data.total_generated_tokens}`
                            return html;
                        }
                    },
                    {
                        data: null,
                        render: function ( data ) {
                            let cost = parseFloat(data.openai_cost);
                            html = "$" + cost.toFixed(2);
                            return html;
                        }
                    }
                ]
            });
            chat_usage_datatable.buttons().container().prependTo('#usage_filter');
            chat_usage_datatable.buttons().container().addClass('float-left');
            $('.dt-buttons').addClass('btn-group-sm');
            $('.dt-buttons div').addClass('btn-group-sm');
            chat_usage_table.on('xhr.dt', function (e, settings, data) {
                chat_usage = {};
                if (data && data.data){
                    $.each(data.data, function(i, v) {
                        chat_usage[v.id] = v;
                    });
                }
                
            });


        });
        
    </script>