<?php
/** @var UserSession $userSession */
$page = 'analyze_chat';
include_once __DIR__ . '/../../_header.php';
?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LLM Chat with Transcript</title>
</head>
<body>
    <div class="d-flex justify-content-left flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
      <button onclick="window.location.href='<?= $rootURL ?>/projects/<?= $projectId ?>/collections/<?= $collectionCode ?>'" class="btn btn-secondary me-2" ><i class="fa-solid fa-arrow-left"></i> Back</button>
    </div>
  <div class="main-flex">
    <div class="transcript-section" style="min-width: 250px; max-width: 320px;">
      <h5>Transcript</h5>
      <div class="transcript-content-scroll" id="transcriptContent">Loading transcript...</div>
      <hr>
      <h6>Conversations</h6>
      <div id="conversationList" style="overflow-y:auto; max-height:200px; margin-bottom:1rem;"></div>
      <button id="refreshConvosBtn" class="btn btn-sm btn-outline-secondary mb-2">Refresh List</button>
    </div>
    <div class="chat-container">
      <div class="chat-header">
        <h4 class="mb-0"><i class="fa-solid fa-comments"></i> Chat with an LLM about this file</h4>
      </div>
      <div class="chat-messages" id="chatMessages"></div>
      <form class="chat-input-area" id="chatForm" autocomplete="off">
        <input type="text" class="chat-input" id="chatInput" placeholder="Type your question about the transcript..." autocomplete="off" required />
        <!-- <input type="text" class="chat-input" id="convoNameInput" placeholder="Conversation name (e.g. 'Initial Review')" style="max-width:200px;" required /> -->
        <button type="submit" class="btn btn-primary chat-send-btn"><i class="fa-solid fa-paper-plane"></i></button>
      </form>
    </div>
  </div>
  <script>
    // --- Transcript loading ---
    var fileId = "<?=$fileId?>";
    var projectId = "<?=$projectId?>";
    var collectionCode = "<?=$collectionCode?>";
    var rootURL = "<?=$rootURL?>";
    let transcriptLoaded = '';
    
    function loadTranscript() {
      $.ajax({
        url: rootURL + '/files/content?project_id=' + encodeURIComponent(projectId) + '&collection_code=' + encodeURIComponent(collectionCode) + '&file_name=' + encodeURIComponent(fileId + '.transcript'),
        method: 'GET',
        success: function(response) {
          if (response.content) {
            $('#transcriptContent').text(response.content);
            transcriptLoaded = response.content;
          } else if (response.error_message) {
            $('#transcriptContent').text(response.error_message);
            transcriptLoaded = '';
          } else {
            $('#transcriptContent').text('Transcript not found.');
            transcriptLoaded = '';
          }
        },
        error: function() {
          $('#transcriptContent').text('Error loading transcript.');
          transcriptLoaded = '';
        }
      });
    }
    loadTranscript();

    // --- Chat logic ---
    let chatHistory = [];
    let isStreaming = false;
    let transcriptSent = false;
    let convoId = null;

    // Updated appendMessage to support injecting historical thoughts
    function appendMessage(role, content, isMarkdown = false, isStreaming = false, thoughtContent = null) {
      const chatMessages = document.getElementById('chatMessages');
      const msgDiv = document.createElement('div');
      msgDiv.className = 'chat-message ' + (role === 'user' ? 'user' : 'llm');
      const bubble = document.createElement('div');
      bubble.className = 'chat-bubble';
      
      if (isStreaming) {
        bubble.innerHTML = '<span class="chat-spinner"></span> <span style="color:#888;">LLM is thinking...</span>';
      } else {
        // If there is reasoning content, display it in a distinct box
        if (thoughtContent) {
            const tDiv = document.createElement('div');
            tDiv.style.backgroundColor = '#f8f9fa';
            tDiv.style.borderLeft = '4px solid #6c757d';
            tDiv.style.padding = '8px 12px';
            tDiv.style.marginBottom = '10px';
            tDiv.style.fontSize = '0.9em';
            tDiv.style.color = '#6c757d';
            tDiv.style.whiteSpace = 'pre-wrap';
            tDiv.innerText = thoughtContent;
            bubble.appendChild(tDiv);
        }
        
        const cDiv = document.createElement('div');
        if (isMarkdown && window.marked) {
          cDiv.innerHTML = window.marked.parse(content);
        } else {
          cDiv.textContent = content;
        }
        bubble.appendChild(cDiv);
      }
      
      msgDiv.appendChild(bubble);
      chatMessages.appendChild(msgDiv);
      chatMessages.scrollTop = chatMessages.scrollHeight;
      return bubble;
    }

    // --- Streaming LLM chat ---
    async function streamLLMChat(prompt, bubbleElement, model='DeepSeek-V3.2', fileId, convoName, transcript, convoHistory, currentConvoId = null) {
      let systemPrompt = "You are a helpful assistant. Answer questions about the transcript and provide helpful, concise, and accurate responses.";
      
      try {
        const response = await fetch(rootURL + '/llm/stream', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ prompt: prompt, system_prompt: systemPrompt, model: model, file_id: fileId, convo_name: convoName, transcript: transcript, convo_history: convoHistory, convo_id: currentConvoId })
        });
        
        if (!response.ok) {
          bubbleElement.textContent = 'Error: ' + response.status;
          return;
        }

        // Clear the initial "thinking" spinner
        bubbleElement.innerHTML = ''; 
        
        // Setup DOM elements for the incoming stream
        const thoughtContainer = document.createElement('div');
        thoughtContainer.style.display = 'none'; // Hidden until we get a thought
        thoughtContainer.style.backgroundColor = '#f8f9fa';
        thoughtContainer.style.borderLeft = '4px solid #6c757d';
        thoughtContainer.style.padding = '8px 12px';
        thoughtContainer.style.marginBottom = '10px';
        thoughtContainer.style.fontSize = '0.9em';
        thoughtContainer.style.color = '#6c757d';
        thoughtContainer.style.whiteSpace = 'pre-wrap';
        
        const contentContainer = document.createElement('div');
        
        bubbleElement.appendChild(thoughtContainer);
        bubbleElement.appendChild(contentContainer);

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        
        let completeThought = '';
        let completeContent = '';
        let buffer = ''; // Buffer for incomplete SSE chunks

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          
          // Append new chunk to our buffer and split by newlines
          buffer += decoder.decode(value, { stream: true });
          let lines = buffer.split('\n');
          
          // Keep the last element in the buffer, as it might be an incomplete line
          buffer = lines.pop(); 
          
          for (let i = 0; i < lines.length; i++) {
            const line = lines[i].trim();
            if (!line) continue;
            
            if (line.startsWith('event: convo_id')) {
               if (i + 1 < lines.length && lines[i+1].startsWith('data: ')) {
                 const newConvoId = lines[i+1].substring(6).trim();
                 if (newConvoId && !convoId) {
                   convoId = newConvoId;
                   window.convoId = convoId;
                 }
                 i++; // Skip the next line since we processed it
               }
               continue;
            }
            
            if (line.startsWith('data: ')) {
              const data = line.substring(6).trim();
              if (data === '[DONE]') continue;
              
              try {
                const payload = JSON.parse(data);
                
                if (payload.type === 'thought') {
                  completeThought += payload.text;
                  thoughtContainer.style.display = 'block'; // Unhide box
                  thoughtContainer.innerText = completeThought;
                  
                  // Auto-scroll as thoughts come in
                  const chatMessages = document.getElementById('chatMessages');
                  chatMessages.scrollTop = chatMessages.scrollHeight;
                  
                } else if (payload.type === 'content') {
                  completeContent += payload.text;
                  if (window.marked) {
                    contentContainer.innerHTML = window.marked.parse(completeContent);
                  } else {
                    contentContainer.textContent = completeContent;
                  }
                  
                  // Auto-scroll as content comes in
                  const chatMessages = document.getElementById('chatMessages');
                  chatMessages.scrollTop = chatMessages.scrollHeight;
                }
              } catch (e) {
                console.error("Failed to parse JSON chunk:", data, e);
              }
            }
          }
        }
        
        // Return only the main content so we don't save messy UI HTML to our local frontend state
        return completeContent; 
        
      } catch (err) {
        bubbleElement.textContent = 'Error: ' + err;
      }
    }

    $('#chatForm').on('submit', async function(e) {
      e.preventDefault();
      if (isStreaming) return;
      
      const input = document.getElementById('chatInput');
      const userMsg = input.value.trim();
      let convoName = userMsg.split(' ', 2).join(' ').trim();
      convoName = convoName.substring(0, 20) + '...';
      
      if (!userMsg || !convoName) return;
      
      let transcript = '';
      if (!transcriptSent) {
        transcript = transcriptLoaded;
        transcriptSent = true;
      }
      
      appendMessage('user', userMsg);
      input.value = '';
      
      isStreaming = true;
      // Pass actual DOM element to stream function now
      const llmBubble = appendMessage('llm', '', false, true);
      
      // Wait for stream to finish and get final content text
      const finalResponse = await streamLLMChat(userMsg, llmBubble, 'DeepSeek-V3.2', fileId, convoName, transcript, chatHistory, window.convoId || convoId);
      
      chatHistory.push({ role: 'user', content: userMsg });
      if (finalResponse && finalResponse.trim()) {
        chatHistory.push({ role: 'assistant', content: finalResponse.trim() });
      }
      
      isStreaming = false;
    });

    // --- Conversation List Sidebar ---
    function loadConversationList() {
      $.get(rootURL + '/llm/list-conversations', { file_id: fileId }, function(data) {
        const listDiv = document.getElementById('conversationList');
        listDiv.innerHTML = '';
        if (Array.isArray(data) && data.length > 0) {
          data.forEach(function(convo) {
            const btn = document.createElement('button');
            btn.className = 'btn btn-sm btn-outline-primary w-100 mb-1';
            btn.textContent = convo.convo_name;
            btn.onclick = function() { loadConversationById(convo.id); };
            listDiv.appendChild(btn);
          });
        } else {
          listDiv.innerHTML = '<div class="text-muted">No conversations found.</div>';
        }
      });
    }

    function loadConversationById(newConvoId) {
      convoId = newConvoId;
      $.get(rootURL + '/llm/get-convo', { file_id: fileId, convo_id: convoId }, function(messages) {
        chatHistory = Array.isArray(messages) ? messages : [];
        const chatMessages = document.getElementById('chatMessages');
        chatMessages.innerHTML = '';
        
        chatHistory.forEach(function(m) {
          let content = m.content;
          let thoughtText = null;
          
          // Handle old formatting if previous messages saved <think> tags to the database
          const thinkStart = '<think>';
          const thinkEnd = '</think>';
          if (content.includes(thinkStart) && content.includes(thinkEnd)) {
            const startIdx = content.indexOf(thinkStart);
            const endIdx = content.indexOf(thinkEnd);
            thoughtText = content.substring(startIdx + thinkStart.length, endIdx).trim();
            content = content.substring(endIdx + thinkEnd.length).trim();
          }
          
          appendMessage(m.role, content, true, false, thoughtText);
        });
      });
    }

    $('#refreshConvosBtn').on('click', loadConversationList);
    loadConversationList();
  </script>
</body>
<?php
  include_once __DIR__ . '/../../_footer.php';
?>
