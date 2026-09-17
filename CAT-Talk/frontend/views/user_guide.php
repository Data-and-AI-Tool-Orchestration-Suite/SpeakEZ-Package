<?php
/** @var UserSession $userSession */
$page = 'user-guide';
include_once __DIR__ . '/_header.php';
?>


        <script>
            $(function() {
                
            });
        </script>

    
            <!-- <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
                <h4>Welcome to the Center for Applied Artificial Intelligence (CAAI) data processing/management site.</h4>
            </div>
            <p>If you are unable to see any options that pertain to your field of study, please contact an administrator.</p>
            <p>Otherwise, if you would like to use the tools available here, please register using this registration form <a href="#">here</a>.</p> -->
            <style>
                p {
                    font-size: 16px;
                }
                a {
                    font-size: 16px;
                }
            </style>
            <div id="markdown-content" style="margin-left: auto; margin-right: auto; max-width:75%"></div>

            
            <script type="text/javascript">
                
                $(function() {
                    window.renderer = new marked.marked.Renderer();

                window.renderer.heading = function(input) {
                    const anchor = input.text.toLowerCase().replace(/[^\w]+/g, '-');  // Generate ID based on the text
                    return `<h${input.depth} id="${anchor}" style="border-bottom: 2px solid #ccc;padding-bottom: 10px;margin-bottom: 20px;">
                    ${input.text}</h${input.depth}>`;
                };
                window.renderer.image = function(image) {
                    return `<img alt="${image.text}" src="${image.href}" class="responsive-img">`;
                };
                window.renderer.code = (code, infostring, escaped) => {
                    if (!infostring && code.lang){
                        infostring = code.lang;
                    }
                    // if (code.text){
                    //     code = code.text;
                    // }
                    
                    
                    const language = infostring ? `language-${infostring}` : '';
                    // const safeCode = code.text.replace(/\\/g, '\\\\') // Escape backslashes
                    //      .replace(/`/g, '\\`') // Escape backticks
                    //      .replace(/"/g, '\\"') // Escape double quotes
                    //      .replace(/'/g, '\\\''); // Escape single quotes
                    const safeCode = code.text.replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    return `
                    <div>
                        <div class="custom-code-block-header">
                            <div class="custom-code-block-header-label">${infostring}</div>
                            <div class="custom-code-block-header-copy" onclick='copyToClipboard(${JSON.stringify(safeCode)})'><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" class="icon-sm"><path fill="currentColor" fill-rule="evenodd" d="M7 5a3 3 0 0 1 3-3h9a3 3 0 0 1 3 3v9a3 3 0 0 1-3 3h-2v2a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3v-9a3 3 0 0 1 3-3h2zm2 2h5a3 3 0 0 1 3 3v5h2a1 1 0 0 0 1-1V5a1 1 0 0 0-1-1h-9a1 1 0 0 0-1 1zM5 9a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1z" clip-rule="evenodd"></path></svg> Copy code</div>
                        </div>
                        <div class="custom-code-block"><pre><code class="${language}">${code.text.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</code></pre></div>
                    </div>
                    <br>`;
                };

                // Optionally, you can wrap inline code as well
                window.renderer.codespan = (code) => {
                    if (code.text){
                        code = code.text;
                    }
                    
                    return `<pre><span class="custom-inline-code"><code>${code.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</code></span></pre>`;
                };

                // window.renderer.paragraph = function(input) {
                //     if (input.text.trim() === ''){
                //         return ''
                //     }
                //     return `<p style="font-size: 16px;">${marked.marked(input.text)}</p>`;
                // };
                window.renderer.link = (input) => {
                    if (input.href.startsWith("https://") || input.href.startsWith("http://")){
                        return `<a href="${input.href}" target="_blank">${input.text}</a>`;
                    }
                    return `<a href="${input.href}">${input.text}</a>`;
                };


                    async function displayMarkdown(file) {
                        try {
                            // Fetch the Markdown file
                            const response = await fetch(file);
                            if (!response.ok) {
                                throw new Error('Network response was not ok ' + response.statusText);
                            }
                            const markdown = await response.text();
                            // console.log(markdown)
                            // Convert Markdown to HTML using marked.js
                            const htmlContent = marked.marked(markdown, { renderer: window.renderer });
                            // console.log(htmlContent)
                            // Insert the HTML content into the DOM
                            document.getElementById('markdown-content').innerHTML = htmlContent;

                            document.querySelectorAll('pre code').forEach((block) => {
                                hljs.highlightElement(block);
                            });
                            // document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                            //     anchor.addEventListener('click', function(e) {
                            //         console.log(e)
                                    
                            //         const targetId = this.getAttribute('href').substring(1);
                            //         if (targetId){
                            //             const targetElement = document.getElementById(targetId);
                            //             e.preventDefault();
                            //             // Calculate the offset based on the height of the fixed header
                            //             const headerOffset = document.querySelector('#page-header').offsetHeight;
                            //             const elementPosition = targetElement.getBoundingClientRect().top + window.scrollY;
                            //             const offsetPosition = elementPosition - headerOffset;
                            //             console.log(targetId, targetElement)
                            //             console.log(headerOffset, elementPosition, offsetPosition)

                            //             window.scrollTo({
                            //                 top: offsetPosition,
                            //                 behavior: 'smooth'
                            //             });
                            //         }
                            //     });
                            // });
                            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                                anchor.addEventListener('click', function (e) {
                                    // Get the target ID from the href attribute
                                    const targetId = this.getAttribute('href').substring(1);
                                    if (targetId) {
                                        const targetElement = document.getElementById(targetId);
                                        if (targetElement) {
                                            e.preventDefault();

                                            // Identify the scrollable container (main)
                                            const scrollContainer = document.querySelector('main');
                                            const header = document.querySelector('#page-header');
                                            const headerOffset = header ? header.offsetHeight : 0;

                                            // Calculate the target position relative to the container
                                            const elementPosition =
                                                targetElement.getBoundingClientRect().top +
                                                scrollContainer.scrollTop; // Use container's scroll position
                                            const offsetPosition = elementPosition - headerOffset;

                                            // Smooth scroll to the calculated position within the container
                                            scrollContainer.scrollTo({
                                                top: offsetPosition,
                                                behavior: 'smooth',
                                            });
                                        } else {
                                            console.warn(`Element with ID '${targetId}' not found.`);
                                        }
                                    }
                                });
                            });
                        } catch (error) {
                            console.error('Failed to load markdown file:', error);
                            document.getElementById('markdown-content').innerText = 'Failed to load content.';
                        }
                    }

                    // Call the function with the path to your Markdown file
                    displayMarkdown('<?= $rootURL ?>/user_guide.md');



                    // document.addEventListener("DOMContentLoaded", function() {
                    //     // Add a click event listener to all links with hash (#) in the href

                    // });
                });

            </script>
<?php
include_once __DIR__ . '/_footer.php';