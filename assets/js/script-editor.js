/**
 * Energy Analytics Script Editor
 *
 * CodeMirror integration for script editing
 *
 * @package Energy_Analytics
 */

jQuery(document).ready(function($) {
    // Check if we're on the script edit page
    if ($('#post-type-ea_script').length || $('#post-type-select option:selected').val() === 'ea_script') {
        // Initialize CodeMirror on the content editor
        var editorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
        editorSettings.codemirror = _.extend(
            {},
            editorSettings.codemirror,
            {
                mode: 'javascript',
                lineNumbers: true,
                indentUnit: 4,
                tabSize: 4,
                lineWrapping: true,
                autoCloseBrackets: true,
                matchBrackets: true,
                theme: 'default'
            }
        );
        
        // If using classic editor
        if ($('#content').length) {
            var editor = wp.codeEditor.initialize($('#content'), editorSettings);
            
            // Save content back to textarea before form submission
            $('#post').on('submit', function() {
                $('#content').val(editor.codemirror.getValue());
            });
        }
    }
});