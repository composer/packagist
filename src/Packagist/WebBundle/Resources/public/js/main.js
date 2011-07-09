window.showLoader = function () {
	$('#loader').removeClass('hidden');
	$('#loader').show();
}

window.hideLoader = function() {
	$('#loader').addClass('hidden');
	$('#loader').hide();	
}