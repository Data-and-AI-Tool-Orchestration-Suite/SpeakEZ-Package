<?php
/** @var UserSession $userSession */
$page = 'analyze';
include_once __DIR__ . '/../../_header.php';
?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Touch-Up</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script> -->
    <!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.js"></script> -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/line-numbers/prism-line-numbers.min.css">
</head>

<body>
    <div class="d-flex justify-content-left flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
      <button onclick="window.location.href='<?= $rootURL ?>/projects/<?= $projectId ?>/collections/<?= $collectionCode ?>'" class="btn btn-secondary me-2" ><i class="fa-solid fa-arrow-left"></i> Back</button>
    </div>
    <h1>Touch-Up</h1>
    <div class="card-subheading">
      This page allows you to edit a transcript in-browser<br>
      Click and type on the transcript to edit it. Then click 'Save.'
    </div>

  <div class="touchup-container">
    <div class="touchup-section">
      <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <button class="btn btn-secondary" id="save-button">Save
          <span id="saveBtnSpinner" class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true" style="display: none;"></span>
        </button>
      </div>
      <pre id="touchupFileContent" class="line-numbers touchup-fullscreen" style="white-space: pre-wrap; word-break: break-word;"><code id="codeContent" class="language-php touchup-fullscreen-code" contenteditable="true" style="white-space: pre-wrap; word-break: break-word;">Loading file content...</code></pre>
    </div>
  </div>

  <script>
    $(document).ready(function() {
    
      const transcriptEditor = document.getElementById('transcript-editor');
      // const mergeButton = document.getElementById('merge-button');
      const saveButton = document.getElementById('save-button');
      const outputDisplay = document.getElementById('analysis-output');
      let schemaFields = []; // Array to store schema fields
      let hashedContent = "";

      var fileName = "<?=$file_name?>";
      var collectionCode = '<?= $collectionCode ?>';
      var projectId = '<?= $projectId ?>';
      var fileId = "<?=$fileId?>";
      var rootURL = "<?=$rootURL?>";
      let selectedJob = "";

      var MD5 = function(d){var r = M(V(Y(X(d),8*d.length)));return r.toLowerCase()};function M(d){for(var _,m="0123456789ABCDEF",f="",r=0;r<d.length;r++)_=d.charCodeAt(r),f+=m.charAt(_>>>4&15)+m.charAt(15&_);return f}function X(d){for(var _=Array(d.length>>2),m=0;m<_.length;m++)_[m]=0;for(m=0;m<8*d.length;m+=8)_[m>>5]|=(255&d.charCodeAt(m/8))<<m%32;return _}function V(d){for(var _="",m=0;m<32*d.length;m+=8)_+=String.fromCharCode(d[m>>5]>>>m%32&255);return _}function Y(d,_){d[_>>5]|=128<<_%32,d[14+(_+64>>>9<<4)]=_;for(var m=1732584193,f=-271733879,r=-1732584194,i=271733878,n=0;n<d.length;n+=16){var h=m,t=f,g=r,e=i;f=md5_ii(f=md5_ii(f=md5_ii(f=md5_ii(f=md5_hh(f=md5_hh(f=md5_hh(f=md5_hh(f=md5_gg(f=md5_gg(f=md5_gg(f=md5_gg(f=md5_ff(f=md5_ff(f=md5_ff(f=md5_ff(f,r=md5_ff(r,i=md5_ff(i,m=md5_ff(m,f,r,i,d[n+0],7,-680876936),f,r,d[n+1],12,-389564586),m,f,d[n+2],17,606105819),i,m,d[n+3],22,-1044525330),r=md5_ff(r,i=md5_ff(i,m=md5_ff(m,f,r,i,d[n+4],7,-176418897),f,r,d[n+5],12,1200080426),m,f,d[n+6],17,-1473231341),i,m,d[n+7],22,-45705983),r=md5_ff(r,i=md5_ff(i,m=md5_ff(m,f,r,i,d[n+8],7,1770035416),f,r,d[n+9],12,-1958414417),m,f,d[n+10],17,-42063),i,m,d[n+11],22,-1990404162),r=md5_ff(r,i=md5_ff(i,m=md5_ff(m,f,r,i,d[n+12],7,1804603682),f,r,d[n+13],12,-40341101),m,f,d[n+14],17,-1502002290),i,m,d[n+15],22,1236535329),r=md5_gg(r,i=md5_gg(i,m=md5_gg(m,f,r,i,d[n+1],5,-165796510),f,r,d[n+6],9,-1069501632),m,f,d[n+11],14,643717713),i,m,d[n+0],20,-373897302),r=md5_gg(r,i=md5_gg(i,m=md5_gg(m,f,r,i,d[n+5],5,-701558691),f,r,d[n+10],9,38016083),m,f,d[n+15],14,-660478335),i,m,d[n+4],20,-405537848),r=md5_gg(r,i=md5_gg(i,m=md5_gg(m,f,r,i,d[n+9],5,568446438),f,r,d[n+14],9,-1019803690),m,f,d[n+3],14,-187363961),i,m,d[n+8],20,1163531501),r=md5_gg(r,i=md5_gg(i,m=md5_gg(m,f,r,i,d[n+13],5,-1444681467),f,r,d[n+2],9,-51403784),m,f,d[n+7],14,1735328473),i,m,d[n+12],20,-1926607734),r=md5_hh(r,i=md5_hh(i,m=md5_hh(m,f,r,i,d[n+5],4,-378558),f,r,d[n+8],11,-2022574463),m,f,d[n+11],16,1839030562),i,m,d[n+14],23,-35309556),r=md5_hh(r,i=md5_hh(i,m=md5_hh(m,f,r,i,d[n+1],4,-1530992060),f,r,d[n+4],11,1272893353),m,f,d[n+7],16,-155497632),i,m,d[n+10],23,-1094730640),r=md5_hh(r,i=md5_hh(i,m=md5_hh(m,f,r,i,d[n+13],4,681279174),f,r,d[n+0],11,-358537222),m,f,d[n+3],16,-722521979),i,m,d[n+6],23,76029189),r=md5_hh(r,i=md5_hh(i,m=md5_hh(m,f,r,i,d[n+9],4,-640364487),f,r,d[n+12],11,-421815835),m,f,d[n+15],16,530742520),i,m,d[n+2],23,-995338651),r=md5_ii(r,i=md5_ii(i,m=md5_ii(m,f,r,i,d[n+0],6,-198630844),f,r,d[n+7],10,1126891415),m,f,d[n+14],15,-1416354905),i,m,d[n+5],21,-57434055),r=md5_ii(r,i=md5_ii(i,m=md5_ii(m,f,r,i,d[n+12],6,1700485571),f,r,d[n+3],10,-1894986606),m,f,d[n+10],15,-1051523),i,m,d[n+1],21,-2054922799),r=md5_ii(r,i=md5_ii(i,m=md5_ii(m,f,r,i,d[n+8],6,1873313359),f,r,d[n+15],10,-30611744),m,f,d[n+6],15,-1560198380),i,m,d[n+13],21,1309151649),r=md5_ii(r,i=md5_ii(i,m=md5_ii(m,f,r,i,d[n+4],6,-145523070),f,r,d[n+11],10,-1120210379),m,f,d[n+2],15,718787259),i,m,d[n+9],21,-343485551),m=safe_add(m,h),f=safe_add(f,t),r=safe_add(r,g),i=safe_add(i,e)}return Array(m,f,r,i)}function md5_cmn(d,_,m,f,r,i){return safe_add(bit_rol(safe_add(safe_add(_,d),safe_add(f,i)),r),m)}function md5_ff(d,_,m,f,r,i,n){return md5_cmn(_&m|~_&f,d,_,r,i,n)}function md5_gg(d,_,m,f,r,i,n){return md5_cmn(_&f|m&~f,d,_,r,i,n)}function md5_hh(d,_,m,f,r,i,n){return md5_cmn(_^m^f,d,_,r,i,n)}function md5_ii(d,_,m,f,r,i,n){return md5_cmn(m^(_|~f),d,_,r,i,n)}function safe_add(d,_){var m=(65535&d)+(65535&_);return(d>>16)+(_>>16)+(m>>16)<<16|65535&m}function bit_rol(d,_){return d<<_|d>>>32-_}

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

      // Fetch file content function
      function fetchFileContent(projectId, collectionCode, fileName, divId) {
      $.ajax({
        url: `/files/content?project_id=${encodeURIComponent(projectId)}&collection_code=${encodeURIComponent(collectionCode)}&file_name=${encodeURIComponent(fileName)}`,
        method: 'GET',
        success: function (response) {
          hashedContent = MD5(unescape(encodeURIComponent(response.content)));
          if (response.content) {
            if (response.content === "\n" || response.content ===  "") { 
              $(divId).text(" ");
            } else {
              $(divId).text(response.content);
            }
            Prism.highlightElement(document.querySelector(divId));
          } else if (response.error_message) {
            $(divId).text(response.error_message);
          } else {
            $(divId).text('Unexpected error occurred.');
          }
        },
        error: function () {
          $(divId).text('Error fetching file content.');
        }
      });
    }

    function saveFileContent(projectId, collectionCode, fileName, content) {
      let spinner = $('#saveBtnSpinner');
      spinner.show();
      let newHashedContent = MD5(unescape(encodeURIComponent(content)));
      if (newHashedContent == hashedContent) {
        showError("No changes have been made.");
        spinner.hide();
        return;
      }
      hashedContent = newHashedContent;
      $.ajax({
            url: rootURL+'/files/save-content',
            method: 'POST',
            data: {
                'project_id': projectId,
                'collection_code': collectionCode,
                'file_name': fileName,
                'content': content
            },
            success: function(response) {
                if (!response.success) {
                  spinner.hide();
                  showError(response.error_message);
                } else {
                  spinner.hide();
                  showSuccess("File saved");
                }
            },
            error: function() {
              spinner.hide();
              showError("Server error");
            }

        });
      }

      // Check if file name exists and fetch content
      if (fileName) {
        fileExtension = fileName.split('.').pop();
        fetchFileContent(projectId, collectionCode, fileId+'.transcript', '#codeContent');
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
    
      saveButton.addEventListener('click', function() {
        let newFileContent = document.getElementById('codeContent').innerText;
        saveFileContent(projectId, collectionCode, fileId+'.transcript', newFileContent);
      });
    });
  </script>
  </body>

<?php
  include_once __DIR__ . '/../../_footer.php';
?>
