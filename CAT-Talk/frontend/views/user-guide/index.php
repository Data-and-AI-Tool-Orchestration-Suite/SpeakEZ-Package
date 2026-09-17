<?php
/** @var User $user */
$page = 'user-guide';
include_once __DIR__ . '/../_header.php';
?>

    <link rel="stylesheet" href="<?= $rootURL ?>/css/user-guide.css" />

    
    <div class="main-container">
        <div class="toc-sidebar">
            <h2>Contents</h2>
            <ul class="toc" id="toc"></ul>
        </div>
        <div class="content" id="content"></div>
    </div>
    
    
    <div class="back-to-top" id="backToTop">↑</div>

    <script>

        let globalCurrentTOCSection = window.location.hash ? window.location.hash.substring(1) : "user-guide-llm-factory";
        let tocLock = false;

        $(document).ready(function() {
            // Function to load Markdown content from external file
            async function loadMarkdownFile(url) {
                try {
                    const response = await $.ajax({
                        url: url,
                        type: 'GET',
                        dataType: 'text'
                    });
                    return response;
                } catch (error) {
                    console.error("Error loading Markdown:", error);
                    return "# Error Loading Content\nThere was an error loading the documentation.";
                }
            }

            // Convert markdown to HTML
            function renderMarkdown(markdown) {
                marked.setOptions({
                    headerIds: true,
                    headerPrefix: '',
                    sanitizer: null,
                    gfm: true,
                    highlight: function(code, lang) {
                        return hljs.highlightAuto(code).value;
                    },
                });

                return marked.parse(markdown);
            }

            // Extract headings to build TOC
            function extractToc(markdown) {
                const headingRegex = /^#+\s+(.+)$/gm;
                const toc = [];
                let match;
                
                while ((match = headingRegex.exec(markdown)) !== null) {
                    const headingText = match[1].replace(/\*\*/g, ''); // Remove asterisks
                    const level = (match[0].match(/^#+/)[0]).length;
                    const anchor = headingText.toLowerCase().trim().replace(/[^\w]+/g, '-');     // Replace spaces with hyphens
                    
                    toc.push({ text: headingText, level, anchor });
                }
                
                return toc;
            }

            // Build TOC HTML
            function buildTocHtml(toc) {
                let html = '';
                let prevLevel = 0;
                let listStack = [];
                
                toc.forEach(item => {
                    // Skip the actual Table of Contents heading
                    if (item.text.includes("Table of Contents")) {
                        return;
                    }

                    // Skip entries that don't have a matching heading in the content
                    if ($(`#${item.anchor}`).length === 0) {
                        return;
                    }
                    
                    while (item.level > prevLevel + 1) {
                        html += '<ul><li>';
                        listStack.push('</li></ul>');
                        prevLevel++;
                    }
                    
                    while (item.level < prevLevel) {
                        html += listStack.pop();
                        prevLevel--;
                    }
                    
                    if (item.level === prevLevel) {
                        html += item.level === 1 ? '' : '</li><li>';
                    } else if (item.level === prevLevel + 1) {
                        html += '<ul><li>';
                        listStack.push('</li></ul>');
                        prevLevel++;
                    }
                    
                    html += `<a href="#${item.anchor}" class="toc-link" data-anchor="${item.anchor}">${item.text}</a>`;
                });
                
                while (listStack.length > 0) {
                    html += listStack.pop();
                }
                
                return html;
            }

            function setActiveTOCItem($activeLink){
                $('#toc .toc-link').removeClass('active');
                $activeLink.addClass('active');
                // Scroll the TOC to keep the active link in view
                const $toc = $('#toc');
                const tocScrollTop = $toc.offset().top;
                const tocHeight = $toc.height();
                const linkOffsetTop = $activeLink.offset().top -tocScrollTop; // relative to #toc
                const linkHeight = $activeLink.outerHeight();
                if (linkOffsetTop < 0) {
                    // Link is above the visible area
                    $toc.scrollTop($toc.scrollTop() + linkOffsetTop );
                } else if (linkOffsetTop + linkHeight > tocHeight) {
                    // Link is below the visible area
                    const diff = linkOffsetTop + linkHeight - tocHeight;
                    $toc.scrollTop($toc.scrollTop() + diff);
                }
            }

            // Update active TOC item based on scroll position
            function updateActiveTocItem() {
                const $main = $('main');
                const mainMarginTop = parseInt($('main').css('padding-top'), 10);
                const scrollPosition = $main.scrollTop();
                const mainTop = mainMarginTop + $main.offset().top
                // Get all section positions
                const sections = $('#content').find('h1, h2, h3, h4, h5, h6').map(function () {
                    const $el = $(this);
                    const offsetTop = $el.offset().top - mainTop; 
                    return {
                        id: $el.attr('id'),
                        offsetTop: offsetTop
                    };
                }).get();
                
                // Find the current section
                let currentSection = sections[0]?.id;
                sections.forEach(section => {
                    if (section.offsetTop < 0) {
                        currentSection = section.id;
                    }
                });

                // Update active class
                if (currentSection !== globalCurrentTOCSection && !tocLock){
                    globalCurrentTOCSection = currentSection
                    if (currentSection) {
                        const $activeLink = $(`#toc .toc-link[data-anchor="${currentSection}"]`);
                        setActiveTOCItem($activeLink);
                    }
                }
                

                // Show/hide back to top button
                if (scrollPosition > 300) {
                    $('#backToTop').fadeIn();
                } else {
                    $('#backToTop').fadeOut();
                }
            }

            // Remove the original table of contents in the content
            function removeTOCFromContent($content) {
                // Find the heading that contains "Table of Contents"
                const $tocHeading = $content.find('h2:contains("Table of Contents")').first();

                if ($tocHeading.length) {
                    const elementsToRemove = [$tocHeading[0]];

                    // Traverse siblings until next <h2>
                    let $el = $tocHeading.next();
                    while ($el.length && !$el.is('h2')) {
                        elementsToRemove.push($el[0]);
                        $el = $el.next();
                    }

                    // Remove all collected elements
                    $(elementsToRemove).remove();
                }
            }

            function gotoGuideItem(targetId){
                tocLock = true;
                const $targetElement = $(`#${targetId}`);
                if ($targetElement.length) {
                    history.replaceState(null, "", `#${targetId}`);
                    const $scrollContainer = $('main');
                    const $header = $('#page-header');
                    const headerOffset = $header.length ? $header.outerHeight() : 0;
                    const $activeLink = $(`#toc .toc-link[data-anchor="${targetId}"]`);
                    setActiveTOCItem($activeLink);

                    const elementPosition = $targetElement.offset().top - $scrollContainer.offset().top + $scrollContainer.scrollTop();
                    const offsetPosition = elementPosition - headerOffset;

                    $scrollContainer.animate({
                        scrollTop: offsetPosition
                    }, 500, function () {
                        tocLock = false;
                        
                    }); // Adjust duration if needed
                } else {
                    console.warn(`Element with ID '${targetId}' not found.`);
                }
            }

            // Initialize the documentation
            async function initDocumentation() {
                const markdown = await loadMarkdownFile('<?= $rootURL ?>/user_guide.md');
                
                // Render content
                const renderedContent = renderMarkdown(markdown);
                
                $('#content').html(renderedContent);

                const contentHeaders = $("#content").find('h1, h2, h3, h4, h5, h6');
                contentHeaders.each(function(index, element) {
                    const anchor = $(element).text().toLowerCase().replace(/[^\w]+/g, '-');
                    element.id = anchor;
                });

                // Hide original TOC
                removeTOCFromContent($('#content'));
                
                // Build and insert TOC
                const toc = extractToc(markdown);
                $('#toc').html(buildTocHtml(toc));
                

                gotoGuideItem(globalCurrentTOCSection);
                
                // Setup scroll event handler
                $("main").on('scroll', updateActiveTocItem);
                
                // Setup back to top button
                $('#backToTop').on('click', function() {
                    $('main').animate({
                        scrollTop: 0
                    }, 500);
                });
                
                // Initial update of active TOC item
                updateActiveTocItem();

                $('#toc .toc-link').on('click', function (e) {
                    
                    const targetId = $(this).attr('href').substring(1);
                    if (targetId) {
                        e.preventDefault();
                        gotoGuideItem(targetId);
                    }
                });
            }

            

            // Initialize when DOM is ready
            initDocumentation();
        });
    </script>
<?php
include_once __DIR__ . '/../_footer.php';
