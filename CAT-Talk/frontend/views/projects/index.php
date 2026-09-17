<?php
/** @var UserSession $userSession */
$page = "projects";

include_once __DIR__ . '/../_header.php';
?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Projects</h1>
        <div class="btn-toolbar mb-2 mb-md-0">
            <button type="button" class="btn btn-success" onclick="showProjectModal()">
                <i class="fas fa-plus mr-1"></i> Create New Project
            </button>
        </div>
    </div>

    <div class="mb-3">
        <label for="projectsLength" class="form-label">Projects per page</label>
        <select id="projectsLength" class="form-select" onchange="loadProjects()">
            <option value="5">5</option>
            <option value="10" selected>10</option>
            <option value="20">20</option>
            <option value="50">50</option>
            <option value="100">100</option>
        </select>
    </div>
    
    <div class="projects-grid">
        <!-- Projects will be dynamically loaded here -->
    </div>

    <div class="modal" id="projectModal" tabindex="-1" role="dialog" aria-labelledby="projectModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="projectModalLabel">New Project</h5>
                    <button type="button" class="btn btn-close" data-dismiss="modal" aria-label="Close" onclick="hideProjectModal()"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <input type="hidden" id="projectModalId" value="" />
                        <div class="col-sm-12 mb-3 form-floating">
                            <input class="form-control" type="text" style="pointer-events: auto;" id="projectModalName" placeholder="Project Name" />
                            <label for="projectModalName">Project Name</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" onclick="hideProjectModal()">Close</button>
                    <button type="button" class="btn btn-primary" onclick="submitProject()">Create Project</button>
                </div>
            </div>
        </div>
    </div>

    <script type="text/javascript">
        var _modal = $('#projectModal');
        var _modalId = $('#projectModalId');
        var _modalName = $('#projectModalName');
        var modalQueueName = $('#projectModalQueueName');
        var modalScript = $('#projectModalScript');

        _modal.on('hidden.bs.modal', function() {
            _modalId.val('');
            _modalName.val('');
        });

        function showProjectModal() {
            $("#projectModal").modal("show");
        }

        function hideProjectModal() {
            $("#projectModal").modal("hide");
        }

        function submitProject() {
            if (_modalName.val() === null || _modalName.val() === '') {
                showError('You must provide a name for the task');
                return;
            }
            if (modalQueueName.val() === null || modalQueueName.val() === '') {
                showError('You must provide a queue name for the task');
                return;
            }
            if (modalScript.val() === null || modalScript.val() === '') {
                showError('You must provide a script for the task');
                return;
            }
            var data = {
                'name': _modalName.val(),
            }
            if (_modalId.val() !== null && _modalId.val() !== '') {
                data['id'] = _modalId.val()
            }
            $.ajax({
                type: 'POST',
                url: '<?= $rootURL ?>/projects/save',
                data: data,
                dataType: 'json',
                success: function(data) {
                    $("#projectModal").modal("hide");
                    showSuccess('Successfully created project');
                    loadProjects();
                },
                error: function(xhr, textStatus, errorThrown) {
                    if (xhr.responseJSON && xhr.responseJSON.error) {
                        showError(xhr.responseJSON.error);
                    } else {
                        showError("Error submitting project. Try again...");
                    }
                }
            });
        }


        function deleteProject(id) {
            if (!id) {
                showError('Invalid project ID.');
                return;
            }

            if (confirm("Are you sure you want to delete this project?")) {
                $.ajax({
                    type: 'POST',
                    url: '<?= $rootURL ?>/projects/delete',
                    data: { id: id },
                    success: function(response) {
                        showSuccess("Project deleted successfully.");
                        loadProjects();
                    },
                    error: function(error) {
                        console.error("Error deleting project:", error);
                        showError("Failed to delete project.");
                    }
                });
            }
        }

        function loadProjects() {
            var length = $('#projectsLength').val(); // Get the selected length
            var start = 0; // You can update this to handle pagination if needed

            $.ajax({
                url: "<?= $rootURL ?>/projects/list",
                type: "GET",
                data: {
                    start: start,
                    length: length,  // Pass selected length here
                    search: $("#searchField").val()  // Add search filter if you have one
                },
                dataType: "json",
                success: function(response) {
                    const projectsGrid = $(".projects-grid");
                    projectsGrid.empty();

                    if (response.data && response.data.length > 0) {
                        response.data.forEach(project => {
                            const projectCard = `
                                <div class="project-card">
                                    <h3 class="project-title">${project.name}</h3>
                                    <div class="project-actions">
                                        <a href="<?= $rootURL ?>/projects/${project.id}/details" class="btn btn-info">Details</a>
                                        <a href="<?= $rootURL ?>/projects/dashboard/${project.id}" class="btn btn-primary">Dashboard</a>
                                        <button class="btn btn-danger" onclick="deleteProject('${project.id}')">Delete</button>
                                    </div>
                                </div>`;
                            projectsGrid.append(projectCard);
                        });
                    } else {
                        projectsGrid.html("<p>No projects available.</p>");
                    }
                },
                error: function(error) {
                    console.error("Error fetching projects:", error);
                }
            });
        }

        $(function() {
            loadProjects(); 

            // Call loadProjects when the projectsLength dropdown value changes
            $('#projectsLength').change(function() {
                loadProjects();
            });
        });
    </script>
<?php
include_once __DIR__ . '/../_footer.php';