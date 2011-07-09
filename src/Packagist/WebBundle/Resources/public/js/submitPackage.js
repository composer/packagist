$(document).ready(function() {
    
    $('#submit').click(function(){
        var repo_name = $('#package_repository').val();
        $('#form').hide();
    	showLoader();
        $.getJSON(window.base + '/name', { url: repo_name }, function(data) {
            $('#package_name').html(data);
            $('#repo_name').html(repo_name);
            hideLoader();
            $('#confirmation_panel').show();
        });
        return false;
    });
    
    $('#confirm').click(function(){
    	$('#confirmation_panel').hide();
    	showLoader();
    	
    	return true;
    });
});