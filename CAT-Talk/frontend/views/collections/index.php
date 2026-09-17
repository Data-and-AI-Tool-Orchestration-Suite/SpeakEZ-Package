<?php
/** @var UserSession $userSession */
$page = 'page1';
include_once __DIR__ . '/../_header.php';
global $rootURL;
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Page 1 - <span class="text-muted">Subtitle</span></h1>
    </div>
    <div class="row">
        <div class="col">
            <table id="collection" class="table table-bordered dt-responsive responsive-text" style="width:100%">
                <thead>
                <tr>
                    <th>Name</th>
                    <th style="text-align: center;">Column 1</th>
                    <th style="text-align: center;">Column 2</th>
                    <th style="text-align: center;">Column 3</th>
                </tr>
                </thead>
                <tbody>
                </tbody>
                <tfoot>
                <tr>
                    <th>Name</th>
                    <th style="text-align: center;">Column 1</th>
                    <th style="text-align: center;">Column 2</th>
                    <th style="text-align: center;">Column 3</th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <script type="text/javascript">
        var collection = {};
        var collectionTable = $('#collection');
        var collectionDataTable = null;

        $(function() {
            collectionDataTable = collectionTable.DataTable({
                serverSide: true,
                processing: true,
                ajax: {
                    url: "<?= $rootURL ?>/page1/list"
                },
                order: [[ 0, "asc" ]],
                responsive: true,
                dom: 'Bfrtip',
                buttons: [
                    'pageLength', 'colvis'
                ],
                columnDefs: [
                    {
                        className: "dt-center",
                        targets: '_all'
                    },
                    {
                        orderable: true,
                        targets: [1, 2]
                    }
                ],
                language: {
                    emptyTable: "No reports have been added"
                },
                pagingType: "full_numbers",
                columns: [
                    {
                        data: 'name',
                        render: function ( data, type ) {
                            if (type === 'display' || type === 'filter') {
                                return "<a href='<?= $rootURL ?>/page1/putpagehere'>" + data + "</a>";
                            } else {
                                return data;
                            }
                        }
                    },
                    {
                        data: 'date',
                        render: function ( data, type ) {
                            if (type === 'display'  || type === 'filter' ) {
                                return (data !== null) ? moment.unix(data).format('MM/DD/YYYY') : '';
                            } else {
                                return data;
                            }
                        }
                    },
                    {
                        data: 'todo_items'
                    },
                    {
                        data: 'signed'
                    }
                ]
            });
        });
    </script>
<?php
include_once __DIR__ . '/../_footer.php';