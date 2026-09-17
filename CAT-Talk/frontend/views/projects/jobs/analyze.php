<?php
/** @var UserSession $userSession */
$page = 'analyze';
include_once __DIR__ . '/../../_header.php';
?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analyze</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script> -->
    <!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js"></script> -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
      @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
      }
    </style>
</head>

<head>
  <title>Analyze</title>
</head>
<body>
    <div class="d-flex justify-content-left flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
      <button onclick="window.location.href='<?= $rootURL ?>/projects/<?= $projectId ?>/collections/<?= $collectionCode ?>'" class="btn btn-secondary me-2" ><i class="fa-solid fa-arrow-left"></i> Back</button>
    </div>
    <h1>Analyze</h1>
    <div class="card-subheading">
      This page allows you to run custom jobs on a generated transcript.
    </div>
    <!-- <h2>Select Process</h2> -->
    <div class="job-select-dropdown">
      <!-- <a class="btn btn-secondary dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        Select a job
      </a> -->
      <ul class="dropdown-menu" id="job-menu">
        <!-- Jobs appended here -->
    </ul>
  </div>




  <div class="analysis-container">
    <div class="analysis-section">
      <h2>Transcript</h2>
      <pre id="fileContent" class="line-numbers"><code id="codeContent" class="language-php">Loading file content...</code></pre>
      <!-- <div>
        <label for="highlightLines">Highlight Lines (comma-separated):</label>
        <input type="text" id="highlightLines" placeholder="e.g. 1,3-5,7">
        <button id="applyHighlight">Highlight</button>
      </div> -->
    </div>
    <div class="prompt-section">
      <h2>Prompt</h2>
      <label for="job-name">Job Name:</label>
      <input type="text" id="job-name" placeholder="No spaces allowed" />
      <br />
      <!-- 
      <label for="system-prompt">System Prompt:</label>
      <input type="text" id="system-prompt" placeholder="You are a helpful assistant" />
      <br /> -->
      <label for="user-prompt">User Prompt:</label>
      <!-- <input type="text" id="user-prompt" placeholder="Instructions for the model" /> -->
      <textarea type="text" rows="5" cols="50" id="user-prompt" placeholder="Instructions for the model"></textarea>
      <!-- <br />
      <label for="output-format">Output Format:</label>
      <input type="text" id="output-format" placeholder="Leave empty for human-readable format" /> -->
      <br />
      <div class="flex-container" style="display: flex; align-items: center; gap: 10px;">
        <!-- <label for="combine">Combine:</label> -->
        <!-- <input type="checkbox" id="combine-output" /> -->
        <!-- <br /> -->
      </div>
      <!-- <div class="card-subheading">Check 'Combine' if the individual outputs of the LLM should be combined into a single report.<br>This is useful for generating meeting minutes or a summary of the entire transcript.</div> -->
      <br>
      <button class="btn btn-secondary" id="process-button">Process</button>
    </div>
    
  </div>
  <div class="output-container">
      <h2>Output</h2>
      <pre id="analysis-output" class="line-numbers language-php" tabindex="0" style="white-space: pre-wrap; word-break: break-word;"><code id="outputContent" class="language-php"></code></pre>
    </div>

  <!-- Output format modal -->
  <div class="modal fade" id="schemaModal" tabindex="-1" aria-labelledby="schemaModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="schemaModalLabel">Add JSON Schema Field</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="json-schema-form">
            <div class="mb-3">
              <label for="field-name" class="form-label">Field Name</label>
              <input type="text" class="form-control" id="field-name" placeholder="Enter field name" required>
            </div>
            <div class="mb-3">
              <label for="field-description" class="form-label">Field Description</label>
              <textarea class="form-control" id="field-description" rows="3" placeholder="Enter field description" required></textarea>
            </div>
            <div class="mb-3">
              <label for="field-type" class="form-label">Variable Type</label>
              <select class="form-select" id="field-type" required>
                <option value="">Choose type</option>
                <option value="string">String</option>
                <option value="number">Number</option>
                <option value="boolean">Boolean</option>
                <option value="array">Array</option>
                <option value="object">Object</option>
              </select>
            </div>
          </form>
          
          <!-- List of added schema fields -->
          <ul id="schema-list" class="list-group mt-3">
            <!-- Schema fields will be dynamically inserted here -->
          </ul>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="button" class="btn btn-primary" id="add-field">Add Field</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    $(document).ready(function() {
      document.getElementById('job-name').addEventListener('keypress', function(event) {
        if (event.key === ' ') {
          event.preventDefault(); // Prevent spaces in job name
        }
      });

      const transcriptEditor = document.getElementById('transcript-editor');
      let systemPrompt_tmp = document.getElementById('system-prompt');
      let promptValue = systemPrompt_tmp?.value?.trim(); // Check for value, ensuring no extra spaces
      const systemPrompt = promptValue || "You are a helpful assistant";
      const userPrompt = document.getElementById('user-prompt');
      const processButton = document.getElementById('process-button');
      const outputDisplay = document.getElementById('analysis-output');
      const schemaModal = new bootstrap.Modal(document.getElementById('schemaModal')); // Initialize the schema modal
      let schemaFields = []; // Array to store schema fields

      var fileName = "<?=$file_name?>";
      var collectionCode = '<?= $collectionCode ?>';
      var projectId = '<?= $projectId ?>';
      var fileId = "<?=$fileId?>";
      var rootURL = "<?=$rootURL?>";
      let selectedJob = "";

      $.ajax({
            url: rootURL+'/files/get-jobs',
            method: 'POST',
            data: {
                'project_id': projectId,
                'file_id': fileId
            },
            success: function(response) {
                if (!response.success) {
                  showError(response.error_message);
                } else {
                  let jobsArray = response.jobs
                  if (jobsArray.length > 0) {
                    jobsArray.forEach(function(item) {
                      $('#job-menu').append('<li><a class="dropdown-item" href="#">' + item + '</a></li>');
                    })
                    $('.dropdown-item').on('click', function(event) {
                        if ($(this).text() == 'describalizer') {
                          selectedItem = $(this).text(); // Store the selected item's text in the global variable
                          selectedJob = selectedItem;
                          fetchFileContent(projectId, collectionCode, fileId+'_describalize.txt', '#outputContent');
                        }
                        else {
                          // Get the text of the selected item
                          selectedItem = $(this).text(); // Store the selected item's text in the global variable
                          selectedJob = selectedItem;
                          fetchJob(projectId, collectionCode, selectedJob)
                          fetchFileContent(projectId, collectionCode, 'custom-'+selectedJob+'-'+fileId+'.report', '#outputContent');
                        }
                    });
                    
                  }
                }
            }
        });

      function updateStatus(uuid, status, action) {
        $.ajax({
            url: rootURL+'/files/update',
            method: 'POST',
            data: {
                'uuid': uuid,
                'status': status,
                'action': action
            },
            success: function(response) {
                if (!response.success) {
                    showError(response.error_message);
                }
            }
        });
    }

      function startJob(fileId, filepath, jobName, system_prompt, user_prompt, outputFormat, combine) {
        if (jobName.includes(" ")) {
          showError("Job Name cannot contain a space");
          processButton.disabled = false;
          processButton.textContent = 'Process';
          return;
        }

        let success = false;
        let data = {
          'file_ids': [fileId],
          'filepath': filepath,
          'job_name': jobName,
          'system_prompt':system_prompt,
          'user_prompt': user_prompt,
          'output_format': outputFormat,
          'parameters': {
            'combine': combine
          }
        };

        if (typeof synchronifier_transcript_file !== 'undefined') {
          data.synchronifier_transcript_file = synchronifier_transcript_file;
        }
        
        $.ajax({
            url: rootURL+'/job/start-custom',
            method: 'POST',
            data: data,
            success: function(response) {
                // If response is an array (as in [{ file_id: ..., success: true, error_message: "" }])
                if (Array.isArray(response)) {
                  // Check if at least one job succeeded
                  let anySuccess = response.some(r => r.success);
                  if (anySuccess) {
                    updateStatus(fileId, "Started", jobName);
                    showSuccess("Job submitted");
                  } else {
                    // Show the first error message if available
                    let firstError = response.find(r => !r.success && r.error_message);
                    showError(firstError ? firstError.error_message : "Job failed to start.");
                  }
                  processButton.disabled = false;
                  processButton.textContent = 'Process';
                  return;
                }
                // If response is an object (legacy or single-job)
                if (response && response.success) {
                  updateStatus(fileId, "Started", jobName);
                  showSuccess("Job submitted");
                } else {
                  processButton.disabled = false;
                  processButton.textContent = 'Process';
                  showError(response && response.error_message ? response.error_message : "Job failed to start.");
                }
            },
            error: function() {
                showError("Server error!");
            }
        });

      }

      // Fetch file content function
      function fetchFileContent(projectId, collectionCode, fileName, divId) {
        $.ajax({
          url: `/files/content?project_id=${encodeURIComponent(projectId)}&collection_code=${encodeURIComponent(collectionCode)}&file_name=${encodeURIComponent(fileName)}`,
          method: 'GET',
          success: function(response) {
            if (response.content) {
	      $(divId).text(response.content);
              Prism.highlightElement(document.getElementById(divId.slice(1)));
            } else if (response.error_message) {
              $(divId).text(response.error_message);
            } else {
              $(divId).text('Unexpected error occurred.');
            }
          },
          error: function() {
            $(divId).text('Error fetching file content.');
          }
        });
      }

      function updateSchema(fieldName, fieldDescription, fieldType) {
        if (fieldName && fieldDescription && fieldType) {
          const newField = {
            name: fieldName,
            description: fieldDescription,
            type: fieldType
          };
          schemaFields.push(newField);

          // Reset the form and close the modal
          document.getElementById('json-schema-form').reset();
          schemaModal.hide();

          // Update the output format and render the schema fields
          updateOutputFormat();
          renderSchemaFields();
        } else {
          showError('Please fill in all fields.');
        }
      }

      function fetchJob(projectId, collectionCode, selectedJob) {
        $.ajax({
          url: `/jobs/get-fields?project_id=${encodeURIComponent(projectId)}&collection_code=${encodeURIComponent(collectionCode)}&file_id=${encodeURIComponent(fileId)}&job_name=${encodeURIComponent(selectedJob)}`,
          method: 'GET',
          success: function(response) {
            if (!response.error) {
              $('#job-name').val(response.jobName);
              $('#system-prompt').val(response.systemPrompt);
              $('#user-prompt').val(response.userPrompt);
              $('#output-format').val(response.outputFormat);
              if (response.outputFormat.length > 0) {
                outputFormatArray = JSON.parse(response.outputFormat);
                outputFormatArray.forEach(item => {
                  updateSchema(item.name, item.description, item.type);
                });
              }
              $('#combine-output').prop('checked', response.combine);
            } else {
              showError(response.error);
            }
          },
          error: function() {
            showError("Server error");
          }
        })
      }

      // Open the modal when focus is on the output format input
      $('#output-format').focus(function() {
        schemaModal.show();
      });

      // Handle adding a new field to the schema
      $('#add-field').click(function() {
        const fieldName = document.getElementById('field-name').value.trim();
        const fieldDescription = document.getElementById('field-description').value.trim();
        const fieldType = document.getElementById('field-type').value;

        if (fieldName && fieldDescription && fieldType) {
          const newField = {
            name: fieldName,
            description: fieldDescription,
            type: fieldType
          };
          schemaFields.push(newField);

          // Reset the form and close the modal
          document.getElementById('json-schema-form').reset();
          schemaModal.hide();

          // Update the output format and render the schema fields
          updateOutputFormat();
          renderSchemaFields();
        } else {
          showError('Please fill in all fields.');
        }
      });

      // Update the output format with the schema fields in JSON format
      function updateOutputFormat() {
        if (schemaFields.length == 0) {
          $('#output-format').val("");
        } else {
          $('#output-format').val(JSON.stringify(schemaFields, null, 2));
        }        
      }

      // Render the schema fields (optional: for displaying fields)
      function renderSchemaFields() {
        const schemaList = document.getElementById('schema-list');
        schemaList.innerHTML = ''; // Clear current list

        // Append all fields to the list
        schemaFields.forEach((field, index) => {
          const listItem = document.createElement('li');
          listItem.classList.add('list-group-item');

          listItem.innerHTML = `
            <strong>${field.name}</strong> (${field.type}): ${field.description}
            <button type="button" class="btn btn-danger btn-sm float-end" data-index="${index}">Delete</button>
          `;

          // Add event listener to the delete button
          listItem.querySelector('button').addEventListener('click', function() {
            const deleteIndex = this.getAttribute('data-index');
            schemaFields.splice(deleteIndex, 1);
            renderSchemaFields();
            updateOutputFormat();
          });

          schemaList.appendChild(listItem);
        });        
      }
      
      // Check if file name exists and fetch content
      if (fileName) {
        fileExtension = fileName.split('.').pop();
        fetchFileContent(projectId, collectionCode, fileId+'.transcript', '#codeContent');
        if (selectedJob.length > 0) {
          fetchFileContent(projectId, collectionCode, 'custom-'+selectedJob+'-'+fileId+'.report', '#outputContent');
        } else {
          $('#analysis').text('Output will be displayed here')
        }
      } else {
        $('#codeContent').text('No file specified.');
      }

      // Apply highlight for specific lines in the code content
      $('#applyHighlight').on('click', function() {
        // Remove existing highlights
        $('.line-highlight').remove();

        // Parse the input for line numbers
        var linesToHighlight = $('#highlightLines').val().split(',').map(function(range) {
          if (range.includes('-')) {
            var parts = range.split('-').map(Number);
            return Array.from({length: parts[1] - parts[0] + 1}, (_, i) => parts[0] + i);
          }
          return [Number(range)];
        }).flat().filter(Boolean);

        // Apply highlights
        linesToHighlight.forEach(function(lineNumber) {
          var lineElement = $(`pre.line-numbers code.language-php .line-numbers-rows > span:nth-child(${lineNumber})`);
          if (lineElement.length) {
            lineElement.addClass('line-highlight');
          }
        });
      });
    
      // Streaming LLM request (SSE/JSON style)
      async function streamLLMRequest(prompt, systemPrompt, model = 'DeepSeek-R1') {
        const outputElem = document.getElementById('outputContent');
        // Show loading spinner until response starts
        outputElem.innerHTML = '<span class="llm-loading-spinner" style="display:inline-block;width:1.5em;height:1.5em;border:3px solid #ccc;border-top:3px solid #007bff;border-radius:50%;animation:spin 1s linear infinite;vertical-align:middle;"></span> <span style="color:#888;">Waiting for response...</span>';
        outputElem.setAttribute('data-placeholder', 'Output will be displayed here');
        let completeResponse = '';
        let responseStarted = false;
        const controller = new AbortController();
        const { signal } = controller;
        try {
          const response = await fetch(rootURL + '/llm/stream', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              prompt: prompt,
              system_prompt: systemPrompt,
              model: model
            }),
            signal
          });
          if (!response.ok) {
            outputElem.textContent = 'Error: ' + response.status;
            return;
          }
          const reader = response.body.getReader();
          const decoder = new TextDecoder();
          while (true) {
            const { done, value } = await reader.read();
            if (done) break;
            const chunk = decoder.decode(value, { stream: true });
            // Parse SSE lines
            const lines = chunk.split('\n');
            let foundDataLine = false;
            for (const line of lines) {
              if (line.startsWith('data: ')) {
                foundDataLine = true;
                const data = line.substring(6);
                if (data === '[DONE]') continue;
                // Only try to parse as JSON if it looks like JSON
                if (data.trim().startsWith('{') || data.trim().startsWith('[')) {
                  try {
                    const jsonData = JSON.parse(data);
                    const content = jsonData.choices?.[0]?.delta?.content || '';
                    if (content) {
                      completeResponse += content;
                      responseStarted = true;
                      if (window.marked) {
                        outputElem.innerHTML = window.marked.parse(completeResponse);
                      } else {
                        outputElem.innerText = completeResponse;
                      }
                      outputElem.parentElement.scrollTop = outputElem.parentElement.scrollHeight;
                    }
                  } catch (e) {
                    completeResponse += data;
                    responseStarted = true;
                    if (window.marked) {
                      outputElem.innerHTML = window.marked.parse(completeResponse);
                    } else {
                      outputElem.innerText = completeResponse;
                    }
                    outputElem.parentElement.scrollTop = outputElem.parentElement.scrollHeight;
                  }
                } else {
                  completeResponse += data;
                  responseStarted = true;
                  if (window.marked) {
                    outputElem.innerHTML = window.marked.parse(completeResponse);
                  } else {
                    outputElem.innerText = completeResponse;
                  }
                  outputElem.parentElement.scrollTop = outputElem.parentElement.scrollHeight;
                }
              }
            }
            // If no data: line found, just append the chunk as plain text
            if (!foundDataLine) {
              // Only display content after </think>
              let displayChunk = chunk;
              const thinkTag = '</think>';
              const thinkIdx = chunk.indexOf(thinkTag);
              if (thinkIdx !== -1) {
                displayChunk = chunk.substring(thinkIdx + thinkTag.length);
              } else {
                // If </think> not found and nothing has been displayed yet, don't show anything
                if (!completeResponse) displayChunk = '';
              }
              if (displayChunk && displayChunk.length > 0) responseStarted = true;
              completeResponse += displayChunk;
              if (window.marked) {
                outputElem.innerHTML = window.marked.parse(completeResponse);
              } else {
                outputElem.innerText = completeResponse;
              }
              outputElem.parentElement.scrollTop = outputElem.parentElement.scrollHeight;
            }
            // Remove spinner as soon as response starts
            if (responseStarted) {
              outputElem.classList.remove('llm-loading');
            }
          } // end while(true)
          // Highlight only after streaming is done
          Prism.highlightElement(outputElem);
        } catch (err) {
          outputElem.textContent = 'Error: ' + err;
        }
        return controller;
      }

      // Replace processButton click handler for streaming
      processButton.addEventListener('click', async function() {
        const userPrompt = document.getElementById('user-prompt').value.trim();
        let systemPrompt_tmp = document.getElementById('system-prompt');
        let promptValue = systemPrompt_tmp?.value?.trim();
        const systemPrompt = promptValue || "You are a helpful assistant";
        // Get transcript from the transcript section
        const transcript = document.getElementById('codeContent').innerText || '';
        // Compose the prompt as requested
        const combinedPrompt = `transcript:\n\n${transcript}\nuser request:\n\n${userPrompt}`;
        // Validate inputs
        if (!userPrompt) {
          showError('Please enter a prompt.');
          return;
        }
        processButton.disabled = true;
        processButton.textContent = 'Processing...';
        await streamLLMRequest(combinedPrompt, systemPrompt);
        processButton.disabled = false;
        processButton.textContent = 'Process';
      });
    
    
    });
  </script>
  </body>

<?php
  include_once __DIR__ . '/../../_footer.php';
?>
