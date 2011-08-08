(function ($){
	$('#add-maintainer').click(function () {
		var $addMaintainerForm = $('#add-maintainer-form');
		if($addMaintainerForm.hasClass('hidden')) {
			$addMaintainerForm.removeClass('hidden');
		}
		else {
			$addMaintainerForm.addClass('hidden');
		}		
	});
})(jQuery);