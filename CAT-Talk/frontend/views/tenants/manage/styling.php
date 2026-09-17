<?php
/** 
 * @var User $user
 * @var string $tenantId
 * @var Tenant $tenant 
 * @var array $_headerTenantStyles
 * */


?>

    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-2 mb-3 border-bottom">
        <h1 class="h4">Styling - <span class="text-muted">Adjust the styling of this tenant</span></h1>
    </div>

    <div class="row mb-5">
        <div class="">
            <form id="style-editor"></form>
            <button id="submitStyles" class="btn btn-success w-100 mt-3">Save Styles</button>
            <div id="responseMessage" class="mt-3"></div>
        </div>
    </div>

    <script type="text/javascript">

        var styleFields = [];

        <?php 
        
        $tenantStyles = $tenant->getStyles();
        echo "styleFields.push({ id: 'title_text', label: 'Site Title Text', type: 'text', value: '{$tenantStyles['title_text']}', default: '{$_defaultTenantStyles['title_text']}' });";
        echo "styleFields.push({ id: 'title_text_color', label: 'Site Title Text Color', type: 'color', value: '{$tenantStyles['title_text_color']}', default: '{$_defaultTenantStyles['title_text_color']}' });";
        echo "styleFields.push({ id: 'navbar_color', label: 'NavBar Color', type: 'color', value: '{$tenantStyles['navbar_color']}', default: '{$_defaultTenantStyles['navbar_color']}' });";
        echo "styleFields.push({ id: 'navbar_menu_color', label: 'NavBar Text Color', type: 'color', value: '{$tenantStyles['navbar_menu_color']}', default: '{$_defaultTenantStyles['navbar_menu_color']}' });";
        echo "styleFields.push({ id: 'menu_active_dropdown_text_color', label: 'Active Menu Dropdown Text Color', type: 'color', value: '{$tenantStyles['menu_active_dropdown_text_color']}', default: '{$_defaultTenantStyles['menu_active_dropdown_text_color']}' });";
        echo "styleFields.push({ id: 'menu_active_dropdown_bg_color', label: 'Active Menu Background Color', type: 'color', value: '{$tenantStyles['menu_active_dropdown_bg_color']}', default: '{$_defaultTenantStyles['menu_active_dropdown_bg_color']}' });";
        echo "styleFields.push({ id: 'button_primary', label: 'Primary Buttons', type: 'color', value: '{$tenantStyles['button_primary']}', default: '{$_defaultTenantStyles['button_primary']}' });";
        echo "styleFields.push({ id: 'button_secondary', label: 'Secondary Buttons', type: 'color', value: '{$tenantStyles['button_secondary']}', default: '{$_defaultTenantStyles['button_secondary']}' });";
        echo "styleFields.push({ id: 'button_success', label: 'Success Buttons', type: 'color', value: '{$tenantStyles['button_success']}', default: '{$_defaultTenantStyles['button_success']}' });";
        echo "styleFields.push({ id: 'button_danger', label: 'Danger Buttons', type: 'color', value: '{$tenantStyles['button_danger']}', default: '{$_defaultTenantStyles['button_danger']}' });";
        echo "styleFields.push({ id: 'button_warning', label: 'Warning Buttons', type: 'color', value: '{$tenantStyles['button_warning']}', default: '{$_defaultTenantStyles['button_warning']}' });";
        echo "styleFields.push({ id: 'button_info', label: 'Info Buttons', type: 'color', value: '{$tenantStyles['button_info']}', default: '{$_defaultTenantStyles['button_info']}' });";
        echo "styleFields.push({ id: 'button_light', label: 'Light Buttons', type: 'color', value: '{$tenantStyles['button_light']}', default: '{$_defaultTenantStyles['button_light']}' });";
        echo "styleFields.push({ id: 'button_dark', label: 'Dark Buttons', type: 'color', value: '{$tenantStyles['button_dark']}', default: '{$_defaultTenantStyles['button_dark']}' });";


        ?>


        // function revert(elementId, defaultValue) {
        //     event.preventDefault();
        //     $(`#${elementId}`).val(defaultValue);
        // }



        $(document).ready(function () {
            const formContainer = $("#style-editor");
            const pickrInstances = {};
            
            styleFields.forEach(field => {
                const inputField = $(`
                    <div class="mb-3 input-group">
                        <label for="${field.id}" class="input-group-text style-input-label">${field.label}</label>
                        <input type="text" class="form-control ${field.type === 'color' ? 'color-input' : ''}" id="${field.id}" name="${field.id}" value="${field.value}">
                        <button class="btn btn-secondary revert-btn" data-toggle='tooltip' data-placement='left' title='Revert to default' data-id="${field.id}" data-default="${field.default}">
                            <i class="fas fa-rotate-left mr-1"></i>
                        </button>
                    </div>
                `);

                formContainer.append(inputField);

                // Initialize Pickr.js for color fields
                if (field.type === 'color') {
                    pickrInstances[field.id] = Pickr.create({
                        el: `#${field.id}`,
                        theme: 'classic', // or 'monolith', 'nano'
                        default: field.value,
                        swatches: ['#FF0000', '#00FF00', '#0000FF', '#FFFF00', 'black', 'white'],
                        components: {
                            preview: true,
                            opacity: true,
                            hue: true,
                            interaction: {
                                input: true,   
                                clear: true,
                                save: true,
                                hex: true,
                                rgba: true,
                                cmyk: true,
                            }
                        }
                    }).on('save', (color, instance) => {
                        $(`#${field.id}`).val(color.toHEXA().toString()); // Update input value
                        instance.hide(); // Hide picker after selecting
                    });
                    
                    
                    const labelWidth = $(`label[for="${field.id}"]`).outerWidth();
                    const labelHeight = $(`label[for="${field.id}"]`).outerHeight();

                    pickrInstances[field.id].on('init', instance => {
                        $(instance.getRoot().button).css({
                            'border': 'var(--bs-border-width) solid var(--bs-border-color)', // Change color as needed
                            'border-radius': '0px',
                            'padding': '5px',
                            'width': "100px",
                            'height': `${labelHeight}px`,
                        });
                    });

                    // Handle manual input updates
                    $(`#${field.id}`).on("input", function () {
                        let inputValue = $(this).val();
                        try {
                            pickrInstances[field.id].setColor(inputValue);
                        } catch (e) {
                            showError("Invalid color format:", inputValue);
                        }
                    });
                }
            });

            // Revert Button Click Event
            $(document).on("click", ".revert-btn", function (event) {
                event.preventDefault();
                const fieldId = $(this).data("id");
                const defaultValue = $(this).data("default");

                if (pickrInstances[fieldId]) {
                    pickrInstances[fieldId].setColor(defaultValue); // Update Pickr UI
                }

                $(`#${fieldId}`).val(defaultValue).trigger("change"); // Update input field
            });


            // Calculate and set the max width for input-group-text elements
            let maxWidth = 0;
            $(".style-input-label").each(function () {
                const width = $(this).outerWidth();
                if (width > maxWidth) {
                    maxWidth = width;
                }
            });

            $(".style-input-label").css("width", maxWidth + "px");
            // $("#style-editor .form-control-color").css({
            //     "flex": "0 0 150px",
            //     "width": "150px",
            // });


            // Handle form submission
            $("#submitStyles").click(function () {
                const formData = {};

                styleFields.forEach(field => {
                    if (field.type === 'color' && pickrInstances[field.id]) {
                        formData[field.id] = pickrInstances[field.id].getColor().toHEXA().toString();
                    } else {
                        formData[field.id] = $("#" + field.id).val();
                    }
                });

                $.ajax({
                    url: "<?= $rootURL ?>/tenants/<?= $tenantId ?>/save-styles",
                    type: "POST",
                    contentType: "application/json",
                    data: JSON.stringify(formData),
                    success: function (response) {
                        <?php if ($_headerCurrentTenantId == $tenantId): ?>
                        location.reload();
                        <?php else: ?>
                        showSuccess("Updated Tenant: <?= $tenant->getName() ?>")
                        <?php endif; ?>
                    },
                    error: function (xhr, status, error) {
                        showError("Failed to edit styles");
                    }
                });
            });

        });


        
    </script>